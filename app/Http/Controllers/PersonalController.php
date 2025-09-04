<?php

namespace App\Http\Controllers;

use App\Http\Services\DropboxService;
use Illuminate\Http\Request;
use App\Events\PusherEvent;
use DB;

class PersonalController extends Controller
{
    public function personal_modificacion_data(){
        $usuarios = DB::select("SELECT id, nombre FROM usuario WHERE status = 1 AND id != 1");

        return response()->json([
            'code'  => 200,
            'usuarios'  => $usuarios
        ]);
    }

    public function personal_modificacion_crear(Request $request){
        $data       = json_decode($request->input('data'));
        $auth       = json_decode($request->auth);
        $usuarios   = array();

        $modificacion = DB::table('personal_modificacion')->insertGetId([
            'id_usuario'    => $auth->id,
            'asignado_a'    => $data->usuario,
            'titulo'        => $data->titulo,
        ]);

        DB::table('personal_modificacion_seguimiento')->insert([
            'id_modificacion'   => $modificacion,
            'id_usuario'        => $auth->id,
            'seguimiento'       => $data->justificacion
        ]);

        foreach ($data->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $dropboxService = new DropboxService();
                $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                DB::table('personal_modificacion_archivo')->insert([
                    'id_modificacion'   => $modificacion,
                    'id_usuario'        => $auth->id,
                    'nombre'            => $archivo->nombre,
                    'dropbox'           => $response['id']
                ]);
            }
        }

        $usuario_nombre = DB::select("SELECT nombre FROM usuario WHERE id = " . $auth->id . "")[0]->nombre;

        $notificacion['titulo']     = "Asignación de tarea";
        $notificacion['message']    = "El usuario " . $usuario_nombre . " te ha asignado una tarea, puedes revisarla dando click en esta notificación.";
        $notificacion['tipo']       = "warning"; // success, warning, danger
        $notificacion['link']       = "/personal/modificacion/pendiente/" . $modificacion;

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data'  => json_encode($notificacion)
        ]);

        $notificacion['id']         = $notificacion_id;
        
        DB::table('notificacion_usuario')->insert([
            'id_usuario'        => $data->usuario,
            'id_notificacion'   => $notificacion_id
        ]);

