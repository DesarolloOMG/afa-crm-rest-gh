<?php

namespace App\Http\Services;

use Carbon\Carbon;
use Twilio\Exceptions\ConfigurationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class WhatsAppService
{
    protected $twilio;

    /**
     * @throws ConfigurationException
     */
    public function __construct()
    {
        $this->twilio = new Client(config("twilio.sid"), config("twilio.token"));
    }

    /**
     * @throws TwilioException
     */
    public function send_whatsapp_verification_code($phone, $code)
    {
        $variables = [
            "1" => strval($code) // Convertir a string por seguridad
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

    public function sendCode(int $userId, string $phone)
    {
        // 1) ¿Ya existe un código vigente?
        $record = DB::table('auth_codes')
            ->where('user', $userId)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($record) {
            $code = $record->code;
        } else {
            // 2) Generamos uno nuevo
            $code = random_int(100000, 999999);
            $expiresAt = Carbon::now()->addMinutes(5);

            DB::table('auth_codes')->insert([
                'user'       => $userId,
                'code'       => $code,
                'expires_at' => $expiresAt,
            ]);
        }
        return $this->send_whatsapp_verification_code($phone, $code);
    }

    /**
     * Valida un código recibido del usuario.
     * - Si es correcto y vigente: elimina el registro y retorna true.
     * - Si no: elimina cualquier código expirado, envía uno nuevo y retorna false.
     */
    public function validateCode(int $userId, string $submittedCode, string $phone): bool
    {
        $record = DB::table('auth_codes')
            ->where('user', $userId)
            ->first();

        // no existe o ya expiró
        if (! $record || Carbon::parse($record->expires_at)->lt(Carbon::now())) {
            if ($record) {
                DB::table('auth_codes')->where('id', $record->id)->delete();
            }
            // reenviamos nuevo código
            $this->sendCode($userId, $phone);
            return false;
        }

        // código incorrecto
        if ($record->code !== $submittedCode) {
            // opcional: podrías contar intentos fallidos aquí
            $this->sendCode($userId, $phone);
            return false;
        }

        // válido: lo borramos y retornamos éxito
        DB::table('auth_codes')->where('id', $record->id)->delete();
        return true;
    }
}