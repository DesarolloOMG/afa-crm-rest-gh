<?php

namespace App\Http\Controllers;

use App\Http\Services\MercadolibreServiceV2;
use Illuminate\Http\Request;
use DateTime;

use DB;

class MercadolibreControllerV2 extends Controller
{

    public function mercadolibre_notificaciones_callbacks(Request $request)
    {
        set_time_limit(0);

        // Responder con HTTP 200 de inmediato para evitar timeouts
        http_response_code(200);

        // Registrar la notificación recibida (opcional, para depuración)
        // Log::info('Notificación recibida de MercadoLibre:', $request->all());

        // Procesar la notificación directamente aquí
        $this->processNotification($request->all());

        return response()->json(['status' => 'received'], 200);
    }

    protected function processNotification($notification)
    {
        // Procesa la notificación aquí
        // Puedes hacer lo que necesites con la notificación recibida
        DB::table('notificacion_mercadolibre')->insert([
            'data' => json_encode($notification) ?? null,
        ]);
    }

    protected function getOrderDetails($resource)
    {
        // Lógica para obtener detalles de la orden desde la API de MercadoLibre
        // Esta llamada debe ser lo más eficiente posible
    }

    protected function updateOrderStatus($orderDetails)
    {
        // Lógica para actualizar el estado de la orden en tu base de datos
        // Optimiza las consultas a la base de datos y evita operaciones costosas
    }

    // public function mercadolibre_notificaciones_callbacks(Request $request)
    // {
    //     set_time_limit(0);
    //     $response = response()->json(['message' => 'Notification received'], 200);
    //     $response->send();

    //     if (function_exists('fastcgi_finish_request')) {
    //         fastcgi_finish_request();
    //     }

    //     $notification = $request->all();

    //     DB::table('notificacion_mercadolibre')->insert([
    //         'topic' => $notification['topic'] ?? null,
    //         'resource' => $notification['resource'] ?? null,
    //         'user_id' => $notification['user_id'] ?? null,
    //         'application_id' => $notification['application_id'] ?? null,
    //         'attempts' => $notification['attempts'] ?? null,
    //         'sent' => $notification['sent'] ?? null, 'received' => date('Y-m-d H:i:s')
    //     ]);
    // }
}
