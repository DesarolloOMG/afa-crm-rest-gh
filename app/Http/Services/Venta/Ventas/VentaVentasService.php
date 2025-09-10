<?php

namespace App\Http\Services\Venta\Ventas;

use Exception;
use Illuminate\Http\JsonResponse;

class VentaVentasService
{
    public static function relacionar_pdf_xml($data, $auth): JsonResponse
    {

//        $id_documento = $data->documento;
//        $uuid = $data->uuid;
//        $pdf = $data->pdf;
//        $xml = $data->xml;
//
//        try {
//            DB::beginTransaction();
//
//            DB::table('documento')->where('id_documento', $id_documento)
//                ->update([
//                    'uuid' => $uuid,
//                ]);
//
//            DB::table('documento_updates_by')->where('id_documento', $id_documento)
//                ->update([
//                    'id_documento' => documento,
//                    'id_usuario' => $auth->id,
//                ]);
//
//            DB::commit();
//
//        } catch (Exception $e) {
//            DB::rollBack();
//            return response()->json([
//                'error' => $e->getMessage()
//            ], 500);
//        }

        return response()->json([
            $data, $auth
        ]);
    }
}