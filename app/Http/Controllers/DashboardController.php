<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function dashboard_venta_marketplace(): JsonResponse
    {
        $inicio_mes_actual = date('Y-m-01 00:00:00');
        $inicio_mes_siguiente = date('Y-m-01 00:00:00', strtotime('+1 month'));
        $inicio_mes_anterior = date('Y-m-01 00:00:00', strtotime('-1 month'));

        $ventas_mes_actual = DB::table('documento')
            ->where('id_tipo', 2)
            ->where('status', 1)
            ->whereBetween('created_at', [$inicio_mes_actual, $inicio_mes_siguiente])
            ->count();

        $ventas_mes_anterior = DB::table('documento')
            ->where('id_tipo', 2)
            ->where('status', 1)
            ->whereBetween('created_at', [$inicio_mes_anterior, $inicio_mes_actual])
            ->count();

        $diferencia_ventas_mes = $ventas_mes_actual - $ventas_mes_anterior;

        $ventas_totales = DB::table('documento')
            ->where('id_tipo', 2)
            ->where('status', 1)
            ->count();

        $ventas_pendientes_finalizar = DB::table('documento')
            ->where('id_tipo', 2)
            ->where('status', 1)
            ->where('id_fase', '<', 6)
            ->count();

        return response()->json([
            'code' => 200,
            'ventas_totales' => $ventas_totales,
            'ventas_pendientes_finalizar' => $ventas_pendientes_finalizar,
            'ventas_mes_actual' => $ventas_mes_actual,
            'diferencia_ventas_mes' => $diferencia_ventas_mes
        ]);
    }


    public static function subnivel_nivel($userid): array
    {
        return DB::table('usuario_subnivel_nivel')
            ->where('id_usuario', $userid)
            ->get()
            ->pluck('id_subnivel_nivel')
            ->toArray();
    }
}
