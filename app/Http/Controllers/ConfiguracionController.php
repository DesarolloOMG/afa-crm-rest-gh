<?php /** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpParamsInspection */

/** @noinspection PhpUndefinedMethodInspection */

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\GeneralService;
use App\Http\Services\UsuarioService;
use App\Http\Services\WhatsAppService;
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
            ->whereNotIn("id", [0, 1])
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
        $niveles = Nivel::where('status', 1)->get();
        $areas = Area::where('area', '!=', 'N/A')->where('status', 1)->get();
        $subnivelNivel = DB::table('subnivel_nivel')
            ->join('subnivel', 'subnivel_nivel.id_subnivel', '=', 'subnivel.id')
            ->join('nivel', 'subnivel_nivel.id_nivel', '=', 'nivel.id')
            ->select('subnivel.subnivel', 'nivel.nivel', 'subnivel_nivel.*')
            ->get();
        $divisiones = DB::table('division')->where('status', 1)->get();

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

    //OP
    public function configuracion_usuario_configuracion_nivel(Request $request): JsonResponse
    {
        $nivel = json_decode($request->input('nivel'));

        if (!isset($nivel->id, $nivel->nivel)) {
            return response()->json([
                'message' => 'Datos incompletos',
                'code' => 400
            ], 400);
        }

        $idNivel = (int)$nivel->id;
        $nombreNivel = trim($nivel->nivel);

        if ($idNivel === 0) {
            $idNivel = DB::table('nivel')->insertGetId([
                'nivel' => $nombreNivel,
                'status' => 1
            ]);

            $message = 'Nivel creado correctamente';
        } else {
            DB::table('nivel')
                ->where('id', $idNivel)
                ->update([
                    'nivel' => $nombreNivel
                ]);

            $message = 'Nivel actualizado correctamente';
        }

        return response()->json([
            'message' => $message,
            'code' => 200,
            'nivel' => $idNivel
        ]);
    }

    //OPT
    public function configuracion_usuario_configuracion_subnivel(Request $request): JsonResponse
    {
        $subnivel = json_decode($request->input('subnivel'));

        if (!isset($subnivel->id, $subnivel->subnivel, $subnivel->nivel)) {
            return response()->json([
                'message' => 'Datos incompletos',
                'code' => 400
            ], 400);
        }

        $idSubnivel = (int)$subnivel->id_subnivel;
        $nombreSubnivel = trim($subnivel->subnivel);
        $idNivel = (int)$subnivel->id_nivel;

        if ($idSubnivel === 0) {
            $existe = DB::table('subnivel')
                ->where('subnivel', $nombreSubnivel)
                ->value('id');

            if (empty($existe)) {
                $idSubnivel = DB::table('subnivel')->insertGetId([
                    'subnivel' => $nombreSubnivel,
                    'status' => 1
                ]);
            } else {
                $idSubnivel = $existe[0]->id;
            }

            DB::table('subnivel_nivel')->insert([
                'id_subnivel' => $idSubnivel,
                'id_nivel' => $idNivel
            ]);

            $message = "Subnivel creado correctamente";
        } else {
            DB::table('subnivel')
                ->where('id', $idSubnivel)
                ->update([
                    'subnivel' => $nombreSubnivel
                ]);

            DB::table('subnivel_nivel')
                ->where('id_subnivel', $idSubnivel)
                ->update([
                    'id_nivel' => $idNivel
                ]);

            $message = "Subnivel actualizado correctamente";
        }

        return response()->json([
            'message' => $message,
            'code' => 200,
            'subnivel' => $idSubnivel
        ]);
    }

    //OPT
    public function configuracion_usuario_configuracion_division(Request $request): JsonResponse
    {
        $divisionData = json_decode($request->input('division'));
        $isNew = $divisionData->id == 0;

        if ($isNew) {
            $id_division = DB::table('division')->insertGetId([
                'division' => $divisionData->division,
                'status' => 1
            ]);

            $message = "División creada correctamente";
        } else {
            DB::table('division')
                ->where('id', $divisionData->id)
                ->update(['division' => $divisionData->division]);

            $id_division = $divisionData->id;
            $message = "División actualizada correctamente";
        }

        return response()->json([
            'code' => 200,
            'message' => $message,
            'division' => $id_division,
        ]);
    }

    //OPT
    /* Configuracion > Sistema */
    public function configuracion_sistema_marketplace_data(): JsonResponse
    {
        $marketplaces = MarketplaceArea::with("marketplace", "area", "api", "empresa")->get();
        $areas = Area::where('area', '!=', 'N/A')->get();

        return response()->json([
            "areas" => $areas,
            "marketplaces" => $marketplaces,
        ]);
    }

    //OPT

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

    //OPT

    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'CC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'ION'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    //OPT

    public function configuracion_sistema_marketplace_guardar(Request $request): JsonResponse
    {
        $payload = json_decode($request->input('data'));

        $marketplace = Marketplace::firstOrCreate(
            ['marketplace' => mb_strtoupper($payload->marketplace->marketplace, "UTF-8")]
        );

        $payload->marketplace->id = $marketplace->id;

        $exists = MarketplaceArea::where('id_marketplace', $marketplace->id)
            ->where('id_area', $payload->area->id)
            ->where('id', '<>', $payload->id)
            ->exists();

        if ($exists) {
            return response()->json([
                "message" => "Ya éxiste un marketplace con los datos proporcionados"
            ], 500);
        }

        $marketplaceArea = $payload->id != 0
            ? MarketplaceArea::find($payload->id)
            : new MarketplaceArea();

        $marketplaceArea->id_marketplace = $marketplace->id;
        $marketplaceArea->id_area = $payload->area->id;
        $marketplaceArea->serie = $payload->serie;
        $marketplaceArea->serie_nota = $payload->serie_nota;
        $marketplaceArea->publico = $payload->publico;
        $marketplaceArea->save();

        $payload->id = $marketplaceArea->id;

        if (!empty($payload->api->extra_1) || !empty($payload->api->extra_2) || !empty($payload->api->app_id) || !empty($payload->api->secret)) {
            $marketplaceApi = $payload->api->id != 0
                ? MarketplaceApi::find($payload->api->id)
                : new MarketplaceApi();

            $marketplaceApi->id_marketplace_area = $marketplaceArea->id;
            $marketplaceApi->extra_1 = $payload->api->extra_1;
            $marketplaceApi->extra_2 = $payload->api->extra_2;
            $marketplaceApi->app_id = $payload->api->app_id;
            $marketplaceApi->secret = app('encrypter')->encrypt($payload->api->secret);
            $marketplaceApi->guia = $payload->api->guia;
            $marketplaceApi->save();

            $payload->api->id = $marketplaceApi->id;
        }

        $empresa = $payload->empresa ?? null;
        $empresaExistente = MarketplaceAreaEmpresa::where("id_marketplace_area", $marketplaceArea->id)->first();

        if ($empresaExistente) {
            if (empty($empresa->id_empresa)) {
                $empresaExistente->delete();
            } else {
                $empresaExistente->id_empresa = $empresa->id_empresa;
                $empresaExistente->utilidad = $empresa->utilidad;
                $empresaExistente->save();
            }
        } elseif (!empty($empresa->id_empresa) && $empresa->id_empresa != 0) {
            MarketplaceAreaEmpresa::create([
                "id_marketplace_area" => $marketplaceArea->id,
                "id_empresa" => $empresa->id_empresa,
                "utilidad" => $empresa->utilidad
            ]);
        }

        return response()->json([
            "message" => $request->input('data.id') == 0
                ? "Marketplace registrado correctamente"
                : "Marketplace editado correctamente"
        ]);
    }

    //OPT

    public function configuracion_sistema_almacen_data(): JsonResponse
    {
        $almacenes = EmpresaAlmacen::with('almacen')->where('id', '!=', 0)->get();
        $impresoras = DB::table('impresora')->get()->keyBy('id');
        $impresorasRet = DB::table('impresora')->get()->toArray();

        $almacenes = $almacenes->map(function ($almacen) use ($impresoras) {
            $almacen->nombre_impresora_picking = $impresoras[$almacen->id_impresora_picking]->nombre ?? '-';
            $almacen->nombre_impresora_guia = $impresoras[$almacen->id_impresora_guia]->nombre ?? '-';
            $almacen->nombre_impresora_etiqueta = $impresoras[$almacen->id_impresora_etiqueta]->nombre ?? '-';
            $almacen->nombre_impresora_manifiesto = $impresoras[$almacen->id_impresora_manifiesto]->nombre ?? '-';
            return $almacen;
        });

        return response()->json([
            "almacenes" => $almacenes,
            "impresoras" => $impresorasRet
        ]);

    }

    // OPT

    public function configuracion_sistema_almacen_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        if (!$data || !isset($data->almacen->almacen) || trim($data->almacen->almacen) === '') {
            return response()->json([
                'message' => 'El nombre del almacén es obligatorio y los datos deben ser válidos',
                'code' => 422
            ], 422);
        }

        DB::beginTransaction();

        try {
            if ($data->id == 0) {
                $id_almacen = DB::table('almacen')->insertGetId([
                    "almacen" => $data->almacen->almacen
                ]);

                DB::table('empresa_almacen')->insert([
                    'id_empresa' => 1,
                    'id_almacen' => $id_almacen,
                    'id_impresora_picking' => $data->id_impresora_picking ?? null,
                    'id_impresora_guia' => $data->id_impresora_guia ?? null,
                    'id_impresora_etiqueta_envio' => $data->id_impresora_etiqueta_envio ?? null,
                    'id_impresora_manifiesto' => $data->id_impresora_manifiesto ?? null,
                ]);
            } else {
                DB::table('almacen')->where('id', $data->almacen->id)->update([
                    "almacen" => $data->almacen->almacen
                ]);

                DB::table('empresa_almacen')->where('id', $data->id)->update([
                    'id_empresa' => 1,
                    'id_almacen' => $data->almacen->id,
                    'id_impresora_picking' => $data->id_impresora_picking ?? null,
                    'id_impresora_guia' => $data->id_impresora_guia ?? null,
                    'id_impresora_etiqueta_envio' => $data->id_impresora_etiqueta_envio ?? null,
                    'id_impresora_manifiesto' => $data->id_impresora_manifiesto ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Almacen guardado correctamente',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al guardar Almacen: ' . $e->getMessage(),
            ], 500);
        }
    }

    // OPT
    public function configuracion_sistema_paqueteria_data(): JsonResponse
    {
        $paqueterias = Paqueteria::get();

        return response()->json([
            "paqueterias" => $paqueterias
        ]);
    }

    // OPT
    public function configuracion_sistema_paqueteria_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        if (!$data) {
            return response()->json([
                'message' => 'Datos inválidos',
                'code' => 400
            ]);
        }

        if (!isset($data->paqueteria) || trim($data->paqueteria) === '') {
            return response()->json([
                'message' => 'El nombre de la paquetería es obligatorio',
                'code' => 422
            ]);
        }

        $paqueteria = $data->id == 0
            ? new Paqueteria()
            : Paqueteria::find($data->id);

        if (!$paqueteria) {
            return response()->json([
                'message' => 'Paquetería no encontrada',
                'code' => 404
            ]);
        }

        $paqueteria->paqueteria = $data->paqueteria;
        $paqueteria->url = $data->url ?? '';
        $paqueteria->guia = $data->guia ?? 0;
        $paqueteria->api = $data->api ?? 0;
        $paqueteria->manifiesto = $data->manifiesto ?? 0;
        $paqueteria->status = $data->status ?? 0;

        $paqueteria->save();

        return response()->json([
            'message' => $data->id == 0
                ? 'Paquetería creada correctamente'
                : 'Paquetería actualizada correctamente',
            'code' => 200,
            'paqueteria' => $paqueteria->id
        ]);
    }

    // OPT
    public function configuracion_logout()
    {
        $notificacion['reload_users'] = 1;

        event(new PusherEvent(json_encode($notificacion)));
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
        return response()->json([
            "impresoras" => $impresoras,
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

}
