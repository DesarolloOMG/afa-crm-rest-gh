<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeveloperController extends Controller
{

    public function test(Request $request): JsonResponse
    {
        $auth = json_decode($request->input('auth'));

        $impresora = DB::table("usuario")
            ->join("impresora", "usuario.id_impresora_packing", "=", "impresora.id")
            ->select("impresora.id", "impresora.servidor")
            ->where("usuario.id", $auth->id)
            ->first();

        $data = new \stdClass();
        $data->documento = '190';

        $impresion_raw = json_decode(file_get_contents("http://localhost:8001/api/guias/print/" . $data->documento . "/" . $impresora->id . "?token=" . $request->get("token")));
        $impresion = @$impresion_raw;

        return response()->json([
            'Respuesta' => $impresion
        ]);
    }

}
