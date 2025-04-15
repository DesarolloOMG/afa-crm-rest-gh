<?php

namespace App\Http\Controllers;

use App\Http\Services\DocumentoService;
use App\Http\Services\GeneralService;

use App\Models\Generalmodel;
use App\Models\NotificacionUsuario;
use App\Models\UsuarioEmpresa;
use App\Models\UsuarioSubnivelNivel;
use App\Models\SubNivel;
use App\Models\UsuarioMarketplaceArea;
use App\Models\Usuario;
use App\Models\Area;
use App\Models\Empresa;
use App\Models\Nivel;
use App\Models\MarketplaceArea;
use App\Models\UsuarioLoginError;
use App\Models\UsuarioIP;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Mailgun\Mailgun;
use DB;

class AuthController extends Controller
{
    public function auth_login(Request $request)
    {
        $excepciones = ['balexander.rosas@gmail.com','lorena@omgcorp.com.mx', 'cesar@omg.com.mx', 'lupita81lara@gmail.com', 'isabel@arome.mx', 'sauladrian.arias@gmail.com', 'alberto@omg.com.mx', 'efren@omg.com.mx'];

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

//        if (empty($data->authy)) {
//            UsuarioLoginError::create([
//                "email" => $data->email,
//                "password" => $data->password,
//                "mensaje" => "El código de Authy no fue proporcionado"
//            ]);
//
//            return response()->json([
//                "message" => "Abre tu aplicación de Authy y escribe el codigo que aparece para poder iniciar sesión"
//            ], 500);
//        }
//        if (!in_array(strtolower($data->email), $excepciones)) {
//            $validate_authy = DocumentoService::authy($existe->id, $data->authy);
//
//            if ($validate_authy->error) {
//                UsuarioLoginError::create([
//                    "email" => $data->email,
//                    "password" => $data->password,
//                    "mensaje" => "Error al validar el token de authy: " . $validate_authy->mensaje
//                ]);
//
//                return response()->json([
//                    "message" => "Error al validar el token de Authy: " . $validate_authy->mensaje
//                ], 500);
//            }
//        }

        if (!Hash::check($data->password, $existe->contrasena)) {
            UsuarioLoginError::create([
                "email" => $data->email,
                "password" => $data->password,
                "mensaje" => "Contraseña incorrecta"
            ]);

            return response()->json([
                "message" => "Usuario no encontrado"
            ], 404);
        }

        $usuario = Usuario::find($existe->id);

        if (Hash::needsRehash($existe->contrasena)) {
            $usuario->contrasena = Hash::make($data->password);
        }

        $usuario->last_ip = $request->ip();

        UsuarioIP::create([
            "id_usuario" => $existe->id,
            "ip" => $request->ip()
        ]);

        $usuario->save();

        $usuario_data = self::usuario_data($usuario->id);

        return response()->json([
            'message' => "Bienvenido " . $usuario->nombre,
            'token' => Generalmodel::jwt(json_encode($usuario_data))
        ]);
    }

    public function auth_reset(Request $request)
    {
        $excepciones = ['balexander.rosas@gmail.com','lorena@omgcorp.com.mx', 'cesar@omg.com.mx', 'lupita81lara@gmail.com', 'isabel@arome.mx', 'sauladrian.arias@gmail.com', 'alberto@omg.com.mx', 'efren@omg.com.mx'];

        $data = json_decode($request->input("data"));

        $existe = Usuario::where("email", $data->email)->first();

        if (!$existe) {
            return response()->json([
                "message" => "No se encontró el usuario con el correo proporcionado"
            ], 404);
        }

        if (!in_array(strtolower($data->email), $excepciones)) {
            if (empty($data->authy)) {
                return response()->json([
                    "message" => "Para iniciar sesión, abre tu aplicación Authy registrada con tu correo y escribe el codigo que aparece en tu pantalla"
                ], 500);
            }

            $validate_authy = DocumentoService::authy($existe->id, $data->authy);

            if ($validate_authy->error) {
                return response()->json([
                    "message" => "Error al validar el token de Authy: " . $validate_authy->mensaje,
                ], 500);
            }
        }

        $contrasena = GeneralService::randomString();

        $view = view('email.reset_password')
            ->with([
                'usuario' => $existe->nombre,
                'anio' => date('Y'),
                'contrasena' => $contrasena
            ]);

        $mg = Mailgun::create(config("mailgun.token"));
        $mg->sendMessage(config("mailgun.domain"), array(
            'from' => config("mailgun.email_from"),
            'to' => $existe->email,
            'subject' => 'Reseteo de contraseña.',
            'html' => $view
        ));

        $usuario_data = Usuario::find($existe->id);

        $usuario_data->contrasena = Hash::make($contrasena);
        $usuario_data->save();

        return response()->json([
            'message' => "Se te ha enviado un email con tu contraseña temporal."
        ]);
    }

    public function usuario_actualizar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $imagen = "";

        $existe_email = Usuario::where("email", $data->email)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_email) {
            return response()->json([
                "message" => "Ya éxiste un usuario con el email proporcionado"
            ], 400);
        }


        $existe_celular = Usuario::where("celular", $data->celular)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_celular) {
            return response()->json([
                "message" => "Ya éxiste un usuario con el celular proporcionado"
            ], 400);
        }

        $usuario_data = Usuario::find($data->id);

        if ($usuario_data->celular != $data->celular) {
            $authy_user_id = 0;
            $authy_request = new \Authy\AuthyApi(config("authy.token"));

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

            $dropbox_id = $response->body->id;

            $object_data = new \stdClass();

            $object_data->path = "/" . $data->imagen_data[0]->nombre;

            $object_data_settings = new \stdClass();
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
                    $object_data = new \stdClass();

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
        $user_id    = $request->input('user_id');
        $json       = array();

        $exists     = Usuario::v_existe_usuario($user_id);

        if (empty($exists)) {
            $json['code']       = 401;
            $json['message']    = "Usuario no encontrado";
        }

        $json['code']   = 200;
        $json['user']   = $exists;

        return $this->make_json($json);
    }

    public function exists_user(Request $request)
    {
        $email = $request->input('email');

        $existe_usuario = Usuario::existe_usuario($email);

        if (empty($existe_usuario)) {
            $json['code']   = 404;
            $json['message']    = "No se encontró ningún usuario registrado con el email proporcionado.";

            return $this->make_json($json);
        }

        $json['code']       = 200;
        $json['message']    = "Por favor ingresa el token de la aplicación Authy.";
        $json['authy']      = $existe_usuario->authy;

        return $this->make_json($json);
    }

    public function usuario_notificacion($offset, Request $request)
    {
        $auth = json_decode($request->auth);

        $notificaciones = NoficacionUsuario::obtener_notificaciones($auth->id, $offset);

        foreach ($notificaciones as $notificacion) {
            $notificacion->data = json_decode($notificacion->data);
        }

        return response()->json([
            'code'  => 200,
            'notificaciones'    => $notificaciones
        ]);
    }

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
            if (in_array($subnivel->id_nivel, $subniveles)) {
                array_push($subniveles[$subnivel->id_nivel], $subnivel->id_subnivel);
            } else {
                array_push($subniveles, $subnivel->id_nivel);

                $subniveles[$subnivel->id_nivel] = [];

                array_push($subniveles[$subnivel->id_nivel], $subnivel->id_subnivel);
            }

            if (!in_array($subnivel->id_nivel, $niveles)) {
                array_push($niveles, $subnivel->id_nivel);
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

    private function random_string($length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }
}
