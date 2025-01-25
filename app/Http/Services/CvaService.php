<?php

namespace App\Http\Services;

use SimpleXMLElement;
use DOMDocument;
use Exception;
use DB;

class CvaService
{
    public static function existenciaProducto($codigo)
    {
        $xml = file_get_contents(config("webservice.cva") . "catalogo_clientes_xml/lista_precios.xml?cliente=236&marca=&grupo=&clave=&codigo=" . $codigo);

        $data = simplexml_load_string($xml);

        return $data;
    }

    public static function crearPedido($documento = 485716)
    {
        $response = new \stdClass();
        $response->error = 1;

        $existe = DB::select("SELECT id FROM documento WHERE id = " . $documento . "");

        if (empty($existe)) {
            $response->mensaje = "No se encontró información del documento proporcionado" . self::logVariableLocation();

            return $response;
        }

        foreach ($productos as $producto) {
            if (empty($producto->codigo)) {
                $response->mensaje = "No se encontró relacion del codigo " . $producto->sku . " con el proveedor externo, favor de revisar e intentar de nuevo." . self::logVariableLocation();

                return $response;
            }

            return self::existenciaProducto($producto->codigo);
        }

        $soap_data = array(
            "suscriberId" => config('estafeta.API_ESTAFETA_TRACK_USER_ID'),
            "login" => config('estafeta.API_ESTAFETA_TRACK_LOGIN'),
            "password" => config('estafeta.API_ESTAFETA_TRACK_PASSWORD'),
            "searchType" => array(
                "type" => "L",
                "waybillList" => array(
                    "waybillType" => "G",
                    "waybills" => array()
                )
            ),
            "searchConfiguration" => array(
                "includeDimensions" => 1,
                "includeWaybillReplaceData" => 1,
                "includeReturnDocumentData" => 1,
                "includeMultipleServiceData" => 0,
                "includeInternationalData" => 0,
                "includeSignature" => 0,
                "includeCustomerInfo" => 1,
                "historyConfiguration" => array(
                    "includeHistory" => 1,
                    "historyType" => "ALL"
                ),
                "filterType" => array(
                    "filterInformation" => 0,
                    "filterType" => ""
                )
            )
        );

        try {
            $client = new SoapClient(config('estafeta.API_ESTAFETA_TRACK_URL'), array('trace' => 1, 'exceptions' => true));

            $soap_response = $client->ExecuteQuery($soap_data);

            if ((int) $soap_response->ExecuteQueryResult->errorCode > 0) {
                $response->error = 1;
                $response->message = $soap_response->ExecuteQueryResult->errorCodeDescriptionSPA . "" . self::logVariableLocation();

                return $response;
            }

            $response->error = 0;

            if (property_exists($soap_response->ExecuteQueryResult->trackingData, "TrackingData")) {
                if (is_array($soap_response->ExecuteQueryResult->trackingData->TrackingData)) {
                    $response->data = array();

                    foreach ($soap_response->ExecuteQueryResult->trackingData->TrackingData as $TrackingData) {
                        $response->data = array_merge($response->data, $TrackingData->history->History);
                    }
                } else {
                    $response->data = $soap_response->ExecuteQueryResult->trackingData->TrackingData->history->History;
                }
            } else {
                $response->data = [];
            }

            return $response;
        } catch (\SoapFault $exception) {
            $response->error = 1;
            $response->message = $exception->faultstring . "" . self::logVariableLocation();

            return $response;
        }
    }
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'CS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'CVA'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
