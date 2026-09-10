<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Services;

use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use stdClass;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Client;

class WhatsAppService
{
    protected $twilio;

    private function twilio(): Client
    {
        if (!$this->twilio) {
            $this->twilio = new Client(config('twilio.sid'), config('twilio.token'));
        }

        return $this->twilio;
    }

    public function send_whatsapp_ticket_notification($phone, $user): MessageInstance
    {
        $variables = [
            "1" => strval($user)
        ];

        return $this->twilio()->messages->create(
            "whatsapp:+" . $phone,
            [
                "contentSid" => config("twilio.template_ticket_id"),
                'from' => config("twilio.service_id"),
                'contentVariables' => json_encode($variables)
            ]
        );
    }


    public function sendCode(int $userId, string $phone): JsonResponse
    {
        $user = Usuario::where('id', $userId)->where('status', 1)->first();
        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado o desactivado.',
            ], 404);
        }

        $status = (new TotpLoginService())->authorizationStatus($user);
        if (!$status['ready']) {
            $response = response()->json([
                'message' => $status['message'],
            ], !empty($status['locked']) ? 429 : 409)->header('Cache-Control', 'no-store');
            if (!empty($status['locked'])) {
                $response->headers->set('Retry-After', (string)$status['retry_after']);
            }
            return $response;
        }

        return response()->json([
            'message' => $status['message'],
        ])->header('Cache-Control', 'no-store');
    }

    public static function validateCode($userId, $code): stdClass
    {
        $response = new stdClass();
        $user = Usuario::where('id', $userId)->where('status', 1)->first();
        if (!$user) {
            $response->error = 1;
            $response->mensaje = 'Usuario no encontrado o desactivado.' . self::logVariableLocation();

            return $response;
        }

        $verification = (new TotpLoginService())->verifyAuthorization($user, (string)$code);
        if (!$verification['accepted']) {
            $response->error = 1;
            $response->mensaje = $verification['message'] . self::logVariableLocation();
            $response->expired = !empty($verification['expired']);
            $response->locked = !empty($verification['locked']);

            return $response;
        }

        $response->error = 0;
        $response->mensaje = 'Código de autenticador correcto.';

        return $response;
    }


    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'WS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'APP'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }
}
