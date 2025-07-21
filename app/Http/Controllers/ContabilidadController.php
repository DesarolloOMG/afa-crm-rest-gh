<?php /** @noinspection PhpUnused */

/** @noinspection PhpUndefinedFieldInspection */

namespace App\Http\Controllers;

use App\Http\Services\MovimientoContableService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ContabilidadController extends Controller
{
    /* Contabilidad > Facturas */
    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'CC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'DAD'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    public function contabilidad_facturas_pendiente_data(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);
        $formas_pago = DB::table('cat_forma_pago')->get();
        $monedas = DB::table('moneda')->get();
        $entidades = DB::table('cat_entidad_financiera')
            ->select(
                'cat_entidad_financiera.id',
                'cat_entidad_financiera.nombre',
                'cat_entidades_financieras_tipo.tipo',
                'cat_bancos.razon_social',
                'cat_bancos.rfc',
                'moneda.moneda',
                'cat_entidad_financiera.no_cuenta',
                'cat_entidad_financiera.sucursal',
                'cat_entidad_financiera.convenio',
                'cat_entidad_financiera.clabe',
                'cat_entidad_financiera.swift',
                'cat_entidad_financiera.plazo',
                'cat_entidad_financiera.comentarios'
            )
            ->join('cat_bancos', 'cat_entidad_financiera.id_banco', '=', 'cat_bancos.id')
            ->join('moneda', 'cat_entidad_financiera.id_moneda', '=', 'moneda.id')
            ->join('cat_entidades_financieras_tipo', 'cat_entidad_financiera.id_tipo', '=', 'cat_entidades_financieras_tipo.id')
            ->get();

        // Llama al SP y pasa el id de usuario autenticado
        $ventas = DB::select('CALL sp_ventas_raw_data(?)', [$auth->id]);

        // Opcional: Decodifica los campos JSON si quieres arrays en lugar de strings
        foreach ($ventas as $venta) {
            $venta->productos = json_decode($venta->productos, true) ?? [];
            $venta->pagos = json_decode($venta->pagos, true) ?? [];
            $venta->seguimiento = json_decode($venta->seguimiento, true) ?? [];
        }

        return response()->json([
            'code' => 200,
            'ventas' => $ventas,
            'formas_pago' => $formas_pago,
            'monedas' => $monedas,
            'entidades_financieras' => $entidades
        ]);
    }

    public function contabilidad_facturas_pendiente_guardar(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);
        $dataForm = json_decode($request->input('data'));
        $user_id = $auth->id;

        $documento = DB::table('documento')->where('id', $dataForm->documento)->first();
        if (!$documento) {
            return response()->json(['code' => 400, 'message' => 'Documento no encontrado.'], 400);
        }
        if ($dataForm->importe > $documento->saldo) {
            return response()->json(['code' => 400, 'message' => 'El importe no puede ser mayor al saldo pendiente.'], 400);
        }

        $entidad = DB::table('documento_entidad')->where('id', $documento->id_entidad)->first();
        $nombre_entidad_origen = $entidad ? $entidad->razon_social : '';

        $cuenta = DB::table('cat_entidad_financiera')->where('id', $dataForm->entidad_destino)->first();
        $nombre_entidad_destino = $cuenta ? $cuenta->nombre : '';

        // Construir el array de datos para el servicio
        $data = [
            'id_tipo_afectacion'     => 1,
            'fecha_operacion'        => $dataForm->fecha_cobro,
            'fecha_afectacion'       => $dataForm->fecha_cobro,
            'moneda'                 => $documento->id_moneda,
            'tipo_cambio'            => 1,
            'monto'                  => $dataForm->importe,
            'origen_tipo'            => 1,
            'entidad_origen'         => $documento->id_entidad,
            'nombre_entidad_origen'  => $nombre_entidad_origen,
            'destino_tipo'           => 2,
            'entidad_destino'        => $dataForm->entidad_destino,
            'nombre_entidad_destino' => $nombre_entidad_destino,
            'id_forma_pago'          => $dataForm->metodo_pago,
            'referencia_pago'        => $dataForm->referencia,
            'descripcion_pago'       => '.',
            'comentarios'            => $dataForm->seguimiento,
            'creado_por'             => $user_id,
            'documentos' => [
                [
                    'id_documento'     => $dataForm->documento,
                    'monto_aplicado'   => $dataForm->importe,
                    'moneda'           => $documento->id_moneda,
                    'tipo_cambio_aplicado' => 1,
                    'parcialidad'      => 1
                ]
            ]
        ];

        $resultado = MovimientoContableService::crearMovimiento($data);

        if ($resultado['success']) {
            return response()->json(['code' => 200, 'message' => 'Ingreso registrado correctamente.']);
        } else {
            return response()->json(['code' => 400, 'message' => $resultado['error'] ?? 'Ocurrió un error.'], 400);
        }
    }

    public function contabilidad_facturas_saldar_data($id_entidad): JsonResponse
    {
        // ingresos filtrados por entidad
        $ingresos = DB::select('CALL sp_contabilidad_ingresos_disponibles_por_entidad(?)', [$id_entidad]);

        // documentos filtrados por entidad
        $documentos = DB::select('CALL sp_contabilidad_documentos_con_saldo_por_entidad(?)', [$id_entidad]);

        $monedas = DB::table('moneda')->get();

        return response()->json([
            'code' => 200,
            'ingresos' => $ingresos,
            'documentos' => $documentos,
            'monedas' => $monedas
        ]);
    }


    public function contabilidad_facturas_saldar_guardar(Request $request): JsonResponse
    {
        $documentos = $request->input("documentos");
        $id_ingreso = $request->input("id_ingreso");

        // Trae el ingreso (movimiento contable)
        $ingreso = DB::table('movimiento_contable')->where('id', $id_ingreso)->first();
        if (!$ingreso) {
            return response()->json(['code' => 404, 'msg' => 'Ingreso no encontrado']);
        }

        // Traduce id_moneda a código de moneda
        $codigoMonedaIngreso = DB::table('moneda')->where('id', $ingreso->id_moneda)->value('moneda');

        // Arma el array de documentos para el service
        $documentos_formateados = [];
        foreach ($documentos as $doc) {
            $documentos_formateados[] = [
                'id_documento' => $doc['id_documento'],
                'monto_aplicado' => $doc['monto'],
                'moneda' => (int)$doc['moneda'],
                'tipo_cambio_aplicado' => $doc['tipo_cambio_aplicado'] ?? $ingreso->tipo_cambio,
                'parcialidad' => 1,
            ];
        }

        DB::beginTransaction();
        try {
            $resultados = MovimientoContableService::aplicarADocumentos(
                $id_ingreso,
                $documentos_formateados,
                $codigoMonedaIngreso, // <-- código de moneda, NO id
                $ingreso->tipo_cambio
            );

            $errores = array_filter($resultados, function ($res) {
                return $res['status'] === 'error';
            });

            if (count($errores) > 0) {
                DB::rollBack();
                return response()->json([
                    'code' => 400,
                    'msg' => 'Algunos documentos no pudieron ser aplicados',
                    'resultados' => $resultados
                ]);
            }

            DB::commit();
            return response()->json([
                'code' => 200,
                'msg' => 'Ingreso aplicado correctamente',
                'resultados' => $resultados
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'msg' => 'Error inesperado',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function contabilidad_facturas_dessaldar_buscar(Request $request): JsonResponse
    {
        $folio = $request->input('folio');

        // Ejecuta el stored procedure que devuelve los datos del ingreso y los documentos aplicados
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare("CALL sp_dessaldar_buscar_ingreso(?)");
        $stmt->execute([$folio]);

        // Primer resultset: datos del ingreso (solo uno)
        $ingreso = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Avanza al segundo resultset: lista de documentos aplicados a ese ingreso
        $stmt->nextRowset();
        $documentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Si no se encontró el ingreso activo con ese folio, retorna código 404
        if (!$ingreso) {
            return response()->json([
                'code' => 404,
                'msg' => 'No se encontró el ingreso activo con ese folio.'
            ]);
        }

        // Responde con los datos del ingreso y la lista de documentos relacionados
        return response()->json([
            'code' => 200,
            'msg' => 'OK',
            'ingreso' => $ingreso,
            'documentos' => $documentos
        ]);
    }

    public function contabilidad_facturas_dessaldar_buscar_documento($id_documento): JsonResponse
    {
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare("CALL sp_dessaldar_buscar_documento(?)");
        $stmt->execute([$id_documento]);

        // primer resultset: datos del documento
        $documento = $stmt->fetch(\PDO::FETCH_ASSOC);

        // segundo resultset: movimientos contables aplicados
        $stmt->nextRowset();
        $movimientos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$documento) {
            return response()->json([
                'code' => 404,
                'msg' => 'No se encontró el documento o no está activo.'
            ]);
        }

        return response()->json([
            'code' => 200,
            'msg' => 'OK',
            'documento' => $documento,
            'movimientos' => $movimientos
        ]);
    }


    public function contabilidad_facturas_dessaldar_guardar(Request $request): JsonResponse
    {
        $id_ingreso = $request->input('id_ingreso');
        $documentos = $request->input('documentos', []);
        $auth = json_decode($request->auth);

        if (!$id_ingreso || !is_array($documentos) || count($documentos) === 0) {
            return response()->json([
                'code' => 400,
                'msg' => 'Faltan datos para dessaldar los documentos'
            ]);
        }

        $errores = [];
        $resultados = [];

        foreach ($documentos as $id_documento) {
            try {
                $resultado = DB::select('CALL sp_desaldar_documento_ingreso(?, ?, ?)', [
                    $id_ingreso,
                    $id_documento,
                    $auth->id
                ]);
                $resultados[] = [
                    'id_documento' => $id_documento,
                    'nuevo_saldo' => $resultado[0]->nuevo_saldo ?? null,
                    'status' => 'success'
                ];
            } catch (\Exception $e) {
                $errores[] = [
                    'id_documento' => $id_documento,
                    'msg' => $e->getMessage(),
                    'status' => 'error'
                ];
            }
        }

        if (count($errores) > 0) {
            return response()->json([
                'code' => 400,
                'msg' => 'Algunos documentos no se pudieron dessaldar',
                'errores' => $errores,
                'resultados' => $resultados
            ]);
        }

        return response()->json([
            'code' => 200,
            'msg' => 'Documentos dessaldados correctamente',
            'resultados' => $resultados
        ]);
    }

    public function contabilidad_facturas_dessaldar_guardar_movimientos(Request $request): JsonResponse
    {
        $idDocumento = $request->input('id_documento');
        $ingresos = $request->input('ingresos'); // array de id_movimiento_contable

        if (!$idDocumento || empty($ingresos)) {
            return response()->json([
                'code' => 400,
                'msg' => 'Parámetros incompletos.'
            ]);
        }

        try {
            DB::beginTransaction();

            foreach ($ingresos as $idIngreso) {
                // actualizar la tabla pivote desactivando la relación
                DB::table('movimiento_contable_documento')
                    ->where('id_movimiento_contable', $idIngreso)
                    ->where('id_documento', $idDocumento)
                    ->where('status', 1)
                    ->update(['status' => 0]);
            }

            DB::commit();

            return response()->json([
                'code' => 200,
                'msg' => 'Movimientos dessaldados correctamente.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json([
                'code' => 500,
                'msg' => 'Error interno al dessaldar movimientos.'
            ]);
        }
    }


    public function contabilidad_compras_saldar_data($idEntidad): JsonResponse
    {
        $egresos = DB::select('CALL sp_contabilidad_egresos_disponibles_por_entidad(?)', [$idEntidad]);
        $documentos = DB::select('CALL sp_contabilidad_documentos_compras_con_saldo_por_entidad(?)', [$idEntidad]);

        $monedas = DB::table('moneda')->get();

        return response()->json([
            'code' => 200,
            'egresos' => $egresos,
            'documentos' => $documentos,
            'monedas' => $monedas
        ]);
    }

    public function compras_aplicar_egreso(Request $request): JsonResponse
    {
        $documentos = $request->input("documentos");
        $id_egreso = $request->input("id_egreso");

        // Trae el egreso (movimiento contable)
        $egreso = DB::table('movimiento_contable')->where('id', $id_egreso)->first();
        if (!$egreso) {
            return response()->json(['code' => 404, 'msg' => 'Egreso no encontrado']);
        }

        // Traduce id_moneda a código de moneda (por compatibilidad con el Service)
        $codigoMonedaEgreso = DB::table('moneda')->where('id', $egreso->id_moneda)->value('moneda');

        // Arma el array de documentos para el service
        $documentos_formateados = [];
        foreach ($documentos as $doc) {
            $documentos_formateados[] = [
                'id_documento'         => $doc['id_documento'],
                'monto_aplicado'       => $doc['monto'],
                'moneda'               => (int)$doc['moneda'],
                'tipo_cambio_aplicado' => $doc['tipo_cambio_aplicado'] ?? $egreso->tipo_cambio,
                'parcialidad'          => 1, // puedes calcularlo si necesitas
            ];
        }

        DB::beginTransaction();
        try {
            $resultados = MovimientoContableService::aplicarADocumentos(
                $id_egreso,
                $documentos_formateados,
                $codigoMonedaEgreso,
                $egreso->tipo_cambio
            );

            $errores = array_filter($resultados, function ($res) {
                return $res['status'] === 'error';
            });

            if (count($errores) > 0) {
                DB::rollBack();
                return response()->json([
                    'code' => 400,
                    'msg' => 'Algunos documentos no pudieron ser aplicados',
                    'resultados' => $resultados
                ]);
            }

            DB::commit();
            return response()->json([
                'code' => 200,
                'msg' => 'Egreso aplicado correctamente',
                'resultados' => $resultados
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'msg' => 'Error inesperado',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function cliente_buscar(Request $request): JsonResponse
    {
        $query = trim($request->input('criterio'));

        // Busca por razón social o RFC
        $clientes = DB::table('documento_entidad')
            ->whereIn('tipo', [1,3])
            ->where(function ($q) use ($query) {
                $q->where('razon_social', 'like', "%$query%")
                    ->orWhere('rfc', 'like', "%$query%");
            })
            ->select('id', 'razon_social', 'rfc', 'telefono', 'correo')
            ->orderBy('razon_social')
            ->limit(20)
            ->get();

        return response()->json([
            'code' => 200,
            'clientes' => $clientes
        ]);
    }

    public function proveedor_buscar(Request $request): JsonResponse
    {
        $query = trim($request->input('criterio'));

        // Busca por razón social o RFC
        $proveedores = DB::table('documento_entidad')
            ->whereIn('tipo', [2,3])
            ->where(function ($q) use ($query) {
                $q->where('razon_social', 'like', "%$query%")
                    ->orWhere('rfc', 'like', "%$query%");
            })
            ->select('id', 'razon_social', 'rfc', 'telefono', 'correo')
            ->orderBy('razon_social')
            ->limit(20)
            ->get();

        return response()->json([
            'code' => 200,
            'proveedores' => $proveedores
        ]);
    }

    public function compra_gasto_data(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);
        $userId = $auth->id;

        $empresas = DB::table('empresa')
            ->where('status', 1)
            ->where('id', '!=', 0)
            ->select('id', 'empresa')
            ->get();

        $empresaIds = $empresas->pluck('id');
        $almacenesPorEmpresa = DB::table('empresa_almacen')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('usuario_empresa_almacen', function ($join) use ($userId) {
                $join->on('usuario_empresa_almacen.id_empresa_almacen', '=', 'empresa_almacen.id')
                    ->where('usuario_empresa_almacen.id_usuario', '=', $userId);
            })
            ->whereIn('empresa_almacen.id_empresa', $empresaIds)
            ->where('almacen.status', 1)
            ->where('almacen.id', '!=', 0)
            ->orderBy('almacen.almacen')
            ->select(
                'empresa_almacen.id_empresa',
                'almacen.id as id_almacen',
                'empresa_almacen.id',
                'almacen.almacen'
            )
            ->get()
            ->groupBy('id_empresa');

        foreach ($empresas as $empresa) {
            $empresa->almacenes = $almacenesPorEmpresa[$empresa->id] ?? collect();
        }


        $metodos = DB::table('metodo_pago')->get();
        $monedas = DB::table('moneda')->get();
        $periodos = DB::table('documento_periodo')->where('status', 1)->get();
        $usos_venta = DB::table('documento_uso_cfdi')->get();
        $regimenes = DB::table('cat_regimen')->get();

        return response()->json([
            'code' => 200,
            'metodos' => $metodos,
            'monedas' => $monedas,
            'periodos' => $periodos,
            'empresas' => $empresas,
            'usos_venta' => $usos_venta,
            'regimenes' => $regimenes
        ]);
    }

    public function compras_gasto_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        // VALIDACIONES
        if (empty($data->proveedor) || empty($data->proveedor->id)) {
            return response()->json([
                'code' => 400,
                'message' => "Selecciona un proveedor válido."
            ]);
        }
        if (empty($data->almacen)) {
            return response()->json([
                'code' => 400,
                'message' => "Selecciona un almacén válido."
            ]);
        }
        if (empty($data->periodo)) {
            return response()->json([
                'code' => 400,
                'message' => "Selecciona un periodo de pago válido."
            ]);
        }
        if (empty($data->moneda)) {
            return response()->json([
                'code' => 400,
                'message' => "Selecciona una moneda válida."
            ]);
        }
        if (empty($data->tipo_cambio)) {
            return response()->json([
                'code' => 400,
                'message' => "Debes especificar el tipo de cambio."
            ]);
        }
        if (empty($data->productos) || count($data->productos) == 0) {
            return response()->json([
                'code' => 400,
                'message' => "Debes agregar al menos un producto."
            ]);
        }

        // Calcula el monto total de los productos
        $monto = 0;
        foreach ($data->productos as $producto) {
            $subtotal = ($producto->costo * $producto->cantidad) * (1 - ($producto->descuento ?? 0) / 100);
            $monto += $subtotal;
        }

        // Inserta el documento (tipo 12 = Gasto)
        $documento_id = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => $data->almacen,
            'id_tipo'       => 12, // Gasto
            'id_periodo'    => $data->periodo,
            'id_usuario'    => $auth->id,
            'id_moneda'     => $data->moneda,
            'tipo_cambio'   => $data->tipo_cambio,
            'observacion'   => trim($data->comentarios ?? ''),
            'id_entidad'    => $data->proveedor->id,
            'total'         => $monto,
            'saldo'         => $monto,
            'status'        => 1
        ]);

        // Inserta productos en tabla movimiento
        foreach ($data->productos as $producto) {
            DB::table('movimiento')->insert([
                'id_documento' => $documento_id,
                'id_modelo'    => $producto->id, // O usa el campo correcto según tu modelo
                'cantidad'     => $producto->cantidad,
                'precio'       => $producto->costo,
                'descuento'    => $producto->descuento ?? 0,
                'comentario'   => $producto->comentario ?? '',
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Gasto creado correctamente.',
            'id_documento' => $documento_id
        ]);
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function contabilidad_estado_ingreso_reporte(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        $rfc = !empty($data->entidad->select) ? $data->entidad->select : null;
        $fecha_inicio = !empty($data->fecha_inicio) ? $data->fecha_inicio : null;
        $fecha_fin = !empty($data->fecha_final) ? $data->fecha_final : null;

        $result = DB::select('CALL sp_estado_cuenta_ingresos(?, ?, ?)', [
            $rfc,
            $fecha_inicio,
            $fecha_fin
        ]);

        if (empty($result)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontraron documentos con la información proporcionada."
            ]);
        }

        // ------------------- GENERA EL EXCEL --------------------
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ESTADO DE CUENTA INGRESOS');

        // Cabecera
        $headers = ['FOLIO', 'MONTO', 'MONEDA', 'T.C', 'FORMA PAGO', 'FECHA', 'FACTURAS PAGADAS'];
        $this->setExcelHeader($headers, $sheet);

        // Datos
        $row = 2;
        foreach ($result as $ingreso) {
            $sheet->setCellValue('A' . $row, $ingreso->folio);
            $sheet->setCellValue('B' . $row, $ingreso->monto);
            $sheet->setCellValue('C' . $row, $ingreso->moneda);
            $sheet->setCellValue('D' . $row, $ingreso->tc);
            $sheet->setCellValue('E' . $row, $ingreso->forma_pago);
            $sheet->setCellValue('F' . $row, $ingreso->fecha);
            $sheet->setCellValue('G' . $row, $ingreso->facturas_pagadas);
            $row++;
        }

        // Guarda el archivo temporal y lo devuelve como base64
        $filename = 'estado_cuenta_ingresos_' . date('Ymd_His') . '.xlsx';
        $file_path = sys_get_temp_dir() . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($file_path);

        $excel_base64 = base64_encode(file_get_contents($file_path));
        unlink($file_path);

        return response()->json([
            'code' => 200,
            'excel' => $excel_base64,
            'ingresos' => $result
        ]);
    }

    /* Facturas > Flujo */

    /**
     * @param array $headers
     * @param Worksheet $sheet
     * @return void
     */
    public function setExcelHeader(array $headers, Worksheet $sheet): void
    {
        foreach ($headers as $k => $header) {
            $col = chr(65 + $k);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getStyle($col . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function contabilidad_estado_factura_reporte(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        $rfc = !empty($data->entidad->select) ? $data->entidad->select : null;
        $tipo = ($data->entidad->tipo == "Clientes") ? 1 : 2; // 1=clientes, 2=proveedores
        $fecha_inicio = !empty($data->fecha_inicial) ? $data->fecha_inicial : null;
        $fecha_fin = !empty($data->fecha_final) ? $data->fecha_final : null;

        $result = DB::select('CALL sp_estado_cuenta_facturas(?, ?, ?, ?)', [
            $rfc,
            $tipo,
            $fecha_inicio,
            $fecha_fin
        ]);

        if (empty($result)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontraron documentos con los criterios proporcionados."
            ]);
        }

        // ------------------- GENERA EL EXCEL --------------------
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ESTADO DE CUENTA FACTURAS');

        // Cabecera
        $headers = ['SERIE', 'FOLIO', 'FECHA', 'UUID', 'MONEDA', 'T.C', 'TOTAL', 'SALDO', 'RAZÓN SOCIAL', 'RFC', 'PAGOS'];
        $this->setExcelHeader($headers, $sheet);

        // Datos
        $row = 2;
        foreach ($result as $factura) {
            $sheet->setCellValue('A' . $row, $factura->factura_serie);
            $sheet->setCellValue('B' . $row, $factura->factura_folio);
            $sheet->setCellValue('C' . $row, $factura->fecha);
            $sheet->setCellValue('D' . $row, $factura->uuid);
            $sheet->setCellValue('E' . $row, $factura->moneda);
            $sheet->setCellValue('F' . $row, $factura->tc);
            $sheet->setCellValue('G' . $row, $factura->total);
            $sheet->setCellValue('H' . $row, $factura->saldo);
            $sheet->setCellValue('I' . $row, $factura->nombre);
            $sheet->setCellValue('J' . $row, $factura->rfc);
            $sheet->setCellValue('K' . $row, $factura->pagos);
            $row++;
        }

        // Guarda el archivo temporal y lo devuelve como base64
        $filename = 'estado_cuenta_facturas_' . date('Ymd_His') . '.xlsx';
        $file_path = sys_get_temp_dir() . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($file_path);

        $excel_base64 = base64_encode(file_get_contents($file_path));
        unlink($file_path);

        return response()->json([
            'code' => 200,
            'excel' => $excel_base64,
            'facturas' => $result
        ]);
    }

    public function contabilidad_ingreso_generar_data(): JsonResponse
    {
        // Catálogo de afectaciones (ingreso, egreso, nota de crédito, etc.)
        $afectaciones = DB::table('cat_tipo_afectacion')
            ->get();

        // Entidades: clientes y proveedores
        $entidades = DB::table('documento_entidad')
            ->whereIn('tipo', [1, 2, 3]) // 1=cliente, 2=proveedor
            ->select('id', 'razon_social', 'rfc', 'tipo')
            ->orderBy('razon_social')
            ->get();

        // Formas de pago
        $formas_pago = DB::table('cat_forma_pago')
            ->orderBy('descripcion')
            ->get();

        // Divisas/monedas
        $divisas = DB::table('moneda')
            ->orderBy('moneda')
            ->get();

        // Catálogo de bancos
        $bancos = DB::table('cat_bancos')
            ->orderBy('razon_social')
            ->get();

        // Catálogo de tipos de entidad financiera
        $tipos_entidad_financiera = DB::table('cat_entidades_financieras_tipo')
            ->orderBy('tipo')
            ->get();

        // Traer todas las entidades financieras con datos relacionados
        $entidades_financieras = DB::table('cat_entidad_financiera as cef')
            ->leftJoin('cat_entidades_financieras_tipo as ceft', 'cef.id_tipo', '=', 'ceft.id')
            ->leftJoin('cat_bancos as cb', 'cef.id_banco', '=', 'cb.id')
            ->leftJoin('moneda as m', 'cef.id_moneda', '=', 'm.id')
            ->select(
                'cef.*',
                'ceft.tipo as tipo_entidad_financiera',
                'cb.razon_social as banco',
                'm.moneda as moneda'
            )
            ->get();

        return response()->json([
            'code' => 200,
            'afectaciones' => $afectaciones,
            'entidades' => $entidades,
            'formas_pago' => $formas_pago,
            'divisas' => $divisas,
            'entidades_financieras' => $entidades_financieras,
            'bancos' => $bancos,
            'tipos_entidad_financiera' => $tipos_entidad_financiera
        ]);
    }

    public function contabilidad_ingreso_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        // Validación básica
        if (
            empty($data->monto) ||
            empty($data->id_tipo_afectacion) ||
            empty($data->fecha_operacion) ||
            empty($data->id_moneda) ||
            empty($data->entidad_origen) ||
            empty($data->entidad_destino)
        ) {
            return response()->json([
                'code' => 400,
                'message' => "Faltan datos requeridos para registrar el ingreso."
            ]);
        }

        // Deducción de origen_tipo y destino_tipo según id_tipo_afectacion
        switch ($data->id_tipo_afectacion) {
            case 1: // Ingreso
                $origen_tipo = 1;   // Entidad financiera
                $destino_tipo = 2;  // Cliente/proveedor
                break;
            case 2: // Egreso
                $origen_tipo = 2;   // Cliente/proveedor
                $destino_tipo = 1;  // Entidad financiera
                break;
            default:
                $origen_tipo = 1;
                $destino_tipo = 2;
        }

        $id_movimiento = DB::table('movimiento_contable')->insertGetId([
            'folio' => $data->folio ?? null,
            'id_tipo_afectacion' => $data->id_tipo_afectacion,
            'fecha_operacion' => $data->fecha_operacion,
            'fecha_afectacion' => $data->fecha_afectacion ?? null,
            'id_moneda' => $data->id_moneda,
            'tipo_cambio' => $data->tipo_cambio ?? 1,
            'monto' => $data->monto,
            'origen_tipo' => $origen_tipo,
            'entidad_origen' => $data->entidad_origen,
            'nombre_entidad_origen' => $data->nombre_entidad_origen ?? '',
            'destino_tipo' => $destino_tipo,
            'entidad_destino' => $data->entidad_destino,
            'nombre_entidad_destino' => $data->nombre_entidad_destino ?? '',
            'id_forma_pago' => $data->id_forma_pago ?? null,
            'referencia_pago' => $data->referencia_pago ?? null,
            'descripcion_pago' => $data->descripcion_pago ?? null,
            'comentarios' => $data->comentarios ?? null,
            'creado_por' => $auth->id ?? null,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Flujo registrado correctamente.',
            'id_movimiento' => $id_movimiento
        ]);
    }

    public function contabilidad_ingreso_editar_cliente(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));

        // 1. Buscar el movimiento por folio
        $movimiento = DB::table('movimiento_contable')->where('folio', $data->movimiento)->first();

        if (!$movimiento) {
            return response()->json([
                "message" => "No se encontró el movimiento con el folio proporcionado."
            ], 404);
        }

        // 2. Buscar el id del cliente/proveedor por RFC
        $entidad = DB::table('documento_entidad')
            ->where('rfc', $data->cliente->rfc)
            ->first();

        if (!$entidad) {
            return response()->json([
                "message" => "No se encontró la entidad con el RFC proporcionado."
            ], 404);
        }

        // 3. Actualizar según el tipo (origen/destino tipo 1)
        $update_data = [];
        if ($movimiento->origen_tipo == 1) {
            $update_data['entidad_origen'] = $entidad->id;
            $update_data['nombre_entidad_origen'] = $entidad->razon_social;
        }
        if ($movimiento->destino_tipo == 1) {
            $update_data['entidad_destino'] = $entidad->id;
            $update_data['nombre_entidad_destino'] = $entidad->razon_social;
        }

        if (empty($update_data)) {
            return response()->json([
                "code" => 400,
                "message" => "El movimiento no tiene ni origen ni destino de tipo cliente/proveedor (tipo 1)."
            ]);
        }

        DB::table('movimiento_contable')->where('folio', $data->movimiento)->update($update_data);

        return response()->json([
            "code" => 200,
            "message" => "Ingreso/Egreso editado correctamente."
        ]);
    }

    public function contabilidad_ingreso_eliminar_data(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        $movimientos = DB::table('movimiento_contable')
            ->join('moneda', 'movimiento_contable.id_moneda', '=', 'moneda.id')
            ->select('movimiento_contable.*', 'moneda.moneda')
            ->where('id_tipo_afectacion', $data->id_tipo_afectacion)
            ->where(function ($query) use ($data) {
                $query->where('entidad_origen', $data->id_entidad)
                    ->orWhere('entidad_destino', $data->id_entidad);
            })
            ->get();


        return response()->json([
            'code' => 200,
            'movimientos' => $movimientos,
        ]);

    }

    public function contabilidad_ingreso_eliminar_eliminar($id): JsonResponse
    {
        DB::beginTransaction();
        try {
            // 1. Marcar el movimiento contable como cancelado
            DB::table('movimiento_contable')
                ->where('id', $id)
                ->update(['status' => 0]);

            // 2. Obtener documentos relacionados al movimiento
            $relaciones = DB::table('movimiento_contable_documento')
                ->where('id_movimiento_contable', $id)
                ->where('status', 1) // solo los activos
                ->get();

            foreach ($relaciones as $rel) {
                // 3. Marcar relación como cancelada
                DB::table('movimiento_contable_documento')
                    ->where('id', $rel->id)
                    ->update(['status' => 0]);

                // 4. Regresar el saldo del documento
                DB::table('documento')
                    ->where('id', $rel->id_documento)
                    ->increment('saldo', $rel->monto_aplicado);
            }

            DB::commit();
            return response()->json([
                'code' => 200,
                'message' => 'Movimiento cancelado correctamente y documentos revertidos'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'message' => 'Error al cancelar el movimiento: ' . $e->getMessage()
            ]);
        }
    }

    public function contabilidad_ingreso_historial_data(): JsonResponse
    {
        // Traer todas las entidades financieras con información relacionada
        $entidades_financieras = DB::table('cat_entidad_financiera as cef')
            ->leftJoin('cat_entidades_financieras_tipo as ceft', 'cef.id_tipo', '=', 'ceft.id')
            ->leftJoin('cat_bancos as cb', 'cef.id_banco', '=', 'cb.id')
            ->leftJoin('moneda as m', 'cef.id_moneda', '=', 'm.id')
            ->select(
                'cef.*',
                'ceft.tipo as tipo_entidad_financiera',
                'cb.razon_social as banco',
                'm.moneda as moneda'
            )
            ->get();

        // Catálogo de tipos de afectación
        $tipos_afectacion = DB::table('cat_tipo_afectacion')
            ->get();

        return response()->json([
            'code' => 200,
            'entidades_financieras' => $entidades_financieras,
            'tipos_afectacion' => $tipos_afectacion
        ]);
    }

    public function contabilidad_historial_filtrado(Request $request): JsonResponse
    {
        // decodificar el form_data
        $data = json_decode($request->input('data'), true);

        $folio = isset($data['folio']) && trim($data['folio']) !== '' ? $data['folio'] : null;
        $fecha_inicio = isset($data['fecha_inicio']) && trim($data['fecha_inicio']) !== '' ? $data['fecha_inicio'] : null;
        $fecha_final = isset($data['fecha_final']) && trim($data['fecha_final']) !== '' ? $data['fecha_final'] : null;
        if ($fecha_final) {
            $fecha_final .= ' 23:59:59';
        }

        $cuenta = isset($data['cuenta']) && trim($data['cuenta']) !== '' ? $data['cuenta'] : null;
        $tipo_afectacion = isset($data['tipo_afectacion']) && trim($data['tipo_afectacion']) !== '' ? $data['tipo_afectacion'] : null;

        try {
            $result = DB::select("CALL sp_contabilidad_historial_movimientos(?, ?, ?, ?, ?)", [
                $cuenta,
                $tipo_afectacion,
                $fecha_inicio,
                $fecha_final,
                $folio
            ]);

            // decodificar el json de documentos_aplicados para cada movimiento
            $movimientos = collect($result)->map(function($item) {
                $item->documentos_aplicados = $item->documentos_aplicados
                    ? collect(json_decode($item->documentos_aplicados))
                        ->filter(function($doc) {
                            return $doc !== null;
                        })
                        ->values()
                        ->toArray()
                    : [];
                return $item;
            });

            return response()->json([
                'code' => 200,
                'movimientos' => $movimientos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    public function contabilidad_globalizar_globalizar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $uuid = $data->uuid;
        $documentos = $data->documentos;

        $afectados = [];
        $no_afectados = [];

        foreach ($documentos as $id) {
            $doc = DB::table('documento')->where('id', $id)->first();
            if ($doc) {
                $updated = DB::table('documento')->where('id', $id)->update(['uuid' => $uuid]);
                if ($updated) {
                    $afectados[] = $id;
                } else {
                    $no_afectados[] = $id;
                }
            } else {
                $no_afectados[] = $id;
            }
        }

        return response()->json([
            'code' => 200,
            'documentos_afectados' => $afectados,
            'documentos_no_afectados' => $no_afectados
        ]);
    }

    public function contabilidad_globalizar_desglobalizar(Request $request): JsonResponse
    {
        $uuid = $request->input('uuid');
        $docs = DB::table('documento')->where('uuid', $uuid)->pluck('id');

        $afectados = [];
        $no_afectados = [];

        foreach ($docs as $id) {
            $updated = DB::table('documento')->where('id', $id)->update(['uuid' => null]);
            if ($updated) {
                $afectados[] = $id;
            } else {
                $no_afectados[] = $id;
            }
        }

        return response()->json([
            'code' => 200,
            'documentos_afectados' => $afectados,
            'documentos_no_afectados' => $no_afectados
        ]);
    }

    public function contabilidad_tesoreria_data(): JsonResponse
    {
        $monedas = DB::table('moneda')->get();

        return response()->json([
            'code' => 200,
            'monedas' => $monedas,
        ]);
    }

    public function contabilidad_tesoreria_buscar_banco(Request $request): JsonResponse
    {
        try {
            $search = trim($request->input('banco', ''));

            if (strlen($search) < 2) {
                return response()->json([
                    'code' => 200,
                    'data' => [],
                    'message' => 'Proporcione al menos 2 caracteres para buscar.'
                ]);
            }

            $bancos = DB::table('cat_bancos')
                ->select('id', 'razon_social as nombre', 'rfc', 'codigo_sat', 'valor')
                ->where('razon_social', 'like', "%$search%")
                ->orderBy('razon_social')
                ->get();

            return response()->json([
                'code' => 200,
                'bancos' => $bancos,
                'message' => ''
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'data' => [],
                'message' => 'Error al buscar bancos: ' . $e->getMessage()
            ]);
        }
    }

    public function contabilidad_tesoreria_cuenta_crear(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            // Validación básica
            if (!$data || !$data['nombre'] || !$data['id_banco'] || !$data['id_moneda']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'Faltan datos obligatorios',
                ]);
            }

            $insert = [
                'nombre' => $data['nombre'],
                'id_tipo' => 1, // Cuenta bancaria
                'id_banco' => $data['id_banco'],
                'id_moneda' => $data['id_moneda'],
                'no_cuenta' => $data['no_cuenta'] ?? null,
                'sucursal' => $data['sucursal'] ?? null,
                'convenio' => $data['convenio'] ?? null,
                'clabe' => $data['clabe'] ?? null,
                'swift' => $data['swift'] ?? null,
                'comentarios' => $data['comentarios'] ?? null,
                // 'plazo'     => null, // <-- No incluirlo
            ];

            $id = DB::table('cat_entidad_financiera')->insertGetId($insert);

            return response()->json([
                'code' => 200,
                'message' => 'Cuenta bancaria creada correctamente',
                'id' => $id
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al crear cuenta bancaria: ' . $e->getMessage()
            ]);
        }
    }

    public function contabilidad_tesoreria_cuenta_editar(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['id']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'ID de la cuenta es requerido',
                ]);
            }

            $update = [
                'nombre' => $data['nombre'],
                'id_banco' => $data['id_banco'],
                'id_moneda' => $data['id_moneda'],
                'no_cuenta' => $data['no_cuenta'] ?? null,
                'sucursal' => $data['sucursal'] ?? null,
                'convenio' => $data['convenio'] ?? null,
                'clabe' => $data['clabe'] ?? null,
                'swift' => $data['swift'] ?? null,
                'comentarios' => $data['comentarios'] ?? null,
                // 'plazo'     => null, // <-- No incluirlo
            ];

            DB::table('cat_entidad_financiera')
                ->where('id', $data['id'])
                ->update($update);

            return response()->json([
                'code' => 200,
                'message' => 'Cuenta bancaria actualizada correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al editar cuenta bancaria: ' . $e->getMessage()
            ]);
        }
    }

    // Listar cajas chicas

    public function contabilidad_tesoreria_cuentas_bancarias(): JsonResponse
    {
        try {
            $cuentas = DB::table('cat_entidad_financiera as ef')
                ->join('cat_bancos as b', 'ef.id_banco', '=', 'b.id')
                ->join('moneda as m', 'ef.id_moneda', '=', 'm.id')
                ->select(
                    'ef.id',
                    'ef.nombre',
                    'b.razon_social as banco',
                    'b.id as id_banco',
                    'ef.no_cuenta as numero',
                    'ef.clabe',
                    'ef.sucursal',
                    'ef.convenio',
                    'ef.swift',
                    'ef.comentarios',
                    'm.moneda',
                    'm.id as id_moneda'
                )
                ->where('ef.id_tipo', 1) // Solo cuentas bancarias
                ->orderBy('ef.nombre')
                ->get();

            return response()->json([
                'code' => 200,
                'data' => $cuentas
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al listar cuentas bancarias: ' . $e->getMessage()
            ]);
        }
    }

