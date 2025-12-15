<?php

namespace App\Http\Services;

use Exception;
use GuzzleHttp\Client;
use Httpful\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DropboxService
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;
    private $vault;
    protected $client;

    public function __construct(VaultService $vault)
    {
        $this->vault = $vault;
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->client = new Client();
        $this->refreshToken = env('DROPBOX_REFRESH_TOKEN');
    }

    /**
     * Renueva el access token de Dropbox y lo guarda en DROPBOX_TOKEN del .env
     * @throws Exception
     */
    public function refreshAccessToken()
    {
        if ($cached = $this->vault->getValid($this->clientId)) {
            self::setEnvValue($cached);
            return $cached;
        }

        $url = 'https://api.dropbox.com/oauth2/token';
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $authorization = base64_encode($this->clientId . ':' . $this->clientSecret);

        $response = Request::post($url)
            ->addHeader('Authorization', "Basic $authorization")
            ->addHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->body($body)
            ->send();

        $data = json_decode($response->raw_body, true);

        if (isset($data['access_token'])) {
            $this->vault->put($this->clientId, $data['access_token']);
            self::setEnvValue($data['access_token']);
            return $data['access_token'];
        }
        throw new Exception('No se pudo renovar el access token de Dropbox');
    }

    /**
     * Actualiza o agrega una variable en el .env
     */
    private static function setEnvValue($value)
    {
        $envPath = base_path('.env');
        $env = File::get($envPath);

        if (preg_match("/^DROPBOX_TOKEN=.*$/m", $env)) {
            $env = preg_replace("/^DROPBOX_TOKEN=.*$/m", "DROPBOX_TOKEN=$value", $env);
        } else {
            $env .= "\nDROPBOX_TOKEN=$value";
        }

        File::put($envPath, $env);
    }
    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'WS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'APP'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }
    public function ensureValidToken(): void
    {
        try {
            $validToken = $this->vault->getValid($this->clientId);

            if (!$validToken) {
                $this->refreshAccessToken();
            }

        } catch (Exception $e) {
            $this->refreshAccessToken();
        }
    }
    public function uploadFile($path, $fileContent, $isBase64 = true)
    {
        $this->ensureValidToken();

        $url = 'https://content.dropboxapi.com/2/files/upload';

        $headers = [
            'Authorization' => 'Bearer ' . config('keys.dropbox'),
            'Dropbox-API-Arg' => json_encode([
                'path' => $path,
                'mode' => 'add',
                'autorename' => true,
                'mute' => false,
            ]),
            'Content-Type' => 'application/octet-stream',
        ];

        $body = $isBase64 ? base64_decode($fileContent) : $fileContent;

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
                'verify' => false,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() === 200 && isset($data['name'])) {
                return $data;
            }

            // Si hay error, devuelve el mensaje real
            if (isset($data['error_summary'])) {
                return [
                    'error' => true,
                    'message' => $data['error_summary'],
                    'dropbox' => $data
                ];
            } elseif (isset($data['error'])) {
                return [
                    'error' => true,
                    'message' => is_array($data['error']) ? json_encode($data['error']) : $data['error'],
                    'dropbox' => $data
                ];
            } else {
                return [
                    'error' => true,
                    'message' => 'Error desconocido de Dropbox',
                    'dropbox' => $data
                ];
            }
        } catch (Exception $e) {
            Log::error('Dropbox uploadFile error: ' . $e->getMessage());
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

}
