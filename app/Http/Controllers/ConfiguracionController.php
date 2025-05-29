<?php /** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpParamsInspection */

/** @noinspection PhpUndefinedMethodInspection */

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\GeneralService;
use App\Http\Services\UsuarioService;
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
use App\Models\Paqueteria;
use App\Models\Usuario;
use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConfiguracionController extends Controller
{
    /* Configuracion > Usuarios */
    // OPT
    public function configuracion_usuario_gestion_data(): JsonResponse
    {
        set_time_limit(0);

        $empresaAlmacenes = DB::table('empresa_almacen')
            ->select('empresa_almacen.id', 'empresa.empresa', 'almacen.almacen')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->where('empresa_almacen.id_empresa', '!=', 0)
            ->orderBy('empresa.id')
            ->get();

        $grupoEmpresaAlmacen = $empresaAlmacenes->groupBy('empresa')->map(function ($items, $empresa) {
            return (object)[
                'nombre' => $empresa,
                'data' => $items->map(function ($item) {
                    return (object)[
                        'id' => $item->id,
                        'almacen' => $item->almacen
                    ];
                })->values()
            ];
        })->values();

        $usuarios = Usuario::with([
            "marketplaces",
            "subniveles",
            "empresas",
        ])
            ->whereNotIn("id", [0,1])
            ->where("status", '!=', '0')
            ->select('celular', 'email', 'id', 'imagen', 'nombre')
            ->get();

        $usuarioEmpresaAlmacen = DB::table('usuario_empresa_almacen')
            ->select('id_usuario', 'id_empresa_almacen')
            ->get()
            ->groupBy('id_usuario');

        $usuarioDivision = DB::table('usuario_division')
            ->join('division', 'usuario_division.id_division', '=', 'division.id')
            ->select('id_usuario', 'id_division', 'division.division')
            ->get()
            ->groupBy('id_usuario');

        foreach ($usuarios as $usuario) {
            $usuario->empresa_almacen = $usuarioEmpresaAlmacen->get($usuario->id, collect())->pluck('id_empresa_almacen');
            $usuario->division = $usuarioDivision->get($usuario->id, collect())->first()->division ?? null;

            if (strpos($usuario->imagen, '/scl/fi/') !== false) {
                $usuario->imagen = 'assets/images/user-profile/user-problem.jpg';
            }
        }

        $niveles = Nivel::with("subniveles")->get();
        $areas = Area::with("marketplaces")
            ->where('area', '!=', 'N/A')
            ->get();

        $empresas = Empresa::select('id', 'empresa')->where('id', '!=', 0)->get();

        $division = DB::table('division')
            ->get();

        return response()->json([
            'usuarios' => $usuarios,
            'areas' => $areas,
            'niveles' => $niveles,
            'empresas' => $empresas,
            'division' => $division,
            'empresa_almacen' => $grupoEmpresaAlmacen
        ]);
    }

    // OPT

    /**
     * @throws Throwable
     */
    public function configuracion_usuario_gestion_registrar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $existe_usuario = Usuario::where(function ($q) use ($data) {
            $q->where('email', $data->email)
                ->orWhere('celular', $data->celular);
        })->where('id', '<>', $data->id)->first();

        if ($existe_usuario) {
            $campoDuplicado = $existe_usuario->email === $data->email ? 'correo' : 'celular';
            return response()->json([
                "message" => "Ya existe un usuario con el $campoDuplicado proporcionado: " . $existe_usuario->nombre
            ], 500);
        }

        if ($data->id == 0) {
            $contrasena = UsuarioService::crearUsuario($data, $auth);
            $message = "Usuario creado correctamente. Usuario: $data->email - Contraseña: $contrasena";
        } else {
            $actualizado = UsuarioService::actualizarUsuario($data);
            if (!$actualizado) {
                return response()->json([
                    "message" => "No se encontró el usuario para su edición, favor de contactar a un administrador"
                ], 404);
            }
            $message = "Usuario editado correctamente";
        }

        return response()->json(["message" => $message]);
    }

    // OPT
    public function configuracion_usuario_gestion_desactivar($usuario): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = Usuario::find($usuario);

            if ($user) {
                $actualizacionExitosa = DB::table('usuario')->where('id', $usuario)->update([
                    'status' => 0
                ]);

                if ($actualizacionExitosa) {
                    Usuario::findOrFail($usuario)->delete();
                    DB::commit();

                    return response()->json([
                        'message' => "Usuario desactivado correctamente"
                    ]);
                } else {
                    return response()->json([
                        'message' => "Error al desactivar el usuario"
                    ], 500);
                }
            } else {
                return response()->json([
                    'message' => "Usuario no encontrado"
                ], 404);
            }
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => "Error al desactivar el usuario"
            ], 500);
        }
    }

    //OPT
    public function configuracion_usuario_configuarcion_data(): JsonResponse
    {
        $niveles = Nivel::get();
        $areas = Area::where('area', '!=', 'N/A')->get();
        $subnivelNivel = DB::table('subnivel_nivel')
            ->join('subnivel', 'subnivel_nivel.id_subnivel', '=', 'subnivel.id')
            ->join('nivel', 'subnivel_nivel.id_nivel', '=', 'nivel.id')
            ->select('subnivel.subnivel', 'nivel.nivel', 'subnivel_nivel.*')
            ->get();
        $divisiones = DB::table('division')->get();

        return response()->json([
            'areas' => $areas,
            'niveles' => $niveles,
            'divisiones' => $divisiones,
            'subnivel_nivel' => $subnivelNivel,
        ]);
    }

    //OPT
    public function configuracion_usuario_configuracion_area(Request $request): JsonResponse
    {
        $areaData = json_decode($request->input('area'));
        $isNew = $areaData->id == 0;

        if ($isNew) {
            $id_area = DB::table('area')->insertGetId([
                'area' => $areaData->area,
                'status' => 1
            ]);

            $message = "Área creada correctamente";
        } else {
            DB::table('area')
                ->where('id', $areaData->id)
                ->update(['area' => $areaData->area]);

            $id_area = $areaData->id;
            $message = "Área actualizada correctamente";
        }

        return response()->json([
            'code' => 200,
            'message' => $message,
            'area' => $id_area,
        ]);
    }


    public function configuracion_usuario_configuracion_nivel(Request $request): JsonResponse
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

    public function configuracion_usuario_configuracion_subnivel(Request $request): JsonResponse
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

        }

        $json['code'] = 200;

        return response()->json($json);
    }

    /* Configuracion > Sistema */
    public function configuracion_sistema_marketplace_data(): JsonResponse
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

        GeneralService::sendEmailToAdmins("Configuración de marketplaces", "El usuario " . $user->nombre . " solicitó ver las credenciales para la api del marketplace " . $marketplace_api->marketplace_area->area->area . " / " . $marketplace_api->marketplace_area->marketplace->marketplace, "", 1);

        return response()->json([
            "data" => $decoded_secret
        ]);
    }

    public function configuracion_sistema_marketplace_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

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

    public function getAlmacenes(): JsonResponse
    {
        $almacenes = EmpresaAlmacen::with('almacen')->with('empresa')->get();

        return response()->json([
            "data" => $almacenes
        ]);
    }

    public function guardar_almacen(Request $request)
    {
        $data = json_decode($request->input('data'));
        $json = array();

        if ($data->id == 0) {
            $existe_almacen = Almacen::consulta(trim($data->almacen));

            if (empty($existe_almacen)) {
                $id_almacen = Almacen::insertGetId(['almacen' => trim($data->almacen), 'codigo' => trim($data->codigo)]);
            } else {
                $id_almacen = $existe_almacen->id;

                Almacen::editar_datos_almacen($id_almacen, trim($data->almacen), trim($data->codigo));
            }

            $json['message'] = "Almacén creado correctamente";
        } else {
            $id_almacen = $data->id;

            Almacen::editar_datos_almacen($data->id, trim($data->almacen), trim($data->codigo));

            $json['message'] = "Almacén actualizado correctamente";
        }

        $json['code'] = 200;
        $json['almacen'] = $id_almacen;

        return $this->make_json($json);
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

    public function configuracion_logout()
    {
        $notificacion['reload_users'] = 1;

        event(new PusherEvent(json_encode($notificacion)));
    }

    private function make_json($json)
    {
        header('Content-Type: application/json');

        return json_encode($json);
    }

    #Configuración > Dev

    public function configuracion_dev_data(): JsonResponse
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


    public function configuracion_sistema_impresora_create(Request $request): JsonResponse
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

    public function configuracion_sistema_impresora_retrive(): JsonResponse
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

    public function configuracion_sistema_impresora_update(Request $request): JsonResponse
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

            return response()->json(['message' => 'Recurso actualizado con éxito']);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function configuracion_sistema_impresora_delete($impresora_id): JsonResponse
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

            return response()->json(['message' => 'Recurso eliminado con éxito']);
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
