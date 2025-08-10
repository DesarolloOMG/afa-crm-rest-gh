<?php

namespace App\Http\Controllers;

use App\Http\Services\DropboxService;
use App\Http\Services\InventarioService;
use App\Http\Services\MercadolibreService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MP;
use stdClass;

class DeveloperController extends Controller
{

    /**
     * @var DropboxService
     */
    private $dropbox;

    public function __construct(DropboxService $dropbox)
    {
        $this->dropbox = $dropbox;
    }

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
        $aplicar = InventarioService::aplicarMovimiento(366);
        dd($aplicar);
    }

    public static function log_meli_error(string $mensaje, string $publicacion_id)
    {
        $publicacion_id = $publicacion_id ?: 'sin_id';

        $dir = "logs/mercadolibre";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $archivo = "{$dir}/" . date("Y.m.d") . "-{$publicacion_id}.log";
        file_put_contents($archivo, date("H:i:s") . " Error: {$mensaje}" . PHP_EOL, FILE_APPEND);
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

    public function recalcularInventario()
    {
        set_time_limit(0);

        $errores = [];
        $aplicados = [];

        $documentos = DB::table('documento')
            ->select('id', 'id_tipo', 'autorizado', 'id_fase')
            ->whereIn('id_tipo', [0,2,3,4,5,6,11])
            ->whereIn('id_fase', [5, 6, 100, 606, 607])
            ->where('status', 1)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($documentos as $documento) {
            try {
                if ((int)$documento->id_tipo === 0 && (int)$documento->id_fase === 606) {
                    $movimientos = DB::table('movimiento')->where('id_documento', $documento->id)->get();
                     if ($movimientos) {
                         foreach ($movimientos as $mov) {
                             $recepcion = DB::table('documento_recepcion')->where('id_movimiento', $mov->id)->first();
                             if ($recepcion) {
                                 $aplicar = InventarioService::procesarRecepcion($recepcion->id_movimiento, $recepcion->cantidad);
                                 if ($aplicar->error) {
                                     $errores[] = "Error en documento: {$documento->id} - {$aplicar->mensaje}" ?? "Error en documento {$documento->id}";
                                 } else {
                                     $aplicados[] =  "Documento: {$documento->id} - {$aplicar->mensaje}";
                                 }
                             }
                         }
                     }
                } else {
                    $aplicar = InventarioService::aplicarMovimiento($documento->id);
                    if ($aplicar->error) {
                        $errores[] = "Error en documento: {$documento->id} - {$aplicar->mensaje}" ?? "Error en documento {$documento->id}";
                    } else {
                        $aplicados[] =  "Documento: {$documento->id} - {$aplicar->mensaje}";
                    }
                }
            } catch (\Throwable $e) {
                $errores[] = "Excepción en documento {$documento->id}: " . $e->getMessage();
            }
        }

        $hayErrores = !empty($errores);

        return response()->json([
            'code'        => $hayErrores ? 207 : 200, // 207 Multi-Status si hubo errores
            'message'     => $hayErrores ? 'Procesado con errores' : 'Ya quedó',
            'errors'      => $errores,
            'aplicados'   => $aplicados,
            'aplicados_count'=> count($aplicados),
            'errors_count'=> count($errores),
            'documentos' => $documentos,
        ], $hayErrores ? 207 : 200);
    }
}
