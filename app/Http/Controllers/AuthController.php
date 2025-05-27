<?php
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnused */
/** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use App\Http\Services\DropboxService;
use App\Http\Services\GeneralService;
use App\Http\Services\WhatsAppService;
use App\Models\Generalmodel;
use App\Models\NoficacionUsuario;
use App\Models\Usuario;
use App\Models\UsuarioIP;
use App\Models\UsuarioLoginError;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Mailgun\Mailgun;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class AuthController extends Controller
{
    public function auth_check(Request $request): JsonResponse
    {
        $user = $request->auth;

        return response()->json([
            'message' => 'Token válido',
            'user' => $user
        ]);
    }

    public function auth_login(Request $request): JsonResponse
    {

        $validator = Validator::make(json_decode($request->input('data'), true), [
            'email' => 'required|email',
            'password' => 'required|string',
            'wa_code' => 'nullable|digits:6'
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Datos inválidos ' . self::logVariableLocation(),
                    'errors' => $validator->errors()
                ], 422)
            );
        }

        $data = (object)$validator->getData();

        $existe = Usuario::where("email", $data->email)->first();

        if (!$existe) {
            UsuarioLoginError::create([
                "email" => $data->email,
                "password" => $data->password,
                "mensaje" => "Correo electronico no encontrado"
            ]);

            return response()->json([
                "message" => "Usuario no encontrado " . self::logVariableLocation()
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
                    "message" => "Contraseña incorrecta " . self::logVariableLocation()
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
                'message' => $e->getMessage() . ' ' . self::logVariableLocation(),
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

        $token = Generalmodel::jwt(json_encode($usuario_data));

        $cookie = new Cookie(
            'token',
            $token,
            time() + 60 * 60 * 11,
            '/',
            null,
            false,
            true,
            false,
            'Lax'
        );

        return response()->json([
            'message' => "Bienvenido " . $existe->nombre
        ])->withCookie($cookie);
    }

    private static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'AS'; //Primera letra del Controlador y Letra de la segunda Palabra: Controller, service
        $fin = 'UTH'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    private function checkAndSendCode($existe): JsonResponse
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
                'message' => "Se ha enviado un codigo a tu whatsapp",
                'data' => $response_whatsapp_service
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => "Hubo un problema con la transacción " . self::logVariableLocation() . ' ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * @throws Exception
     */
    private function validarCodigoAutenticacion(int $userId, int $code): void
    {
        $authCode = DB::table('auth_codes')
            ->where('user', $userId)
            ->where('code', $code)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$authCode) {
            throw new Exception('Código inválido o expirado. ' . self::logVariableLocation());
        }

        DB::table('auth_codes')->where('id', $authCode->id)->delete();
    }

    private function usuario_data($usuario_id)
    {
        $usuario = Usuario::with("marketplaces", "subnivelesbynivel", "empresas")->find($usuario_id);

        if (!$usuario) {
            return null;
        }

        $usuario->marketplaces = $usuario->marketplaces->pluck('id_marketplace_area');

        $usuario->empresas = $usuario->empresas->pluck('id_empresa');

        $subniveles = [];
        $niveles = [];

        foreach ($usuario->subnivelesbynivel as $subnivel) {
            $nivelId = $subnivel->id_nivel;
            $subnivelId = $subnivel->id_subnivel;

            if (!isset($subniveles[$nivelId])) {
                $subniveles[$nivelId] = [];
                $niveles[] = $nivelId;
            }

            $subniveles[$nivelId][] = $subnivelId;
        }

        $usuario->subniveles = $subniveles;
        $usuario->niveles = $niveles;

        unset($usuario->contrasena, $usuario->subnivelesbynivel);

        return $usuario;
    }

    public function auth_logout(): JsonResponse
    {
        $cookie = new Cookie(
            'token',
            '',
            time() - 3600,
            '/',
            null,
            false,
            true,
            false,
            'Lax'
        );

        return response()->json([
            'message' => 'Sesión cerrada correctamente'
        ])->withCookie($cookie);
    }

    /**
     * @throws Throwable
     */
    public function auth_reset(Request $request): JsonResponse
    {
        $validator = Validator::make(json_decode($request->input('data'), true), [
            'email' => 'required|email',
            'wa_code' => 'nullable|digits:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Email inválido. ' . self::logVariableLocation(),
                'errors' => $validator->errors()
            ], 422);
        }

        $data = (object)$validator->getData();

        $existe = Usuario::where("email", $data->email)->first();

        if (!$existe) {
            return response()->json([
                "message" => "Usuario no encontrado. " . self::logVariableLocation()
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
                'message' => $e->getMessage() . ' ' . self::logVariableLocation(),
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
        try {
            $mg = Mailgun::create(config("mailgun.token"));

            $mg->messages()->send(config("mailgun.domain"), [
                'from' => config("mailgun.email_from"),
                'to' => $existe->email,
                'subject' => 'Reseteo de contraseña.',
                'html' => $view->render()
            ]);

        } catch (Exception $e) {
            return response()->json([
                "message" => "No se pudo enviar el correo. " . self::logVariableLocation(),
                "error" => $e->getMessage()
            ], 500);
        }

        $usuario_data = Usuario::find($existe->id);
        $usuario_data->contrasena = Hash::make($contrasena);
        $usuario_data->save();

        return response()->json([
            'message' => "Se te ha enviado un email con tu contraseña temporal.",
            'email_sent' => true
        ]);
    }

    public function usuario_actualizar(Request $request): JsonResponse
    {
        $dropbox = new DropboxService();

        $data = json_decode($request->input('data'));
        $imagen = "";

        $validator = Validator::make((array)$data, [
            'id' => 'required|integer|exists:usuarios,id',
            'email' => 'required|email',
            'celular' => 'required',
            'nombre' => 'required|string',
            'contrasena' => 'nullable|string|min:6',
            'imagen_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => "Datos inválidos. " . self::logVariableLocation(),
                "errors" => $validator->errors()
            ], 422);
        }

        if (Usuario::where("email", $data->email)->where("id", "<>", $data->id)->exists()) {
            return response()->json(["message" => "Ya existe un usuario con el email proporcionado. " . self::logVariableLocation()], 400);
        }

        if (Usuario::where("celular", $data->celular)->where("id", "<>", $data->id)->exists()) {
            return response()->json(["message" => "Ya existe un usuario con el celular proporcionado. " . self::logVariableLocation()], 400);
        }

        $usuario = Usuario::find($data->id);

        $usuario->nombre = mb_strtoupper($data->nombre, 'UTF-8');
        $usuario->email = $data->email;
        $usuario->celular = $data->celular;

        if (!empty($data->contrasena)) {
            $usuario->contrasena = Hash::make($data->contrasena);
        }

        $usuario->save();

        if (!empty($data->imagen_data)) {
            try {
                $img = $data->imagen_data[0];

                $dropbox->uploadImage($img->data, $img->nombre, $img->tipo);
                $publicUrl = $dropbox->getPublicLink($img->nombre);

                $usuario->imagen = $publicUrl;
                $usuario->save();
                $imagen = $publicUrl;

            } catch (Exception $e) {
                return response()->json([
                    "message" => "Imagen subida pero hubo un error. " . self::logVariableLocation(),
                    "error" => $e->getMessage()
                ], 500);
            }
        }

        $usuario_data = self::usuario_data($data->id);

        return response()->json([
            'message' => "Información actualizada correctamente",
            'imagen' => $imagen,
            'token' => Generalmodel::jwt(json_encode($usuario_data))
        ]);
    }

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
}
