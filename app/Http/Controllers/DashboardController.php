<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // OPT
    public function dashboard_venta_marketplace()
    {
        // Ejecutar el SP y obtener resultado
        $res = DB::select("CALL sp_dashboard_venta_metricas()")[0];

        // Calcular diferencia de ventas
        $diferencia_ventas_mes = (date('Y-m') == date('Y-') . "01")
            ? $res->ventas_mes_actual
            : $res->ventas_mes_actual - $res->ventas_mes_anterior;

        return response()->json([
            'code'  => 200,
            'ventas_totales' => $res->ventas_totales,
            'ventas_pendientes_finalizar' => $res->ventas_pendientes_finalizar,
            'ventas_mes_actual' => $res->ventas_mes_actual,
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
