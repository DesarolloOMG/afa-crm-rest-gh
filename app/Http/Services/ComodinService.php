<?php

namespace App\Http\Services;

use DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class ComodinService
{
    public static function logistica_envio_pendiente_documento($documento, $marketplace, $zpl = 0)
    {
        $marketplace_data = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_api.extra_2,
                                            marketplace_api.extra_1,
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = " . $marketplace . "")[0];

        $informacion = DB::select("SELECT
                                documento.no_venta,
                                paqueteria.paqueteria
                            FROM documento
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            WHERE documento.id = " . $documento . "")[0];

        switch (strtolower($marketplace_data->marketplace)) {
            case 'mercadolibre externo':
            case 'mercadolibre':
                $response = $zpl ? MercadolibreService::documentoZPL($informacion->no_venta, $marketplace_data) : MercadolibreService::documento($informacion->no_venta, $marketplace_data);

                break;

            case 'linio 2':
            case 'linio':
                try {
                    $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
                } catch (DecryptException $e) {
                    $marketplace_data->secret = "";
                }

                $response = LinioService::documento($informacion->no_venta, $marketplace_data);
                break;

            //!! RELEASE T1 reempalzar

            case 'claroshop':
            case 'sears':
            case 'sanborns':
                $response = ClaroshopServiceV2::documento($informacion->no_venta, $marketplace_data);
                break;

            // case 'claroshop':
            // case 'sears':
            //     try {
            //         $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
            //     } catch (DecryptException $e) {
            //         $marketplace_data->secret = "";
            //     }

            //     $response = ClaroshopService::documento($informacion->no_venta, $marketplace_data, strtolower($informacion->paqueteria));
            //     break;

            case 'walmart':
                $response = WalmartService::documento($documento, $marketplace_data->id);
                break;

            case 'coppel':
                $response = CoppelService::documento($documento, $marketplace_data->id);
                break;

            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador.<br/> Error: LC249";

                break;
        }

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje,
                'raw'   => property_exists($response, 'raw') ? $response->raw : ''
            ]);
        }

        return response()->json([
            'code' => 200,
            'file' => $response->file,
            'pdf' => property_exists($response, 'pdf') ? $response->pdf : 1
        ]);
    }

    public static function importar_sinonimos($data) {
        foreach ($data as $skus) {
            $modelo = DB::table("modelo")->where("sku", $skus->sku)->first();

            if (!empty($modelo)) {
                DB::table("modelo_sinonimo")->insert([
                    'id_usuario' => 1,
                    'id_modelo' => $modelo->id,
                    'codigo' => $skus->codigo,
                ]);
            }
        }
    }

    public static function insertar_seguimiento($documento, $mensaje) {
        DB::table("seguimiento")->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => $mensaje,
        ]);
    }
}
