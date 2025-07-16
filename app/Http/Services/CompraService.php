<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;

class CompraService
{
    public static function guardarModelo($data, $auth): int
    {
        $modeloExistente = DB::table('modelo')->where('sku', trim($data->sku))->first();

        if ($data->id != 0 && $modeloExistente && $modeloExistente->id != $data->id) {
            return $data->id;
        }

        $datosModelo = [
            'id_tipo' => $data->tipo,
            'sku' => $data->sku,
            'descripcion' => $data->descripcion,
            'costo' => $data->costo,
            'alto' => $data->alto,
            'ancho' => $data->ancho,
            'largo' => $data->largo,
            'peso' => $data->peso,
            'serie' => $data->serie,
            'clave_sat' => $data->clave_sat,
            'unidad' => 'PIEZA',
            'clave_unidad' => $data->clave_unidad,
            'refurbished' => $data->refurbished,
            'np' => $data->np,
            'cat1' => $data->cat1,
            'cat2' => $data->cat2,
            'cat3' => $data->cat3,
            'cat4' => $data->cat4,
            'caducidad' => $data->caducidad,
        ];

        if ($data->id != 0) {
            $modeloId = $data->id;
            $modeloAntes = DB::table('modelo')->find($modeloId);
            DB::table('modelo')->where('id', $modeloId)->update($datosModelo);
            $modeloDespues = DB::table('modelo')->find($modeloId);

            DB::table('modelo_edits')->insert([
                'id_modelo' => $modeloId,
                'id_usuario' => $auth->id,
                'informacion_antes' => json_encode($modeloAntes),
                'informacion_despues' => json_encode($modeloDespues)
            ]);
        } else {
            if ($modeloExistente) {
                $modeloId = $modeloExistente->id;
                DB::table('modelo')->where('id', $modeloId)->update($datosModelo);
            } else {
                $modeloId = DB::table('modelo')->insertGetId($datosModelo);
            }
        }

        self::guardarAmazon($data->amazon, $modeloId);
        self::guardarImagenes($data->imagenes, $modeloId);

        return $modeloId;
    }

    public static function guardarAmazon($amazon, int $modeloId): void
    {
        $exists = DB::table('modelo_amazon')->where('id_modelo', $modeloId)->exists();

        if ($exists) {
            DB::table('modelo_amazon')->where('id_modelo', $modeloId)->update([
                'codigo' => $amazon->codigo,
                'descripcion' => $amazon->descripcion
            ]);
        } else {
            DB::table('modelo_amazon')->insert([
                'id_modelo' => $modeloId,
                'codigo' => $amazon->codigo,
                'descripcion' => $amazon->descripcion
            ]);
        }
    }

    public static function guardarImagenes(array $imagenes, int $modeloId): void
    {
        $dropboxService = new DropboxService();

        foreach ($imagenes as $archivo) {
            if (!empty($archivo->nombre) && !empty($archivo->data)) {
                $archivoData = base64_decode(preg_replace(
                    '#^data:' . $archivo->tipo . '/\w+;base64,#i',
                    '',
                    $archivo->data
                ));

                $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivoData, false);

                DB::table('modelo_imagen')->insert([
                    'id_modelo' => $modeloId,
                    'nombre' => $archivo->nombre,
                    'dropbox' => $response['id']
                ]);
            }
        }
    }

    public static function sincronizarProveedores(array $proveedores, int $modeloId): void
    {
        foreach ($proveedores as $proveedor) {
            if (!empty($proveedor->producto)) {
                DB::table('modelo_proveedor_producto')
                    ->where('id', $proveedor->producto)
                    ->update(['id_modelo' => $modeloId]);
            } else {
                DB::table('modelo_proveedor_producto')
                    ->where('id_modelo_proveedor', $proveedor->id)
                    ->where('id_modelo', $modeloId)
                    ->update(['id_modelo' => null]);
            }
        }
    }

    public static function gestionarPrecios($precioData, string $sku, int $modeloId, $auth): void
    {
        if (empty($precioData->empresa)) {
            return;
        }

        if (!empty($precioData->productos)) {
            foreach ($precioData->productos as $producto) {
                $modelo = DB::table('modelo')->where('sku', $producto->codigo)->first();
                if (!$modelo) continue;

                $existente = DB::table('modelo_precio')
                    ->where('id_modelo', $modelo->id)
                    ->where('id_empresa', $precioData->empresa)
                    ->first();

                if ($existente) {
                    DB::table('modelo_precio')
                        ->where('id', $existente->id)
                        ->update(['precio' => $producto->precio]);

                    DB::table('modelo_precio_updates')->insert([
                        'id_modelo_precio' => $existente->id,
                        'id_usuario' => $auth->id,
                        'precio_anterior' => $existente->precio,
                        'precio_actualizado' => $producto->precio
                    ]);
                } else {
                    DB::table('modelo_precio')->insert([
                        'id_usuario' => $auth->id,
                        'id_modelo' => $modelo->id,
                        'id_empresa' => $precioData->empresa,
                        'precio' => $producto->precio
                    ]);
                }
            }
        } else {
            $modeloId = $modeloId ?: DB::table('modelo')->where('sku', $sku)->value('id');
            if (!$modeloId) return;

            $existente = DB::table('modelo_precio')
                ->where('id_modelo', $modeloId)
                ->where('id_empresa', $precioData->empresa)
                ->first();

            if ($existente) {
                DB::table('modelo_precio')
                    ->where('id', $existente->id)
                    ->update(['precio' => $precioData->precio]);

                DB::table('modelo_precio_updates')->insert([
                    'id_modelo_precio' => $existente->id,
                    'id_usuario' => $auth->id,
                    'precio_anterior' => $existente->precio,
                    'precio_actualizado' => $precioData->precio
                ]);
            } else {
                DB::table('modelo_precio')->insert([
                    'id_usuario' => $auth->id,
                    'id_modelo' => $modeloId,
                    'id_empresa' => $precioData->empresa,
                    'precio' => $precioData->precio
                ]);
            }
        }
    }
}