// Crear

    public function contabilidad_tesoreria_cuenta_eliminar($id): JsonResponse
    {
        try {
            DB::table('cat_entidad_financiera')->where('id', $id)->delete();

            return response()->json([
                'code' => 200,
                'message' => 'Cuenta bancaria eliminada correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al eliminar cuenta bancaria: ' . $e->getMessage()
            ]);
        }
    }

// Editar

    public function contabilidad_tesoreria_cajas_chicas(): JsonResponse
    {
        try {
            $cajas = DB::table('cat_entidad_financiera as ef')
                ->join('moneda as m', 'ef.id_moneda', '=', 'm.id')
                ->select(
                    'ef.id',
                    'ef.nombre',
                    'm.moneda',
                    'm.id as id_moneda',
                    'ef.comentarios'
                )
                ->where('ef.id_tipo', 2) // 2 = Caja chica
                ->orderBy('ef.nombre')
                ->get();

            return response()->json([
                'code' => 200,
                'data' => $cajas
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al listar cajas chicas: ' . $e->getMessage()
            ]);
        }
    }

// Eliminar

    public function contabilidad_tesoreria_caja_chica_crear(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['nombre'] || !$data['id_moneda']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'Faltan datos obligatorios',
                ]);
            }

            $id = DB::table('cat_entidad_financiera')->insertGetId([
                'nombre' => $data['nombre'],
                'id_tipo' => 2, // Caja chica
                'id_banco' => null,
                'id_moneda' => $data['id_moneda'],
                'comentarios' => $data['comentarios'] ?? null,
            ]);

            return response()->json([
                'code' => 200,
                'message' => 'Caja chica creada correctamente',
                'id' => $id
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al crear caja chica: ' . $e->getMessage()
            ]);
        }
    }

    // Listar acreedores

    public function contabilidad_tesoreria_caja_chica_editar(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['id']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'ID de la caja chica es requerido',
                ]);
            }

            DB::table('cat_entidad_financiera')
                ->where('id', $data['id'])
                ->update([
                    'nombre' => $data['nombre'],
                    'id_moneda' => $data['id_moneda'],
                    'comentarios' => $data['comentarios'] ?? null,
                ]);

            return response()->json([
                'code' => 200,
                'message' => 'Caja chica actualizada correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al editar caja chica: ' . $e->getMessage()
            ]);
        }
    }

