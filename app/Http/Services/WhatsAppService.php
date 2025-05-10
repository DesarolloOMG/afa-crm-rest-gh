<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use stdClass;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Client;

class WhatsAppService
{
    protected $twilio;

    public function __construct()
    {
        $this->twilio = new Client(config("twilio.sid"), config("twilio.token"));
    }

    /**
     * @throws TwilioException
     */
    public function send_whatsapp_verification_code($phone, $code): MessageInstance
    {
        $variables = [
            "1" => strval($code)
        ];

        return $this->twilio->messages->create(
            "whatsapp:+" . $phone,
            [
                "contentSid" => config("twilio.template_id"),
                'from' => config("twilio.service_id"),
                'contentVariables' => json_encode($variables)
            ]
        );
    }


    public function sendCode(int $userId, string $phone): JsonResponse
    {
        $exits_code_user = DB::table("auth_codes")
            ->where('user', $userId)
            ->where('expires_at', '>', Carbon::now())
            ->first();
        try {
            if (!$exits_code_user) {
                $code = random_int(100000, 999999);

                $code_expires_at = Carbon::now()->addMinutes(5);

                DB::beginTransaction();

                DB::table('auth_codes')->insert([
                    'user' => $userId,
                    'code' => $code,
                    'expires_at' => $code_expires_at
                ]);

                DB::commit();
            } else {
                $code = $exits_code_user->code;
            }
            self::send_whatsapp_verification_code($phone, $code);

            return response()->json([
                "message" => "Código enviado a whatsapp, verifica tu celular para continuar.",
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => "Hubo un problema con la transacción " . self::logVariableLocation() . ' ' . $e->getMessage(),
            ], 404);
        }
    }

    public static function validateCode($userId, $code): stdClass
    {
        $response = new stdClass();

        $authCode = DB::table('auth_codes')
            ->where('user', $userId)
            ->where('code', $code)
            ->first();

        # El codigo proporcionado por el usuario no existe
        if (!$authCode) {
            $response->error = 1;
            $response->mensaje = 'Código inválido' . self::logVariableLocation();

            return $response;
        }

        # El codigo proporcionado por el usuario expirado
        if ($authCode->expires_at < Carbon::now()) {
            DB::table('auth_codes')
                ->where('id', $authCode->id)
                ->delete();

            $response->error = 1;
            $response->mensaje = 'Código expirado, vuelve a intentarlo' . self::logVariableLocation();

            return $response;
        }

        # El codigo existe y es valido, se procede a eliminarlo y dar acceso
        DB::table('auth_codes')
            ->where('id', $authCode->id)
            ->delete();

        $response->error = 0;
        $response->mensaje = 'Validación correcta';

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
