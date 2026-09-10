<?php /** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/** @noinspection PhpUnused */

namespace App\Http\Controllers;


use App\Http\Services\WhatsAppService;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function whatsapp_send(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        if (!isset($auth->id)) {
            return response()->json([
                'code' => 400,
                'message' => 'Parámetros incompletos: id de usuario requerido',
                'data' => $auth
            ], 400);
        }

        return $this->callToServiceSend($auth);
    }

    public function whatsapp_send_with_option(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        if (!isset($data->usuario)) {
            return response()->json([
                'code' => 400,
                'message' => 'Selecciona el usuario que autorizará la operación.',
            ], 400);
        }

        $usuario = Usuario::where('id', $data->usuario)->where('status', 1)->first();
        if (!$usuario) {
            return response()->json([
                'code' => 404,
                'message' => 'Usuario no encontrado o desactivado.',
            ], 404);
        }

        return $this->callToServiceSend($usuario);
    }

    public function whatsapp_validate($code, Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        $verification = WhatsAppService::validateCode($auth->id, (string)$code);

        return $this->callToServiceValidate($verification);
    }

    public function whatsapp_validate_with_option(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));

        if (!isset($data->usuario, $data->token)) {
            return response()->json([
                'code' => 400,
                'message' => 'Usuario y código de autenticador son requeridos.',
            ], 400);
        }

        $verification = WhatsAppService::validateCode($data->usuario, (string)$data->token);

        return $this->callToServiceValidate($verification);
    }

    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'WC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'APP'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    /**
     * @param $auth
     * @return JsonResponse
     */
    public function callToServiceSend($auth): JsonResponse
    {
        $whatsappService = new WhatsAppService();
        $response_whatsapp_service = $whatsappService->sendCode($auth->id, $auth->celular ?? '');

        $status = $response_whatsapp_service->getStatusCode();
        $responseData = $response_whatsapp_service->getData();

        return response()->json([
            'code' => $status,
            'message' => $responseData->message ?? 'No fue posible preparar la autorización',
            'data' => $responseData
        ], $status);
    }

    /**
     * @param $verification
     * @return JsonResponse
     */
    public function callToServiceValidate($verification): JsonResponse
    {
        if ($verification->error) {
            return response()->json([
                'code' => 422,
                'message' => $verification->mensaje,
                'expired' => !empty($verification->expired),
                'locked' => !empty($verification->locked),
            ], !empty($verification->locked) ? 429 : 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'code' => 200,
            'message' => $verification->mensaje,
        ])->header('Cache-Control', 'no-store');
    }

}
