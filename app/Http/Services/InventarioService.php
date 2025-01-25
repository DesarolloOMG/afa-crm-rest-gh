<?php

namespace App\Http\Services;

use DB;

class InventarioService
{
    public static function importarExistencias()
    {
        set_time_limit(0);

        $errores = array();
        $ids_empresas = [7, 6, 2, 8, 5]; // Array con los identificadores de las empresas

        foreach ($ids_empresas as $id_empresa) {
            $url = "http://201.7.208.53:11903/api/adminpro/Consultas/ProductosV2/" . $id_empresa;
            $productos = @json_decode(file_get_contents($url));

            foreach ($productos as $producto) {
                if($producto->tipo == 1) {
                    $modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                    if(!empty($modelo)) {
                        foreach ($producto->existencias->almacenes as $existencia) {
                            $empresa_almacen = DB::table('empresa_almacen')->where('id_erp', $existencia->almacenid)->first();
                            $existe = DB::table('modelo_inventario')->where('id_modelo', $modelo->id)->where('id_empresa_almacen', $empresa_almacen->id)->first();

                            if($existe) {
                                DB::table('modelo_inventario')->where('id_modelo', $modelo->id)->where('id_empresa_almacen', $empresa_almacen->id)->update([
                                    'existencia' => $existe->fisico
                                ]);
                            } else {
                                if(!empty($empresa_almacen)) {
                                    $mod_inv = DB::table('modelo_inventario')->insert([
                                        'id_modelo' => $modelo->id,
                                        'id_empresa_almacen' => $empresa_almacen->id,
                                        'id_erp' => $existencia->almacenid,
                                        'almacen' => $existencia->almacen,
                                        'existencia' => $existencia->fisico,
                                    ]);
                                } else {
                                    $mod_inv = DB::table('modelo_inventario')->insert([
                                        'id_modelo' => $modelo->id,
                                        'id_erp' => $existencia->almacenid,
                                        'almacen' => $existencia->almacen,
                                        'existencia' => $existencia->fisico,
                                        'comentarios' => "No se encontro el almacen en CRM"
                                    ]);
                                }

                                if(!$mod_inv) {
                                    DB::table('modelo_inventario_errores')->insert([
                                        'sku' => $producto->sku,
                                        'almacen' => $existencia->almacen,
                                        'existencia' => $existencia->fisico,
                                        'comentarios' => "Error desconocido",
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        return response()->json([
            "code" => 200,
            "message" => "Productos importados correctamente",
            "errores" => $errores
        ]);
    }

    public static function disminuirInventario($documento) {
        $movimientos = DB::table('movimiento')->where('id_documento', $documento)->get();
        $info_documento = DB::table('documento')->where('id', $documento)->first();

        if(!empty($movimientos)) {
            foreach ($movimientos as $mov) {
                $modelo = DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                    ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->first();

                if(!empty($modelo)) {
                    DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                        ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->update([
                        'stock' => $modelo->stock - $mov->cantidad
                    ]);
                }
            }
        }
    }

    public static function aumentarInventario($documento) {
        $movimientos = DB::table('movimiento')->where('id_documento', $documento)->get();
        $info_documento = DB::table('documento')->where('id', $documento)->first();

        if(!empty($movimientos)) {
            foreach ($movimientos as $mov) {
                $modelo = DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                    ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->first();

                if(!empty($modelo)) {
                    DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                        ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->update([
                            'stock' => $modelo->stock + $mov->cantidad
                        ]);
                }
            }
        }
    }

    public static function movimientoEntreAlmacen($documento)
    {
        $movimientos = DB::table('movimiento')->where('id_documento', $documento)->get();
        $info_documento = DB::table('documento')->where('id', $documento)->first();

        if(!empty($movimientos)) {
            foreach ($movimientos as $mov) {
                $modelo = DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                    ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->first();

                if(!empty($modelo)) {
                    DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                        ->where('id_empresa_almacen', $info_documento->id_almacen_principal_empresa)->update([
                            'existencia' => $modelo->existencia + $mov->cantidad
                        ]);
                }

                $modelo_salida = DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                    ->where('id_empresa_almacen', $info_documento->id_almacen_secundario_empresa)->first();

                if(!empty($modelo_salida)) {
                    DB::table('modelo_inventario')->where('id_modelo', $mov->id_modelo)
                        ->where('id_empresa_almacen', $info_documento->id_almacen_secundario_empresa)->update([
                            'existencia' => $modelo->existencia - $mov->cantidad
                        ]);
                }
            }
        }
    }
}
