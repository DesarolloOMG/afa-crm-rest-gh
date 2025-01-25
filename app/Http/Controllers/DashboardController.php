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

    public function dashboard_ticket_guardar(Request $request)
    {
        $index = 1;
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $ticketID = DB::table("ticket")->insertGetId([
            "id_usuario" => $auth->id,
            "titulo" => $data->titulo,
            "descripcion" => $data->descripcion,
            "contacto_metodo" => $data->metodo,
            "contacto" => $data->contacto,
        ]);

        foreach ($data->archivos as $key) {
            DB::table("ticket")->where('id', $ticketID)->update([
                "evidencia" . $index => empty($data->archivos) ? "" : $key->data
            ]);
            $index++;
        }

        DB::table("ticket_seguimiento")->insert([
            "id_ticket" => $ticketID,
            "id_usuario" => 1,
            "descripcion" => 'Ticket creado en el sistema'
        ]);


        return response()->json([
            "code" => 200,
            "message" => "Ticket generado correctamente"
        ]);
    }

    public function dashboard_ticket_get_data(Request $request)
    {
        $auth = json_decode($request->auth);
        $esAdministrador = json_decode($request->input("data"));
        $admins = json_decode($request->input("admins"));

        if ($esAdministrador) {
            $abiertos =
                DB::table('ticket')
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'ticket.*')
                ->whereIn('ticket.status', [1, 2])
                ->get()
                ->toArray();

            $cerrados = DB::table("ticket")
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'ticket.*')
                ->where("ticket.status", 3)
                ->get()
                ->toArray();

            $eliminados = DB::table("ticket")
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->leftJoin('usuario as tbl4', 'tbl4.id', '=', 'ticket.deleted_by')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'tbl4.nombre as i_eliminado', 'ticket.*')
                ->where("ticket.status", 0)
                ->get()
                ->toArray();
        } else {
            $abiertos =
                DB::table('ticket')
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'ticket.*')
                ->where("ticket.id_usuario", $auth->id)
                ->whereIn('ticket.status', [1, 2])
                ->get()
                ->toArray();

            $cerrados = DB::table("ticket")
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'ticket.*')
                ->where("ticket.id_usuario", $auth->id)
                ->where("ticket.status", 3)
                ->get()
                ->toArray();

            $eliminados = DB::table("ticket")
                ->leftJoin('usuario as tbl1', 'tbl1.id', '=', 'ticket.id_asignado')
                ->leftJoin('usuario as tbl2', 'tbl2.id', '=', 'ticket.finished_by')
                ->leftJoin('usuario as tbl3', 'tbl3.id', '=', 'ticket.id_usuario')
                ->leftJoin('usuario as tbl4', 'tbl4.id', '=', 'ticket.deleted_by')
                ->select('tbl1.nombre as i_asignado', 'tbl2.nombre as i_cerrado', 'tbl3.nombre as i_creado', 'tbl4.nombre as i_eliminado', 'ticket.*')
                ->where("ticket.id_usuario", $auth->id)
                ->where("ticket.status", 0)
                ->get()
                ->toArray();
        }

        $admin = DB::table("usuario")->select('id', 'nombre')->whereIn('id', $admins)->get()->toArray();

        if (empty($abiertos) && empty($cerrados) && empty($eliminados)) {
            return response()->json([
                "code" => 1,
                "mensaje" => 'No hay datos para mostrar en ninguna tabla',
                "administrador" => $esAdministrador,
                "admin" => $admin,
            ]);
        }
        return response()->json([
            "code" => 200,
            "abiertos" => $abiertos,
            "cerrados" => $cerrados,
            "eliminados" => $eliminados,
            "administrador" => $esAdministrador,
            "admin" => $admin,
        ]);
    }

    public function dashboard_ticket_get_seguimientos(Request $request)
    {

        $data = json_decode($request->input("data"));
        //buscar los usuarios que vienen en los seguimientos

        $seguimientos =
            DB::table('ticket_seguimiento')
            ->select('usuario.nombre', 'usuario.imagen AS avatar', 'ticket_seguimiento.*')
            ->join('usuario', 'usuario.id', '=', 'ticket_seguimiento.id_usuario')
            ->where("id_ticket", $data)
            ->get()
            ->toArray();

        return response()->json([
            "code" => '200',
            "data" => $seguimientos,
        ]);
    }

    public function dashboard_ticket_seguimiento(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $seguimiento = DB::table("ticket_seguimiento")->insertGetId([
            "id_ticket" => $data->id,
            "id_usuario" => $auth->id,
            "descripcion" => $data->descripcion
        ]);

        if ($data->archivo != '') {
            DB::table("ticket_seguimiento")
                ->where("id", $seguimiento)
                ->update([
                    "imagen" => $data->archivo
                ]);
        }

        return response()->json([
            "code" => '200',
            "data" => $data,
            "auth" => $auth,
        ]);
    }

    public function dashboard_ticket_terminar($ticket, Request $request)
    {
        $auth = json_decode($request->auth);

        DB::table("ticket")->where("id", $ticket)->update([
            "status" => 3,
            "finished_by" => $auth->id,
            "finished_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ]);

        DB::table("ticket_seguimiento")->insert([
            "id_ticket" => $ticket,
            "id_usuario" => 1,
            "descripcion" => 'Ticket cerrado por el usuario: ' . $auth->nombre
        ]);

        return response()->json([
            "code" => 200
        ]);
    }

    public function dashboard_ticket_eliminar($ticket, Request $request)
    {

        $auth = json_decode($request->auth);

        DB::table("ticket")->where("id", $ticket)->update([
            "status" => 0,
            "deleted_by" => $auth->id,
            "deleted_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ]);

        DB::table("ticket_seguimiento")->insert([
            "id_ticket" => $ticket,
            "id_usuario" => 1,
            "descripcion" => 'Ticket eliminado por el usuario: ' . $auth->nombre
        ]);

        return response()->json([
            "code" => 200
        ]);
    }

    public function dashboard_ticket_actualizar($ticket, Request $request)
    {

        $auth = json_decode($request->auth);
        $data = json_decode($request->input("data"));


        DB::table("ticket")->where("id", $ticket)->update([
            "status" => $data->estatus,
            "sla" => $data->sla,
            "id_asignado" => $data->asignado,
            "updated_at" => date("Y-m-d H:i:s")
        ]);


        DB::table("ticket_seguimiento")->insert([
            "id_ticket" => $ticket,
            "id_usuario" => 1,
            "descripcion" => 'Estatus cambiado por el usuario: ' . $auth->nombre,
        ]);

        return response()->json([
            "code" => 200
        ]);
    }

    public function dashboard_ticket_abrir($ticket, Request $request)
    {

        $auth = json_decode($request->auth);

        DB::table("ticket")->where("id", $ticket)->update([
            "status" => 1,
            "updated_at" => date("Y-m-d H:i:s")
        ]);

        DB::table("ticket_seguimiento")->insert([
            "id_ticket" => $ticket,
            "id_usuario" => 1,
            "descripcion" => 'Ticket se vuelve a abrir por el usuario: ' . $auth->nombre
        ]);

        return response()->json([
            "code" => 200
        ]);
    }

    public function dashboard_cliente_proveedor_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $es_admin = DB::table("usuario_subnivel_nivel")
            ->select("usuario_subnivel_nivel.id")
            ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
            ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
            ->where("subnivel_nivel.id_nivel", 6)
            ->first();

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.bd", "empresa.empresa")
            ->join("usuario_empresa", "empresa.id", "=", "usuario_empresa.id_empresa")
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->where("empresa.id", "<>", 5)
            ->get()
            ->toArray();

        if (empty($es_admin)) {
            $empresas = DB::table("empresa")
                ->select("empresa.id", "empresa.bd", "empresa.empresa")
                ->where("empresa.id", "<>", 5)
                ->get()
                ->toArray();
        }

        return response()->json([
            "code" => 200,
            "empresas" => $empresas
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
