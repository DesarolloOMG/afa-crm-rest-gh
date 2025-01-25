<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Validator;
use DB;

//Electra
class ElektraService
{
    public static function venta($venta, $marketplace_id)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . self::logVariableLocation();

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $informacion = \Httpful\Request::get(config("webservice.elektra_enpoint") . "orders/" . $venta)
            ->addHeader('Authorization', "Bearer " .  $token->token . "")
            ->send();

        if (empty($informacion->body)) {
            $response->error = 1;
            $response->mensaje = "No se encontró informació de la venta." . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->data = $informacion->body;

        return $response;
    }

    public static function cambiarEstado($venta, $marketplace, $estado)
    {
        $response = new \stdClass();

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        if ($estado === 1) {
            $request = \Httpful\Request::post(config("webservice.elektra_enpoint") . "orders/" . $venta . "/start-handling")
                ->addHeader('Authorization', "Bearer " .  $token->token . "")
                ->send();
        } else {
            $productos = array();

            $informacion_venta = DB::select("SELECT
                                                documento.id,
                                                documento_guia.guia,
                                                documento.id_marketplace_area,
                                                paqueteria.paqueteria,
                                                paqueteria.url
                                            FROM documento
                                            INNER JOIN documento_guia ON documento.id = documento_guia.id_documento
                                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                            WHERE documento.no_venta = '" . $venta . "'
                                            AND documento.status = 1");

            if (empty($informacion_venta)) {
                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta para cambiar el estado en el marketplace." . self::logVariableLocation();

                return $response;
            }

            $informacion_elektra = self::venta($venta, $informacion_venta[0]->id_marketplace_area);

            if ($informacion_elektra->error) {
                return $informacion_elektra;
            }

            foreach ($informacion_elektra->data->items as $item) {
                $producto_data = new \stdClass();

                $producto_data->id = $item->id;
                $producto_data->quantity = $item->quantity;

                array_push($productos, $producto_data);
            }

            $track_url = trim($informacion_venta[0]->url . $informacion_venta[0]->guia);

            if (trim($informacion_venta[0]->paqueteria) == "Estafeta") {
                $track_url = trim($informacion_venta[0]->url);
            }

            $data = array(
                "courier" => trim($informacion_venta[0]->paqueteria),
                "invoiceNumber" => (string) $informacion_venta[0]->id,
                "items" => $productos,
                "trackingNumber" => trim($informacion_venta[0]->guia),
                "trackingUrl" => $track_url
            );

            $request = \Httpful\Request::post(config("webservice.elektra_enpoint") . "orders/" . $venta . "/invoice")
                ->addHeader('Authorization', "Bearer " .  $token->token . "")
                ->addHeader('Content-Type', 'application/json')
                ->body(json_encode($data))
                ->send();
        }

        $response->error = 0;

        return $response;
    }

    public static function token($marketplace)
    {
        $response = new \stdClass();

        $request = \Httpful\Request::post(config("webservice.elektra_enpoint") . "oauth/token?grant_type=password&username=" . $marketplace->app_id . "&password=" . $marketplace->secret . "")
            ->addHeader('Authorization', "Basic " .  base64_encode($marketplace->extra_1 . ":" . $marketplace->extra_2) . "")
            ->send();

        $response_data = @json_decode($request->raw_body);

        if (empty($response_data)) {
            $response->error = 1;
            $response->mensaje = "No se pudo obtener el token, error desconocido" . self::logVariableLocation();
            $response->raw = $request->raw_body;

            return $response;
        }

        if (property_exists($response_data, 'error')) {
            $response->error = 1;
            $response->mensaje = $response_data->error_description . "" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->token = $response_data->access_token;

        return $response;
    }
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'ES'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'TRA'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
