<?php

namespace App\Http\Controllers;

use App\Models\Enums\TicketEstado;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use RuntimeException;

use App\Events\PusherEvent;
use App\Models\Ticket;
use App\Models\TicketArchivo;
use App\Models\Usuario;

use App\Http\Services\DropboxService;
use App\Http\Services\WhatsAppService;

class TicketController extends Controller
{
    protected $dropbox;
    protected $whatsapp;

    public function __construct(DropboxService $dropbox, WhatsAppService $whatsappService)
    {
        $this->dropbox = $dropbox;
        $this->whatsapp = $whatsappService;
    }

    public function ticket_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $tickets_abiertos = Ticket::where('creado_por', $auth->id)
            ->whereNotIn('estado', ['resuelto', 'cerrado'])
            ->get()
            ->count();

        if ($tickets_abiertos == 5) {
            return response()->json([
                "message" => "Ya alcanzaste el limite de tickets abiertos (5), espera a que se cierren los anteriores para crear uno nuevo"
            ]);
        }

        $ticket = Ticket::create([
            'titulo' => $data->titulo,
            'descripcion' => $data->descripcion,
            'estado' => 'nuevo',
            'creado_por' => $auth->id
        ]);

        foreach ($data->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $response = $this->dropbox->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                if (!empty($response['error'])) {
                    throw new RuntimeException('Dropbox: ' . ($response['message'] ?? 'Error'));
                }

                TicketArchivo::create([
                    'id_ticket' => $ticket->id,
                    'dropbox' => $response['id'],
                    'nombre' => $archivo->nombre
                ]);
            }
        }

        $creador = Usuario::find($auth->id);

        $asignadores = DB::table("usuario")
                        ->join("usuario_subnivel_nivel", "usuario.id", "usuario_subnivel_nivel.id_usuario")
                        ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "subnivel_nivel.id")
                        ->join("subnivel", "subnivel_nivel.id_subnivel", "subnivel.id")
                        ->where("subnivel.subnivel", "TICKETS")
                        ->where("usuario.status", 1)
                        ->get();

        if ($asignadores->count() > 0) {
            foreach ($asignadores as $asignador) {
                $this->whatsapp->send_whatsapp_ticket_notification($asignador->celular, $creador->nombre);
            }
        }

        return response()->json([
            "message" => "Ticket creado exitosamente con el ID $ticket->id"
        ]);
    }

    public function ticket_tecnicos(Request $request)
    {
        $tecnicos = DB::table("usuario")
                        ->join("usuario_subnivel_nivel", "usuario.id", "usuario_subnivel_nivel.id_usuario")
                        ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "subnivel_nivel.id")
                        ->join("subnivel", "subnivel_nivel.id_subnivel", "subnivel.id")
                        ->where("subnivel.subnivel", "SISTEMA")
                        ->where("usuario.status", 1)
                        ->select("usuario.id", "usuario.nombre")
                        ->get();

        return response()->json(
            $tecnicos
        );
    }

    public function ticket_informacion_por_estado(Request $request) {
        $estado = $request->input('data');
        $auth = json_decode($request->auth);

        $query = Ticket::query()->with('archivos', 'creador', 'tecnico');

        if ($estado) {
            $query->where('estado', $estado);

            if ($estado == TicketEstado::ASIGNADO || $estado == TicketEstado::EN_REVISION) {
                $query->where('asignado_a', $auth->id);
            }
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->get()
        );
    }

    public function ticket_asignar(Request $request) {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $ticket = Ticket::find($data->id);

        if (!$ticket) {
            return response()->json([
                "message" => "Ticket no encontrado"
            ], 404);
        }

        $informacion_usuario = Usuario::find($auth->id);

        $ticket->asignado_a = $data->asignado_a;
        $ticket->asignado_por = $auth->id;
        $ticket->assigned_at = date('Y-m-d H:i:s');
        $ticket->estado = 'asignado';
        $ticket->save();

        # Notificacion para el usuario
        $notificacion_usuario['titulo'] = "Actualización de tu ticket";
        $notificacion_usuario['message'] = "Tu ticket $ticket->id ha sido asignado a un técnico.";
        $notificacion_usuario['tipo'] = "success"; // success, warning, danger
        $notificacion_usuario['link'] = "/ticket/historial";

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data' => json_encode($notificacion_usuario)
        ]);

        $notificacion_usuario['id'] = $notificacion_id;

        DB::table('notificacion_usuario')->insert([
            'id_usuario' => $ticket->creado_por,
            'id_notificacion' => $notificacion_id
        ]);

        $notificacion_usuario['usuario'] = $ticket->creado_por;

        event(new PusherEvent(json_encode($notificacion_usuario)));

        # Notificacion para el tecnico
        $notificacion_tecnico['titulo'] = "Tienes un nuevo ticket";
        $notificacion_tecnico['message'] = "El usuario $informacion_usuario->nombre te asignó un ticket.";
        $notificacion_tecnico['tipo'] = "success"; // success, warning, danger
        $notificacion_tecnico['link'] = "/ticket/pendiente-resolucion";

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data' => json_encode($notificacion_tecnico)
        ]);

        $notificacion_tecnico['id'] = $notificacion_id;

        DB::table('notificacion_usuario')->insert([
            'id_usuario' => $ticket->asignado_a,
            'id_notificacion' => $notificacion_id
        ]);

        $notificacion_tecnico['usuario'] = $ticket->asignado_a;

        event(new PusherEvent(json_encode($notificacion_tecnico)));

        $informacion_tecnico = Usuario::find($ticket->asignado_a);

        $this->whatsapp->send_whatsapp_ticket_notification($informacion_tecnico->celular, $informacion_usuario->nombre);

        return response()->json([
            "message" => "Ticket asignado exitosamente"
        ]);
    }

    public function ticket_iniciar_revision(Request $request) {
        try {
            $data = json_decode($request->input('data'));
            $auth = json_decode($request->auth);

            $ticket = Ticket::find($data->id);

            if (!$ticket) {
                return response()->json([
                    "message" => "Ticket no encontrado"
                ], 404);
            }

            $ticket->estado = 'en_revision';
            $ticket->started_at = date('Y-m-d H:i:s');
            $ticket->save();

            return response()->json([
                "message" => "Ticket iniciado en revisión exitosamente"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "error" => $e->getMessage(),
                "line" => $e->getLine(),
                "file" => $e->getFile()
            ], 500);
        }
    }


