<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // OPT
    public function dashboard_venta_marketplace(): JsonResponse
    {

        $inicio_mes_actual = Carbon::now()->startOfMonth();
        $inicio_mes_siguiente = (clone $inicio_mes_actual)->addMonth();
        $inicio_mes_anterior = (clone $inicio_mes_actual)->subMonth();

        $baseQuery = DB::table('documento')
            ->where('id_tipo', 2)
            ->where('status', 1);

        $ventas_mes_actual = (clone $baseQuery)
            ->whereBetween('created_at', [$inicio_mes_actual, $inicio_mes_siguiente])
            ->count();

        $ventas_mes_anterior = (clone $baseQuery)
            ->whereBetween('created_at', [$inicio_mes_anterior, $inicio_mes_actual])
            ->count();

        $diferencia_ventas_mes = $ventas_mes_actual - $ventas_mes_anterior;

        $ventas_totales = (clone $baseQuery)->count();

        $ventas_pendientes_finalizar = (clone $baseQuery)
            ->where('id_fase', '<', 6)
            ->count();

        $porcentaje_cambio = $ventas_mes_anterior > 0
            ? round(($diferencia_ventas_mes / $ventas_mes_anterior) * 100, 2)
            : null;

        return response()->json([
            'code' => 200,
            'ventas_totales' => $ventas_totales,
            'ventas_pendientes_finalizar' => $ventas_pendientes_finalizar,
            'ventas_mes_actual' => $ventas_mes_actual,
            'diferencia_ventas_mes' => $diferencia_ventas_mes,
            'porcentaje_cambio' => $porcentaje_cambio,
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
