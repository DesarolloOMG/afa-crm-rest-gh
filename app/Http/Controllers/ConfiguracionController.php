<?php

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\GeneralService;
use App\Http\Services\WhatsAppService;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Empresa;
use App\Models\EmpresaAlmacen;
use App\Models\Marketplace;
use App\Models\MarketplaceApi;
use App\Models\MarketplaceArea;
use App\Models\MarketplaceAreaEmpresa;
use App\Models\Nivel;
use App\Models\NotificacionUsuario;
use App\Models\Paqueteria;
use App\Models\Usuario;
use App\Models\Usuario_Login_Error;
use App\Models\UsuarioEmpresa;
use App\Models\UsuarioMarketplaceArea;
use App\Models\UsuarioSubnivelNivel;
use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mailgun\Mailgun;
use stdClass;
use Throwable;

class ConfiguracionController extends Controller
{
    /* Configuracion > Usuarios */
    public function configuracion_usuario_gestion_data()
    {
        set_time_limit(0);

        $response = array();
        $resultado = DB::table('empresa_almacen')
            ->select('empresa_almacen.id', 'empresa_almacen.id_empresa', 'empresa_almacen.id_almacen', 'almacen.almacen', 'empresa.empresa')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->where('empresa_almacen.id_empresa', '!=', 0)
            ->orderBy('id_empresa', 'asc')
            ->get()->toArray();
        $gruposPorEmpresa = [];

        foreach ($resultado as $item) {
            $empresa = $item->empresa;

            if (!isset($gruposPorEmpresa[$empresa])) {
                $gruposPorEmpresa[$empresa] = [];
            }
            unset($item->id_empresa);
            unset($item->id_almacen);

            $gruposPorEmpresa[$empresa][] = $item;
        }

        foreach ($gruposPorEmpresa as $nombreEmpresa => $grupo) {
            $empresasSeparadas = new stdClass();

            $empresasSeparadas->nombre = $nombreEmpresa;
            $empresasSeparadas->data = $grupo;

            array_push($response, $empresasSeparadas);
        }

        $usuarios = Usuario::with([
            "marketplaces",
            "subniveles",
            "empresas",
        ])
            ->where("id", "<>", 0)
            ->get();

        $niveles = Nivel::with("subniveles")->get();
        $areas = Area::with("marketplaces")
            ->where('area', '!=', 'N/A')
            ->get();
        $empresas = Empresa::where("id", "<>", 0)->get();

        $area =
            DB::table('usuario')
                ->select('area')
                ->where('status', 1)
                ->groupBy('area')
                ->orderBy('area')
                ->get()
                ->toarray();

        foreach ($usuarios as $key) {
            $empresaAlmacenIds = DB::table('usuario_empresa_almacen')
                ->where('id_usuario', $key->id)
                ->pluck('id_empresa_almacen')
                ->toArray();

            $key->empresa_almacen = $empresaAlmacenIds;
        }
        return response()->json([
            'usuarios' => $usuarios,
            'areas' => $areas,
            'niveles' => $niveles,
            'empresas' => $empresas,
            'area' => $area,
            'empresa_almacen' => $response
        ]);
    }

    public function configuracion_usuario_gestion_desactivar($usuario)
    {
        try {
            //Se inicia la transaccion por si hay algun error no se afecte la bd
            DB::beginTransaction();

            //se valida que el usuario exista
            $user = Usuario::find($usuario);

            if ($user) {
                //Se hizo asi porque no estaba actualizando usando los modelos
                $actualizacionExitosa = DB::table('usuario')->where('id', $usuario)->update([
                    'status' => 0
                ]);

                if ($actualizacionExitosa) {
                    // La actualización se realizó correctamente
                    //Se procede a hacer un soft delete para no borrar todo
                    Usuario::findOrFail($usuario)->delete();
                    //Se hace la afectacion en la bd
                    DB::commit();

                    return response()->json([
                        'message' => "Usuario desactivado correctamente"
                    ]);
                } else {
                    // La actualización falló
                    return response()->json([
                        'message' => "Error al desactivar el usuario"
                    ], 500);
                }
            } else {
                // El usuario no fue encontrado
                return response()->json([
                    'message' => "Usuario no encontrado"
                ], 404);
            }
        } catch (Exception $e) {
            // Si ocurre un error, revertir la transacción
            DB::rollBack();

            return response()->json([
                'message' => "Error al desactivar el usuario"
            ], 500);
        }
    }