//    public function ticket_iniciar_revision(Request $request) {
//        $data = json_decode($request->input('data'));
//        $auth = json_decode($request->auth);
//
//        $ticket = Ticket::find($data->id);
//
//        if (!$ticket) {
//            return response()->json([
//                "message" => "Ticket no encontrado"
//            ], 404);
//        }
//
//        $ticket->estado = 'en_revision';
//        $ticket->started_at = date('Y-m-d H:i:s');
//        $ticket->save();
//
////        # Notificacion para el usuario
////        $notificacion_usuario['titulo'] = "Actualización de tu ticket";
////        $notificacion_usuario['message'] = "Tu ticket $ticket->id ha sido iniciado en revisión.";
////        $notificacion_usuario['tipo'] = "success"; // success, warning, danger
////        $notificacion_usuario['link'] = "/ticket/historial";
////
////        $notificacion_id = DB::table('notificacion')->insertGetId([
////            'data' => json_encode($notificacion_usuario)
////        ]);
////
////        $notificacion_usuario['id'] = $notificacion_id;
////
////        DB::table('notificacion_usuario')->insert([
////            'id_usuario' => $ticket->creado_por,
////            'id_notificacion' => $notificacion_id
////        ]);
////
////        $notificacion_usuario['usuario'] = $ticket->creado_por;
////
////        event(new PusherEvent(json_encode($notificacion_usuario)));
//
//        return response()->json([
//            "message" => "Ticket iniciado en revisión exitosamente"
//        ]);
//    }

    public function ticket_terminar(Request $request) {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $ticket = Ticket::find($data->id);

        if (!$ticket) {
            return response()->json([
                "message" => "Ticket no encontrado"
            ]);
        }

        $ticket->estado = TicketEstado::RESUELTO;
        $ticket->resolucion = $data->resolucion;
        $ticket->resolved_at = date('Y-m-d H:i:s');
        $ticket->save();

        # Notificacion para el usuario
        $notificacion_usuario['titulo'] = "Actualización de tu ticket";
        $notificacion_usuario['message'] = "Tu ticket $ticket->id ha sido resuelto.";
        $notificacion_usuario['tipo'] = "success"; // success, warning, danger
        $notificacion_usuario['link'] = "/ticket/historial";

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data' => json_encode($notificacion_usuario)
        ]);

        $notificacion_usuario['id'] = $notificacion_id;

        DB::table('notificacion_usuario')->insert([
            'id_usuario' => $ticket->creado_por,
            'id_notificacion' => $notificacion_id
        ]);

        $notificacion_usuario['usuario'] = $ticket->creado_por;

        event(new PusherEvent(json_encode($notificacion_usuario)));

        return response()->json([
            "message" => "Ticket terminado exitosamente"
        ]);
    }
}
