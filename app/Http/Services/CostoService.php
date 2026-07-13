<?php

namespace App\Http\Services;

use DB;

class CostoService
{
    public const USUARIOS_RECALCULO_IDS = [160, 51, 78];
    public const USUARIOS_RECALCULO_NOMBRES = [
        'ROBERTO GARCIA HERNANDEZ',
        'SAUL ADRIAN ARIAS LORETO',
        'EFREN ZAMORA GARZA',
    ];
    public const NIVEL_ALMACEN_ID = 7;
    public const SUBNIVEL_ADMINISTRADOR_ID = 1;

    /**
     * Recalcula costos para todos los modelos, uno solo o los modelos de una compra.
     */
    public static function recalcular(
        int $idModelo = 0,
        int $idDocumentoCompra = 0,
        bool $aplicar = false
    ): \stdClass {
        return self::ejecutarProcedimiento('CALL sp_recalcularCostos(?, ?, ?)', [
            $idModelo,
            $idDocumentoCompra,
            $aplicar ? 1 : 0,
        ]);
    }

    /**
     * Recibe exclusivamente documento.id de una COMPRA. El SP valida tipo y status.
     */
    public static function recalcularPorCompra(int $idDocumentoCompra): \stdClass
    {
        return self::ejecutarProcedimiento(
            'CALL sp_recalcularCostosPorCompra(?)',
            [$idDocumentoCompra]
        );
    }

    /**
     * Obtiene el costo vigente para reportes: promedio recalculado y, si no existe,
     * el costo base del modelo.
     */
    public static function obtenerCostoPromedioPorSku(string $sku): float
    {
        $modelo = DB::table('modelo as m')
            ->leftJoin('modelo_costo as mc', 'mc.id_modelo', '=', 'm.id')
            ->where('m.sku', trim($sku))
            ->selectRaw('COALESCE(NULLIF(mc.costo_promedio, 0), m.costo, 0) AS costo_promedio')
            ->first();

        return $modelo ? (float) $modelo->costo_promedio : 0.0;
    }

    /**
     * Permite Administrador de Almacen (nivel 7/subnivel 1) o los usuarios
     * autorizados expresamente para ejecutar el recalculo.
     * La validacion se realiza contra la BD y no depende solo del token/frontend.
     */
    public static function usuarioPuedeRecalcular(int $idUsuario): bool
    {
        $usuario = DB::table('usuario')
            ->select('id', 'nombre')
            ->where('id', $idUsuario)
            ->where('status', 1)
            ->first();

        if (!$usuario) {
            return false;
        }

        $nombre = mb_strtoupper(trim((string) $usuario->nombre), 'UTF-8');
        if (in_array($idUsuario, self::USUARIOS_RECALCULO_IDS, true)
            || in_array($nombre, self::USUARIOS_RECALCULO_NOMBRES, true)) {
            return true;
        }

        return DB::table('usuario_subnivel_nivel as usn')
            ->join('subnivel_nivel as sn', 'sn.id', '=', 'usn.id_subnivel_nivel')
            ->where('usn.id_usuario', $idUsuario)
            ->where('sn.id_nivel', self::NIVEL_ALMACEN_ID)
            ->where('sn.id_subnivel', self::SUBNIVEL_ADMINISTRADOR_ID)
            ->exists();
    }

    /**
     * MySQL 8 y el mysqlnd incluido con PHP 7.3 requieren prepares emulados
     * para consumir correctamente este SP; además se vacían todos los rowsets.
     */
    private static function ejecutarProcedimiento(string $sql, array $parametros): \stdClass
    {
        $pdo = DB::connection()->getPdo();
        $emulacionAnterior = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $stmt = null;

        try {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($parametros);
            $resultado = $stmt->fetchObject();

            while ($stmt->nextRowset()) {
                // Consumir los rowsets adicionales generados por CALL.
            }
            $stmt->closeCursor();

            if (!$resultado) {
                throw new \RuntimeException('El procedimiento de costos no devolvio un resultado.');
            }

            return $resultado;
        } finally {
            if ($stmt) {
                $stmt->closeCursor();
            }
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulacionAnterior);
        }
    }
}
