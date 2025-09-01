<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Httpful\Request as HttpfulRequest;

class EnsambleController extends Controller
{
    /** Almacén de ensamble (con series y stock de componentes) */
    private $ALMACEN_ENSAMBLE = 15;
    private $ALMACEN_SERIES_ENSAMBLE = 8;

    public function __construct()
    {
        //
    }

    /** Helper de respuesta JSON uniforme */
    private function ok($data = null, $message = 'OK', $code = 200)
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function fail($message, $code = 400, $errors = null)
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    // ============================================================
    //  BUSCAR PRODUCTO POR SKU (KIT) – sin validar existencia
    //  GET /ensamble/producto/kit/{sku}
    // ============================================================
    public function getProductoKitBySku($sku)
    {
        $sku = trim($sku);

        $modelo = DB::table('modelo as m')
            ->selectRaw("
                m.id as id_modelo,
                m.sku,
                m.descripcion,
                COALESCE(
                    (SELECT mc.ultimo_costo FROM modelo_costo mc
                      WHERE mc.id_modelo = m.id
                      ORDER BY mc.updated_at DESC LIMIT 1
                    ),
                    m.costo, 0
                ) as costo
            ")
            ->where('m.sku', $sku)
            ->first();

        if (!$modelo) {
            return $this->fail("No se encontró el SKU {$sku} en catálogo.", 404);
        }

        return $this->ok($modelo);
    }

    // ============================================================
    //  BUSCAR PRODUCTO POR SKU (COMPONENTE)
    //  Valida existencia en almacén de ensamble (id=15) con el SP
    //  GET /ensamble/producto/componente/{sku}
    // ============================================================
    public function getProductoComponenteBySku($sku)
    {
        $sku = trim($sku);

        // Usamos el SP para filtrar por almacén y exigir existencia
        $rows = DB::select("CALL sp_calcularExistenciaGeneral(?, ?, ?)", [
            $sku,               // in_criterio
            $this->ALMACEN_ENSAMBLE, // in_id_almacen
            1                   // in_con_existencia (1 = solo con existencia)
        ]);

        if (empty($rows)) {
            return $this->fail("El SKU {$sku} no existe o no tiene registros de existencias en el almacén de ensamble.", 404);
        }

        // Buscar match exacto de SKU (columna 'codigo' del SP)
        $hit = collect($rows)->first(function ($r) use ($sku) {
            return strcasecmp($r->codigo, $sku) === 0;
        }) ?: $rows[0];

        // Regla: disponible > 0 (con 1 basta)
        $disponible = (int)($hit->disponible ?? 0);
        if ($disponible <= 0) {
            return $this->fail("No hay existencia disponible del SKU {$sku} en el almacén de ensamble.", 409);
        }

        $data = (object)[
            'id_modelo'   => (int)$hit->id_modelo,
            'sku'         => $hit->codigo,
            'descripcion' => $hit->descripcion,
            'costo'       => (float)($hit->ultimo_costo ?? 0)
        ];

        return $this->ok($data);
    }

    // ============================================================
    //  LISTAR SERIES DISPONIBLES POR MODELO EN ALMACÉN 15
    //  GET /ensamble/series/{id_modelo}
    // ============================================================
    public function getSeriesPorModelo($id_modelo)
    {
        $id_modelo = (int)$id_modelo;

        $series = DB::table('producto')
            ->select('serie')
            ->where('id_modelo', $id_modelo)
            ->where('id_almacen', $this->ALMACEN_SERIES_ENSAMBLE)
            ->where('status', 1) // 1 = disponible (según tu schema)
            ->orderBy('serie')
            ->pluck('serie');

        return $this->ok($series);
    }

    // ============================================================
    //  VALIDAR SERIE 1:1 CONTRA SKU EN ALMACÉN 15
    //  POST /ensamble/serie/validar  (FormData: producto, serie)
    // ============================================================
    public function validarSerieComponente(Request $request)
    {
        $sku   = trim((string)$request->input('producto'));
        $serie = trim((string)$request->input('serie'));

        if ($sku === '' || $serie === '') {
            return $this->fail('Parámetros inválidos (producto, serie).', 422);
        }

        $modelo = DB::table('modelo')->select('id')->where('sku', $sku)->first();
        if (!$modelo) {
            return $this->fail("SKU {$sku} no encontrado.", 404);
        }

        $enAlmacen = DB::table('producto')
            ->where('id_modelo', $modelo->id)
            ->where('id_almacen', $this->ALMACEN_SERIES_ENSAMBLE)
            ->where('serie', $serie)
            ->where('status', 1) // disponible
            ->exists();

        if (!$enAlmacen) {
            return $this->fail("La serie '{$serie}' no está disponible para el SKU {$sku} en el almacén de ensamble.", 409);
        }

        return $this->ok(['sku' => $sku, 'serie' => $serie, 'status' => 1], 'Serie válida');
    }

    public function crear(Request $request)
    {
        try {
            $data = json_decode($request->input('data'));

            if (!$data) {
                return $this->fail('Payload inválido.', 422);
            }

            // Validaciones mínimas de estructura
            if (empty($data->id_modelo_kit) || empty($data->componentes) || !is_array($data->componentes)) {
                return $this->fail('Faltan campos: id_modelo_kit y/o componentes.', 422);
            }

            // ===== 1) Validar KIT por id =====
            $kit = DB::table('modelo')
                ->select('id', 'sku', 'descripcion', 'costo')
                ->where('id', (int) $data->id_modelo_kit)
                ->first();

            if (!$kit) {
                return $this->fail('El modelo (KIT) no existe.', 404);
            }

            // ===== 2) Validar componentes =====
            $errores = [];
            foreach ($data->componentes as $idx => $c) {
                $idModelo = (int)($c->id_modelo_componente ?? 0);
                $serie    = trim((string)($c->serie ?? ''));

                if ($idModelo <= 0 || $serie === '') {
                    $errores[] = "Componente #{$idx}: datos incompletos (id_modelo_componente, serie).";
                    continue;
                }

                // 2.1) Confirmar que el modelo existe y obtener su SKU para el SP
                $modelo = DB::table('modelo')->select('id', 'sku')->where('id', $idModelo)->first();
                if (!$modelo) {
                    $errores[] = "Componente #{$idx}: El modelo {$idModelo} no existe.";
                    continue;
                }

                // 2.2) Validar existencia disponible (SP) en almacén de ensamble (por SKU)
                $sp = DB::select("CALL sp_calcularExistenciaGeneral(?, ?, ?)", [
                    $modelo->sku, $this->ALMACEN_ENSAMBLE, 1
                ]);

                if (empty($sp)) {
                    $errores[] = "Componente #{$idx} ({$modelo->sku}): sin registros de existencia en almacén de ensamble.";
                    continue;
                }

                // primer match por seguridad
                $hit = collect($sp)->first(function ($r) use ($modelo) {
                    return strcasecmp($r->codigo, $modelo->sku) === 0;
                }) ?: $sp[0];

                $disponible = (int)($hit->disponible ?? 0);
                if ($disponible <= 0) {
                    $errores[] = "Componente #{$idx} ({$modelo->sku}): sin disponible en almacén de ensamble.";
                    continue;
                }

                // 2.3) Validar serie disponible en tabla producto (almacén de series)
                $serieProducto = DB::table('producto')
                    ->select('id', 'status')
                    ->where('id_modelo', $idModelo)
                    ->where('id_almacen', $this->ALMACEN_SERIES_ENSAMBLE)
                    ->where('serie', $serie)
                    ->first();

                if (!$serieProducto || (int)$serieProducto->status !== 1) {
                    $errores[] = "Componente #{$idx} ({$modelo->sku}): la serie '{$serie}' no está disponible en almacén de ensamble.";
                }
            }

            if (!empty($errores)) {
                return $this->fail('Validación fallida.', 409, $errores);
            }

            // ===== 3) Persistencia
            DB::beginTransaction();

            // 3.1) Generar serie del KIT (<=13) única
            $serieKit = $this->generarSerie($kit->id);
            // Insertar serie del kit en PRODUCTO (almacén de series)
            $idProductoKit = DB::table('producto')->insertGetId([
                'id_almacen' => $this->ALMACEN_SERIES_ENSAMBLE, // tabla almacen
                'id_modelo'  => $kit->id,
                'serie'      => $serieKit,
                'status'     => 1,
            ]);

            // 3.2) Insertar en modelo_ensamble_kit
            $auth = json_decode($request->auth);

            $idEnsamble = DB::table('modelo_ensamble_kit')->insertGetId([
                'id_modelo_kit' => $kit->id,
                'id_serie_kit'  => $idProductoKit,
                // costo_kit = costo del producto principal + suma costos componentes
                'costo_kit'     => (float)($data->costo_kit ?? 0),
                'comentarios'   => $data->comentarios ?? null,
                'id_usuario'    => $auth->id,
            ]);

            // 3.3) Insertar componentes y consumir sus series
            foreach ($data->componentes as $c) {
                $idModeloComponente = (int)$c->id_modelo_componente;
                $serieComp = trim((string)$c->serie);

                // localizar producto (serie) del componente en almacén de series
                $prodSerie = DB::table('producto')
                    ->select('id')
                    ->where('id_modelo', $idModeloComponente)
                    ->where('id_almacen', $this->ALMACEN_SERIES_ENSAMBLE)
                    ->where('serie', $serieComp)
                    ->where('status', 1)
                    ->lockForUpdate()
                    ->first();

                if (!$prodSerie) {
                    DB::rollBack();
                    return $this->fail("La serie {$serieComp} ya no está disponible.", 409);
                }

                // registrar componente del ensamble
                DB::table('modelo_ensamble_componente')->insert([
                    'id_modelo_ensamble_kit' => $idEnsamble,
                    'id_modelo_componente'   => $idModeloComponente,
                    'id_serie_componente'    => $prodSerie->id,
                ]);

                // consumir la serie del componente
                DB::table('producto')
                    ->where('id', $prodSerie->id)
                    ->update([
                        'status'     => 0, // consumido
                    ]);

                // opcional: descontar existencia (empresa_almacen 15) de ese modelo
                $this->sumarExistencia($idModeloComponente, -1);
            }

            // 3.4) Dar existencia al KIT en el almacén de ensamble (empresa_almacen)
            $this->sumarExistencia($kit->id, +1);

            DB::commit();

            // ===== 4) Imprimir etiqueta 2x1 (SKU + Serie inventada)
            /*try {
                $this->imprimirSkuSerie($kit->sku, $kit->descripcion, $serieKit, $request->get('token'));
            } catch (Exception $e) {
            }*/

            return $this->ok([
                'id_ensamble'   => $idEnsamble,
                'serie_kit'     => $serieKit,
                'producto_kit'  => $idProductoKit,
                'costo_kit'     => (float)($data->costo_kit ?? 0),
            ], 'Ensamble creado correctamente.');

        } catch (Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            return $this->fail('Error interno: ' . $e->getMessage(), 500);
        }
    }

    /** ======================== HELPERS ======================== */

    /** genera serie <=13, única en tabla producto */
    private function generarSerie(int $idModelo): string
    {
        // Formato: 3 dígitos del modelo + yymmdd (6) + 4 alfanum -> total 13
        $pref = str_pad(substr((string)$idModelo, -3), 3, '0', STR_PAD_LEFT);
        $fecha = date('ymd'); // 6
        do {
            $rand = substr(strtoupper(bin2hex(random_bytes(4))), 0, 4); // 4
            $serie = $pref . $fecha . $rand; // 13 caracteres
            $existe = DB::table('producto')->where('serie', $serie)->exists();
        } while ($existe);

        return $serie;
    }

    private function sumarExistencia(int $idModelo, int $cantidad): void
    {
        $row = DB::table('modelo_existencias')
            ->where('id_modelo', $idModelo)
            ->where('id_almacen', $this->ALMACEN_ENSAMBLE)
            ->lockForUpdate()
            ->first();

        if ($row) {
            DB::table('modelo_existencias')
                ->where('id_modelo', $idModelo)
                ->where('id_almacen', $this->ALMACEN_ENSAMBLE)
                ->update([
                    'stock'      => $row->stock + $cantidad,
                ]);
        } else {
            DB::table('modelo_existencias')->insert([
                'id_modelo' => $idModelo,
                'id_almacen'=> $this->ALMACEN_ENSAMBLE,
                'stock'     => $cantidad,
                'stock_inicial' => $cantidad,
                'stock_anterior' => 0
            ]);
        }
    }

    /** imprime SKU + DESCRIPCIÓN + SERIE usando el printserver (endpoint busqueda) */
    private function imprimirSkuSerie(string $sku, string $descripcion, string $serie, ?string $token = null): void
    {
        $url = rtrim(config('webservice.printserver'), '/') . '/api/etiquetas/ensamble';
        if ($token) {
            $url .= '?token=' . urlencode($token);
        }

        $body = [
            'data' => json_encode([
                'sku'         => $sku,
                'descripcion' => $descripcion,
                'serie'       => $serie,
            ]),
        ];

        try {
            HttpfulRequest::post($url)
                ->sendsJson()
                ->body($body)
                ->send(); // si quieres, valida ->code === 200
        } catch (\Exception $e) {
            // log opcional; no rompas el flujo del ensamble por la impresión
        }
    }
}