    public function configuracion_usuario_gestion_registrar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $area = json_decode($request->input('area'));
        $empresa_almacen = json_decode($request->input('uea'));
        $auth = json_decode($request->auth);

        $existe_correo = Usuario::where("email", $data->email)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_correo) {
            return response()->json([
                "message" => "Ya existe un usuario con el correo proporcionado: " . $existe_correo->nombre . ""
            ], 500);
        }

        $existe_celular = Usuario::where("celular", $data->celular)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_celular) {
            return response()->json([
                "message" => "Ya existe un usuario con el correo proporcionado: " . $existe_celular->nombre . ""
            ], 500);
        }

        if ($data->id == 0) {

            $contrasena = GeneralService::randomString(10);

            $usuario = Usuario::create([
                'nombre' => mb_strtoupper($data->nombre, 'UTF-8'),
                'email' => $data->email,
                'contrasena' => Hash::make($contrasena),
                'tag' => $contrasena,
                'celular' => $data->celular
            ]);

            foreach ($data->marketplaces as $marketplace) {
                UsuarioMarketplaceArea::create([
                    'id_usuario' => $usuario->id,
                    'id_marketplace_area' => $marketplace
                ]);
            }

            foreach ($data->subniveles as $subnivel) {
                UsuarioSubnivelNivel::create([
                    'id_usuario' => $usuario->id,
                    'id_subnivel_nivel' => $subnivel
                ]);
            }

            foreach ($data->empresas as $empresa) {
                UsuarioEmpresa::create([
                    'id_usuario' => $usuario->id,
                    'id_empresa' => $empresa
                ]);
            }

            $creador = Usuario::find($auth->id);

            $view = view('email.notificacion_usuario_creado')->with([
                "usuario" => $data->nombre,
                "creador" => $creador->nombre,
                "correo" => $data->email,
                "contrasena" => $contrasena,
                "anio" => date("Y")
            ]);

            $mg = Mailgun::create(config("mailgun.token"));

            $mg->messages()->send(
                config("mailgun.domain"),
                array(
                    'from' => config("mailgun.email_from"),
                    'to' => $data->email,
                    'subject' => "Tu nuevo usuario para CRM OMG International",
                    'html' => $view->render()
                )
            );

            DB::table('usuario')->where('nombre', mb_strtoupper($data->nombre, 'UTF-8'))->whereNull('area')->update([
                'area' => $area
            ]);
            $userr = DB::table('usuario')->where('nombre', mb_strtoupper($data->nombre, 'UTF-8'))->first();

            foreach ($empresa_almacen as $key) {

                $userId = $userr->id;

                if ($userId > 0 && $userId != '0') {
                    DB::table('usuario_empresa_almacen')->insert([
                        'id_usuario' => $userId,
                        'id_empresa_almacen' => $key,
                    ]);
                }
            }
        } else {
            $usuario_data = Usuario::find($data->id);

            if (!$usuario_data) {
                return response()->json([
                    "message" => "No se encontró el usuario para su edición, favor de contactar a un administrador"
                ], 404);
            }

            $usuario_data->nombre = mb_strtoupper($data->nombre, 'UTF-8');
            $usuario_data->email = $data->email;
            $usuario_data->celular = $data->celular;

            $usuario_data->save();

            UsuarioEmpresa::where("id_usuario", $data->id)->delete();

            foreach ($data->empresas as $empresa) {
                UsuarioEmpresa::create([
                    'id_usuario' => $data->id,
                    'id_empresa' => $empresa
                ]);
            }

            UsuarioSubnivelNivel::where("id_usuario", $data->id)->delete();

            foreach ($data->subniveles as $subnivel) {
                UsuarioSubnivelNivel::create([
                    'id_usuario' => $data->id,
                    'id_subnivel_nivel' => $subnivel
                ]);
            }

            UsuarioMarketplaceArea::where("id_usuario", $data->id)->delete();

            foreach ($data->marketplaces as $marketplace) {
                UsuarioMarketplaceArea::create([
                    'id_usuario' => $data->id,
                    'id_marketplace_area' => $marketplace
                ]);
            }
            DB::table('usuario')->where('id', $data->id)->update([
                'area' => $area
            ]);

            $allEmpresaAlmacen = DB::table('usuario_empresa_almacen')->where('id_usuario', $data->id)->pluck('id_empresa_almacen')->toArray();

            $addItems = array_diff($empresa_almacen, $allEmpresaAlmacen);
            foreach ($addItems as $item) {
                DB::table('usuario_empresa_almacen')->insert([
                    'id_usuario' => $data->id,
                    'id_empresa_almacen' => $item,
                ]);
            }

            $deleteItems = array_diff($allEmpresaAlmacen, $empresa_almacen);
            DB::table('usuario_empresa_almacen')->where('id_usuario', $data->id)->whereIn('id_empresa_almacen', $deleteItems)->delete();
        }

        $message = $data->id != 0
            ? "Usuario editado correctamente"
            : "Usuario creado correctamente. Usuario: " . $data->email . " - Contraseña: " . $contrasena;

        return response()->json(["message" => $message]);
    }

    public function configuracion_usuario_configuarcion_data()
    {
        $niveles = Nivel::with("subniveles")->get();
        $areas = Area::where('area', '!=', 'N/A')->get();
        return response()->json([
            'areas' => $areas,
            'niveles' => $niveles
        ]);
    }

    public function configuracion_usuario_configuracion_area(Request $request)
    {
        $area = json_decode($request->input('area'));
        $json = array();

        if ($area->id == 0) {
            $id_area = DB::table('area')
                ->insertGetId([
                    'area' => $area->area,
                    'status' => 1
                ]);

            $json['message'] = "Área creada correctamente";
        } else {
            DB::table('area')
                ->where(['id' => $area->id])
                ->update([
                    'area' => $area->area
                ]);

            $json['message'] = "Área actualizada correctamente";

            $id_area = $area->id;
        }

        $json['code'] = 200;
        $json['area'] = $id_area;

        return response()->json($json);
    }

    public function configuracion_usuario_configuracion_nivel(Request $request)
    {
        $nivel = json_decode($request->input('nivel'));
        $json = array();

        if ($nivel->id == 0) {
            $id_nivel = DB::table('nivel')
                ->insertGetId([
                    'nivel' => $nivel->nivel,
                    'status' => 1
                ]);

            $json['message'] = "Nivel creado correctamente";
        } else {
            DB::table('nivel')
                ->where(['id' => $nivel->id])
                ->update([
                    'nivel' => $nivel
                ]);

            $json['message'] = "Nivel actualizada correctamente";

            $id_nivel = $nivel->id;
        }

        $json['code'] = 200;
        $json['nivel'] = $id_nivel;

        return response()->json($json);
    }

    public function configuracion_usuario_configuracion_subnivel(Request $request)
    {
        $subnivel = json_decode($request->input('subnivel'));
        $json = array();

        if ($subnivel->id == 0) {
            $existe_subnivel = DB::select("SELECT id FROM subnivel WHERE subnivel = '" . trim($subnivel->subnivel) . "'");

            if (empty($existe_subnivel)) {
                $id_subnivel = DB::table('subnivel')
                    ->insertGetId([
                        'subnivel' => $subnivel->subnivel,
                        'status' => 1
                    ]);

                DB::table('subnivel_nivel')
                    ->insert([
                        'id_subnivel' => $id_subnivel,
                        'id_nivel' => $subnivel->nivel
                    ]);
            } else {
                DB::table('subnivel_nivel')
                    ->insert([
                        'id_subnivel' => $existe_subnivel[0]->id,
                        'id_nivel' => $subnivel->nivel
                    ]);

                $id_subnivel = $existe_subnivel[0]->id;
            }

            $json['message'] = "Subnivel creado correctamente";
            $json['subnivel'] = $id_subnivel;
        } else {
            DB::table('subnivel')
                ->where(['id' => $subnivel->id])
                ->update([
                    'subnivel' => $subnivel->subnivel
                ]);

            DB::table('subnivel_nivel')
                ->where(['id_subnivel' => $subnivel->id])
                ->update([
                    'id_nivel' => $subnivel->nivel
                ]);

            $json['message'] = "Subnivel actualizado correctamente";

            $id_nivel = $subnivel->id;
        }

        $json['code'] = 200;

        return response()->json($json);
    }

    /* Configuracion > Sistema */
    public function configuracion_sistema_marketplace_data()
    {
        $marketplaces = MarketplaceArea::with("marketplace", "area", "api", "empresa")->get();
        $empresas = Empresa::where("id", "<>", 0)->get();
        $areas = Area::where('area', '!=', 'N/A')->get();

        return response()->json([
            "areas" => $areas,
            "marketplaces" => $marketplaces,
            "empresas" => $empresas
        ]);
    }

    /**
     * @throws Throwable
     */
    public function configuracion_sistema_marketplace_ver_credenciales(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        $marketplace_api = MarketplaceApi::with("marketplace_area.marketplace", "marketplace_area.area")->find($data->marketplace_api);

        if (!$marketplace_api) {
            return response()->json([
                "message" => "No se encontró información del API del marketplace" . " " . self::logVariableLocation()
            ], 500);
        }

        try {
            $decoded_secret = Crypt::decrypt($marketplace_api->secret);
        } catch (DecryptException $e) {
            return response()->json([
                "message" => $e->getMessage() . " " . self::logVariableLocation()
            ], 500);
        }

        $user = DB::table("usuario")->find($auth->id);

        GeneralService::sendEmailToAdmins("Configuración de marketplaces", "El usuario " . $user->nombre . " solicitó ver las credenciales para la api del marketplace " . $marketplace_api->marketplace_area->area->area . " / " . $marketplace_api->marketplace_area->marketplace->marketplace . "", "", 1);

        return response()->json([
            "data" => $decoded_secret
        ]);
    }

    public function configuracion_sistema_marketplace_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $json = array();

        $existe_marketplace = Marketplace::where("marketplace", $data->marketplace->marketplace)->first();

        if (!$existe_marketplace) {
            $data->marketplace->id = Marketplace::create([
                "marketplace" => mb_strtoupper($data->marketplace->marketplace, "UTF-8")
            ])->id;
        } else {
            $data->marketplace->id = $existe_marketplace->id;
        }

        $existe_marketplace_area = MarketplaceArea::where("id_marketplace", $data->marketplace->id)
            ->where("id_area", $data->area->id)
            ->where("id", "<>", $data->id)
            ->first();

        if ($existe_marketplace_area) {
            return response()->json([
                "message" => "Ya éxiste un marketplace con los datos proporcionados"
            ], 500);
        }

        if ($data->id != 0) {
            $marketplace_area = MarketplaceArea::find($data->id);
            $marketplace_area->id_marketplace = $data->marketplace->id;
            $marketplace_area->id_area = $data->area->id;
            $marketplace_area->serie = $data->serie;
            $marketplace_area->serie_nota = $data->serie_nota;
            $marketplace_area->publico = $data->publico;

            $marketplace_area->save();
        } else {
            $data->id = MarketplaceArea::create([
                "id_marketplace" => $data->marketplace->id,
                "id_area" => $data->area->id,
                "serie" => $data->serie,
                "serie_nota" => $data->serie_nota,
                "publico" => $data->publico
            ])->id;
        }

        if ($data->api->extra_1 || $data->api->extra_2 || $data->api->app_id || $data->api->secret) {
            if ($data->api->id != 0) {
                $marketplace_api = MarketplaceApi::find($data->api->id);

                $marketplace_api->extra_1 = $data->api->extra_1;
                $marketplace_api->extra_2 = $data->api->extra_2;
                $marketplace_api->app_id = $data->api->app_id;
                $marketplace_api->secret = Crypt::encrypt($data->api->secret);
                $marketplace_api->guia = $data->api->guia;

                $marketplace_api->save();
            } else {
                $data->api->id = MarketplaceApi::create([
                    "id_marketplace_area" => $data->id,
                    "extra_1" => $data->api->extra_1,
                    "extra_2" => $data->api->extra_2,
                    "app_id" => $data->api->app_id,
                    "secret" => Crypt::encrypt($data->api->secret),
                    "guia" => $data->api->guia
                ])->id;
            }
        }

        $existe_empresa = MarketplaceAreaEmpresa::where("id_marketplace_area", $data->id)->first();

        if ($existe_empresa) {
            $marketplace_area_empresa = MarketplaceAreaEmpresa::find($existe_empresa->id);

            if (empty($data->empresa->id_empresa)) {
                $marketplace_area_empresa->delete();
            } else {
                $marketplace_area_empresa->id_empresa = $data->empresa->id_empresa;
                $marketplace_area_empresa->utilidad = $data->empresa->utilidad;

                $marketplace_area_empresa->save();
            }
        } else {
            if (!empty($data->empresa->id_empresa)) {
                if ($data->empresa->id_empresa != 0) {
                    MarketplaceAreaEmpresa::create([
                        "id_marketplace_area" => $data->id,
                        "id_empresa" => $data->empresa->id_empresa,
                        "utilidad" => $data->empresa->utilidad
                    ]);
                }
            }
        }

        return response()->json([
            "message" => $data->id == 0 ? "Marketplace registrado correctamente" : "Marketplace editado correctamente"
        ]);
    }

    public function getAlmacenes()
    {
        $almacenes = EmpresaAlmacen::with('almacen')->with('empresa')->get();

        return response()->json([
            "data" => $almacenes
        ]);
    }

    public function guardar_almacen(Request $request)
    {
        $almacen = $request->input('almacen');

        $existe_almacen = DB::table('almacen')->where('almacen', $almacen)->first();

        if($existe_almacen){
            DB::table('almacen')->where('id', $existe_almacen->id)->update([
                'status' => 1
            ]);

            $this->guardar_empresa_almacen($existe_almacen->id);

            return response()->json([
                'code' => 200,
                'message' => "Almacen creado correctamente"
            ]);
        } else {
            $id_almacen = DB::table('almacen')->insertGetId([
                'almacen' => $almacen,
                'status' => 1
            ]);

            $this->guardar_empresa_almacen($id_almacen);

            return response()->json([
                'code' => 200,
                'message' => "Almacen creado correctamente",
            ]);
        }
    }

    public function eliminar_almacen(Request $request)
    {
        $almacen = $request->input('id');

        DB::table('empresa_almacen')->where('id_almacen', $almacen)->where('id_empresa', 1)->delete();
        DB::table('almacen')->where('id', $almacen)->delete();

        return response()->json([
            'code' => 200,
            'message' => "Almacen eliminado correctamente"
        ]);
    }

    public function guardar_empresa_almacen($almacen){
        $empresa_almacen = DB::table('empresa_almacen')->where('id_empresa', 1)->where('id_almacen', $almacen)->first();

        if(!$empresa_almacen){
            DB::table('empresa_almacen')->insert([
                'id_empresa' => 1,
                'id_almacen' => $almacen,
                'id_impresora_picking' => 3,
                'id_impresora_guia' => 4,
                'id_impresora_etiqueta_envio' => 4,
                'id_impresora_manifiesto' => 4,
            ]);
        }
    }

    public function paqueteria()
    {
        $json = array();

        $paqueterias = Paqueteria::consulta();

        $json['code'] = 200;
        $json['paqueterias'] = $paqueterias;

        return $this->make_json($json);
    }

    public function guardar_paqueteria(Request $request)
    {
        $data = json_decode($request->input('data'));
        $json = array();

        if ($data->id == 0) {
            $existe_paqueteria = Paqueteria::consulta_paqueteria(trim($data->paqueteria));

            if (empty($existe_paqueteria)) {
                $id_paqueteria = Paqueteria::insertGetId([
                    'paqueteria' => trim($data->paqueteria),
                    'codigo' => trim($data->codigo)
                ]);
            } else {
                $id_paqueteria = $existe_paqueteria->id;

                Paqueteria::actualizar_paqueteria($id_paqueteria, trim($data->paqueteria), trim($data->codigo));
            }

            $json['message'] = "Paqueteria creada correctamente";
        } else {
            $id_paqueteria = $data->id;

            Paqueteria::actualizar_paqueteria($id_paqueteria, trim($data->paqueteria), trim($data->codigo));

            $json['message'] = "Paqueteria actualizada correctamente";
        }

        $json['code'] = 200;
        $json['paqueteria'] = $id_paqueteria;

        return $this->make_json($json);
    }

    public function configuracion_logout(Request $request)
    {
        $notificacion['reload_users'] = 1;

//        event(new PusherEvent(json_encode($notificacion)));
    }

    private function make_json($json)
    {
        header('Content-Type: application/json');

        return json_encode($json);
    }

    #Configuración > Dev

    public function configuracion_dev_data()
    {
        set_time_limit(0);
        $inicio = date('m/d/Y h:i:s a', time());

        // $documento_guia = DB::select("SELECT dg.guia, dg.id_documento, d.id_paqueteria
        //                               FROM documento_guia dg
        //                               INNER JOIN documento d ON d.id = dg.id_documento 
        //                               INNER JOIN manifiesto m ON m.guia = dg.guia");

        // foreach ($documento_guia as $dg) {
        //     DB::table('manifiesto')->where(['guia' => $dg->guia])->update([
        //         'id_paqueteria' => $dg->id_paqueteria,

        //     ]);
        // }

        // // WHEN 7 THEN 'iVoy' 
        // // WHEN 8 THEN 'RedPack' 
        // // WHEN 9 THEN '99 minutos' 
        // // WHEN 10 THEN 'DHL' 
        // // WHEN 11 THEN 'MEL'
        // // WHEN 12 THEN 'Coppel Express'
        // // WHEN 15 THEN 'Walmart'
        // // WHEN 18 THEN 'UPS' 
        // // WHEN 20 THEN 'PAQUETEXPRESS'
        // // WHEN 22 THEN 'Estafeta'
        // // WHEN 30 THEN 'RedPack'
        // // WHEN 34 THEN 'Fedex'
        $mitad = date('m/d/Y h:i:s a', time());

        $manifiesto = DB::select("SELECT id, guia,
                                    CASE LENGTH(guia)
                                    WHEN 7 THEN 12
                                    WHEN 8 THEN 11
                                    WHEN 9 THEN 5
                                    WHEN 10 THEN 2
                                    WHEN 11 THEN 14
                                    WHEN 12 THEN 17
                                    WHEN 15 THEN 16
                                    WHEN 18 THEN 18
                                    WHEN 20 THEN 4
                                    WHEN 22 THEN 1
                                    WHEN 30 THEN 11
                                    WHEN 34 THEN 3
                                    ELSE 1
                                    END AS paqueteria
                                  FROM manifiesto
                                  WHERE id_paqueteria IS NULL");


        foreach ($manifiesto as $m) {
            DB::table('manifiesto')->where(['id' => $m->id])->update([
                'id_paqueteria' => $m->paqueteria,

            ]);
        }
        $final = date('m/d/Y h:i:s a', time());

        return response()->json([
            'code' => 200,
            'message' => "ID PAQUETERIA guardado correctamente.",
            'inicio' => $inicio,
            'mitad' => $mitad,
            'final' => $final,

        ]);
    }


    public function configuracion_sistema_impresora_create(Request $request)
    {
        $data = json_decode($request->input('data'));
        try {
            $id = DB::table('impresora')->insertGetId([
                'nombre' => $data->nombre,
                'tamanio' => $data->tamanio,
                'tipo' => $data->tipo,
                'ip' => $data->ip,
                'servidor' => $data->servidor,
            ]);

            DB::commit();

            return response()->json(['message' => 'Impresora creada con éxito', 'id' => $id], 201);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Error al crear la impresora', 'error' => $e->getMessage()], 400);
        }
    }

    public function configuracion_sistema_impresora_retrive()
    {
        $impresoras = DB::table('impresora')->get();
        $empresas = Empresa::where("id", "<>", 0)->get();
        $areas = Area::where("id", "<>", 0)
            ->where("area", "!=", "N/A")
            ->get();

        return response()->json([
            "areas" => $areas,
            "impresoras" => $impresoras,
            "empresas" => $empresas
        ]);
    }

    public function configuracion_sistema_impresora_update(Request $request)
    {
        $data = json_decode($request->input('data'));

        DB::beginTransaction();

        try {

            $updated = DB::table('impresora')
                ->where('id', $data->id)
                ->update([
                    'nombre' => $data->nombre,
                    'tamanio' => $data->tamanio,
                    'tipo' => $data->tipo,
                    'ip' => $data->ip,
                    'servidor' => $data->servidor
                ]);

            if (!$updated) {
                throw new Exception('Recurso no encontrado o no se realizaron cambios');
            }

            DB::commit();

            return response()->json(['message' => 'Recurso actualizado con éxito'], 200);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function configuracion_sistema_impresora_delete($impresora_id)
    {
        DB::beginTransaction();

        try {
            $deleted = DB::table('impresora')
                ->where('id', $impresora_id)
                ->delete();

            if (!$deleted) {
                throw new Exception('Recurso no encontrado');
            }

            DB::commit();

            return response()->json(['message' => 'Recurso eliminado con éxito'], 200);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'CC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'ION'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

}