//        array_push($usuarios, $data->usuario);
        
        if (!empty($usuarios)) {
            $notificacion['usuario']    = $usuarios;

//            event(new PusherEvent(json_encode($notificacion)));
        }

        return response()->json([
            'code'      => 200,
            'message'   => "Tarea creada correctamente con el ID " . $modificacion . ", una vez que sea aceptada se te notificará."
        ]);
    }

    public function personal_modificacion_pendiente_data(Request $request){
        $auth = json_decode($request->auth);

        $pendientes = DB::select("SELECT
                                    personal_modificacion.id,
                                    personal_modificacion.id_google,
                                    personal_modificacion.id_lista_google,
                                    personal_modificacion.titulo,
                                    personal_modificacion.autorizada,
                                    personal_modificacion.created_at,
                                    usuario.nombre
                                FROM personal_modificacion
                                INNER JOIN usuario ON personal_modificacion.id_usuario = usuario.id
                                WHERE personal_modificacion.terminada = 0
                                AND personal_modificacion.asignado_a = " . $auth->id . "");

        foreach ($pendientes as $pendiente) {
            $seguimiento = DB::select("SELECT personal_modificacion_seguimiento.*, usuario.nombre FROM personal_modificacion_seguimiento INNER JOIN usuario ON personal_modificacion_seguimiento.id_usuario = usuario.id WHERE id_modificacion = " . $pendiente->id . "");

            $archivos = DB::select("SELECT * FROM personal_modificacion_archivo WHERE id_modificacion = " . $pendiente->id . "");

            $pendiente->seguimiento = $seguimiento;
            $pendiente->archivos    = $archivos;
        }

        return response()->json([
            'code'          => 200,
            'pendientes'    => $pendientes
        ]);
    }

    public function personal_modificacion_pendiente_guardar(Request $request){
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $creador    = DB::select("SELECT usuario.id, usuario.nombre FROM personal_modificacion INNER JOIN usuario ON personal_modificacion.id_usuario = usuario.id WHERE personal_modificacion.id = " . $data->documento . "")[0];
        $asignado   = DB::select("SELECT usuario.id, usuario.nombre FROM personal_modificacion INNER JOIN usuario ON personal_modificacion.asignado_a = usuario.id WHERE personal_modificacion.id = " . $data->documento . "")[0];

        $autorizada = DB::select("SELECT autorizada, autorized_at FROM personal_modificacion WHERE id = " . $data->documento . "")[0];

        DB::table('personal_modificacion')->where(['id' => $data->documento])->update([
            'autorizada'        => ($data->autorizada),
            'autorized_at'      => ($autorizada->autorizada) ? $autorizada->autorized_at : date('Y-m-d H:i:s'),
            'terminada'         => $data->terminada,
            'finished_at'       => ($data->terminada) ? date('Y-m-d H:i:s') : '0000-00-00 00:00:00',
            'id_google'         => $data->id_google,
            'id_lista_google'   => $data->lista_seleccionada
        ]);

        DB::table('personal_modificacion_seguimiento')->insert([
            'id_modificacion'   => $data->documento,
            'id_usuario'        => $auth->id,
            'seguimiento'       => $data->seguimiento
        ]);

        if ($data->terminada) {
            $notificacion['titulo']     = "Petición de modificación terminada";
            $notificacion['message']    = "Estimado " . $creador->nombre . " la tarea que asignaste a " . $asignado->nombre . " con el ID " . $data->documento . ", ha sido concluida.";
            $notificacion['tipo']       = "success"; // success, warning, danger
            $notificacion['link']       = "/personal/modificacion/historial/" . $data->documento;

            $notificacion_id = DB::table('notificacion')->insertGetId([
                'data'  => json_encode($notificacion)
            ]);

            $notificacion['id']         = $notificacion_id;

            DB::table('notificacion_usuario')->insert([
                'id_usuario'        => $creador->id,
                'id_notificacion'   => $notificacion_id
            ]);

            $notificacion['usuario']    = $creador->id;

//            event(new PusherEvent(json_encode($notificacion)));
        }
        else {
            if (!$autorizada->autorizada && $data->autorizada) {
                $nombre = DB::select("SELECT nombre FROM usuario WHERE id = " . $auth->id . "")[0]->nombre;

                $notificacion['titulo']     = "Petición de modificación autorizada";
                $notificacion['message']    = "Estimado " . $creador->nombre . " la tarea que asignaste a " . $asignado->nombre . " con el ID " . $data->documento . ", ha sido aceptada por el mismo.";
                $notificacion['tipo']       = "success"; // success, warning, danger
                $notificacion['link']       = "/personal/modificacion/historial/" . $data->documento;

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data'  => json_encode($notificacion)
                ]);

                $notificacion['id']         = $notificacion_id;

                DB::table('notificacion_usuario')->insert([
                    'id_usuario'        => $creador->id,
                    'id_notificacion'   => $notificacion_id
                ]);

                $notificacion['usuario']    = $creador->id;

//                event(new PusherEvent(json_encode($notificacion)));
            }
        }

        return response()->json([
            'code'      => 200,
            'message'   => ($data->terminada) ? "Tarea terminada correctamente" : "Seguimiento guardado correctamente."
        ]);
    }

    public function personal_modificacion_historial_data(Request $request){
        $extra_query    = "";
        $auth = json_decode($request->auth);

        $pendientes = DB::select("SELECT
                                    personal_modificacion.id,
                                    personal_modificacion.titulo,
                                    personal_modificacion.autorizada,
                                    personal_modificacion.terminada,
                                    personal_modificacion.created_at,
                                    personal_modificacion.id_usuario AS creador_id,
                                    personal_modificacion.asignado_a AS asignado_id,
                                    (SELECT nombre FROM usuario WHERE id = creador_id) AS creador,
                                    (SELECT nombre FROM usuario WHERE id = asignado_id) AS asignado
                                FROM personal_modificacion
                                INNER JOIN usuario ON personal_modificacion.id_usuario = usuario.id
                                WHERE (personal_modificacion.asignado_a = " . $auth->id . " OR personal_modificacion.id_usuario = " . $auth->id . ")");

        foreach ($pendientes as $pendiente) {
            $seguimiento = DB::select("SELECT personal_modificacion_seguimiento.*, usuario.nombre FROM personal_modificacion_seguimiento INNER JOIN usuario ON personal_modificacion_seguimiento.id_usuario = usuario.id WHERE id_modificacion = " . $pendiente->id . "");

            $archivos = DB::select("SELECT * FROM personal_modificacion_archivo WHERE id_modificacion = " . $pendiente->id . "");

            $pendiente->seguimiento = $seguimiento;
            $pendiente->archivos    = $archivos;
        }

        return response()->json([
            'code'          => 200,
            'pendientes'    => $pendientes
        ]);
    }

    public function personal_modificacion_historial_guardar(Request $request){
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('personal_modificacion_seguimiento')->insert([
            'id_modificacion'   => $data->documento,
            'id_usuario'        => $auth->id,
            'seguimiento'       => $data->seguimiento
        ]);

        return response()->json([
            'code'      => 200,
            'message'   => "Seguimiento guardado correctamente."
        ]);
    }

    public function personal_todo_data(Request $request){
        $auth = json_decode($request->auth);

        $tareas = DB::select("SELECT * FROM personal_todo_list WHERE id_usuario = " . $auth->id . " ORDER BY created_at DESC");

        return response()->json([
            'code'  => 200,
            'tareas'    => $tareas
        ]);
    }

    public function personal_todo_crear(Request $request){
        $tarea = $request->input('tarea');
        $auth = json_decode($request->auth);

        $tarea_id = DB::table('personal_todo_list')->insertGetId([
            'id_usuario'    => $auth->id,
            'tarea'         => $tarea
        ]);

        $tarea_data = DB::select("SELECT * FROM personal_todo_list WHERE id = " . $tarea_id . "");

        return response()->json([
            'code'  => 200,
            'tarea' => $tarea_data
        ]);
    }

    public function personal_todo_actualizar(Request $request){
        $tarea_id   = $request->input('tarea_id');
        $status     = $request->input('status');

        DB::table('personal_todo_list')->where(['id' => $tarea_id])->update([
            'status'    => $status
        ]);
    }
}