// Crear

    public function contabilidad_tesoreria_caja_chica_eliminar($id): JsonResponse
    {
        try {
            DB::table('cat_entidad_financiera')->where('id', $id)->delete();

            return response()->json([
                'code' => 200,
                'message' => 'Caja chica eliminada correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al eliminar caja chica: ' . $e->getMessage()
            ]);
        }
    }

// Editar

    public function contabilidad_tesoreria_acreedores(): JsonResponse
    {
        try {
            $acreedores = DB::table('cat_entidad_financiera as ef')
                ->leftJoin('cat_bancos as b', 'ef.id_banco', '=', 'b.id')
                ->join('moneda as m', 'ef.id_moneda', '=', 'm.id')
                ->select(
                    'ef.id',
                    'ef.nombre',
                    'b.razon_social as banco',
                    'b.id as id_banco',
                    'm.moneda',
                    'm.id as id_moneda',
                    'ef.plazo',
                    'ef.comentarios'
                )
                ->where('ef.id_tipo', 3) // 3 = Acreedor
                ->orderBy('ef.nombre')
                ->get();

            return response()->json([
                'code' => 200,
                'data' => $acreedores
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al listar acreedores: ' . $e->getMessage()
            ]);
        }
    }

// Eliminar

    public function contabilidad_tesoreria_acreedor_crear(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['nombre'] || !$data['id_moneda'] || !$data['plazo'] || !$data['id_banco']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'Faltan datos obligatorios',
                ]);
            }

            $id = DB::table('cat_entidad_financiera')->insertGetId([
                'nombre' => $data['nombre'],
                'id_tipo' => 3, // Acreedor
                'id_banco' => $data['id_banco'],
                'id_moneda' => $data['id_moneda'],
                'plazo' => $data['plazo'],
                'comentarios' => $data['comentarios'] ?? null,
            ]);

            return response()->json([
                'code' => 200,
                'message' => 'Acreedor creado correctamente',
                'id' => $id
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al crear acreedor: ' . $e->getMessage()
            ]);
        }
    }

    // Listar deudores

    public function contabilidad_tesoreria_acreedor_editar(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['id']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'ID del acreedor es requerido',
                ]);
            }

            DB::table('cat_entidad_financiera')
                ->where('id', $data['id'])
                ->update([
                    'nombre' => $data['nombre'],
                    'id_banco' => $data['id_banco'],
                    'id_moneda' => $data['id_moneda'],
                    'plazo' => $data['plazo'],
                    'comentarios' => $data['comentarios'] ?? null,
                ]);

            return response()->json([
                'code' => 200,
                'message' => 'Acreedor actualizado correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al editar acreedor: ' . $e->getMessage()
            ]);
        }
    }

