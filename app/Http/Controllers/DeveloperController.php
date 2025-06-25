<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MP;
use stdClass;

class DeveloperController extends Controller
{

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
        $existe_publicacion = new stdClass();

        $ventaData = self::callMlApi(1, "orders/{venta}", ['{venta}' => 2000012073771010]);
        $info = json_decode($ventaData->getContent());

        $item = $info->order_items[0];
        $existe_publicacion->id = 910;

        $productos_query = DB::table('marketplace_publicacion_producto')
            ->where('id_publicacion', $existe_publicacion->id);

        if (!is_null($item->item->variation_id)) {
            $productos_query->whereRaw('CAST(etiqueta AS CHAR) = ?', [(string)$item->item->variation_id]);
        }

        $productos_publicacion = $productos_query->get();

        if (empty($productos_publicacion)) {
            $productos_publicacion = DB::table('marketplace_publicacion_producto')
                ->where('id_publicacion', $existe_publicacion->id)
                ->get();
        }

        if (empty($productos_publicacion)) {
            $pack = new stdClass();
            $pack->error = 0;
            $pack->venta_principal->seguimiento = "No hay relación entre productos y la publicación {$item->item->id} en la venta {$venta->id}";
            $pack->venta_principal->fase = 1;
            $pack->venta_principal->error = 1;

            return response()->json([
                'Respuesta' => $pack
            ]);

        }

        $porcentaje_total = $productos_publicacion->sum('porcentaje');

        if ($porcentaje_total != 100) {
            $pack = new stdClass();

            $pack->error = 0;
            $pack->venta_principal->seguimiento = "Los productos de la publicación {$item->item->id} no suman un porcentaje total de 100%.";
            $pack->venta_principal->fase = 1;
            $pack->venta_principal->error = 1;

            return response()->json([
                'Respuesta' => $pack
            ]);
        }
        return response()->json([
            'Respuesta' => $porcentaje_total
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

    public static function callMpApi($marketplaceId, $endpointTemplate, array $placeholders = [], $opt = 0)
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
        $url = "https://api.mercadopago.com/" . $endpoint;

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
