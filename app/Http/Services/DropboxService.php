<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DropboxService
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;
    protected $client;

    public function __construct()
    {
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->refreshToken = env('DROPBOX_REFRESH_TOKEN');
        $this->client = new Client();
    }

    /**
     * Renueva el access token de Dropbox y lo guarda en DROPBOX_TOKEN del .env
     */
    public function refreshAccessToken()
    {
        $url = 'https://api.dropbox.com/oauth2/token';
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $authorization = base64_encode($this->clientId . ':' . $this->clientSecret);

        $response = \Httpful\Request::post($url)
            ->addHeader('Authorization', "Basic $authorization")
            ->addHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->body($body)
            ->send();

        $data = json_decode($response->raw_body, true);

        if (isset($data['access_token'])) {
            self::setEnvValue($data['access_token']);
            return $data['access_token'];
        }
        throw new \Exception('No se pudo renovar el access token de Dropbox');
    }

    /**
     * Actualiza o agrega una variable en el .env
     */
    private static function setEnvValue($value)
    {
        $envPath = base_path('.env');
        $env = File::get($envPath);

        if (preg_match("/^DROPBOX_TOKEN=.*$/m", $env)) {
            $env = preg_replace("/^DROPBOX_TOKEN=.*$/m", "DROPBOX_TOKEN={$value}", $env);
        } else {
            $env .= "\nDROPBOX_TOKEN={$value}";
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

    /**
     * Descarga un archivo en binario desde Dropbox.
     * Devuelve el contenido binario (para enviar al frontend como base64 si se necesita).
     */
    public function downloadFile($path)
    {
        $url = 'https://content.dropboxapi.com/2/files/download';

        $headers = [
            'Authorization' => 'Bearer ' . config('keys.dropbox'),
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
        } catch (\Exception $e) {
            Log::error('Dropbox downloadFile error: '.$e->getMessage());
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
        } catch (\Exception $e) {
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
        $headers = [
            'Authorization' => 'Bearer ' . config('keys.dropbox'),
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
                Log::warning('Dropbox request ('.$url.') status: '.$status.' | response: '.$res);
                sleep(1);
            }
        } catch (\Exception $e) {
            Log::error('Dropbox request error: '.$e->getMessage());
            sleep(1);
        }
        return [
            'error' => true,
            'message' => 'No se pudo conectar con Dropbox ('.$url.')'
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
}
