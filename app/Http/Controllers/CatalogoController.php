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
}
