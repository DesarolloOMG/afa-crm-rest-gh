<?php

namespace App\Http\Controllers;

use App\Http\Services\DropboxService;
use DB;
use Illuminate\Http\Request;

class ExternoController extends Controller{
    public function venta_externo_crear(Request $request){
        $data = json_decode($request->input('data'));

        $clienteExistente = DB::table('documento_entidad')
            ->select('id')
            ->where('rfc', trim($data->cliente->rfc))
            ->where('tipo', 1)
            ->first();

        $valores = [
            'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
            'rfc' => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
            'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
            'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
            'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8')),
        ];

        if (!$clienteExistente) {
            $cliente = DB::table('documento_entidad')->insertGetId($valores + ['tipo' => 1]);
        } else {
            $cliente = $clienteExistente->id;

            DB::table('documento_entidad')
                ->where('id', $cliente)
                ->update($valores);
        }

        $paqueteria_id = 9;

        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

        foreach ($paqueterias as $paqueteria) {
            if ($data->documento->paqueteria == 'Express' && $paqueteria->id == 2) {
                $paqueteria_id = 2;
            }
            else {
                if ($paqueteria->paqueteria == explode(" ", $data->documento->paqueteria)[0]) {
                    $paqueteria_id = $paqueteria->id;
                }
            }
        }

        $documento = DB::table('documento')->insertGetId([
            'documento_extra'               => "",
            'id_almacen_principal_empresa'  => $data->documento->almacen,
            'id_periodo'                    => $data->documento->periodo,
            'id_cfdi'                       => $data->documento->uso_venta,
            'id_marketplace_area'           => $data->documento->marketplace,
            'id_usuario'                    => $data->usuario,
            'id_moneda'                     => $data->documento->moneda,
            'id_paqueteria'                 => $paqueteria_id,
            'id_entidad'                    => $cliente,
            'id_fase'                       => 3,
            'no_venta'                      => $data->documento->venta,
            'tipo_cambio'                   => $data->documento->tipo_cambio,
            'referencia'                    => $data->documento->referencia,
            'observacion'                   => $data->documento->observacion
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $data->usuario,
            'seguimiento'   => $data->documento->seguimiento
        ]);

        foreach ($data->documento->productos as $producto) {
            $existe_modelo = DB::table('modelo')->where('sku', trim($producto->sku))->first();

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'id_tipo'       => 1,
                    'sku'           => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                    'descripcion'   => mb_strtoupper(trim($producto->descripcion), 'UTF-8'),
                    'costo'         => 0,
                    'alto'          => 0,
                    'ancho'         => 0,
                    'largo'         => 0,
                    'peso'          => 0
                ]);
            }
            else {
                $modelo = $existe_modelo->id;
            }

            DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento,
                'id_modelo'     => $modelo,
                'cantidad'      => (integer) $producto->cantidad,
                'precio'        => (float) $producto->precio / 1.16,
                'garantia'      => 30,
                'modificacion'  => "",
                'comentario'    => "",
                'addenda'       => "",
                'regalo'        => 0
            ]);
        }

        DB::table('documento_direccion')->insert([
            'id_documento'      => $documento,
            'id_direccion_pro'  => 0,
            'contacto'          => $data->documento->direccion_envio->contacto,
            'calle'             => $data->documento->direccion_envio->calle,
            'numero'            => $data->documento->direccion_envio->numero,
            'numero_int'        => $data->documento->direccion_envio->numero_int,
            'colonia'           => $data->documento->direccion_envio->colonia,
            'ciudad'            => $data->documento->direccion_envio->ciudad,
            'estado'            => $data->documento->direccion_envio->estado,
            'codigo_postal'     => $data->documento->direccion_envio->codigo_postal,
            'referencia'        => is_null($data->documento->direccion_envio->referencia) ? "" : $data->documento->direccion_envio->referencia
        ]);
        
        foreach ($data->documento->archivos as $archivo) {
            $dropboxService = new DropboxService();
            $dropboxResponse = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo->data);

            if (isset($dropboxResponse['id'])) {
                DB::table('documento_archivo')->insert([
                    'id_documento'  =>  $documento,
                    'id_usuario'    =>  $data->usuario,
                    'nombre'        =>  $archivo->nombre,
                    'dropbox'       =>  $dropboxResponse['id'],
                    'tipo'          =>  strpos($archivo->nombre, 'etiqueta') !== false ? 2 : 1,
                ]);
            }
        }

        $pago = DB::table('documento_pago')->insertGetId([
            'id_usuario'                => $data->usuario,
            'id_metodopago'             => 99,
            'id_vertical'               => 0,
            'id_categoria'              => 0,
            'id_clasificacion'          => 1,
            'tipo'                      => 1,
            'origen_importe'            => 0,
            'destino_importe'           => 0,
            'folio'                     => "",
            'entidad_origen'            => 1,
            'origen_entidad'            => $data->cliente->rfc,
            'entidad_destino'           => '',
            'destino_entidad'           => '',
            'referencia'                => '',
            'clave_rastreo'             => '',
            'autorizacion'              => '',
            'destino_fecha_operacion'   => date('Y-m-d'),
            'destino_fecha_afectacion'  => '',
            'cuenta_cliente'            => ''
        ]);

        DB::table('documento_pago_re')->insert([
            'id_documento'  => $documento,
            'id_pago'       => $pago
        ]);

        return (float) $documento;
    }
}
