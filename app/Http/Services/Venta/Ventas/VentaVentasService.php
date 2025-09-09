<?php

namespace App\Http\Services\Venta\Ventas;

use Illuminate\Http\JsonResponse;

class VentaVentasService
{
    public static function relacionar_pdf_xml($data, $auth): JsonResponse
    {
        return response()->json([
            $data, $auth
        ]);
    }
}