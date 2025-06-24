<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MP;
use stdClass;

class DeveloperController extends Controller
{
//    public static function venta($venta, $marketplace_id)
//    {
//
//
//        foreach ($ventas as $venta) {
//            $venta->productos = array();
//
//            $pack_id = explode(".", empty($venta->pack_id) ? $venta->id : sprintf('%lf', $venta->pack_id))[0];
//
//            $envio = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta->id . "/shipments?access_token=" . $token));
//            $mensajes = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "messages/packs/" . $pack_id . "/sellers/" . $venta->seller->id . "?access_token=" . $token));
//
//            $venta->mensajes = empty($mensajes) ? [] : $mensajes->messages;
//
//            if (!empty($envio)) {
//                if ($envio->status == "to_be_agreed" || $envio->status == "shipping_deferred") {
//                    $venta->shipping = 0;
//                } else {
//                    $envio->costo = $envio->shipping_option->cost;
//                    $venta->shipping = $envio;
//                }
//            } else {
//                $venta->shipping = 0;
//            }
//
//            foreach ($venta->payments as $payment) {
//                $detalle_pago = @json_decode(file_get_contents("https://api.mercadopago.com/v1/payments/" . $payment->id . "?access_token=" . $token));
//
//                $payment->more_details = $detalle_pago;
//            }
//
//            foreach ($venta->order_items as $item) {
//                $existe_publicacion = DB::select("SELECT
//                                                    marketplace_publicacion.id,
//                                                    empresa_almacen.id_almacen
//                                                FROM marketplace_publicacion
//                                                INNER JOIN empresa_almacen ON marketplace_publicacion.id_almacen_empresa = empresa_almacen.id
//                                                WHERE publicacion_id = '" . $item->item->id . "'");
//
//                if (!empty($existe_publicacion)) {
//                    $productos_publicacion = DB::select("SELECT
//                                                            marketplace_publicacion_producto.id_modelo,
//                                                            marketplace_publicacion_producto.garantia,
//                                                            (marketplace_publicacion_producto.cantidad * " . $item->quantity . ") AS cantidad,
//                                                            marketplace_publicacion_producto.regalo,
//                                                            modelo.sku,
//                                                            modelo.descripcion
//                                                        FROM marketplace_publicacion_producto
//                                                        INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
//                                                        WHERE id_publicacion = " . $existe_publicacion[0]->id . "");
//
//                    if (!empty($productos_publicacion)) {
//                        $existe_publicacion[0]->productos = $productos_publicacion;
//
//                        array_push($venta->productos, $existe_publicacion[0]);
//                    }
//                }
//            }
//        }
//
//        $response->error = 0;
//        $response->data = $ventas;
//
//        return $response;
//    }

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
        $response = new stdClass();

        $marketplace_id = 1;
        $seller_id = 1051840872;
        $venta = 2000008277006275;

        $venta = str_replace("%20", " ", $venta);
        $venta = rawurlencode($venta);
        $ventas = [];

        $paqueteEndpoint = "packs/{venta}";
        $paqueteData = self::callMlApi(
            $marketplace_id,
            $paqueteEndpoint,
            [
                '{venta}' => $venta
            ]
        );

        $informacion_paquete = json_decode($paqueteData->getContent());

        if (empty($informacion_paquete)) {
            $ventaEndpoint = "orders/{venta}";
            $ventaData = self::callMlApi(
                $marketplace_id,
                $ventaEndpoint,
                [
                    '{venta}' => $venta
                ]
            );
            $informacion_venta = json_decode($ventaData->getContent());

            if (empty($informacion_venta)) {

                $response->error = 1;
                $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." .  self::logVariableLocation();

                return $response;
            }

            $ventas[] = $informacion_venta;

        } else {
            foreach ($informacion_paquete->orders as $venta_paquete) {

                $ventaEndpoint = "orders/{venta}";
                $ventaData = self::callMlApi(
                    $marketplace_id,
                    $ventaEndpoint,
                    [
                        '{venta}' => rawurlencode($venta_paquete->id)
                    ]
                );
                $informacion_venta = json_decode($ventaData->getContent());

                if (empty($informacion_venta)) {
                    $response->error = 1;
                    $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . self::logVariableLocation();

                    return $response;
                }

                $ventas[] = $informacion_venta;
            }
        }

        return response()->json([
            'Respuesta' => $ventas
        ]);
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


}