// Crear

    public function contabilidad_tesoreria_acreedor_eliminar($id): JsonResponse
    {
        try {
            DB::table('cat_entidad_financiera')->where('id', $id)->delete();

            return response()->json([
                'code' => 200,
                'message' => 'Acreedor eliminado correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al eliminar acreedor: ' . $e->getMessage()
            ]);
        }
    }

// Editar

    public function contabilidad_tesoreria_deudores(): JsonResponse
    {
        try {
            $deudores = DB::table('cat_entidad_financiera as ef')
                ->leftJoin('cat_bancos as b', 'ef.id_banco', '=', 'b.id')
                ->join('moneda as m', 'ef.id_moneda', '=', 'm.id')
                ->select(
                    'ef.id',
                    'ef.nombre',
                    'b.razon_social as banco',
                    'b.id as id_banco',
                    'm.moneda',
                    'm.id as id_moneda',
                    'ef.comentarios'
                )
                ->where('ef.id_tipo', 4) // 4 = Deudor
                ->orderBy('ef.nombre')
                ->get();

            return response()->json([
                'code' => 200,
                'data' => $deudores
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al listar deudores: ' . $e->getMessage()
            ]);
        }
    }

// Eliminar

    public function contabilidad_tesoreria_deudor_crear(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['nombre'] || !$data['id_moneda'] || !$data['id_banco']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'Faltan datos obligatorios',
                ]);
            }

            $id = DB::table('cat_entidad_financiera')->insertGetId([
                'nombre' => $data['nombre'],
                'id_tipo' => 4, // Deudor
                'id_banco' => $data['id_banco'],
                'id_moneda' => $data['id_moneda'],
                'comentarios' => $data['comentarios'] ?? null,
            ]);

            return response()->json([
                'code' => 200,
                'message' => 'Deudor creado correctamente',
                'id' => $id
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al crear deudor: ' . $e->getMessage()
            ]);
        }
    }

    // Listar bancos

    public function contabilidad_tesoreria_deudor_editar(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['id']) {
                return response()->json([
                    'code' => 400,
                    'message' => 'ID del deudor es requerido',
                ]);
            }

            DB::table('cat_entidad_financiera')
                ->where('id', $data['id'])
                ->update([
                    'nombre' => $data['nombre'],
                    'id_banco' => $data['id_banco'],
                    'id_moneda' => $data['id_moneda'],
                    'comentarios' => $data['comentarios'] ?? null,
                ]);

            return response()->json([
                'code' => 200,
                'message' => 'Deudor actualizado correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al editar deudor: ' . $e->getMessage()
            ]);
        }
    }

