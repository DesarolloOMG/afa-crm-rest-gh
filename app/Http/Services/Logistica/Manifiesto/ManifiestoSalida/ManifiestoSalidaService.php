<?php

namespace App\Http\Services\Logistica\Manifiesto\ManifiestoSalida;

use App\Http\Controllers\LogisticaController;
use App\Http\Controllers\PrintController;
use App\Http\Services\CorreoService;
use App\Http\Services\MercadolibreService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ManifiestoSalidaService
{
    public static function data(): JsonResponse
    {
        $printers = DB::table("impresora")
            ->select("id", "nombre", "servidor", "ip")
            ->where("tamanio", "continua")
            ->get()
            ->toArray();

        $labels = LogisticaController::manifiesto_guias_raw_data("salida = 1 AND impreso = 0");

        $shipping_providers = DB::table("paqueteria")
            ->select("id", "paqueteria")
            ->where("manifiesto", 1)
            ->orderBy("paqueteria")
            ->get()
            ->toArray();

        return response()->json([
            'labels' => $labels,
            'printers' => $printers,
            'shipping_providers' => $shipping_providers,
        ]);
    }

    public static function agregar($guia, $paqueteria): JsonResponse
    {
        $manifiesto = DB::table("manifiesto")
            ->where("guia", $guia)
            ->first();

        if (!$manifiesto) {
            return response()->json([
                'message' => "La guía no fue encontrada en el manifiesto, favor de agregarla y luego agregar su salida."
            ], 400);
        }

        $hoy = date('dmY');
        if ($manifiesto->manifiesto != $hoy) {
            DB::table('manifiesto')
                ->where("guia", $guia)
                ->update([
                    'manifiesto' => $hoy,
                    'salida' => 0,
                    'impreso' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
        }

        $documentoGuia = DB::table('documento_guia')->where('guia', $guia)->first();
        if ($documentoGuia) {
            $documento = DB::table('documento')->where('id', $documentoGuia->id_documento)->first();

            if ($documento && $documento->id_marketplace_area == 1) {
                $informacion = MercadolibreService::venta($documento->no_venta, $documento->id_marketplace_area);

                if ($informacion->error) {
                    return response()->json([
                        'message' => "No se encontró la guía en el Marketplace."
                    ], 500);
                }

                if (empty($informacion->data)) {
                    return response()->json([
                        'message' => "No hay información de la venta en el Marketplace."
                    ], 500);
                }

                $venta = $informacion->data[0];
                if ($venta->status === "cancelled") {
                    DB::table('manifiesto')->where('guia', $guia)->delete();

                    return response()->json([
                        'message' => "La guía no se encuentra activa, NO SURTIR. Guía quitada del manifiesto. Favor de cancelar el Pedido " . $documento->id
                    ], 500);
                }
            }
        }

        if ($manifiesto->salida == 1) {
            return response()->json([
                'message' => "La guía ya está marcada para su salida."
            ], 500);
        }

        DB::table('manifiesto')
            ->where('guia', $guia)
            ->update([
                'salida' => 1,
                'id_paqueteria' => $paqueteria
            ]);

        $labelData = LogisticaController::manifiesto_guias_raw_data(
            "manifiesto.salida = 1 AND manifiesto.impreso = 0 AND manifiesto.guia = '" . $guia . "'"
        );

        return response()->json([
            'label' => $labelData
        ]);
    }

    /**
     * @throws Throwable
     */
    public static function imprimir($data, $request): JsonResponse
    {
        $guias_paqueteria = [];

        $impresora = DB::table('impresora')
            ->where('ip', $data->printer)
            ->first();

        $tipo = $data->type;

        if ($tipo == 1 || $tipo == '1' || $tipo == 2 || $tipo == '2') {
            $impreso = ($tipo == 2 || $tipo == '2') ? 1 : 0;

            $guias = DB::table('manifiesto')
                ->join('paqueteria', 'manifiesto.id_paqueteria', '=', 'paqueteria.id')
                ->select('manifiesto.id', 'manifiesto.guia', 'paqueteria.paqueteria')
                ->where('manifiesto.manifiesto', date('dmY'))
                ->where('manifiesto.salida', 1)
                ->where('manifiesto.impreso', $impreso)
                ->where('manifiesto.id_paqueteria', $data->shipping_provider->id)
                ->where('manifiesto.id_impresora', $impresora->id)
                ->get();
        }

        if (empty($guias)) {
            return response()->json([
                'code' => 500,
                'message' => 'No hay guias agregadas al manifiesto de salida.',
            ]);
        }

        foreach ($guias as $g) {
            $guias_paqueteria[] = $g->guia;
        }

        try {
            $array = [
                'data' => json_encode([
                    "shipping_provider" => $data->shipping_provider->paqueteria,
                    "printer" => $data->printer,
                ]),
                'impresion_reimpresion' => $data->type,
            ];

            $impresion_raw = (new PrintController)->manifiestoSalida($array, $request);
            $impresion = @$impresion_raw;

        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => "Ocurrió un error al imprimir el manifiesto de salida. Error: " . $e->getMessage()
            ]);
        }

        CorreoService::enviarManifiesto(
            $guias_paqueteria,
            1,
            $data->shipping_provider->paqueteria
        );

        return response()->json([
            'guias' => $guias_paqueteria,
            'impresion' => $impresion,
            'code' => 200,
            'message' => 'Manifiesto de salida impreso correctamente.'
        ]);
    }

}