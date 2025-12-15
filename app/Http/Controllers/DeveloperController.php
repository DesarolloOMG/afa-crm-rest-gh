<?php

namespace App\Http\Controllers;

use App\Http\Services\DropboxService;
use App\Http\Services\InventarioService;
use App\Http\Services\MercadolibreService;
use App\Http\Services\VaultService;
use App\Models\Documento;
use App\Models\Enums\DocumentoTipo as EnumDocumentoTipo;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MP;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeveloperController extends Controller
{

    /**
     * @var DropboxService
     */
    private $dropbox;

    public function __construct(DropboxService $dropbox)
    {
        $this->dropbox = $dropbox;
    }

    public static function logVariableLocation(): string
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'MS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'BRE'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }

    public function test(Request $request)
    {
        set_time_limit(0);
        $stock = InventarioService::existenciaProducto(7500938008169, 2);
        dd($stock);
    }

    public static function log_meli_error(string $mensaje, string $publicacion_id)
    {
        $publicacion_id = $publicacion_id ?: 'sin_id';

        $dir = "logs/mercadolibre";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $archivo = "{$dir}/" . date("Y.m.d") . "-{$publicacion_id}.log";
        file_put_contents($archivo, date("H:i:s") . " Error: {$mensaje}" . PHP_EOL, FILE_APPEND);
    }

    public static function callMlApi($marketplaceId, $endpointTemplate, array $placeholders = [], $opt = 0)
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 1;

        $marketplace = self::getMarketplaceData($marketplaceId);
        if (!$marketplace) {
            $response->mensaje = "No se encontró información del marketplace." . self::logVariableLocation();
            return $response;
        }

        $marketplaceData = $marketplace->marketplace_data;
        $token = self::token($marketplaceData->app_id, $marketplaceData->secret);

        $endpoint = strtr($endpointTemplate, $placeholders);
        $url = config("webservice.mercadolibre_enpoint") . $endpoint;

        $options = [
            "http" => [
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($options);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return response()->json(["error" => "No se pudo obtener información. " . $url], 500);
        }

        return response()->json(json_decode($raw, true));
    }

    public static function callMpApi($marketplaceId, $endpointTemplate, array $placeholders = [], $opt = 0)
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 1;

        $marketplace = self::getMarketplaceData($marketplaceId);
        if (!$marketplace) {
            $response->mensaje = "No se encontró información del marketplace." . self::logVariableLocation();
            return $response;
        }

        $marketplaceData = $marketplace->marketplace_data;
        $token = self::token($marketplaceData->app_id, $marketplaceData->secret);

        $endpoint = strtr($endpointTemplate, $placeholders);
        $url = "https://api.mercadopago.com/" . $endpoint;

        $options = [
            "http" => [
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($options);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return response()->json(["error" => "No se pudo obtener información. " . $url], 500);
        }

        return response()->json(json_decode($raw, true));
    }

    public static function getMarketplaceData($marketplace_id): stdClass
    {
        $response = new stdClass();
        $response->error = 0;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_id)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }
        $response->marketplace_data = $marketplace_data;

        return $response;
    }

    public static function token($app_id, $secret_key)
    {
        $existe = DB::select("SELECT token FROM marketplace_api WHERE app_id = '" . $app_id . "' AND secret = '" . $secret_key . "' AND '" . date("Y-m-d H:i:s") . "' >= token_created_at AND '" . date("Y-m-d H:i:s") . "' <= token_expired_at AND token != 'N/A'");

        if (empty($existe)) {
            try {
                $decoded_secret_key = Crypt::decrypt($secret_key);
            } catch (DecryptException $e) {
                $decoded_secret_key = "";
            }

            $mp = new MP($app_id, $decoded_secret_key);
            $access_token = $mp->get_access_token();

            DB::table("marketplace_api")->where(["app_id" => $app_id, "secret" => $secret_key])->update([
                "token" => $access_token,
                "token_created_at" => date("Y-m-d H:i:s"),
                "token_expired_at" => date("Y-m-d H:i:s", strtotime("+6 hours"))
            ]);

            return $access_token;
        }

        return $existe[0]->token;
    }

    public function recalcularInventario()
    {
        set_time_limit(0);

        $errores = [];
        $aplicados = [];
        $log_preparacion = [];

        // --- FASE 1: PREPARACIÓN DEL TERRENO ---
        $log_preparacion[] = "Iniciando Fase 1: Preparación de existencias y costos...";
        DB::beginTransaction();
        try {
            // Obtenemos todos los documentos que se van a procesar.
            $documentos = DB::table('documento')
                ->whereIn('id_tipo', [0, 2, 3, 4, 5, 6, 11])
                ->whereIn('id_fase', [5, 6, 100, 606, 607])
                ->where('status', 1)
                ->select('id', 'id_almacen_principal_empresa')
                ->orderBy('created_at', 'asc')
                ->get();

            $idsDocumentos = $documentos->pluck('id');

            // Obtenemos una lista única de todos los productos (modelos) que serán afectados.
            $modelosAfectados = DB::table('movimiento')
                ->whereIn('id_documento', $idsDocumentos)
                ->select('id_modelo')
                ->distinct()
                ->get();

            $almacenesAfectados = $documentos->pluck('id_almacen_principal_empresa')->unique()->filter();

            // Para cada producto y almacén, nos aseguramos de que exista su registro.
            foreach ($modelosAfectados as $modelo) {
                // Asegurar registro de costo
                $costoExistente = DB::table('modelo_costo')->where('id_modelo', $modelo->id_modelo)->first();
                if (!$costoExistente) {
                    DB::table('modelo_costo')->insert([
                        'id_modelo'      => $modelo->id_modelo,
                        'costo_inicial'  => 0,
                        'costo_promedio' => 0,
                        'ultimo_costo'   => 0
                    ]);
                    $log_preparacion[] = "Creado registro de costo para modelo: {$modelo->id_modelo}";
                }

                // Asegurar registro de existencia en cada almacén
                foreach ($almacenesAfectados as $almacenId) {
                    $existenciaExistente = DB::table('modelo_existencias')
                        ->where('id_modelo', $modelo->id_modelo)
                        ->where('id_almacen', $almacenId)
                        ->first();

                    if (!$existenciaExistente) {
                        DB::table('modelo_existencias')->insert([
                            'id_modelo'      => $modelo->id_modelo,
                            'id_almacen'     => $almacenId,
                            'stock_inicial'  => 0,
                            'stock'          => 0,
                            'stock_anterior' => 0
                        ]);
                        $log_preparacion[] = "Creado registro de existencia para modelo {$modelo->id_modelo} en almacén {$almacenId}";
                    }
                }
            }

            DB::commit();
            $log_preparacion[] = "Fase 1 completada exitosamente.";

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'message' => 'Error crítico durante la fase de preparación.',
                'error_details' => $e->getMessage()
            ], 500);
        }

        // --- FASE 2: PROCESAMIENTO DE DOCUMENTOS (El código que ya teníamos) ---
        // Obtenemos los documentos de nuevo con todos sus campos necesarios.
        $documentosParaProcesar = DB::table('documento')
            ->select('id', 'id_tipo', 'autorizado', 'id_fase')
            ->whereIn('id', $idsDocumentos)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($documentosParaProcesar as $documento) {
            DB::beginTransaction();
            try {
                // Se aplica la lógica condicional original
                if ((int)$documento->id_tipo === 0) {
                    // Lógica de Recepción...
                    $movimientos = DB::table('movimiento')->where('id_documento', $documento->id)->get();
                    $todoOk = true;
                    $mensajes = ['Recepción procesada'];

                    if ($movimientos->isNotEmpty()) {
                        foreach ($movimientos as $mov) {
                            $recepcion = DB::table('documento_recepcion')->where('id_movimiento', $mov->id)->first();
                            if ($recepcion) {
                                $aplicar = InventarioService::procesarRecepcion($recepcion->id_movimiento, $recepcion->cantidad);
                                if ($aplicar->error) {
                                    $todoOk = false;
                                    $mensajes = [$aplicar->mensaje];
                                    break;
                                }
                            }
                        }
                    }

                    if ($todoOk) { DB::commit(); $aplicados[] = "Documento: {$documento->id} - " . implode(', ', $mensajes); }
                    else { DB::rollBack(); $errores[] = "Error en documento: {$documento->id} - " . implode(', ', $mensajes); }

                } else {
                    // Lógica General...
                    $aplicar = InventarioService::aplicarMovimiento($documento->id);
                    if ($aplicar->error) { DB::rollBack(); $errores[] = "Error en documento: {$documento->id} - {$aplicar->message}"; }
                    else { DB::commit(); $aplicados[] = "Documento: {$documento->id} - {$aplicar->message}"; }
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $errores[] = "Excepción en documento {$documento->id}: " . $e->getMessage();
            }
        }

        return response()->json([
            'code'        => !empty($errores) ? 207 : 200,
            'message'     => !empty($errores) ? 'Procesado con errores' : 'Proceso de recálculo definitivo completado.',
            'log_preparacion' => $log_preparacion,
            'errors'      => $errores,
            'aplicados'   => $aplicados,
            'aplicados_count' => count($aplicados),
            'errors_count' => count($errores),
        ], !empty($errores) ? 207 : 200);
    }

    public function recalcularCosto(Request $request)
    {
        set_time_limit(0);

        $sku = $request->input('sku');

        $modelo = DB::table('modelo')->where('sku', $sku)->first();

        if (!empty($modelo)) {
            $documentos = DB::select('CALL sp_historial_modelo(?)', [$modelo->id]);

            $stock_total = 0;
            $costo_promedio = 0;
            $costo_provisional = 0;

            foreach ($documentos as $venta) {
                if($venta->id_tipo == 5) {
                    continue;
                }

                if($venta->id_tipo == 3 && $costo_promedio == 0) {
                    $costo_promedio = $venta->precio * $venta->tipo_cambio;
                }

                if($venta->id_tipo == 3 && $costo_provisional == 0) {
                    $costo_provisional = $venta->precio * $venta->tipo_cambio;
                }

                if ($venta->id_tipo == 0) {
                    $tiene_compra = DB::table('documento_recepcion')
                        ->where('id_movimiento', $venta->id_movimiento)
                        ->first();

                    if (!empty($tiene_compra) && $tiene_compra->documento_erp_compra !== 'N/A') {
                        $docCompraId = (int) $tiene_compra->documento_erp_compra;

                        // Buscar la compra dentro del mismo arreglo $documentos
                        foreach ($documentos as $k => $docCompra) {
                            if ((int) $docCompra->id_documento === $docCompraId) {

                                $montoCompra = (float) $docCompra->cantidad * (float) $docCompra->precio * (float) $docCompra->tipo_cambio;

                                if ($stock_total == 0 || $costo_promedio == 0) {
                                    // si no hay stock previo, el costo promedio pasa a ser el de esta compra
                                    $costo_promedio = (float) $docCompra->precio * (float) $docCompra->tipo_cambio;
                                } else {
                                    // promedio ponderado
                                    $montoTotal = $stock_total * $costo_promedio;
                                    $costo_promedio = ($montoTotal + $montoCompra) / ($stock_total + (float) $docCompra->cantidad);
                                }
                                // === Quitar la compra del arreglo para no reprocesarla después ===
                                unset($documentos[$k]);

                                break;
                            }
                        }
                    } else {
                        $montoCompraProvisional = $venta->cantidad * $venta->precio * $venta->tipo_cambio;

                        if ($stock_total <= 0) {
                            $costo_provisional = $venta->precio * $venta->tipo_cambio;
                        } else {
                            $montoTotalProvisional = $stock_total * $costo_provisional;
                            $costo_provisional = ($montoTotalProvisional + $montoCompraProvisional) / ($stock_total + $venta->cantidad);
                        }
                    }
                }

                if($venta->sumaInventario == 1) {
                    $stock_total = $stock_total + $venta->cantidad;
                }

                if($venta->restaInventario == 1) {
                    $stock_total = $stock_total - $venta->cantidad;
                }
            }

            return response()->json([
                'code' => 200,
                'message' => "Costo calculado correctamente.",
                'producto' => $modelo->descripcion,
                "costo" => $costo_promedio,
                'costo_provisional' => $costo_provisional,
                'stock_total' => $stock_total,
            ]);
        } else {
            return response()->json([
                'code' => 500,
                'message' => "Codigo no encontrado."
            ]);
        }
    }

    public function aplicarCosto(Request $request) {
        $costo = $request->input('costo');
        $sku = $request->input('sku');
        $tipo = $request->input('tipo');
        $auth = json_decode($request->auth);

        $modelo = DB::table('modelo')->where('sku', $sku)->first();

        if (!empty($modelo)) {
            $modelo_costo = DB::table('modelo_costo')->where('id_modelo', $modelo->id)->first();
            if (empty($modelo_costo)) {
                DB::table('bitacora_recalcular_productos')->insert([
                    'id_modelo' => $modelo->id,
                    'id_usuario' => $auth->id,
                    'tipo_elegido' => $tipo,
                    'titulo' => "Recalculo de costo del sku: {$modelo->sku}",
                    'costo_anterior' => 0,
                    'costo_nuevo_calculado' => $costo,
                    'fecha' => Carbon::now()
                ]);

                DB::table('modelo_costo')->insert([
                    'id_modelo' => $modelo->id,
                    'costo_inicial' => $costo,
                    'costo_promedio' => $costo,
                    'ultimo_costo' => $costo
                ]);
            } else {
                DB::table('bitacora_recalcular_productos')->insert([
                    'id_modelo' => $modelo->id,
                    'id_usuario' => $auth->id,
                    'tipo_elegido' => $tipo,
                    'titulo' => "Recalculo de costo del sku: {$modelo->sku}",
                    'costo_anterior' => $modelo_costo->costo_promedio,
                    'costo_nuevo_calculado' => $costo,
                    'fecha' => Carbon::now()
                ]);

                DB::table('modelo_costo')->where('id_modelo', $modelo->id)->update([
                    'costo_promedio' => $costo,
                ]);
            }


            return response()->json([
                'code' => 200,
                'message' => "Costo aplicado correctamente."
            ]);
        } else {
            return response()->json([
                'code' => 500,
                'message' => "Codigo no encontrado."
            ]);
        }
    }

    /**
     * Devuelve:
     *  - inventario_original: { almacen, stock } desde sp_calcularExistenciaGeneral (solo columna stock)
     *  - inventario_nuevo: { almacen, stock_movimientos, pendientes_fase3, stock_recalculado }
     */
    public function getInventarioPorAlmacen(Request $request)
    {
        set_time_limit(0);

        $sku = trim((string) $request->input('sku', ''));
        if ($sku === '') {
            return response()->json(['code' => 422, 'message' => 'SKU requerido'], 422);
        }

        // 0) Localizar el modelo por SKU
        $modelo = DB::table('modelo')->select('id', 'descripcion')->where('sku', $sku)->first();
        if (!$modelo) {
            return response()->json(['code' => 404, 'message' => 'Código no encontrado.'], 404);
        }

        // 1) INVENTARIO ORIGINAL (del SP de existencias): usamos SOLO 'almacen' y 'stock'
        //    firma: sp_calcularExistenciaGeneral(criterio, id_almacen=0, con_existencia=0)
        $origRows = DB::select('CALL sp_calcularExistenciaGeneral(?, ?, ?)', [$sku, 0, 0]);

        // Agrupar por nombre de almacén sumando 'stock' (por si el SP devuelve varias filas del mismo almacén)
        $mapOriginal = []; // 'ALMACEN' => stock
        foreach ($origRows as $r) {
            $almacen = (string) ($r->almacen ?? 'SIN NOMBRE');
            $stock   = (float)  ($r->stock   ?? 0);
            if (!isset($mapOriginal[$almacen])) $mapOriginal[$almacen] = 0.0;
            $mapOriginal[$almacen] += $stock;
        }

        // Pasar a arreglo ordenado
        $inventarioOriginal = [];
        foreach ($mapOriginal as $almacen => $stock) {
            $inventarioOriginal[] = [
                'almacen' => $almacen,
                'stock'   => (float) $stock,
            ];
        }

        // 2) INVENTARIO NUEVO (del SP con pendientes fase 3 restados)
        //    firma: sp_inventario_modelo_por_almacen(id_modelo)
        $nuevoRows = DB::select('CALL sp_inventario_modelo_por_almacen(?)', [$modelo->id]);

        $inventarioNuevo = array_map(function ($r) {
            return [
                // Si necesitas los IDs, ya vienen en el SP: id_empresa_almacen / id_almacen
                'almacen'             => (string)($r->almacen ?? 'SIN NOMBRE'),
                'stock_movimientos'   => (float) ($r->stock_movimientos ?? 0),
                'pendientes_fase3'    => (float) ($r->pendientes_fase3    ?? 0),
                'stock_recalculado'   => (float) ($r->stock_recalculado   ?? 0),
            ];
        }, $nuevoRows);

        return response()->json([
            'code'               => 200,
            'message'            => 'OK',
            'sku'                => $sku,
            'producto'           => $modelo->descripcion,
            'inventario_original'=> $inventarioOriginal,
            'inventario_nuevo'   => $inventarioNuevo,
        ]);
    }

    /**
     * Descarga un Excel (.xlsx) con TODAS las columnas que regrese
     * el SP sp_doc_fase3_pendientes_por_modelo (solo docs con series en FASE 3).
     * Parámetro: sku (GET/POST)
     */
    public function getDocumentosPendientes(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $sku = trim((string) $request->input('sku', ''));
        if ($sku === '') {
            return response()->json(['code' => 422, 'message' => 'SKU requerido'], 422);
        }

        // SP: pendientes fase 3 con series (tu SP ya filtra esto)
        $rows = DB::select('CALL sp_doc_fase3_pendientes_por_modelo(?)', [$sku]);

        // Construimos el Excel en memoria
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pendientes Fase 3');

        // Si no hay filas, devolvemos hoja con mensaje
        if (empty($rows)) {
            $sheet->setCellValue('A1', 'Sin pendientes con series para este SKU.');
            $sheet->getStyle('A1')->getFont()->setBold(true);
            $filename = 'pendientes_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $sku) . '_' . date('Ymd_His') . '.xlsx';

            return new StreamedResponse(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $spreadsheet->getCalculationEngine()->disableCalculationCache();
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control'       => 'max-age=0, no-store, no-cache, must-revalidate',
                'Pragma'              => 'public',
            ]);
        }

        // Encabezados dinámicos
        $headers = array_keys((array) $rows[0]);

        // Escribir encabezados con estilo
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($colIndex, 1, strtoupper($h));
            $sheet->getStyleByColumnAndRow($colIndex, 1)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow($colIndex, 1)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyleByColumnAndRow($colIndex, 1)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E6F4F1');
            $colIndex++;
        }

        // Columnas que deben ir SIEMPRE como TEXTO (sin notación científica)
        $textCols = ['sku', 'series', 'serie', 'factura_folio']; // agrega/quita según tu SP

        // Escribir filas
        $rowIndex = 1;
        foreach ($rows as $r) {
            $rowIndex++;
            $colIndex = 1;

            foreach ($headers as $h) {
                $key = strtolower($h);
                $val = isset($r->$h) ? $r->$h : null;

                if (in_array($key, $textCols, true)) {
                    // Texto explícito + formato '@' para evitar notación científica
                    $sheet->setCellValueExplicitByColumnAndRow(
                        $colIndex,
                        $rowIndex,
                        (string) $val,
                        DataType::TYPE_STRING
                    );
                    $sheet->getStyleByColumnAndRow($colIndex, $rowIndex)
                        ->getNumberFormat()->setFormatCode('@');
                } else {
                    // Numérico si aplica; si no, texto
                    if (is_numeric($val) && $val !== '' && $val !== null) {
                        $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $val + 0);
                    } else {
                        $sheet->setCellValueExplicitByColumnAndRow(
                            $colIndex,
                            $rowIndex,
                            (string) $val,
                            DataType::TYPE_STRING
                        );
                    }
                }

                $colIndex++;
            }
        }

        // Auto-filtro, freeze y auto-size
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setAutoFilter("A1:{$lastCol}{$rowIndex}");
        $sheet->freezePane('A2');
        for ($c = 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        // Descargar
        $filename = 'pendientes_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $sku) . '_' . date('Ymd_His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $spreadsheet->getCalculationEngine()->disableCalculationCache();
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'max-age=0, no-store, no-cache, must-revalidate',
            'Pragma'              => 'public',
        ]);
    }


    public function aplicarPendientes(Request $request)
    {
        set_time_limit(0);

        $sku = trim((string) $request->input('sku', ''));
        if ($sku === '') {
            return response()->json(['code' => 422, 'message' => 'SKU requerido'], 422);
        }

        // Documentos fase 3 con series (pendientes)
        $rows = DB::select('CALL sp_doc_fase3_pendientes_por_modelo(?)', [$sku]);

        // Deduplicar id_documento
        $docIds = [];
        foreach ($rows as $r) {
            $docIds[(int)$r->id_documento] = true;
        }
        $docIds = array_keys($docIds);

        $resultados = [];

        foreach ($docIds as $docId) {
            $res = [
                'id_documento' => $docId,
                'aplicado'     => false,
                'omitido'      => false,
                'error'        => null,
            ];

            try {
                DB::beginTransaction();

                // Doble verificación para evitar doble aplicación en carreras
                $yaAplicado = DB::table('modelo_kardex')->where('id_documento', $docId)->exists();
                if ($yaAplicado) {
                    $res['omitido'] = true;
                    DB::commit();
                    $resultados[] = $res;
                    continue;
                }

                // Llama tu lógica de aplicación (ajusta la firma si necesitas más datos)
                $aplicar = InventarioService::aplicarMovimiento($docId);

                if ($aplicar->error) {
                    throw new \Exception($aplicar['message'] ?? 'Error al aplicar movimiento.');
                }
                $res['aplicado'] = true;
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $res['error'] = $e->getMessage();
            }

            $resultados[] = $res;
        }

        return response()->json([
            'code'        => 200,
            'message'     => 'Proceso finalizado.',
            'aplicaciones'=> $resultados,
        ]);
    }

    public function afectarInventario(Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);
        $sku = trim((string) $request->input('sku', ''));
        if ($sku === '') {
            return response()->json(['code' => 422, 'message' => 'SKU requerido'], 422);
        }

        $modelo = DB::table('modelo')->select('id')->where('sku', $sku)->first();
        if (!$modelo) {
            return response()->json(['code' => 404, 'message' => 'Modelo no encontrado'], 404);
        }

        // Recalcular para obtener stock_recalculado por EA
        $rows = DB::select('CALL sp_inventario_modelo_por_almacen(?)', [$modelo->id]);

        $cambios = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $eaId   = (int)($r->id_empresa_almacen ?? 0);
                $nuevo  = (int)($r->stock_recalculado   ?? 0);

                if ($eaId <= 0) { continue; }

                $actual = (int) DB::table('modelo_existencias')
                    ->where('id_modelo', $modelo->id)
                    ->where('id_almacen', $eaId)
                    ->value('stock');

                if ($actual !== $nuevo) {
                    DB::table('bitacora_recalcular_productos')->insert([
                        'id_modelo' => $modelo->id,
                        'id_usuario' => $auth->id,
                        'tipo_elegido' => 'Calculado',
                        'titulo' => "Recalculo de INVENTARIO del sku: {$sku} en el almacen {$eaId}",
                        'stock_anterior' => $actual,
                        'stock_nuevo_calculado' => $nuevo,
                        'fecha' => Carbon::now()
                    ]);

                    DB::table('modelo_existencias')
                        ->where('id_modelo', $modelo->id)
                        ->where('id_almacen', $eaId)
                        ->update([
                            'stock'      => $nuevo,
                            'updated_at' => Carbon::now(),
                        ]);

                    $cambios[] = [
                        'id_empresa_almacen' => $eaId,
                        'almacen'            => (string)($r->almacen ?? ''),
                        'antes'              => $actual,
                        'despues'            => $nuevo,
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'code'    => 200,
                'message' => 'Inventario actualizado.',
                'cambios' => $cambios,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'code'    => 500,
                'message' => 'Error al actualizar inventario: '.$e->getMessage(),
            ], 500);
        }
    }

    public function actualizarTokenDropbox()
    {
        set_time_limit(0);
        $dropbox = app(DropboxService::class);
        try {
            $token = $dropbox->refreshAccessToken();

            return response()->json(['code' => 200, 'token' => $token]);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'error' => $e->getMessage()]);
        }
    }

    public function getDropboxToken()
    {
        VaultService::checkDropboxToken();
        $token = config('keys.dropbox');
        return response()->json(['token' => $token]);
    }
}
