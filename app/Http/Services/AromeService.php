<?php

namespace App\Http\Services;

use Exception;
use DateTime;
use DB;

class AromeService
{
    public static function venta($venta, $credenciales)
    {
        $response = new \stdClass();
        $basic_auth = base64_encode($credenciales->app_id . ":" . $credenciales->secret);
        $request_url = $credenciales->extra_2 . "api/orders/" . $venta . "";

        $informacion = \Httpful\Request::get($request_url)
            ->addHeader('Authorization', "Basic " . $basic_auth)
            ->expectsJson()
            ->send();

        $data = $informacion->body;

        if ($data->status == 404) {
            $response->error = 1;
            $response->mensaje = "La venta no fue encontrada." . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->data = $data;

        return $response;
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
