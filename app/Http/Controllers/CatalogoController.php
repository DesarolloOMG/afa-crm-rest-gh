<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    public function buscar_CP($cp)
    {
        $existe_codigo = DB::table("cat_codigo_postal")
            ->where("codigo_postal", $cp)
            ->select('codigo_postal', 'tipo_asentamiento', 'colonia', 'estado', 'municipio', 'ciudad')
            ->get();

        if ($existe_codigo && count($existe_codigo) > 0) {
            // Sacar valores únicos para colonias, ciudades y municipios
            $colonias = $existe_codigo->pluck('colonia')->unique()->values();
            $ciudades = $existe_codigo->pluck('ciudad')->unique()->values();
            $municipios = $existe_codigo->pluck('municipio')->unique()->values();
            $estado = $existe_codigo[0]->estado; // todos tienen el mismo estado en teoría

            return response()->json([
                'code'      => 200,
                'cp'        => $cp,
                'colonias'  => $colonias,
                'ciudades'  => $ciudades,
                'municipios'=> $municipios,
                'estado'    => $estado,
                'message'   => "Codigo postal encontrado."
            ]);
        } else {
            return response()->json([
                'code'    => 404,
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
