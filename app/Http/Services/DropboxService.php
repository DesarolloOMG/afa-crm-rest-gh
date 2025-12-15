<?php

namespace App\Http\Services;

use Exception;
use GuzzleHttp\Client;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Models\OauthToken;

class DropboxService
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;
    protected $client;
    protected $vault;

    public function __construct()
    {
        $this->vault = app(RemoteVaultService::class);
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->refreshToken = env('DROPBOX_REFRESH_TOKEN');
        $this->client = new Client();
    }

    public function getDropboxToken()
    {
        $dropbox_token = OauthToken::where('provider', 'dropbox')->first();

        if (!$dropbox_token) {
            throw new \RuntimeException('No hay registro de tokens para Dropbox');
        }

        if ($dropbox_token->expires_at && $dropbox_token->expires_at->gt(Carbon::now()->addMinutes(5))) {
            return $dropbox_token->access_token;
        }

        $token = self::refreshAccessToken();

        return $token;
    }

    /**
     * Renueva el access token de Dropbox y lo guarda en DROPBOX_TOKEN del .env
     * @throws ConnectionErrorException
     * @throws Exception
     */
    public function refreshAccessToken()
    {
        /*
        if ($token = $this->vault->getValid($this->clientId)) {
            self::setEnvValue($token);
            return $token;
        }
        */

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
            $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 4 * 60 * 60;

            OauthToken::where('provider', 'dropbox')->update([
                "access_token" => $data['access_token'],
                "expires_at" => Carbon::now()->addSeconds($expiresIn)
            ]);

            //self::setEnvValue($data['access_token']);
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

    /**
     * Obtiene un link temporal de Dropbox para visualizar/descargar el archivo.
     */
    public function getTemporaryLink($path)
    {
        $url = 'https://api.dropboxapi.com/2/files/get_temporary_link';
        $body = ['path' => $path];
        return $this->requestDropbox($url, $body, true);
    }

    public function createOrGetSharedLink($path)
    {
        $createUrl = 'https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings';
        $body = [
            'path'     => $path,
            'settings' => ['requested_visibility' => 'public']
        ];
        $res = $this->requestDropbox($createUrl, $body, true);

        # devuelve url normalizada
        if (is_array($res) && isset($res['url'])) {
            return ['url' => $this->toRawUrl($res['url'])];
        }

        # Ya existe el link publico
        if (isset($res['error']) && $res['error'] === true) {
            # Si el error no es que ya existe, devuelve
            $message = $res['message'] ?? '';
            $already = strpos($message, 'shared_link_already_exists/') !== false;

            if (!$already) {
                return $res; # otro error real
            }
        }

        # Obtenemos el link existente
        $listUrl = 'https://api.dropboxapi.com/2/sharing/list_shared_links';
        $listBody = ['path' => $path, 'direct_only' => true];
        $list = $this->requestDropbox($listUrl, $listBody, true);

        if (is_array($list) && !empty($list['links'][0]['url'])) {
            return ['url' => $this->toRawUrl($list['links'][0]['url'])];
        }

        return [
            'error' => true,
            'message' => 'Dropbox no devolvió shared links para el archivo.'
        ];
    }

    protected function toRawUrl(string $url): string
    {
        if (strpos($url, '?dl=0') !== false) return str_replace('?dl=0', '?dl=1', $url);
        if (strpos($url, '?') !== false)     return $url . '&dl=1';
        return $url . '?dl=1';
    }


    /**
     * Descarga un archivo en binario desde Dropbox.
     * Devuelve el contenido binario (para enviar al frontend como base64 si se necesita).
     */
    public function downloadFile($path): ?string
    {
        $this->ensureValidToken();
        $token = $this->getDropboxToken();

        $url = 'https://content.dropboxapi.com/2/files/download';

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Dropbox-API-Arg' => json_encode(['path' => $path])
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            }
            sleep(1);
        } catch (Exception $e) {
            Log::error('Dropbox downloadFile error: ' . $e->getMessage());
            sleep(1);
        }
        // Si falla, retorna error estándar
        return null;
    }

    /**
     * Elimina un archivo de Dropbox.
     */
    public function deleteFile($path)
    {
        $url = 'https://api.dropboxapi.com/2/files/delete_v2';
        $body = ['path' => $path];
        return $this->requestDropbox($url, $body, true);
    }

    /**
     * Sube un archivo a Dropbox.
     */
    public function uploadFile($path, $fileContent, $isBase64 = true)
    {
        $this->ensureValidToken();
        $token = $this->getDropboxToken();

        $url = 'https://content.dropboxapi.com/2/files/upload';

        $headers = [
            'Authorization' => 'Bearer ' . $token,
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

    /**
     * Función central para llamadas a Dropbox (GET/POST).
     * $asJson indica si debe decodificar la respuesta como array.
     */
    private function requestDropbox($url, $body = [], $asJson = false)
    {
        $this->ensureValidToken();
        $token = $this->getDropboxToken();

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => $headers,
                'body' => !empty($body) ? json_encode($body) : null,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $res = $response->getBody()->getContents();

            if ($status === 200) {
                return $asJson ? json_decode($res, true) : $res;
            } else {
                Log::warning('Dropbox request (' . $url . ') status: ' . $status . ' | response: ' . $res);
                sleep(1);
            }
        } catch (Exception $e) {
            Log::error('Dropbox request error: ' . $e->getMessage());
            sleep(1);
        }
        return [
            'error' => true,
            'message' => 'No se pudo conectar con Dropbox (' . $url . ')'
        ];
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
}
