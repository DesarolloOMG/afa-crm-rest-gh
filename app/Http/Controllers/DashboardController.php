<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DateTime;
use DB;

class DashboardController extends Controller
{
    public function dashboard_venta_marketplace()
    {
        $diferencia_ventas_mes = 0;

        $mes_actual = date('Y-m');
        $mes_anterior = date('Y-m', strtotime('first day of last month'));

        $ventas_mes_anterior = DB::select("SELECT COUNT(*) AS cantidad FROM documento WHERE id_tipo = 2 AND status = 1 AND created_at LIKE '%" . $mes_anterior . "%'")[0]->cantidad;
        $ventas_mes_actual = DB::select("SELECT COUNT(*) AS cantidad FROM documento WHERE id_tipo = 2 AND status = 1 AND created_at LIKE '%" . $mes_actual . "%'")[0]->cantidad;

        if ($mes_actual == date('Y-') . "01") {
            $diferencia_ventas_mes = $ventas_mes_actual;
        } else {
            $diferencia_ventas_mes = $ventas_mes_actual - $ventas_mes_anterior;
        }

        $ventas_totales = DB::select("SELECT COUNT(*) as cantidad FROM documento WHERE id_tipo = 2 AND status = 1")[0]->cantidad;
        $ventas_pendientes_finalizar = DB::select("SELECT COUNT(*) as cantidad FROM documento WHERE id_tipo = 2 AND status = 1 AND id_fase < 6")[0]->cantidad;

        return response()->json([
            'code'  => 200,
            'ventas_totales'    => $ventas_totales,
            'ventas_pendientes_finalizar'   => $ventas_pendientes_finalizar,
            'ventas_mes_actual' => $ventas_mes_actual,
            'diferencia_ventas_mes' => $diferencia_ventas_mes
        ]);
    }

    public static function subnivel_nivel($userid)
    {
        $subniveles = DB::table('usuario_subnivel_nivel')
            ->where('id_usuario', $userid)
            ->get()
            ->pluck('id_subnivel_nivel')
            ->toArray();

        return $subniveles;
    }
}
