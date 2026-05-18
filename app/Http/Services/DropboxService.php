<?php

namespace App\Http\Services;

use Exception;
use GuzzleHttp\Client;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;
use Illuminate\Support\Facades\DB;
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

        if ($dropbox_token && $dropbox_token->expires_at && $dropbox_token->expires_at->gt(Carbon::now()->addMinutes(5))) {
            return $dropbox_token->access_token;
        }

        if ($cached = $this->getVaultToken()) {
            $this->persistAccessToken($cached);
            return $cached;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Renueva el access token de Dropbox y lo guarda en DROPBOX_TOKEN del .env
     * @throws ConnectionErrorException
     * @throws Exception
     */
    public function refreshAccessToken()
    {
        if (!$this->clientId || !$this->clientSecret || !$this->refreshToken) {
            throw new Exception('Faltan credenciales de Dropbox en .env');
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
            $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 4 * 60 * 60;
            $expiresAt = Carbon::now()->addSeconds($expiresIn);

            $this->persistAccessToken($data['access_token'], $expiresAt);

            return $data['access_token'];
        }

        Log::error('Dropbox refreshAccessToken error: ' . $response->raw_body);
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
        config(['keys.dropbox' => $value]);
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
                'verify' => false,
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
        $url = 'https://content.dropboxapi.com/2/files/upload';

        $body = $isBase64 ? base64_decode($fileContent) : $fileContent;

        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $token = $attempt === 0 ? $this->getDropboxToken() : $this->refreshAccessToken();

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

                $response = $this->client->post($url, [
                    'headers' => $headers,
                    'body' => $body,
                    'http_errors' => false,
                    'verify' => false,
                ]);
                $status = $response->getStatusCode();
                $raw = $response->getBody()->getContents();
                $data = json_decode($raw, true);

                if ($status === 200 && isset($data['id'])) {
                    return $data;
                }

                if ($attempt === 0 && $this->isExpiredTokenResponse($status, $data, (string)$raw)) {
                    Log::warning('Dropbox uploadFile token expirado, reintentando con refresh', ['path' => $path]);
                    continue;
                }

                // Si hay error, devuelve el mensaje real
                if (is_array($data) && isset($data['error_summary'])) {
                    return [
                        'error' => true,
                        'message' => $data['error_summary'],
                        'dropbox' => $data
                    ];
                } elseif (is_array($data) && isset($data['error'])) {
                    return [
                        'error' => true,
                        'message' => is_array($data['error']) ? json_encode($data['error']) : $data['error'],
                        'dropbox' => $data
                    ];
                } else {
                    Log::warning('Dropbox uploadFile unexpected response', [
                        'status' => $status,
                        'path' => $path,
                        'body' => substr((string)$raw, 0, 1000)
                    ]);

                    return [
                        'error' => true,
                        'message' => 'Dropbox HTTP ' . $status . ': ' . ($raw !== '' ? substr((string)$raw, 0, 300) : 'respuesta vacia'),
                        'dropbox' => $data ?: $raw
                    ];
                }
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
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $token = $attempt === 0 ? $this->getDropboxToken() : $this->refreshAccessToken();

                $headers = [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ];

                $response = $this->client->request('POST', $url, [
                    'headers' => $headers,
                    'body' => !empty($body) ? json_encode($body) : null,
                    'http_errors' => false,
                    'verify' => false,
                ]);
                $status = $response->getStatusCode();
                $res = $response->getBody()->getContents();
                $data = json_decode($res, true);

                if ($status === 200) {
                    return $asJson ? $data : $res;
                }

                if ($attempt === 0 && $this->isExpiredTokenResponse($status, $data, (string)$res)) {
                    Log::warning('Dropbox request token expirado, reintentando con refresh', ['url' => $url]);
                    continue;
                }

                Log::warning('Dropbox request (' . $url . ') status: ' . $status . ' | response: ' . $res);
                sleep(1);
                break;
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
        $this->getDropboxToken();
    }

    private function getVaultToken()
    {
        if (!$this->clientId) {
            return null;
        }

        try {
            return $this->vault->getValid($this->clientId);
        } catch (Exception $e) {
            Log::warning('Dropbox vault getValid error: ' . $e->getMessage());
            return null;
        }
    }

    private function persistAccessToken(string $token, ?Carbon $expiresAt = null): void
    {
        $expiresAt = $expiresAt ?: Carbon::now()->addMinutes(210);

        DB::table('oauth_tokens')->updateOrInsert(
            ['provider' => 'dropbox'],
            [
                'access_token' => $token,
                'expires_at' => $expiresAt
            ]
        );

        try {
            $this->vault->put($this->clientId, $token);
        } catch (Exception $e) {
            Log::warning('Dropbox vault put error: ' . $e->getMessage());
        }

        try {
            self::setEnvValue($token);
        } catch (Exception $e) {
            Log::warning('Dropbox setEnvValue error: ' . $e->getMessage());
            config(['keys.dropbox' => $token]);
        }
    }

    private function isExpiredTokenResponse(int $status, $data, string $raw): bool
    {
        if ($status !== 401 && strpos($raw, 'expired_access_token') === false) {
            return false;
        }

        if (is_array($data) && isset($data['error_summary']) && strpos($data['error_summary'], 'expired_access_token') !== false) {
            return true;
        }

        return strpos($raw, 'expired_access_token') !== false;
    }
}
