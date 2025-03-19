<?php

namespace App\Http\Services;

use DB;

class MovimientoService
{
    public static function crearMovimiento($data, $empresa, $usuario) {
        $response = new \stdClass();

        $documento_pago = DB::table('documento_pago')->insertGetId([
            'id_empresa' => $empresa,
            'id_usuario' => $usuario,
            'id_metodopago' => $data->metodo_pago,
            'id_vertical' => !empty($data->vertical) ? $data->vertical : 0,
            'id_categoria' => !empty($data->categoria) ? $data->categoria : 0,
            'id_clasificacion' => !empty($data->clasificacion) ? $data->clasificacion : 0,
            'tipo' => $data->tipo_documento,
            'origen_importe' => $data->origen->monto,
            'entidad_origen' => $data->origen->entidad,
            'origen_entidad' => ($data->tipo_documento == 1) ? ((property_exists($data->origen, 'entidad_rfc')) ? (($data->origen->entidad_rfc == 'XEXX010101000') ? $data->origen->cuenta_bancaria : $data->origen->entidad_rfc) : $data->origen->cuenta_bancaria) : $data->origen->cuenta_bancaria,
            'entidad_destino' => $data->destino->entidad,
            'destino_entidad' => ($data->tipo_documento == 0) ? ((property_exists($data->destino, 'entidad_rfc')) ? (($data->destino->entidad_rfc == 'XEXX010101000') ? $data->destino->cuenta_bancaria : $data->destino->entidad_rfc) : $data->destino->cuenta_bancaria) : $data->destino->cuenta_bancaria,
            'referencia' => $data->referencia,
            'clave_rastreo' => $data->clave_rastreo,
            'autorizacion' => $data->autorizacion,
            'cuenta_cliente' => $data->cuenta_proveedor,
            'destino_fecha_operacion' => $data->destino->fecha_operacion,
            'destino_fecha_afectacion' => $data->destino->fecha_operacion,
            'origen_fecha_operacion' => $data->origen->fecha_operacion,
            'origen_fecha_afectacion'   => $data->origen->fecha_operacion,
            'tipo_cambio' => $data->tipo_cambio
        ]);

        if(!empty($data->documentos)) {
            foreach($data->documentos as $documento) {
                DB::table('documento_pago_re')->insert([
                    'id_documento' => $documento->id,
                    'id_pago' => $documento_pago,
                    'saldo' => $documento->saldo,
                    'tipo_cambio' => $documento->tipo_cambio,
                ]);

                $info_documento = DB::table('documento')->where('id', $documento->id)->first();

                DB::table('documento')->where('id', $documento->id)->update([
                    'saldo' => $info_documento->total - $documento->saldo,
                ]);
            }
        }

        $response->error = 0;
        $response->ingreso = $documento_pago;
        $response->documentos = $data->documentos;
        $response->code = 200;
        $response->message = 'Ingreso creado correctamente';

        return $response;
    }
}
