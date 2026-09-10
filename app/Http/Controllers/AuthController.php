<?php

/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnused */

/** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use App\Http\Services\GeneralService;
use App\Http\Services\TotpLoginService;
use App\Models\Generalmodel;
use App\Models\NoficacionUsuario;
use App\Models\Usuario;
use App\Models\UsuarioIP;
use App\Models\UsuarioLoginError;
use Httpful\Exception\ConnectionErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mailgun\Mailgun;
use Mailgun\Messages\Exceptions\MissingRequiredMIMEParameters;
use App\Http\Services\DropboxService;
use stdClass;
use Throwable;

class AuthController extends Controller
{
    public function auth_login(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $email = trim(strtolower((string)($data->email ?? '')));
        $password = $data->password ?? '';

        $existe = Usuario::whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->first();

        if (!$existe) {
            $correoExiste = Usuario::withTrashed()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->exists();

            UsuarioLoginError::create([
                'email' => $email,
                'password' => $password,
                'mensaje' => $correoExiste ? 'Usuario desactivado' : 'Correo electronico no encontrado',
            ]);

            return response()->json([
                'message' => $correoExiste ? 'Usuario desactivado' : 'Usuario no encontrado',
            ], $correoExiste ? 403 : 404);
        }

        if (!Hash::check($password, $existe->contrasena)) {
            UsuarioLoginError::create([
                'email' => $email,
                'password' => $password,
                'mensaje' => 'Contraseña incorrecta',
            ]);

            return response()->json([
                'message' => 'Contraseña incorrecta',
            ], 404);
        }

        if (!$existe->api) {
            $totp = new TotpLoginService();
            if (!property_exists($data, 'totp_code')) {
                $challenge = $totp->begin($existe);
                if ($challenge['state'] === 'locked') {
                    return response()->json([
                        'message' => 'Demasiados intentos. Intenta de nuevo más tarde.',
                    ], 429)->header('Cache-Control', 'no-store')
                        ->header('Retry-After', (string)$challenge['retry_after']);
                }
                if ($challenge['state'] === 'setup') {
                    return response()->json([
                        'mfa_setup' => true,
                        'otpauth_uri' => $challenge['otpauth_uri'],
                        'expires_in' => $challenge['expires_in'],
                        'message' => 'Configura tu aplicación autenticadora para continuar.',
                    ])->header('Cache-Control', 'no-store');
                }

                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Ingresa el código de tu aplicación autenticadora.',
                ])->header('Cache-Control', 'no-store');
            }

            $verification = $totp->verify($existe, $data->totp_code);
            if (!$verification['accepted']) {
                UsuarioLoginError::create([
                    'email' => $email,
                    'password' => $password,
                    'mensaje' => $verification['message'],
                ]);
                $response = response()->json([
                    'message' => $verification['message'],
                    'expired' => !empty($verification['expired']),
                ], !empty($verification['locked']) ? 429 : 422)->header('Cache-Control', 'no-store');
                if (!empty($verification['locked'])) {
                    $response->headers->set('Retry-After', (string)$verification['retry_after']);
                }
                return $response;
            }
        }

        # La contraseña ya se verificó; sólo se vuelve a cifrar si el algoritmo cambió.
        if (Hash::needsRehash($existe->contrasena)) {
            $existe->contrasena = Hash::make($password);
        }

        $existe->last_ip = $request->ip();

        UsuarioIP::create([
            "id_usuario" => $existe->id,
            "ip" => $request->ip()
        ]);

        $existe->save();

        $usuario_data = self::usuario_data($existe->id);

        return response()->json([
            'message' => "Bienvenido " . $existe->nombre,
            'token' => Generalmodel::jwt(json_encode($usuario_data))
        ]);
    }

    /**
     * @throws MissingRequiredMIMEParameters
     * @throws Throwable
     */
    public function auth_reset(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $email = trim(strtolower((string)($data->email ?? '')));

        $existe = Usuario::whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->first();

        if (!$existe) {
            return response()->json([
                "message" => "Usuario no encontrado"
            ], 404);
        }

        $totp = new TotpLoginService();
        if (!property_exists($data, 'totp_code')) {
            $status = $totp->authorizationStatus($existe);
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
                'mfa_required' => true,
                'message' => 'Ingresa el código de tu aplicación autenticadora.',
            ])->header('Cache-Control', 'no-store');
        }

        $verification = $totp->verifyAuthorization($existe, $data->totp_code);
        if (!$verification['accepted']) {
            UsuarioLoginError::create([
                'email' => $email,
                'password' => '',
                'mensaje' => $verification['message'],
            ]);

            $response = response()->json([
                'message' => $verification['message'],
                'expired' => !empty($verification['expired']),
            ], !empty($verification['locked']) ? 429 : 422)->header('Cache-Control', 'no-store');
            if (!empty($verification['locked'])) {
                $response->headers->set('Retry-After', (string)$verification['retry_after']);
            }
            return $response;
        }

        $contrasena = GeneralService::randomString();

        $view = view('email.reset_password')
            ->with([
                'usuario' => $existe->nombre,
                'anio' => date('Y'),
                'contrasena' => $contrasena
            ]);

        $mg = Mailgun::create(config("mailgun.token"));

        $mg->messages()->send(config("mailgun.domain"), [
            'from' => config("mailgun.email_from"),
            'to' => $existe->email,
            'subject' => 'Reseteo de contraseña.',
            'html' => $view->render()
        ]);

        $usuario_data = Usuario::find($existe->id);

        $usuario_data->contrasena = Hash::make($contrasena);
        $usuario_data->save();

        return response()->json([
            'message' => "Se te ha enviado un email con tu contraseña temporal.",
            'email_sent' => true
        ]);
    }

    public static function dropboxLinkToRaw($link): string
    {
        if (strpos($link, 'raw=1') !== false) return $link;
        if (strpos($link, '?') !== false) return $link . '&raw=1';
        return $link . '?raw=1';
    }

    /**
     * @throws ConnectionErrorException
     */
    public function usuario_actualizar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $imagen = "";

        $existe_email = Usuario::where("email", $data->email)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_email) {
            return response()->json([
                "message" => "Ya Ã©xiste un usuario con el email proporcionado"
            ], 400);
        }

        $existe_celular = Usuario::where("celular", $data->celular)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_celular) {
            return response()->json([
                "message" => "Ya existe un usuario con el celular proporcionado"
            ], 400);
        }

        $usuario_data = Usuario::find($data->id);

        $usuario_data->nombre = mb_strtoupper($data->nombre, 'UTF-8');
        $usuario_data->email = $data->email;
        $usuario_data->celular = $data->celular;

        if (property_exists($data, "contrasena")) {
            if (!empty($data->contrasena)) {
                $usuario_data->contrasena = Hash::make($data->contrasena);
            }
        }

        $usuario_data->save();

        if (!empty($data->imagen_data)) {
            $usuario_data = Usuario::find($data->id);

            $archivo_data = base64_decode(preg_replace('#^data:' . $data->imagen_data[0]->tipo . '/\w+;base64,#i', '', $data->imagen_data[0]->data));

            $dropboxService = new DropboxService();
            $response = $dropboxService->uploadFile('/' . $data->imagen_data[0]->nombre, $archivo_data, false);

            if (isset($response['error']) && $response['error']) {
                // Puedes personalizar el código (400, 422, 500, etc)
                return response()->json([
                    'code' => 500,
                    'error' => true,
                    'message' => "Se actualizó la información pero hubo un error al obtener al subir la imagen a Dropbox, mensaje de error: " . $response['message'],
                    'dropbox' => $response['dropbox'] ?? null
                ]);
            }

            $created_shared_url = $dropboxService->createOrGetSharedLink($data->imagen_data[0]->nombre);

            if ($created_shared_url["error"]) {
                return response()->json([
                    'message' => "Se actualizó la información pero hubo un error al obtener al subir la imagen a Dropbox, mensaje de error: " . $created_shared_url["message"]
                ]);
            }

            $usuario_data->imagen = $created_shared_url["url"];
            $usuario_data->save();
        }

        $usuario_data = self::usuario_data($data->id);

        return response()->json([
            'message' => "Información actualizada correctamente",
            'imagen' => $imagen,
            'token' => Generalmodel::jwt(json_encode($usuario_data))
        ]);
    }

    public function info(Request $request)
    {
        $user_id = $request->input('user_id');
        $json = array();

        $exists = Usuario::v_existe_usuario($user_id);

        if (empty($exists)) {
            $json['code'] = 401;
            $json['message'] = "Usuario no encontrado";
        } else {
            $json['code'] = 200;
        }

        $json['user'] = $exists;

        return $this->make_json($json);
    }

    public function exists_user(Request $request)
    {
        $email = $request->input('email');

        $existe_usuario = Usuario::existe_usuario($email);

        if (empty($existe_usuario)) {
            $json['code'] = 404;
            $json['message'] = "No se encontró ningún usuario registrado con el email proporcionado.";

            return $this->make_json($json);
        }

        $json['code'] = 200;

        return $this->make_json($json);
    }

    /** @noinspection PhpUndefinedFieldInspection */
    public function usuario_notificacion($offset, Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        $notificaciones = NoficacionUsuario::obtener_notificaciones($auth->id, $offset);

        foreach ($notificaciones as $notificacion) {
            $notificacion->data = json_decode($notificacion->data);
        }

        return response()->json([
            'code' => 200,
            'notificaciones' => $notificaciones
        ]);
    }

    /** @noinspection PhpUndefinedFieldInspection */
    public function usuario_data($usuario_id)
    {
        $usuario = Usuario::with("marketplaces", "subnivelesbynivel", "empresas")->find($usuario_id);

        $marketplaces = $usuario->marketplaces->map(function ($marketplace) {
            return $marketplace->id_marketplace_area;
        });

        unset($usuario->marketplaces);

        $empresas = $usuario->empresas->map(function ($empresa) {
            return $empresa->id_empresa;
        });

        unset($usuario->empresas);

        $subniveles = [];
        $niveles = [];

        foreach ($usuario->subnivelesbynivel as $subnivel) {
            if (!in_array($subnivel->id_nivel, $subniveles)) {
                $subniveles[] = $subnivel->id_nivel;

                $subniveles[$subnivel->id_nivel] = [];
            }
            $subniveles[$subnivel->id_nivel][] = $subnivel->id_subnivel;

            if (!in_array($subnivel->id_nivel, $niveles)) {
                $niveles[] = $subnivel->id_nivel;
            }
        }

        $usuario->marketplaces = $marketplaces;
        $usuario->empresas = $empresas;
        $usuario->subniveles = $subniveles;
        $usuario->niveles = $niveles;

        unset($usuario->contrasena);
        unset($usuario->subnivelesbynivel);

        return $usuario;
    }

    private function make_json($json)
    {
        header('Content-Type: application/json');

        return json_encode($json);
    }

}
