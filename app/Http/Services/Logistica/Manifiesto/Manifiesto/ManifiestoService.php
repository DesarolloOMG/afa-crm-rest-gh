<?php

namespace App\Http\Services\Logistica\Manifiesto\Manifiesto;

use App\Http\Controllers\LogisticaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ManifiestoService
{
    public static function data(): JsonResponse
    {
        $shipment = DB::table('paqueteria')
            ->select("id", "paqueteria")
            ->orderBy('paqueteria')
            ->get()
            ->toArray();

        $printers = DB::table("impresora")
            ->select("id", "nombre")
            ->where("tamanio", "continua")
            ->get()
            ->toArray();

        $labels = LogisticaController::manifiesto_guias_raw_data("salida = 0 AND impreso = 0");

        return response()->json([
            'labels' => $labels,
            'printers' => $printers,
            'shipment' => $shipment
        ]);
    }

    public static function agregar($data): JsonResponse
    {
        $exits = DB::table("manifiesto")
            ->where("guia", $data->label)
            ->first();

        if ($exits) {
            if ($exits->manifiesto == date("dmY")) {
                return response()->json([
                    "message" => "La guía ya se encuentra en el manifiesto"
                ], 500);
            }

            DB::table('manifiesto')->where("guia", $data->label)->update([
                'id_impresora' => $data->printer,
                'manifiesto' => date('dmY'),
                'salida' => 0,
                'impreso' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'id_paqueteria' => $data->shipment
            ]);
        } else {
            DB::table('manifiesto')->insert([
                'id_impresora' => $data->printer,
                'manifiesto' => date('dmY'),
                'guia' => trim($data->label),
                'id_paqueteria' => $data->shipment
            ]);
        }

        $label_data = LogisticaController::manifiesto_guias_raw_data("manifiesto.salida = 0 AND manifiesto.impreso = 0 AND manifiesto.guia = '" . $data->label . "'");

        return response()->json([
            "label" => $label_data
        ]);
    }

    public static function eliminar($data): JsonResponse
    {
        DB::table('manifiesto')->where(['guia' => trim($data)])->delete();

        return response()->json([
            'message' => "Guía eliminada correctamente."
        ]);
    }
}