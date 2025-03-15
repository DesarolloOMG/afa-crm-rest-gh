<?php

namespace App\Http\Services;

use DB;

class InventarioService
{
    /**
     * Agrega o actualiza los productos al inventario
     *
     * @param int $id_modelo
     * @param int $id_empresa_almacen
     * @param int $existencia
     * @param int $fisico
     * @param string $comentarios
     * @return array   [code, message]
     */
    public static function agregarAInventario(int $id_modelo, int $id_empresa_almacen, int $existencia, int $fisico, string $comentarios, int $id_documento = null)
    {
        if($id_documento == null) {
            $id = DB::table('modelo_inventario')->insertGetId([
                'id_modelo' => $id_modelo,
                'id_empresa_almacen' => $id_empresa_almacen,
                'existencia' => $existencia,
                'fisico' => $fisico,
                'comentarios' => $comentarios,
            ]);
        } else {
            self::aplicarMovimientoInventario($id_documento);
        }
        return ['code' => 200, 'message' => 'OK'];
    }

    public static function aplicarMovimientoInventario($idDocumento)
    {
        // 1. Obtenemos la información del documento
        $infoDocumento = DB::table('documento')->where('id', $idDocumento)->first();
        if (!$infoDocumento) {
            // Manejar el caso de documento no encontrado
            return ['code' => 404, 'message' => 'Documento no encontrado.'];
        }

        // Suponiendo que en la misma tabla `documento` vienen las banderas:
        // $infoDocumento->sumaInventario, $infoDocumento->restaInventario
        // o bien podrías obtener el tipo con:
        $tipoDoc = DB::table('documento_tipo')->where('id', $infoDocumento->id_tipo)->first();

        // 2. Obtenemos todos los movimientos asociados al documento
        $movimientos = DB::table('movimiento')
            ->where('id_documento', $idDocumento)
            ->get();

        // 3. Iteramos sobre los movimientos
        foreach ($movimientos as $mov) {

            // 3.1. Buscamos si ya existe el modelo_inventario en el almacén principal
            $modeloInventarioPrincipal = DB::table('modelo_inventario')
                ->where('id_modelo', $mov->id_modelo)
                ->where('id_empresa_almacen', $infoDocumento->id_almacen_principal_empresa)
                ->first();

            // 3.2. Según la configuración del documento, hacemos la lógica
            $suma = $tipoDoc->sumaInventario == 1;
            $resta = $tipoDoc->restaInventario == 1;

            if ($suma && !$resta) {
                // 3.2.1. Sumar inventario en el almacén principal
                DB::table('modelo_inventario')
                    ->where('id', $modeloInventarioPrincipal->id)
                    ->update([
                        'existencia' => $modeloInventarioPrincipal->existencia + $mov->cantidad
                    ]);
            } elseif (!$suma && $resta) {
                // 3.3.2. Restar inventario en el almacén principal
                DB::table('modelo_inventario')
                    ->where('id', $modeloInventarioPrincipal->id)
                    ->update([
                        'existencia' => $modeloInventarioPrincipal->existencia - $mov->cantidad
                    ]);
            } elseif ($suma && $resta) {
                // 3.3.3. Es un TRASPASO (suma y resta)

                // A) SUMAR en el almacén principal
                DB::table('modelo_inventario')
                    ->where('id', $modeloInventarioPrincipal->id)
                    ->update([
                        'existencia' => $modeloInventarioPrincipal->existencia + $mov->cantidad
                    ]);

                // B) RESTAR en el almacén secundario
                //   Primero buscamos si existe en el almacén secundario
                $modeloInventarioSecundario = DB::table('modelo_inventario')
                    ->where('id_modelo', $mov->id_modelo)
                    ->where('id_empresa_almacen', $infoDocumento->id_almacen_secundario_empresa)
                    ->first();

                if (!$modeloInventarioSecundario) {
                    // Si no existe, lo creamos en el secundario
                    $idNuevoSec = DB::table('modelo_inventario')->insertGetId([
                        'id_modelo'           => $mov->id_modelo,
                        'id_empresa_almacen'  => $infoDocumento->id_almacen_secundario_empresa,
                        'existencia'          => 0,
                        'fisico'              => 0,
                        'comentarios'         => 'Creado automáticamente (almacén secundario)',
                    ]);
                    $modeloInventarioSecundario = DB::table('modelo_inventario')->where('id', $idNuevoSec)->first();
                }

                // Actualizamos restando la cantidad
                DB::table('modelo_inventario')
                    ->where('id', $modeloInventarioSecundario->id)
                    ->update([
                        'existencia' => $modeloInventarioSecundario->existencia - $mov->cantidad
                    ]);
            }
        }
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
