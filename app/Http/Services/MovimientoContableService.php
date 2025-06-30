<?php /** @noinspection PhpUnused */

namespace App\Http\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use stdClass;

class MovimientoContableService
{
    /**
     * Crea un movimiento contable (ingreso, egreso, traspaso, nota de crédito, etc.)
     */
    public static function crearMovimiento($data): array
    {
        DB::beginTransaction();

        try {
            // 1. Insertar cabecera del movimiento
            $movimientoId = DB::table('movimiento_contable')->insertGetId([
                'folio'                => $data['folio'] ?? null,
                'id_tipo_afectacion'   => $data['id_tipo_afectacion'],
                'fecha_operacion'      => $data['fecha_operacion'],
                'fecha_afectacion'     => $data['fecha_afectacion'] ?? null,
                'moneda'               => $data['moneda'],
                'tipo_cambio'          => $data['tipo_cambio'] ?? 1,
                'monto'                => $data['monto'],
                'origen_tipo'          => $data['origen_tipo'],
                'entidad_origen'       => $data['entidad_origen'],
                'nombre_entidad_origen'=> $data['nombre_entidad_origen'],
                'destino_tipo'         => $data['destino_tipo'],
                'entidad_destino'      => $data['entidad_destino'],
                'nombre_entidad_destino'=> $data['nombre_entidad_destino'],
                'id_forma_pago'        => $data['id_forma_pago'],
                'referencia_pago'      => $data['referencia_pago'] ?? null,
                'descripcion_pago'     => $data['descripcion_pago'] ?? null,
                'comentarios'          => $data['comentarios'] ?? null,
                'creado_por'           => $data['creado_por'] ?? null
            ]);

            // 2. Aplicar a documentos si existen
            if (isset($data['documentos']) && is_array($data['documentos'])) {
                self::aplicarADocumentos($movimientoId, $data['documentos'], $data['moneda'], $data['tipo_cambio'] ?? 1);
            }

            DB::commit();

            return [
                'success' => true,
                'movimiento_id' => $movimientoId
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Aplica un movimiento a uno o varios documentos
     * $documentos = [ [id_documento, monto_aplicado, moneda_documento, tipo_cambio_aplicado], ... ]
     */
    public static function aplicarADocumentos($idMovimiento, $documentos, $monedaMovimiento, $tipoCambioMovimiento = 1): array
    {
        $resultados = [];

        foreach ($documentos as $doc) {
            try {
                $idDocumento        = $doc['id_documento'];
                $montoAplicado      = $doc['monto_aplicado'];
                $monedaDocumento    = $doc['moneda'] ?? $monedaMovimiento;
                $tipoCambioAplicado = $doc['tipo_cambio_aplicado'] ?? $tipoCambioMovimiento;
                $parcialidad        = $doc['parcialidad'] ?? 1;

                // 1. Verifica que el documento exista
                $documento = DB::table('documento')->where('id', $idDocumento)->first();
                if (!$documento) {
                    $resultados[] = [
                        'id_documento' => $idDocumento,
                        'status' => 'error',
                        'message' => "Documento no encontrado"
                    ];
                    continue;
                }

                // 2. Verifica que el saldo sea suficiente
                // Si el monto a aplicar es mayor al saldo, marca error
                if ($documento->saldo < $montoAplicado) {
                    $resultados[] = [
                        'id_documento' => $idDocumento,
                        'status' => 'error',
                        'message' => "El monto a aplicar es mayor al saldo del documento"
                    ];
                    continue;
                }

                // 3. Inserta en movimiento_contable_documento
                DB::table('movimiento_contable_documento')->insert([
                    'id_movimiento_contable' => $idMovimiento,
                    'id_documento'           => $idDocumento,
                    'monto_aplicado'         => $montoAplicado,
                    'saldo_documento'        => null, // Se puede actualizar después
                    'moneda'                 => $monedaDocumento,
                    'tipo_cambio_aplicado'   => $tipoCambioAplicado,
                    'parcialidad'            => $parcialidad
                ]);

                // 4. Actualiza saldo del documento (conversión de moneda si aplica)
                if ($monedaMovimiento != $monedaDocumento) {
                    $montoAplicadoEnDoc = ($monedaMovimiento == 'USD' && $monedaDocumento == 'MXN')
                        ? $montoAplicado * $tipoCambioAplicado
                        : $montoAplicado / $tipoCambioAplicado;
                } else {
                    $montoAplicadoEnDoc = $montoAplicado;
                }

                $nuevoSaldo = max(0, $documento->saldo - $montoAplicadoEnDoc);

                DB::table('documento')->where('id', $idDocumento)->update([
                    'saldo' => $nuevoSaldo,
                    'pagado' => ($nuevoSaldo == 0) ? 1 : 0
                ]);

                $resultados[] = [
                    'id_documento' => $idDocumento,
                    'status' => 'success',
                    'message' => "Aplicado correctamente",
                    'nuevo_saldo' => $nuevoSaldo
                ];

            } catch (Exception $e) {
                $resultados[] = [
                    'id_documento' => $doc['id_documento'] ?? null,
                    'status' => 'error',
                    'message' => "Excepción: " . $e->getMessage()
                ];
            }
        }

        return $resultados;
    }

    /**
     * Refactura un documento en el CRM creando una nota de crédito, reasignando el ingreso y dejando todo documentado.
     *
     * @param int $documento       ID del documento original a refacturar
     * @param int $usuario         ID del usuario que ejecuta el proceso
     * @param string $observacion  Observaciones adicionales (opcional)
     * @return stdClass            Objeto con información del proceso y los nuevos IDs
     */
    public static function refacturar(int $documento, int $usuario, string $observacion = ''): stdClass
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 1; // Por defecto asumimos error hasta completar todo el flujo

        // 1. Verifica que el documento original exista y no esté cancelado
        $doc_original = DB::table('documento')->where('id', $documento)->where('status', 1)->first();
        if (!$doc_original) {
            $response->mensaje = "Documento no encontrado o cancelado.";
            return $response;
        }

        // 2. Verifica que el documento NO haya sido refacturado antes
        if ($doc_original->refacturado) {
            $response->mensaje = "Este documento ya fue refacturado anteriormente.";
            return $response;
        }

        // 3. Trae los productos originales asociados al documento
        $productos = DB::table('movimiento')->where('id_documento', $documento)->get();
        if ($productos->isEmpty()) {
            $response->mensaje = "El documento no tiene productos asociados.";
            return $response;
        }

        // 4. Trae la información del cliente o entidad vinculada
        $entidad = DB::table('documento_entidad')->where('id', $doc_original->id_entidad)->first();
        if (!$entidad) {
            $response->mensaje = "No se encontró el cliente asociado al documento.";
            return $response;
        }

        // 5. Trae el ingreso/movimiento contable relacionado (debe estar activo)
        $relacion_ingreso = DB::table('movimiento_contable_documento')
            ->where('id_documento', $documento)
            ->where('status', 1)
            ->first();
        if (!$relacion_ingreso) {
            $response->mensaje = "No se encontró ingreso activo relacionado al documento.";
            return $response;
        }

        // 6. Crea el documento de Nota de Crédito (tipo 4, ajusta si tu catálogo difiere)
        $doc_nc_id = DB::table('documento')->insertGetId([
            'id_tipo'                      => 4, // Nota de crédito
            'id_almacen_principal_empresa' => $doc_original->id_almacen_principal_empresa,
            'id_periodo'                   => $doc_original->id_periodo,
            'id_cfdi'                      => $doc_original->id_cfdi,
            'id_marketplace_area'          => $doc_original->id_marketplace_area,
            'id_usuario'                   => $usuario,
            'id_moneda'                    => $doc_original->id_moneda,
            'id_fase'                      => 6, // Fase de documento finalizado
            'id_entidad'                   => $doc_original->id_entidad,
            'tipo_cambio'                  => $doc_original->tipo_cambio,
            'referencia'                   => 'Refacturación de pedido ' . $documento,
            'observacion'                  => 'Nota de crédito por refacturación. ' . $observacion,
            'pagado'                       => 0,
            'status'                       => 1
        ]);

        // 7. Copia todos los productos al nuevo documento NC (la NC sí lleva productos)
        foreach ($productos as $prod) {
            DB::table('movimiento')->insert([
                'id_documento'  => $doc_nc_id,
                'id_modelo'     => $prod->id_modelo,
                'cantidad'      => $prod->cantidad,
                'precio'        => $prod->precio,
                'garantia'      => $prod->garantia,
                'modificacion'  => $prod->modificacion,
                'comentario'    => $prod->comentario,
                'regalo'        => $prod->regalo
            ]);
        }

        // 8. Calcula el monto total de la NC (suma de todos los productos)
        $monto_nc = 0;
        foreach ($productos as $prod) {
            $monto_nc += $prod->cantidad * $prod->precio;
        }

        // 9. Crea el movimiento contable para la Nota de Crédito (tipo afectación 4)
        $mc_nc_id = DB::table('movimiento_contable')->insertGetId([
            'folio'                  => "NC-{$documento}-".date('YmdHis'),
            'id_tipo_afectacion'     => 4, // Nota de crédito
            'fecha_operacion'        => date('Y-m-d'),
            'id_moneda'              => $doc_original->id_moneda,
            'tipo_cambio'            => $doc_original->tipo_cambio,
            'monto'                  => $monto_nc,
            'origen_tipo'            => 2, // Cliente/proveedor
            'entidad_origen'         => $doc_original->id_entidad,
            'nombre_entidad_origen'  => $entidad->razon_social,
            'destino_tipo'           => 1, // Entidad financiera (si aplica, sino null)
            'entidad_destino'        => null,
            'nombre_entidad_destino' => null,
            'id_forma_pago'          => null,
            'referencia_pago'        => "Nota de crédito por refacturación",
            'descripcion_pago'       => "NC Refacturación documento {$documento}",
            'comentarios'            => $observacion,
            'creado_por'             => $usuario
        ]);

        // 10. Enlaza el movimiento contable de la NC al campo "nota" del documento NC
        DB::table('documento')->where('id', $doc_nc_id)->update([
            'nota' => $mc_nc_id
        ]);

        // 11. Crea una copia del documento original (nuevo pedido/factura con datos idénticos, puedes cambiar cliente aquí si lo necesitas)
        $doc_nuevo_id = DB::table('documento')->insertGetId([
            'id_tipo'                      => $doc_original->id_tipo,
            'id_almacen_principal_empresa' => $doc_original->id_almacen_principal_empresa,
            'id_periodo'                   => $doc_original->id_periodo,
            'id_cfdi'                      => $doc_original->id_cfdi,
            'id_marketplace_area'          => $doc_original->id_marketplace_area,
            'id_usuario'                   => $usuario,
            'id_moneda'                    => $doc_original->id_moneda,
            'id_fase'                      => 1, // Nueva fase inicial
            'id_entidad'                   => $doc_original->id_entidad, // Cambia si el cliente también cambia
            'tipo_cambio'                  => $doc_original->tipo_cambio,
            'referencia'                   => 'Documento por refacturación de pedido '.$documento,
            'observacion'                  => 'Copia generada por refacturación. '.$observacion,
            'pagado'                       => 0,
            'status'                       => 1
        ]);

        // 12. Copia los productos originales al nuevo documento
        foreach ($productos as $prod) {
            DB::table('movimiento')->insert([
                'id_documento'  => $doc_nuevo_id,
                'id_modelo'     => $prod->id_modelo,
                'cantidad'      => $prod->cantidad,
                'precio'        => $prod->precio,
                'garantia'      => $prod->garantia,
                'modificacion'  => $prod->modificacion,
                'comentario'    => $prod->comentario,
                'regalo'        => $prod->regalo
            ]);
        }

        // 13. Desactiva (status = 0) la relación actual del ingreso con el documento original
        DB::table('movimiento_contable_documento')
            ->where('id_documento', $documento)
            ->where('id_movimiento_contable', $relacion_ingreso->id_movimiento_contable)
            ->where('status', 1)
            ->update(['status' => 0]);

        // 14. Crea la relación de la NC con el documento original (estatus activo)
        DB::table('movimiento_contable_documento')->insert([
            'id_documento'           => $documento,
            'id_movimiento_contable' => $mc_nc_id,
            'status'                 => 1,
            'referencia'             => 'Relación por refacturación/NC'
        ]);

        // 15. Crea la nueva relación del ingreso original al documento nuevo (estatus activo)
        DB::table('movimiento_contable_documento')->insert([
            'id_documento'           => $doc_nuevo_id,
            'id_movimiento_contable' => $relacion_ingreso->id_movimiento_contable,
            'status'                 => 1,
            'referencia'             => 'Relación por refacturación (ingreso original)'
        ]);

        // 16. Marca el documento original como refacturado
        DB::table('documento')->where('id', $documento)->update([
            'refacturado' => 1,
            'refacturado_at' => now()
        ]);

        // 17. Crea seguimientos (logs internos para auditoría)
        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario'   => $usuario,
            'seguimiento'  => "Documento refacturado. Se crea nota de crédito (id {$doc_nc_id}), movimiento contable de NC (id {$mc_nc_id}), y se reasigna ingreso al documento nuevo (id {$doc_nuevo_id})."
        ]);
        DB::table('seguimiento')->insert([
            'id_documento' => $doc_nc_id,
            'id_usuario'   => $usuario,
            'seguimiento'  => "Nota de crédito generada por refacturación del documento {$documento}."
        ]);
        DB::table('seguimiento')->insert([
            'id_documento' => $doc_nuevo_id,
            'id_usuario'   => $usuario,
            'seguimiento'  => "Documento generado por refacturación del pedido {$documento}, vinculado al ingreso original."
        ]);

        // 18. Regresa la respuesta con todos los IDs involucrados
        $response->error = 0;
        $response->mensaje = "Refacturación completada correctamente.";
        $response->documento_original = $documento;
        $response->documento_nota_credito = $doc_nc_id;
        $response->movimiento_nc = $mc_nc_id;
        $response->documento_nuevo = $doc_nuevo_id;

        return $response;
    }


    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'MS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'BLE'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }
}
