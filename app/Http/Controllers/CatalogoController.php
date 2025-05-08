<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    public function buscar_CP($cp)
    {
        $existe_codigo = DB::table("cat_codigo_postal")->where("codigo_postal", $cp)
            ->select('codigo_postal', 'tipo_asentamiento', 'colonia', 'estado', 'municipio', 'ciudad')->get();

        if($existe_codigo){
            return response()->json([
                'code'  => 200,
                'message' => "Codigo postal encontrado.",
                'data' => $existe_codigo
            ]);
        } else {
            return response()->json([
                'code'  => 404,
                'message' => "Codigo postal no encontrado."
            ]);
        }
    }

    public function buscar_producto(Request $request)
    {
        $criterio = $request->input('criterio');

        $existe_modelo = DB::table('modelo')->where('sku', $criterio)->orWhere('descripcion', 'like', '%' . $criterio . '%')->get();

        if($existe_modelo){
            return response()->json([
                'code'  => 200,
                'message' => "Producto encontrado.",
                'data' => $existe_modelo
            ]);
        } else {
            return response()->json([
                'code'  => 404,
                'message' => "Producto no encontrado."
            ]);
        }
    }
}