// Crear

    public function contabilidad_tesoreria_deudor_eliminar($id): JsonResponse
    {
        try {
            DB::table('cat_entidad_financiera')->where('id', $id)->delete();

            return response()->json([
                'code' => 200,
                'message' => 'Deudor eliminado correctamente'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => 'Error al eliminar deudor: ' . $e->getMessage()
            ]);
        }
    }

// Editar

    public function contabilidad_tesoreria_bancos(): JsonResponse
    {
        try {
            $bancos = DB::table('cat_bancos')->select('id', 'valor', 'razon_social', 'rfc', 'codigo_sat')->orderBy('razon_social')->get();
            return response()->json(['code' => 200, 'data' => $bancos]);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'message' => 'Error al listar bancos: ' . $e->getMessage()]);
        }
    }

// Eliminar

    public function contabilidad_tesoreria_banco_crear(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['valor'] || !$data['razon_social']) {
                return response()->json(['code' => 400, 'message' => 'Faltan datos obligatorios']);
            }

            $id = DB::table('cat_bancos')->insertGetId([
                'valor' => $data['valor'],
                'razon_social' => $data['razon_social'],
                'rfc' => $data['rfc'] ?? null,
                'codigo_sat' => $data['codigo_sat'] ?? null,
            ]);
            return response()->json(['code' => 200, 'message' => 'Banco creado correctamente', 'id' => $id]);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'message' => 'Error al crear banco: ' . $e->getMessage()]);
        }
    }

    public function contabilidad_tesoreria_banco_editar(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->input('data'), true);

            if (!$data || !$data['id']) {
                return response()->json(['code' => 400, 'message' => 'ID del banco es requerido']);
            }

            DB::table('cat_bancos')
                ->where('id', $data['id'])
                ->update([
                    'valor' => $data['valor'],
                    'razon_social' => $data['razon_social'],
                    'rfc' => $data['rfc'] ?? null,
                    'codigo_sat' => $data['codigo_sat'] ?? null,
                ]);

            return response()->json(['code' => 200, 'message' => 'Banco actualizado correctamente']);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'message' => 'Error al editar banco: ' . $e->getMessage()]);
        }
    }

    public function contabilidad_tesoreria_banco_eliminar($id): JsonResponse
    {
        try {
            DB::table('cat_bancos')->where('id', $id)->delete();

            return response()->json(['code' => 200, 'message' => 'Banco eliminado correctamente']);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'message' => 'Error al eliminar banco: ' . $e->getMessage()]);
        }
    }
}
