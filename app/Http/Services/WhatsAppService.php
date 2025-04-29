<?php

namespace App\Http\Services;

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
}