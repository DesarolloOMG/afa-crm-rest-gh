<?php

namespace App\Http\Services;

use App\Models\Documento;
use Exception;
use Illuminate\Support\Facades\Crypt;
use DB;
use stdClass;

class LiverpoolService
{
    public static function venta($venta, $marketplace)
    {
        $response = new \stdClass();
        $response->error = 1;

        $api_key = DB::select("SELECT
                                    marketplace_api.secret
                                FROM marketplace_area
                                INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                WHERE marketplace_area.id = " . $marketplace . "");

        if (empty($api_key)) {
            $response->mensaje = "No se encontró información del marketplace." . self::logVariableLocation();

            return $response;
        }

        try {
            $api_key = Crypt::decrypt($api_key[0]->secret);
        } catch (DecryptException $e) {
            $api_key = "";
        }

        if (empty($api_key)) {
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace" . self::logVariableLocation();

            return $response;
        }

        $request = \Httpful\Request::get(config("webservice.liverpool_endpoint") . "/orders?order_ids=" . trim($venta))
            ->addHeader('Authorization', $api_key)
            ->addHeader('Accept', 'application/json')
            ->send();

        $raw_request = $request->raw_body;
        $request = @json_decode($request->raw_body);

        if (empty($request)) {
            $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma." . self::logVariableLocation();
            $response->raw = $raw_request;

            return $response;
        }

        if (empty($request->orders)) {
            $response->mensaje = "No se encontró ninguna venta con el número proporcinado." . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->data = $request->orders[0];

        return $response;
    }

    public static function importar_ventas($ventas, $marketplace, $almacen)
    {
        set_time_limit(0);

        foreach ($ventas as $venta) {
            $existe =DB::table('documento')->where('no_venta', $venta->no_venta)->first();

            if ($existe) {
                $venta->Error = 1;
                $venta->ErrorMessage = "Ya existe la venta en CRM";
                $venta->Documentos = $existe->id;
            } else {
                $importar = self::importarVentaIndividual($venta, $marketplace, $venta->full, $almacen);

                if($importar->error) {
                    $venta->Error = 1;
                    $venta->ErrorMessage = $importar->mensaje;
                } else {
                    $venta->Error = 0;
                    $venta->ErrorMessage = "Creado correctamente";
                    $venta->Documentos = $importar->documentos;
                }
            }
        }

        return $ventas;
    }

    public static function importarVentaIndividual($venta, $marketplace_area, $fullfilment, $almacen)
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 0;
        $documentosStr = "";

        try {
            // Inserta la entidad (dirección de facturación)
            $entidadId = self::insertarEntidad($venta);
            if (!$entidadId) throw new Exception("No se creó la entidad");

            $documentoId = self::insertarDocumento($venta, $marketplace_area, $fullfilment, $almacen);
            if (!$documentoId) throw new Exception("No se creó el documento");

            $documentosStr .= $documentoId;

            self::insertarSeguimiento($documentoId, "PEDIDO IMPORTADO DESDE EXCEL LIVERPOOL");
            self::relacionarEntidadDocumento($entidadId, $documentoId);

            $direccionId = self::insertarDireccion($venta, $documentoId);
            if (!$direccionId) throw new Exception("No se creó la dirección");

            $movimientoId = self::insertarMovimiento($venta, $documentoId);
            if (!$movimientoId) throw new Exception("No se creó el movimiento del producto");

            $pagoId = self::insertarPago($venta, $documentoId);
            if (!$pagoId) throw new Exception("No se creó el pago");

            $response->error = 0;
            $response->mensaje = "Importado correctamente";
            $response->documentos = $documentosStr;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = $e->getMessage();
            $response->documentos = $documentosStr;
        }

        return $response;
    }

    private static function insertarEntidad($venta)
    {
        return DB::table('documento_entidad')->insertGetId([
            'razon_social' => $venta->cliente ?? 0,
            'rfc' => mb_strtoupper('XAXX010101000', 'UTF-8'),
            'telefono' => $venta->telefono ?? 0,
            'telefono_alt' => "0",
            'correo' => "N/A"
        ]);
    }

    private static function insertarDocumento($venta, $marketplace_area, $fullfilment, $almacen)
    {
        return DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_tipo' => 2,
            'id_almacen_principal_empresa' => $almacen ?? 114,
            'id_marketplace_area' => $marketplace_area,
            'id_usuario' => 1,
            'id_paqueteria' => 1,
            'id_fase' => 1,
            'id_modelo_proveedor' => 0,
            'no_venta' => $venta->no_venta,
            'referencia' => "N/A",
            'observacion' => "Pedido Importado Excel Liverpool",
            'info_extra' => "",
            'fulfillment' => $fullfilment,
            'comentario' => 0,
            'mkt_publicacion' => "N/A",
            'mkt_total' => $venta->total ?? 0,
            'mkt_fee' => 0,
            'mkt_coupon' => 0,
            'mkt_shipping_total' => 0,
            'mkt_created_at' => $venta->fecha_creacion ?? 0,
            'mkt_user_total' => 0,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function insertarSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => "<h2>{$mensaje}</h2>"
        ]);
    }

    private static function insertarMensajeSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => $mensaje
        ]);
    }

    private static function relacionarEntidadDocumento($entidadId, $documentoId)
    {
        DB::table('documento')->where('id', $documentoId)->update([
            'id_entidad' => $entidadId
        ]);
    }

    private static function insertarDireccion($venta, $documentoId)
    {
        return DB::table('documento_direccion')->insert([
            'id_documento' => $documentoId,
            'id_direccion_pro' => 0,
            'contacto' => $venta->cliente ?? 0,
            'calle' => $venta->direccion1 ?? 0,
            'numero' => $venta->direccion2 ?? 0,
            'numero_int' => "N/A",
            'colonia' => $venta->colonia ?? 0,
            'ciudad' => $venta->ciudad ?? 0,
            'estado' => $venta->estado ?? "0",
            'codigo_postal' => $venta->cp ?? 0,
            'referencia' => "Sin referencia."
        ]);
    }

    private static function insertarMovimiento($venta, $documentoId)
    {
        $modelo = DB::table('modelo')->where('sku', $venta->sku)->first();
        $modeloId = $modelo ? $modelo->id : DB::table('modelo_sinonimo')->where('codigo', $venta->sku)->value('id_modelo');

        if ($modeloId) {
            return DB::table('movimiento')->insertGetId([
                'id_documento' => $documentoId,
                'id_modelo' => $modeloId,
                'cantidad' => $venta->cantidad ?? 1,
                'precio' => $venta->precio / 1.16,
                'garantia' => 90,
                'modificacion' => '',
                'regalo' => ''
            ]);
        }
    }

    private static function insertarPago($venta, $documentoId)
    {
        $pagoId = DB::table('documento_pago')->insertGetId([
            'id_usuario' => 1,
            'id_metodopago' => 31,
            'id_vertical' => 0,
            'id_categoria' => 0,
            'id_clasificacion' => 0,
            'tipo' => 1,
            'origen_importe' => 0,
            'destino_importe' => $venta->total,
            'folio' => "",
            'entidad_origen' => 1,
            'origen_entidad' => 'XAXX010101000',
            'entidad_destino' => "",
            'destino_entidad' => '',
            'referencia' => '',
            'clave_rastreo' => '',
            'autorizacion' => '',
            'destino_fecha_operacion' => date('Y-m-d'),
            'destino_fecha_afectacion' => '',
            'cuenta_cliente' => ''
        ]);

        if ($pagoId) {
            DB::table('documento_pago_re')->insert([
                'id_documento' => $documentoId,
                'id_pago' => $pagoId
            ]);
        }

        return $pagoId;
    }

    private static function actualizarDocumentoFase($documentoId)
    {
        DB::table('documento')->where(['id' => $documentoId])->update([
            'id_fase' => 5
        ]);
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'LS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'OOL'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
