<?php

namespace App\Http\Services;

use Exception;
use DateTime;
use DB;

class MovimientoContableService
{
    /**
     * Crea un movimiento contable (ingreso, egreso, traspaso, nota de crédito, etc.)
     */
    public static function crearMovimiento($data)
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
    public static function aplicarADocumentos($idMovimiento, $documentos, $monedaMovimiento, $tipoCambioMovimiento = 1)
    {
        foreach ($documentos as $doc) {
            $idDocumento        = $doc['id_documento'];
            $montoAplicado      = $doc['monto_aplicado'];
            $monedaDocumento    = $doc['moneda'] ?? $monedaMovimiento;
            $tipoCambioAplicado = $doc['tipo_cambio_aplicado'] ?? $tipoCambioMovimiento;
            $parcialidad        = $doc['parcialidad'] ?? 1;

            // 1. Insertar en movimiento_contable_documento
            DB::table('movimiento_contable_documento')->insert([
                'id_movimiento_contable' => $idMovimiento,
                'id_documento'           => $idDocumento,
                'monto_aplicado'         => $montoAplicado,
                'saldo_documento'        => null, // Se actualizará después
                'moneda'                 => $monedaDocumento,
                'tipo_cambio_aplicado'   => $tipoCambioAplicado,
                'parcialidad'            => $parcialidad
            ]);

            // 2. Actualizar saldo en la tabla documento
            $documento = DB::table('documento')->where('id', $idDocumento)->first();

            if (!$documento) {
                throw new Exception("Documento no encontrado: $idDocumento");
            }

            // Convertir monto aplicado a moneda del documento si es necesario
            if ($monedaMovimiento != $monedaDocumento) {
                // Siempre convierte el monto del movimiento a la moneda del documento usando el tipo de cambio proporcionado
                $montoAplicadoEnDoc = ($monedaMovimiento == 'USD' && $monedaDocumento == 'MXN')
                    ? $montoAplicado * $tipoCambioAplicado
                    : $montoAplicado / $tipoCambioAplicado;
            } else {
                $montoAplicadoEnDoc = $montoAplicado;
            }

            // Asegura que no se pase de saldar el saldo pendiente
            $nuevoSaldo = max(0, $documento->saldo - $montoAplicadoEnDoc);

            DB::table('documento')->where('id', $idDocumento)->update([
                'saldo' => $nuevoSaldo,
                'pagado' => ($nuevoSaldo == 0) ? 1 : 0
            ]);
        }
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'AS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'OME'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
