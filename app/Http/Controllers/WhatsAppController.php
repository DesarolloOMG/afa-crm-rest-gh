<?php /** @noinspection PhpUnused */

namespace App\Http\Controllers;


use App\Http\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsAppController extends Controller
{
    public function whatsapp_send(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        if (!isset($auth->id, $auth->celular)) {
            return response()->json([
                'code' => 400,
                'message' => 'Parámetros incompletos: id y celular requeridos',
                'data' => $auth
            ], 400);
        }

        $whatsappService = new WhatsAppService();
        $response_whatsapp_service = $whatsappService->sendCode($auth->id, $auth->celular);

        $status = $response_whatsapp_service->getStatusCode();
        $responseData = $response_whatsapp_service->getData();

        return response()->json([
            'code' => $status,
            'message' => $responseData->message ?? 'Error al enviar el código',
            'data' => $responseData
        ], $status);
    }

    public function whatsapp_validate($code, Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        $authCode = DB::table('auth_codes')
            ->where('user', $auth->id)
            ->where('code', $code)
            ->first();

        # El codigo proporcionado por el usuario no existe
        if (!$authCode) {
            return response()->json([
                'code' => 500,
                'message' => 'Código inválido',
                "data" => $authCode
            ], 500);
        }

        # El codigo proporcionado por el usuario expirado
        if ($authCode->expires_at < Carbon::now()) {
            DB::table('auth_codes')
                ->where('id', $authCode->id)
                ->delete();

            return response()->json([
                'code' => 500,
                'message' => 'Código expirado, vuelve a intentarlo',
                "data" => $authCode,
                "expired" => true
            ], 500);
        }

        # El codigo existe y es valido, se procede a eliminarlo y dar acceso
        DB::table('auth_codes')
            ->where('id', $authCode->id)
            ->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Código correcto',
            "data" => $authCode,
        ]);
    }

    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'WC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'APP'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

}
