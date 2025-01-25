<?php

namespace App\Http\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use DB;

class CoppelService
{
    public static function venta($venta, $marketplace)
    {
        $response = new \stdClass();
        $response->error = 1;

        $api_key = DB::table("marketplace_area")
            ->select("marketplace_api.secret")
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->where("marketplace_area.id", $marketplace)
            ->first();

        if (!$api_key) {
            $response->mensaje = "No se encontró información del marketplace." . self::logVariableLocation();

            return $response;
        }

        $api_key = self::descryptKey($api_key->secret);

        if (empty($api_key)) {
            $response->mensaje = "Error al desencriptar el api key del marketplace" . self::logVariableLocation();

            return $response;
        }

        $request_data = \Httpful\Request::get(config("webservice.coppel_endpoint") . "/orders?order_ids=" . trim($venta))
            ->addHeader('Authorization', $api_key)
            ->addHeader('Accept', 'application/json')
            ->send();

        $raw_request = $request_data->raw_body;
        $request = @json_decode($request_data->raw_body);

        if (empty($request)) {
            $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma." . self::logVariableLocation();
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        if (property_exists($request, "status")) {
            if ($request->status == 401) {
                $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma, mensaje de error: " . $request->message . "" . self::logVariableLocation();
                $response->raw = $raw_request;

                return $response;
            }
        }

        if (empty($request->orders)) {
            $response->mensaje = "No se encontró ninguna venta con el número proporcinado." . self::logVariableLocation();
            $response->raw = $raw_request;
            $response->data = $request->orders;

            return $response;
        }

        $response->error = 0;
        $response->data = $request->orders[0];

        return $response;
    }

    public static function documento($documento, $marketplace)
    {
        $response = new \stdClass();
        $response->error = 1;
        $archivos = array();

        $venta = DB::table("documento")
            ->where("id", $documento)
            ->select("no_venta")
            ->first();

        if (empty($venta)) {
            $response->mensaje = "No se encontró el número de venta del documento para descargar el documento de embarque." . self::logVariableLocation();

            return $response;
        }

        $api_key = DB::table("marketplace_area")
            ->select("marketplace_api.secret")
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->where("marketplace_area.id", $marketplace)
            ->first();

        if (!$api_key) {
            $response->mensaje = "No se encontró información del marketplace." . self::logVariableLocation();

            return $response;
        }

        $api_key = self::descryptKey($api_key->secret);

        if (empty($api_key)) {
            $response->mensaje = "Error al desencriptar el api key del marketplace" . self::logVariableLocation();

            return $response;
        }

        $request_data = \Httpful\Request::get(config("webservice.coppel_endpoint") . "/orders/documents?order_ids=" . trim($venta->no_venta) . "")
            ->addHeader('Authorization', $api_key)
            ->addHeader('Accept', 'application/json')
            ->send();

        $raw_request = $request_data->raw_body;
        $request = @json_decode($raw_request);

        if (empty($request)) {
            $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma." . self::logVariableLocation();
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        if (property_exists($request, "status")) {
            if ($request->status == 401) {
                $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma, mensaje de error: " . $request->message . "" . self::logVariableLocation();
                $response->raw = $raw_request;

                return $response;
            }
        }

        if (empty($request->order_documents)) {
            $response->mensaje = "No se encontraron documentos en la plataforma para la venta " . $venta->no_venta . "." . self::logVariableLocation();
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        $label_id = NULL;

        foreach ($request->order_documents as $document) {
            if ($document->type === "SHIPPING_LABEL") {
                $label_id = $document->id;
            }
        }

        if (is_null($label_id)) {
            $response->mensaje = "No se encontró ningun documento de embarque en la plataforma para la venta " . $venta->no_venta . "." . self::logVariableLocation();

            return $response;
        }

        $request_file = \Httpful\Request::get(config("webservice.coppel_endpoint") . "/orders/documents/download?document_ids=" . $label_id)
            ->addHeader('Authorization', $api_key)
            ->addHeader('Accept', 'application/json')
            ->send();

        $raw_request_file = $request_file->raw_body;
        $request_file = $request_file->body;

        if (empty($request_file)) {
            $response->mensaje = "Ocurrió un error al obtener el documento de embarque." . self::logVariableLocation();
            $response->raw = $raw_request_file;

            return $response;
        }

        $response->error = 0;
        $response->file =  base64_encode($request_file);

        return $response;
    }

    private static function descryptKey($secret_key)
    {
        try {
            $decoded_secret_key = Crypt::decrypt($secret_key);
        } catch (DecryptException $e) {
            $decoded_secret_key = "";
        }

        return $decoded_secret_key;
    }
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'CC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'PEL'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
