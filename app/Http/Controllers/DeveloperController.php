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
        $log_preparacion = [];

        // --- FASE 1: PREPARACIÓN DEL TERRENO ---
        $log_preparacion[] = "Iniciando Fase 1: Preparación de existencias y costos...";
        DB::beginTransaction();
        try {
            // Obtenemos todos los documentos que se van a procesar.
            $documentos = DB::table('documento')
                ->whereIn('id_tipo', [0, 2, 3, 4, 5, 6, 11])
                ->whereIn('id_fase', [5, 6, 100, 606, 607])
                ->where('status', 1)
                ->select('id', 'id_almacen_principal_empresa')
                ->orderBy('created_at', 'asc')
                ->get();

            $idsDocumentos = $documentos->pluck('id');

            // Obtenemos una lista única de todos los productos (modelos) que serán afectados.
            $modelosAfectados = DB::table('movimiento')
                ->whereIn('id_documento', $idsDocumentos)
                ->select('id_modelo')
                ->distinct()
                ->get();

            $almacenesAfectados = $documentos->pluck('id_almacen_principal_empresa')->unique()->filter();

            // Para cada producto y almacén, nos aseguramos de que exista su registro.
            foreach ($modelosAfectados as $modelo) {
                // Asegurar registro de costo
                $costoExistente = DB::table('modelo_costo')->where('id_modelo', $modelo->id_modelo)->first();
                if (!$costoExistente) {
                    DB::table('modelo_costo')->insert([
                        'id_modelo'      => $modelo->id_modelo,
                        'costo_inicial'  => 0,
                        'costo_promedio' => 0,
                        'ultimo_costo'   => 0
                    ]);
                    $log_preparacion[] = "Creado registro de costo para modelo: {$modelo->id_modelo}";
                }

                // Asegurar registro de existencia en cada almacén
                foreach ($almacenesAfectados as $almacenId) {
                    $existenciaExistente = DB::table('modelo_existencias')
                        ->where('id_modelo', $modelo->id_modelo)
                        ->where('id_almacen', $almacenId)
                        ->first();

                    if (!$existenciaExistente) {
                        DB::table('modelo_existencias')->insert([
                            'id_modelo'      => $modelo->id_modelo,
                            'id_almacen'     => $almacenId,
                            'stock_inicial'  => 0,
                            'stock'          => 0,
                            'stock_anterior' => 0
                        ]);
                        $log_preparacion[] = "Creado registro de existencia para modelo {$modelo->id_modelo} en almacén {$almacenId}";
                    }
                }
            }

            DB::commit();
            $log_preparacion[] = "Fase 1 completada exitosamente.";

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'message' => 'Error crítico durante la fase de preparación.',
                'error_details' => $e->getMessage()
            ], 500);
        }

        // --- FASE 2: PROCESAMIENTO DE DOCUMENTOS (El código que ya teníamos) ---
        // Obtenemos los documentos de nuevo con todos sus campos necesarios.
        $documentosParaProcesar = DB::table('documento')
            ->select('id', 'id_tipo', 'autorizado', 'id_fase')
            ->whereIn('id', $idsDocumentos)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($documentosParaProcesar as $documento) {
            DB::beginTransaction();
            try {
                // Se aplica la lógica condicional original
                if ((int)$documento->id_tipo === 0 && (int)$documento->id_fase === 606) {
                    // Lógica de Recepción...
                    $movimientos = DB::table('movimiento')->where('id_documento', $documento->id)->get();
                    $todoOk = true;
                    $mensajes = ['Recepción procesada'];

                    if ($movimientos->isNotEmpty()) {
                        foreach ($movimientos as $mov) {
                            $recepcion = DB::table('documento_recepcion')->where('id_movimiento', $mov->id)->first();
                            if ($recepcion) {
                                $aplicar = InventarioService::procesarRecepcion($recepcion->id_movimiento, $recepcion->cantidad);
                                if ($aplicar->error) {
                                    $todoOk = false;
                                    $mensajes = [$aplicar->mensaje];
                                    break;
                                }
                            }
                        }
                    }

                    if ($todoOk) { DB::commit(); $aplicados[] = "Documento: {$documento->id} - " . implode(', ', $mensajes); }
                    else { DB::rollBack(); $errores[] = "Error en documento: {$documento->id} - " . implode(', ', $mensajes); }

                } else {
                    // Lógica General...
                    $aplicar = InventarioService::aplicarMovimiento($documento->id);
                    if ($aplicar->error) { DB::rollBack(); $errores[] = "Error en documento: {$documento->id} - {$aplicar->message}"; }
                    else { DB::commit(); $aplicados[] = "Documento: {$documento->id} - {$aplicar->message}"; }
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $errores[] = "Excepción en documento {$documento->id}: " . $e->getMessage();
            }
        }

        return response()->json([
            'code'        => !empty($errores) ? 207 : 200,
            'message'     => !empty($errores) ? 'Procesado con errores' : 'Proceso de recálculo definitivo completado.',
            'log_preparacion' => $log_preparacion,
            'errors'      => $errores,
            'aplicados'   => $aplicados,
            'aplicados_count' => count($aplicados),
            'errors_count' => count($errores),
        ], !empty($errores) ? 207 : 200);
    }
}
