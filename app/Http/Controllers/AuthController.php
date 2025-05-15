<?php
/** @noinspection PhpUnused */

/** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use App\Http\Services\GeneralService;
use App\Http\Services\WhatsAppService;
use App\Models\Generalmodel;
use App\Models\NoficacionUsuario;
use App\Models\Usuario;
use App\Models\UsuarioIP;
use App\Models\UsuarioLoginError;
use Authy\AuthyApi;
use Exception;
use Httpful\Exception\ConnectionErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mailgun\Mailgun;
use Mailgun\Messages\Exceptions\MissingRequiredMIMEParameters;
use stdClass;
use Throwable;

class AuthController extends Controller
{
    public function auth_login(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));

        $existe = Usuario::where("email", $data->email)->first();

        if (!$existe) {
            UsuarioLoginError::create([
                "email" => $data->email,
                "password" => $data->password,
                "mensaje" => "Correo electronico no encontrado"
            ]);

            return response()->json([
                "message" => "Usuario no encontrado"
            ], 404);
        }

        if (empty($data->wa_code)) {
            if (!Hash::check($data->password, $existe->contrasena)) {
                UsuarioLoginError::create([
                    "email" => $data->email,
                    "password" => $data->password,
                    "mensaje" => "Contraseña incorrecta"
                ]);

                return response()->json([
                    "message" => "Contraseña incorrecta"
                ], 404);
            }

            return $this->checkAndSendCode($existe);
        }

        try {

            $this->validarCodigoAutenticacion($existe->id, $data->wa_code);

        } catch (Exception $e) {
            UsuarioLoginError::create([
                "email" => $data->email,
                "password" => $data->password,
                "mensaje" => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                "expired" => $e->getMessage() === 'Código expirado'
            ], 500);
        }

        # No es necesario verificar la contraseña de nuevo ya que se verificó al mandar el codigo, solo si necesita rehash
        if (Hash::needsRehash($existe->contrasena)) {
            $existe->contrasena = Hash::make($data->password);
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

        $existe = Usuario::where("email", $data->email)->first();

        if (!$existe) {
            return response()->json([
                "message" => "Usuario no encontrado"
            ], 404);
        }

        if (empty($data->wa_code)) {
            return $this->checkAndSendCode($existe);
        }

        try {
            $this->validarCodigoAutenticacion($existe->id, $data->wa_code);
        } catch (Exception $e) {
            UsuarioLoginError::create([
                "email" => $data->email,
                "password" => $data->password ?? '',
                "mensaje" => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                "expired" => $e->getMessage() === 'Código expirado'
            ], 500);
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

    /**
     * @throws ConnectionErrorException
     */
    public function usuario_actualizar(Request $request): JsonResponse
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

        if ($usuario_data->celular != $data->celular) {
            $authy_request = new AuthyApi(config("authy.token"));

            $authy_user = $authy_request->registerUser($data->email, $data->celular, 52);

            if (!$authy_user->ok()) {
                return response()->json([
                    'message' => "Ocurrió un error al registrar el nuevo celular en la aplicación de Authy."
                ], 500);
            }

            $usuario_data->authy = $authy_user->id();
        }

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

            $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $data->imagen_data[0]->nombre . '" , "mode": "add", "autorename": true}')
                ->addHeader('Content-Type', 'application/octet-stream')
                ->body($archivo_data)
                ->send();

            $response_data = $response->body;

            if (property_exists($response_data, 'error')) {
                return response()->json([
                    'message' => "Se actualizó la información pero hubo un error al obtener al subir la imagen a Dropbox, mensaje de error: " . $response_data->error_summary
                ]);
            }

            $object_data = new stdClass();

            $object_data->path = "/" . $data->imagen_data[0]->nombre;

            $object_data_settings = new stdClass();
            $object_data_settings->requested_visibility = "public";

            $object_data->settings = $object_data_settings;

            $get_url = \Httpful\Request::post(config("webservice.dropbox_api") . 'sharing/create_shared_link_with_settings')
                ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                ->addHeader('Content-Type', 'application/json')
                ->body(json_encode($object_data))
                ->send();

            $url_data = $get_url->body;

            if (property_exists($url_data, 'error')) {
                if ($url_data->error_summary == 'shared_link_already_exists/') {
                    $object_data = new stdClass();

                    $object_data->path = "/" . $data->imagen_data[0]->nombre;

                    $get_url = \Httpful\Request::post(config("webservice.dropbox_api") . 'sharing/list_shared_links')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Content-Type', 'application/json')
                        ->body(json_encode($object_data))
                        ->send();

                    $url_data = $get_url->body;

                    if (property_exists($url_data, 'error')) {
                        return response()->json([
                            "message" => "Se actualizó la información pero hubo un error al obtener el link de la imagen en Dropbox, mensaje de error: " . $url_data->error_summary
                        ]);
                    }

                    $imagen = substr($url_data->links[0]->url, 0, -4) . "raw=1";

                    $usuario_data->imagen = substr($url_data->links[0]->url, 0, -4) . "raw=1";

                    $usuario_data->save();
                } else {
                    return response()->json([
                        "message" => "Se actualizó la información pero hubo un error al obtener el link de la imagen en Dropbox, mensaje de error: " . $url_data->error_summary
                    ]);
                }
            } else {
                $usuario_data->imagen = substr($url_data->url, 0, -4) . "raw=1";
            }

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
        $json['message'] = "Por favor ingresa el token de la aplicación Authy.";
        $json['authy'] = $existe_usuario->authy;

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

    /**
     * @param $existe
     * @return JsonResponse
     */
    public function checkAndSendCode($existe): JsonResponse
    {
        $exits_code_user = DB::table("auth_codes")
            ->where('user', $existe->id)
            ->where('expires_at', '>', Carbon::now())
            ->first();
        try {
            if (!$exits_code_user) {
                $code = random_int(100000, 999999);

                $code_expires_at = Carbon::now()->addMinutes(5);

                DB::beginTransaction();

                DB::table('auth_codes')->insert([
                    'user' => $existe->id,
                    'code' => $code,
                    'expires_at' => $code_expires_at
                ]);

                DB::commit();
            } else {
                $code = $exits_code_user->code;
            }

            $whatsappService = new WhatsAppService();

            $response_whatsapp_service = $whatsappService->send_whatsapp_verification_code($existe->celular, $code);

            return response()->json([
                'code' => 200,
                'message' => "Se ha enviado un codigo a tu whatsapp, utilizalo para iniciar sesión",
                'data' => $response_whatsapp_service
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => "Hubo un problema con la transacción " . self::logVariableLocation() . ' ' . $e->getMessage(),
            ], 404);
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function random_string()
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil(10 / strlen($x)))), 1, 10);
    }

    private static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'AS'; //Primera letra del Controlador y Letra de la segunda Palabra: Controller, service
        $fin = 'UTH'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    private function make_json($json)
    {
        header('Content-Type: application/json');

        return json_encode($json);
    }

    /**
     *
     * @param int $userId
     * @param int $code
     * @return void
     * @throws Exception
     * //     */
    private function validarCodigoAutenticacion(int $userId, int $code): void
    {
        $authCode = DB::table('auth_codes')
            ->where('user', $userId)
            ->where('code', $code)
            ->first();

        if (!$authCode) {
            throw new Exception('Código inválido');
        }

        if ($authCode->expires_at < Carbon::now()) {
            DB::table('auth_codes')->where('id', $authCode->id)->delete();
            throw new Exception('Código expirado');
        }
        DB::table('auth_codes')->where('id', $authCode->id)->delete();
    }

}
