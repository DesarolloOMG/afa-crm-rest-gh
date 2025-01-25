<?php

namespace App\Http\Services;

use DB;

class BitacoraService
{
    public static function insertarBitacoraValidarVenta($documento, $usuario, $mensaje) {
        DB::table("bitacora_validar_ventas")->insert([
            'id_usuario' => $usuario,
            'id_documento' => $documento,
            'mensaje' => $mensaje,
        ]);
    }
}
