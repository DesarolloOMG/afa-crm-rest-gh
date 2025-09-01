<?php /** @noinspection PhpParamsInspection */

namespace App\Http\Services;

use App\Events\PusherEvent;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Mailgun\Mailgun;
use Illuminate\Support\Facades\DB;
use Throwable;

class CorreoService
{
    public static function cambioDeFase($documento, $mensaje): JsonResponse
    {
        try {
            $usuario_documento = DB::table('documento')
                ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
                ->where('documento.id', $documento)
                ->select('usuario.id as id_usuario', 'usuario.email', 'usuario.nombre')
                ->first();

            if ($usuario_documento->id_usuario > 1) {
                $notificacion['titulo'] = "Pedido Actualizado.";
                $notificacion['message'] = "Tú pedido " . $documento . " ah cambiado de fase.";
                $notificacion['tipo'] = "warning"; // success, warning, danger
                $notificacion['link'] = "/general/busqueda/venta/id/" . $documento;

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data' => json_encode($notificacion)
                ]);

                $notificacion['id'] = $notificacion_id;

                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario_documento->id_usuario,
                    'id_notificacion' => $notificacion_id
                ]);

                $notificacion['usuario'] = $usuario_documento->id_usuario;

                self::notificationAndEmail($notificacion, $usuario_documento, $documento, $mensaje);
            }

            return response()->json([
                "code" => 200
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                "color" => "red-border-top",
                'message' => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'code' => 500,
                "color" => "red-border-top",
                'message' => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }
    }

    public static function enviarMensaje($documento, $mensaje): JsonResponse
    {
        try {
            $usuario_documento = DB::table('usuario')
                ->where('id', 60)
                ->first();

            if ($usuario_documento->id > 1) {
                $notificacion['titulo'] = "Pedido Cancelado.";
                $notificacion['message'] = "Tú pedido " . $documento . " tiene Cancelado el Envio en Walmart.";
                $notificacion['tipo'] = "warning";
                $notificacion['link'] = "/general/busqueda/venta/id/" . $documento;

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data' => json_encode($notificacion)
                ]);

                $notificacion['id'] = $notificacion_id;

                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario_documento->id,
                    'id_notificacion' => $notificacion_id
                ]);

                $notificacion['usuario'] = $usuario_documento->id;

                self::notificationAndEmail($notificacion, $usuario_documento, $documento, $mensaje);
            }

            return response()->json([
                "code" => 200
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                "color" => "red-border-top",
                'message' => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'code' => 500,
                "color" => "red-border-top",
                'message' => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }
    }

    /**
     * @throws Throwable
     */
    public static function cambioFaseConta($documento, $mensaje)
    {
        $emails = self::getEmails();

        $vista = view('email.notificacion_factura')->with([
            'mensaje' => $mensaje,
            'anio' => date('Y'),
            'documento' => $documento
        ]);

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->messages()->send($domain, array(
            'from' => 'CRM OMG International <crm@omg.com.mx>',
            'to' => $emails,
            'subject' => 'Pedido Pendiente de factura',
            'html' => $vista->render()
        ));
    }

    /**
     * @throws Throwable
     * @noinspection PhpUnused
     */
    public static function enviarErrorComercial($mensaje)
    {
        $emails = self::getEmails();

        $vista = view('email.notificacion_error_comercial')->with([
            'mensaje' => $mensaje,
            'anio' => date('Y')
        ]);

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->messages()->send($domain, array(
            'from' => 'CRM OMG International <crm@omg.com.mx>',
            'to' => $emails,
            'subject' => 'Pedido Pendiente de factura',
            'html' => $vista->render()
        ));
    }

    /**
     * @throws Throwable
     */
    public static function enviarManifiesto($guias, $tipo, $paqueteria)
    {
        $emails = "";
        $correos = DB::table('usuario')
            ->join('usuario_subnivel_nivel', 'usuario.id', '=', 'usuario_subnivel_nivel.id_usuario')
            ->join('subnivel_nivel', 'usuario_subnivel_nivel.id_subnivel_nivel', '=', 'subnivel_nivel.id')
            ->join('subnivel', 'subnivel_nivel.id_subnivel', '=', 'subnivel.id')
            ->whereIn('subnivel_nivel.id', [69, 47, 48])
            ->where('usuario.status', 1)
            ->whereNotIn('usuario.email', ['roberto@omg.com.mx','sauladrian.arias@gmail.com'])
            ->groupBy('usuario.email')
            ->pluck('usuario.email');

        if ($tipo == 1) {
            $manifiesto = "Manifiesto";
        } else {
            $manifiesto = "Reimpresión Manifiesto";
        }

        foreach ($correos as $correo) {
            $emails .= $correo . ";";
        }

        $fechaHoraActual = Carbon::now('America/Mexico_City');

        $dia = $fechaHoraActual->format('j');
        $mes = $fechaHoraActual->format('n');
        $anio = $fechaHoraActual->format('Y');

        $hora = $fechaHoraActual->format('H');
        $minutos = $fechaHoraActual->format('i');
        $segundos = $fechaHoraActual->format('s');

        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo',
            4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre',
            10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];

        $fechaHoraEnEspanol = "$dia de {$meses[(int)$mes]} de $anio $hora:$minutos:$segundos";

        $asunto = $manifiesto . ' ' . $paqueteria . ' ' . $fechaHoraEnEspanol;

        $vista = view('email.notificacion_manifiesto')->with([
            'guias' => json_encode($guias),
            'anio' => date('Y'),
            'fecha' => $fechaHoraEnEspanol
        ]);

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->messages()->send($domain, array(
            'from' => 'CRM OMG International <crm@omg.com.mx>',
            'to' => $emails,
            'subject' => $asunto,
            'html' => $vista->render()
        ));
    }

    /**
     * @return string
     */
    public static function getEmails(): string
    {
        $emails = "";
        $correos = DB::table('usuario')
            ->join('usuario_subnivel_nivel', 'usuario.id', '=', 'usuario_subnivel_nivel.id_usuario')
            ->join('subnivel_nivel', 'usuario_subnivel_nivel.id_subnivel_nivel', '=', 'subnivel_nivel.id')
            ->join('subnivel', 'subnivel_nivel.id_subnivel', '=', 'subnivel.id')
            ->whereIn('subnivel.subnivel', ['CXC', 'CXP'])
            ->where('usuario.email', 'like', '%@omg%')
            ->groupBy('usuario.email')
            ->select('usuario.email')
            ->get();

        foreach ($correos as $correo) {
            $emails .= $correo->email . ";";
        }
        return $emails;
    }

    /**
     * @param $notificacion
     * @param $usuario_documento
     * @param $documento
     * @param $mensaje
     * @return void
     * @throws Throwable
     */
    public static function notificationAndEmail($notificacion, $usuario_documento, $documento, $mensaje): void
    {
        event(new PusherEvent(json_encode($notificacion)));

        $view = view('email.notificacion_problema')->with([
            'vendedor' => $usuario_documento->nombre,
            'problema' => 0,
            'usuario' => $usuario_documento->nombre,
            'anio' => date('Y'),
            'documento' => $documento,
            'comentario' => $mensaje
        ]);

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->messages()->send($domain, array(
            'from' => 'CRM OMG International <crm@omg.com.mx>',
            'to' => $usuario_documento->email,
            'subject' => 'Pedido ' . $documento . ' cambiado de fase.',
            'html' => $view->render()
        ));
    }
}
