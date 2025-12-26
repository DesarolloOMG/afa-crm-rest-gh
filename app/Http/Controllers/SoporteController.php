<?php

/** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\DocumentoService;
use App\Http\Services\DropboxService;
use App\Http\Services\InventarioService;
use App\Http\Services\WhatsAppService;
use App\Models\Documento;
use App\Models\DocumentoGarantiaCausa;
use App\Models\Enums\DocumentoFase;
use App\Models\Enums\DocumentoGarantiaFase;
use App\Models\Enums\DocumentoGarantiaTipo;
use App\Models\Enums\DocumentoStatus;
use App\Models\Enums\DocumentoTipo;
use App\Models\Enums\HttpStatusCode;
use App\Models\Enums\UsuarioNivel;
use App\Models\Paqueteria;
use App\Models\Usuario;
use Crabbly\Fpdf\Fpdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoporteController extends Controller
{
    /*  Soporte > garantías y devoluciones */
    public function soporte_garantia_devolucion_data()
    {
        $tipos_documento = DB::select("SELECT * FROM documento_garantia_tipo WHERE id != 4");
        $causas_documento = DB::select("SELECT * FROM documento_garantia_causa WHERE id != 0");

        return response()->json([
            'tipos' => $tipos_documento,
            'causas' => $causas_documento
        ]);
    }

    public function soporte_garantia_devolucion_venta($venta)
    {
        // 1) Intentar interpretar $venta como ID de documento (venta)
        $documento = DB::table('documento')
            ->where('id', trim($venta))
            ->where('id_tipo', 2)   // 2 = Venta (ajusta si tu catálogo difiere)
            ->where('status', 1)
            ->first();

        $venta_id = 0;

        // 2) Si NO hay documento por ID, buscar por serie de producto
        if (!$documento) {
            $docPorSerie = DB::table('documento')
                ->select('documento.id')
                ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
                ->join('movimiento_producto', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
                ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
                ->where('producto.serie', trim($venta))
                ->where('documento.id_tipo', 2)
                ->where('documento.status', 1)
                ->first();

            if (!$docPorSerie) {
                return response()->json([
                    'code' => 404,
                    'message' => 'No se encontró la venta.'
                ], 404);
            }

            $venta_id = (int)$docPorSerie->id;
        } else {
            $venta_id = (int)$documento->id;
        }

        // 3) Encabezado: almacén y marketplace del documento
        $header = DB::table('documento')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('marketplace_area', 'documento.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->leftJoin('area', 'marketplace_area.id_area', '=', 'area.id')
            ->where('documento.id', $venta_id)
            ->select(
                'documento.id as venta_id',
                'almacen.id as id_almacen',
                'almacen.almacen',
                'marketplace.id as id_marketplace',
                'marketplace.marketplace',
            )
            ->first();

        if (!$header) {
            // Caso raro: el documento existe pero no pudo resolver joins
            return response()->json([
                'code' => 404,
                'message' => 'No se encontró información de encabezado para la venta.'
            ], 404);
        }

        // 4) Productos y cantidades de la venta
        // 4) Productos disponibles de la venta (restando devoluciones/garantías parciales)
        $vendidosQ = DB::table('movimiento as mv')
            ->join('modelo as mo', 'mo.id', '=', 'mv.id_modelo')
            ->select([
                'mv.id_modelo',
                'mo.sku',
                'mo.descripcion',
                DB::raw('SUM(mv.cantidad) AS vendidos'),
            ])
            ->where('mv.id_documento', $venta_id)
            ->groupBy('mv.id_modelo', 'mo.sku', 'mo.descripcion');

        // Nota: documento_garantia_producto.producto guarda el identificador del producto devuelto.
        // En la mayoría de implementaciones es el SKU. Si en tu data es así, podemos mapear por SKU.
        $devueltosQ = DB::table('documento_garantia_re as dgr')
            ->join('documento_garantia as dg', 'dg.id', '=', 'dgr.id_garantia')
            ->join('documento_garantia_producto as dgp', 'dgp.id_garantia', '=', 'dg.id')
            ->join('modelo as mo', 'mo.sku', '=', 'dgp.producto') // <-- mapeo por SKU
            ->select([
                'mo.id as id_modelo',
                DB::raw('SUM(dgp.cantidad) AS devueltos'),
            ])
            ->where('dgr.id_documento', $venta_id)
            ->where('dg.status', 1)          // solo garantías activas
            ->whereIn('dg.id_tipo', [2, 3]) // solo parciales (si tu lógica así lo requiere)
            ->groupBy('mo.id');

        $productos = DB::query()
            ->fromSub($vendidosQ, 'v')
            ->leftJoinSub($devueltosQ, 'r', 'r.id_modelo', '=', 'v.id_modelo')
            ->select([
                'v.sku',
                'v.descripcion',
                DB::raw('v.vendidos AS cantidad_vendida'),
                DB::raw('IFNULL(r.devueltos, 0) AS cantidad_devueltos'),
                DB::raw('(v.vendidos - IFNULL(r.devueltos, 0)) AS cantidad_disponible'),
            ])
            ->having('cantidad_disponible', '>', 0)
            ->orderBy('v.sku')
            ->get();

        return response()->json([
            'code' => 200,
            'venta_id' => $venta_id,
            'almacen' => $header->almacen,
            'marketplace' => $header->marketplace,
            'productos' => $productos,
        ]);
    }

    public function soporte_garantia_devolucion_eliminar_documento(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        DB::table("documento_garantia")->where("id", $data->documento)->delete();

        return response()->json([
            "message" => "Documento eliminado correctamente"
        ]);
    }

    /* Soporte > garantías y devoluciones > devolución */
    public function soporte_garantia_devolucion_crear(Request $request)
    {
        try {
            DB::beginTransaction();

            $data = json_decode($request->input('data'));
            $auth = json_decode($request->auth);

            $informacion_documento = Documento::select(
                "documento.id",
                "documento.id_fase"
            )
                ->where("documento.id", $data->venta_id)
                ->where("documento.id_tipo", DocumentoTipo::VENTA)
                ->where("documento.status", DocumentoStatus::ACTIVO)
                ->first();

            if (!$informacion_documento) {
                throw new \Exception("No se encontró la venta con el ID proporcionado.", HttpStatusCode::NOT_FOUND);
            }

            if ($informacion_documento->id_fase < DocumentoFase::PENDIENTE_FACTURA) {
                throw new \Exception("No es posible generar un documento de devolución o garantía ya que el producto no ha sido enviado.", HttpStatusCode::NOT_ACCEPTABLE);
            }

            $existe_devolucion = DB::table("documento_garantia")
                ->select("documento_garantia.id")
                ->join("documento_garantia_re", "documento_garantia.id", "=", "documento_garantia_re.id_garantia")
                ->where("documento_garantia_re.id_documento", $informacion_documento->id)
                ->where("documento_garantia.id_tipo", DocumentoGarantiaTipo::DEVOLUCION)
                ->first();

            if ($existe_devolucion) {
                throw new \Exception("Ya existe una devolución generada a partir de esa venta. ID " . $existe_devolucion->id, HttpStatusCode::NOT_ACCEPTABLE);
            }

            $informacion_usuario = Usuario::find($auth->id);
            if (!$informacion_usuario) {
                throw new \Exception("No se encontró información sobre el usuario", HttpStatusCode::NOT_ACCEPTABLE);
            }

            $informacion_cliente = DB::table("documento")
                ->select("documento_entidad.*")
                ->join("documento_entidad", "documento.id_entidad", "=", "documento_entidad.id")
                ->where("documento.id", $informacion_documento->id)
                ->first();

            if (!$informacion_cliente) {
                throw new \Exception("No se encontró información sobre el cliente.", HttpStatusCode::NOT_ACCEPTABLE);
            }

            $documento_garantia = DB::table('documento_garantia')->insertGetId([
                'id_tipo'   => $data->tipo,
                'id_causa'  => $data->causa,
                'id_fase'   => $data->tipo == DocumentoGarantiaTipo::GARANTIA
                    ? DocumentoGarantiaFase::GARANTIA_PENDIENTE_LLEGADA
                    : DocumentoGarantiaFase::DEVOLUCION_PENDIENTE,
                'no_reclamo' => $data->reclamo,
                'created_by' => $auth->id
            ]);

            DB::table('documento_garantia_re')->insertGetId([
                'id_documento' => $informacion_documento->id,
                'id_garantia'  => $documento_garantia
            ]);

            DB::table('documento_garantia_seguimiento')->insert([
                'id_documento' => $documento_garantia,
                'id_usuario'   => $auth->id,
                'seguimiento'  => $data->seguimiento
            ]);

            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(
                        preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data)
                    );

                    $dropboxService = new DropboxService();
                    $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                    if (!$response || !$response->body || empty($response->body->id)) {
                        throw new \Exception("Error al subir archivo a Dropbox.");
                    }

                    $documento_archivo = DB::table('documento_archivo')->insertGetId([
                        'id_documento' => $informacion_documento->id,
                        'id_usuario'   => $auth->id,
                        'nombre'       => $archivo->nombre,
                        'dropbox'      => $response['id']
                    ]);

                    DB::table('documento_garantia_archivo')->insert([
                        'id_archivo' => $documento_archivo,
                        'id_garantia' => $documento_garantia
                    ]);
                }
            }

            foreach ($data->productos as $producto) {
                DB::table('documento_garantia_producto')->insert([
                    'id_garantia' => $documento_garantia,
                    'producto'    => $producto->sku,
                    'cantidad'    => $producto->cantidad
                ]);
            }

            // Generar PDF (si aplica)
            $file_name = "";
            $file_data = "";

            $response = self::documento_garantia($documento_garantia);
            if ($response && !$response->error) {
                $file_data = base64_encode($response->file);
                $file_name = $response->name;
            }

            DB::commit();

            $jsonResponse = [
                "code"    => 200,
                "message" => "Documento creado correctamente con el siguiente número: " . $documento_garantia,
            ];

            if (!empty($file_name)) {
                $jsonResponse['file'] = $file_data;
                $jsonResponse['name'] = $file_name;
            }

            return response()->json($jsonResponse);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "code"    => $e->getCode() ?: 500,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function soporte_garantia_devolucion_devolucion_data()
    {
        set_time_limit(0);

        $tecnicos = Usuario::whereHas("subnivelesbynivel", function ($query) {
            return $query->where("id_nivel", UsuarioNivel::SOPORTE);
        })
            ->where("usuario.status", 1)
            ->get();

        $paqueterias = Paqueteria::get();
        $causas = DocumentoGarantiaCausa::get();

        $documentos = $this->garantia_devolucion_raw_data(11, "1,2");

        return response()->json([
            'causas' => $causas,
            'ventas' => $documentos,
            'tecnicos' => $tecnicos,
            'paqueterias' => $paqueterias
        ]);
    }

    /*public function soporte_garantia_devolucion_devolucion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            foreach ($data->productos as $producto) {
                if (COUNT($producto->series) > 0) {
                    if (COUNT($producto->series) != (int)$producto->cantidad) {
                        return response()->json([
                            'code' => 500,
                            'message' => "La cantidad de series agregadas no concuerda con la cantidad del producto, favor de revisar en intentar de nuevo."
                        ]);
                    }

                    foreach ($producto->series as $serie) {
                        $serie = str_replace(["'", '\\'], '', $serie);
                        $existe_serie = DB::select("SELECT
                                                        producto.id
                                                    FROM movimiento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                    WHERE producto.serie = '" . trim($serie) . "'
                                                    AND modelo.sku = '" . trim($producto->sku) . "'");

                        if (empty($existe_serie)) {
                            return response()->json([
                                'code' => 500,
                                'message' => "La serie " . $serie . " no corresponde a su producto asignado, favor de verificar e intentar de nuevo."
                            ]);
                        }

                        $existe_movimiento_producto = DB::select("SELECT
                                                                    movimiento.id
                                                                FROM movimiento
                                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                WHERE movimiento.id_documento = " . $data->documento . "
                                                                AND modelo.sku = '" . trim($producto->sku) . "'");

                        if (empty($existe_movimiento_producto)) {
                            return response()->json([
                                'code' => 500,
                                'message' => "El documento no cuenta con ningun movimiento que contenga el sku " . trim($producto->sku) . ""
                            ]);
                        }
                    }
                }
            }

            $productos = DB::select("SELECT
                                        movimiento.id AS id_movimiento,
                                        movimiento.cantidad,
                                        movimiento.precio AS precio_unitario,
                                        movimiento.id_modelo,
                                        movimiento.comentario AS comentarios,
                                        modelo.serie,
                                        modelo.sku,
                                        modelo.costo
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE id_documento = " . $data->documento . "");

            $info_documento = DB::select("SELECT
                                area.area,
                                documento.factura_serie,
                                documento.factura_folio,
                                documento.id_almacen_principal_empresa,
                                documento.fulfillment,
                                marketplace.marketplace,
                                empresa.bd,
                                empresa.almacen_devolucion_garantia_erp,
                                empresa.almacen_devolucion_garantia_sistema,
                                empresa.almacen_devolucion_garantia_serie,
                                empresa_almacen.id_erp AS id_almacen
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            INNER JOIN moneda ON documento.id_moneda = moneda.id
                            INNER JOIN documento_periodo ON documento.id_periodo
                            INNER JOIN documento_uso_cfdi ON documento.id_cfdi
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            WHERE documento.id = " . $data->documento . "");

            $info_entidad = DB::table('documento')
                ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
                ->where('documento.id', $data->documento)
                ->whereIn('documento_entidad.tipo', [1, 3])
                ->select('documento_entidad.*')
                ->first();

            if (empty($info_entidad)) {
                return response()->json([
                    'code' => 501,
                    'message' => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
                ]);
            }

            if (empty($info_entidad)) {
                return response()->json([
                    'code' => 501,
                    'message' => "No se encontró la información del cliente, favor de contactar al administrador."
                ]);
            }

            if (empty($productos)) {
                return response()->json([
                    'code' => 404,
                    'message' => "No se encontraron productos del documento, favor de contactar al administrador."
                ]);
            }

            $info_documento = $info_documento[0];

            # Se relaciona las series a las partidas de la venta para llevar un registro
            foreach ($data->productos as $producto) {
                foreach ($producto->series as $serie) {
                    $serie = str_replace(["'", '\\'], '', $serie);
                    $id_serie = DB::select("SELECT id FROM producto WHERE serie = '" . $serie . "'")[0]->id;
                    $id_movimiento = DB::select("SELECT
                                                    movimiento.id
                                                FROM movimiento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE movimiento.id_documento = " . $data->documento . "
                                                AND modelo.sku = '" . trim($producto->sku) . "'")[0]->id;

                    DB::table('movimiento_producto')->insert([
                        'id_movimiento' => $id_movimiento,
                        'id_producto' => $id_serie
                    ]);

                    DB::table('producto')->where(['id' => $id_serie])->update([
                        'status' => 0
                    ]);
                }
            }

            # Sí la venta es de AMAZON y es fulfillment, no se hace traspaso
            $venta_fba = (((strtolower($info_documento->marketplace) == "amazon") && $info_documento->fulfillment)) ? true : false;

            $empresa_externa = DB::select("SELECT
                                        empresa.rfc,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                    INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                    WHERE documento.id = " . $data->documento . "");

            $info_documento_almacenes = [];

            if (!$venta_fba) {
                $seguimiento_traspaso = "";

                $documento_traspaso = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa' => !empty($empresa_externa) ? $info_documento_almacenes[0]->almacen_devolucion_garantia_sistema : $info_documento->almacen_devolucion_garantia_sistema,
                    'id_almacen_secundario_empresa' => !empty($empresa_externa) ? $almacen_secundario_empresa[0]->id : $info_documento->id_almacen_principal_empresa,
                    'id_tipo' => 5,
                    'id_periodo' => 1,
                    'id_cfdi' => 1,
                    'id_marketplace_area' => 1,
                    'id_usuario' => $auth->id,
                    'id_moneda' => 3,
                    'id_paqueteria' => 6,
                    'id_fase' => 100,
                    'factura_folio' => '',
                    'tipo_cambio' => 1,
                    'referencia' => 'N/A',
                    'info_extra' => 'N/A',
                    'observacion' => 'Traspaso entre almacenes por cancelacion de venta ' . $data->documento, // Status de la compra
                ]);

                foreach ($productos as $index => $producto) {
                    $movimiento = DB::table('movimiento')->insertGetId([
                        'id_documento' => $documento_traspaso,
                        'id_modelo' => $producto->id_modelo,
                        'cantidad' => $producto->cantidad,
                        'precio' => $producto->precio_unitario,
                        'garantia' => 0,
                        'modificacion' => 'N/A',
                        'regalo' => 0
                    ]);

                    if (COUNT($data->productos[$index]->series) > 0) {
                        foreach ($data->productos[$index]->series as $serie) {
                            $serie = str_replace(["'", '\\'], '', $serie);
                            $existe_serie = DB::select("SELECT id FROM producto WHERE serie = '" . TRIM($serie) . "'");

                            if (empty($existe_serie)) {
                                $id_serie = DB::table('producto')->insertGetId([
                                    'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                    'id_modelo' => $producto->id_modelo,
                                    'serie' => trim($serie),
                                    'status' => 1
                                ]);
                            } else {
                                $id_serie = $existe_serie[0]->id;

                                DB::table('producto')->where(['id' => $existe_serie[0]->id])->update([
                                    'id_modelo' => $producto->id_modelo,
                                    'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                    'status' => 1
                                ]);
                            }

                            DB::table('movimiento_producto')->insert([
                                'id_movimiento' => $movimiento,
                                'id_producto' => $id_serie
                            ]);

                            DB::table('producto')->where(['id' => $id_serie])->update([
                                'id_almacen' => $info_documento->almacen_devolucion_garantia_serie
                            ]);
                        }
                    }
                }

                $seguimiento_traspaso .= "<p>Traspaso creado correctamente con el ID " . $documento_traspaso . ".</p>";
                $afectar = InventarioService::aplicarMovimiento($documento_traspaso);
                if ($afectar->error) {
                    $seguimiento_traspaso .= "<p>Traspaso con el ID " . $documento_traspaso . "no se pudo afectar correctamente.</p>";
                } else {
                    $seguimiento_traspaso .= "<p>Traspaso con el ID " . $documento_traspaso . " afectado correctamente.</p>";
                }

                DB::table('seguimiento')->insert([
                    'id_documento' => $data->documento,
                    'id_usuario' => $auth->id,
                    'seguimiento' => $seguimiento_traspaso
                ]);
            }

            DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                'id_fase' => ($data->causa == 6) ? 5 : ($venta_fba ? 100 : 3),
                'id_causa' => $data->causa,
                'asigned_to' => $data->tecnico,
                'guia_llegada' => $data->guia,
                'id_paqueteria_llegada' => $data->paqueteria,
            ]);

            # Si la devolución es frade, se envia una notificación al creador para que tome cartas en el asunto
            if ($data->causa == 6) {
                $usuario_devolucion = DB::select("SELECT created_by FROM documento_garantia WHERE id = " . $data->documento_garantia . "")[0]->created_by;

                $notificacion['titulo'] = "Tú devolución es fraude";
                $notificacion['message'] = "La devolución generada del pedido  " . $data->documento . " ha sido marcada como fraude, favor de revisar la información en la sección de pendiente de reclamo.";
                $notificacion['tipo'] = "danger"; // success, warning, danger
                $notificacion['link'] = "/soporte/garantia-devolucion/devolucion/reclamo/" . $data->documento;

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data' => json_encode($notificacion)
                ]);

                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario_devolucion,
                    'id_notificacion' => $notificacion_id
                ]);

                $notificacion['id'] = $notificacion_id;
                $notificacion['usuario'] = $usuario_devolucion;

                event(new PusherEvent(json_encode($notificacion)));
            }
        }

        # Sí la devolución tiene evidencia en archivos, se suben a dropbox y se relacionan al pedido
        if (!empty($data->archivos)) {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $dropboxService = new DropboxService();
                    $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->documento,
                        'id_usuario' => $auth->id,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response['id']
                    ]);
                }
            }
        }


        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }*/

    public function soporte_garantia_devolucion_devolucion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        // --- 1. VALIDAR SERIES INGRESADAS VS. VENTA ORIGINAL ---

        $series_originales_raw = DB::select('CALL sp_obtenerSeriesPorDocumento(?)', [$data->documento]);

        $series_originales = [];
        foreach ($series_originales_raw as $serie_db) {
            $sku = DB::table('modelo')->where('id', $serie_db->id_modelo)->value('sku');
            if (!isset($series_originales[$sku])) {
                $series_originales[$sku] = [];
            }
            $series_originales[$sku][] = $serie_db->serie;
        }

        foreach ($data->productos as $producto) {
            foreach ($producto->series as $serie_ingresada) {
                $serie_limpia = trim($serie_ingresada);

                if (!isset($series_originales[trim($producto->sku)])) {
                    return response()->json([
                        'code' => 500,
                        'message' => "El producto con SKU " . $producto->sku . " no pertenece a la venta original."
                    ]);
                }

                if (!in_array($serie_limpia, $series_originales[trim($producto->sku)])) {
                    return response()->json([
                        'code' => 500,
                        'message' => "La serie '" . $serie_limpia . "' no corresponde al producto " . $producto->sku . " en la venta original."
                    ]);
                }
            }
        }

        // --- 2. ACTUALIZAR DATOS DE LA GARANTÍA (GARANTIA, ARCHIVOS, SEGUIMIENTOS, SERIES) ---

        DB::table('documento_garantia')->where('id', $data->documento_garantia)->update([
            'id_fase' => DocumentoGarantiaFase::DEVOLUCION_REVISION,
            'asigned_to' => $data->tecnico,
            'guia_llegada' => $data->guia,
            'id_paqueteria_llegada' => $data->paqueteria,
        ]);

        $productos_garantia = DB::table('documento_garantia_producto')
            ->where('id_garantia', $data->documento_garantia)
            ->get()->keyBy('producto');

        foreach ($data->productos as $producto) {
            if (isset($productos_garantia[trim($producto->sku)])) {
                $id_documento_garantia_producto = $productos_garantia[trim($producto->sku)]->id;

                foreach ($producto->series as $serie_ingresada) {
                    DB::table('documento_garantia_producto_series')->insert([
                        'id_documento_garantia_producto' => $id_documento_garantia_producto,
                        'serie' => trim($serie_ingresada)
                    ]);
                }
            }
        }

        if (!empty($data->archivos)) {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));
                    $dropboxService = new DropboxService();
                    $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);
                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->documento,
                        'id_usuario' => $auth->id,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response['id']
                    ]);
                }
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        // --- 3. GENERAR PDF ---
        $pdf_response = self::generar_pdf_devolucion($data->documento_garantia);
        $file_data = "";
        $file_name = "";

        if (!$pdf_response->error) {
            $file_data = base64_encode($pdf_response->file);
            $file_name = $pdf_response->name;
        }

        // --- 4. SE TERMINA LA FUNCIÓN Y SE DEVUELVE LA RESPUESTA ---
        return response()->json([
            'code' => 200,
            'message' => "Documento guardado y validado correctamente.",
            'file' => $file_data,
            'name' => $file_name
        ]);
    }

    private function generar_pdf_devolucion($id_garantia)
    {
        $response = new \stdClass();

        $informacion_garantia = DB::table('documento_garantia as dg')
            ->join('documento_garantia_re as dgr', 'dg.id', '=', 'dgr.id_garantia')
            ->join('documento as d', 'dgr.id_documento', '=', 'd.id')
            ->join('documento_entidad as de', 'd.id_entidad', '=', 'de.id')
            ->leftJoin('usuario as tecnico', 'dg.asigned_to', '=', 'tecnico.id')
            ->join('usuario as creador', 'dg.created_by', '=', 'creador.id')
            ->leftJoin('documento_garantia_causa as dgc', 'dg.id_causa', '=', 'dgc.id')
            ->leftJoin('paqueteria as p', 'dg.id_paqueteria_llegada', '=', 'p.id')
            ->select(
                'dg.id',
                'dg.id_tipo',
                'd.id as numero_pedido',
                'tecnico.nombre as tecnico',
                'creador.nombre as creador',
                'de.razon_social as cliente',
                'de.telefono',
                'de.correo',
                'dgc.causa as motivo',
                'dg.guia_llegada',
                'p.paqueteria as paqueteria_llegada'
            )
            ->where('dg.id', $id_garantia)->first();

        if (!$informacion_garantia) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento.";
            return $response;
        }

        $esDevolucion = ($informacion_garantia->id_tipo == 1);
        $tipo_texto_titulo = $esDevolucion ? 'REPORTE DE DEVOLUCION' : 'REPORTE DE GARANTIA';
        $tipo_texto_detalles = $esDevolucion ? 'DETALLES DE LA DEVOLUCION' : 'DETALLES DE LA GARANTIA';
        $tipo_texto_numero = $esDevolucion ? 'No. Devolucion' : 'No. Garantia';
        $tipo_texto_productos = $esDevolucion ? 'PRODUCTOS EN DEVOLUCION' : 'PRODUCTOS EN GARANTIA';

        $productos = DB::table('documento_garantia_producto as dgp')
            ->join('modelo as m', 'dgp.producto', '=', 'm.sku')
            ->select('dgp.id', 'm.sku', 'm.descripcion')
            ->where('dgp.id_garantia', $id_garantia)->get();

        foreach ($productos as $producto) {
            $producto->series = DB::table('documento_garantia_producto_series')
                ->where('id_documento_garantia_producto', $producto->id)
                ->pluck('serie')->toArray();
        }

        $seguimientos = DB::table('documento_garantia_seguimiento')
            ->where('id_documento', $id_garantia)->get();

        $pdf = new Fpdf();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Image(base_path('public/img/omg.png'), 10, 8, 50);
        $pdf->SetFont('Arial', 'B', 28);
        $pdf->SetTextColor(220, 53, 69);
        $pdf->Cell(80);
        $pdf->Cell(100, 10, $tipo_texto_titulo, 0, 0, 'C');
        $pdf->Ln(25);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY(40);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(95, 8, 'INFORMACION DEL CLIENTE', 0, 1, 'L', true);
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Cliente:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->cliente));
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Correo:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->correo));
        $pdf->Ln();
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, iconv('UTF-8', 'windows-1252', 'Teléfono:'));
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->telefono));
        $pdf->Ln(10);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetDrawColor(222, 226, 230);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(95, 7, 'INFORMACION DE SEGUIMIENTO', 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(25, 7, 'Tecnico:', 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(70, 7, iconv('UTF-8', 'windows-1252', $informacion_garantia->tecnico), 'R', 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(25, 7, 'Paqueteria:', 'L');
        $pdf->Cell(70, 7, iconv('UTF-8', 'windows-1252', $informacion_garantia->paqueteria_llegada), 'R', 1);
        $pdf->Cell(25, 7, 'Guia:', 'LB');
        $pdf->Cell(70, 7, iconv('UTF-8', 'windows-1252', $informacion_garantia->guia_llegada), 'RB', 1);
        $y_fin_col_izquierda = $pdf->GetY();

        $pdf->SetY(40);
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(90, 8, $tipo_texto_detalles, 0, 1, 'L', true);
        $pdf->Ln(2);
        $y_datos_importantes = $pdf->GetY();
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetDrawColor(222, 226, 230);
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(44, 7, $tipo_texto_numero, 0, 1, 'C');
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(44, 10, $informacion_garantia->id, 1, 0, 'C', true);
        $pdf->SetY($y_datos_importantes);
        $pdf->SetX(155);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(45, 7, 'Pedido Original', 0, 1, 'C');
        $pdf->SetX(155);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(45, 10, $informacion_garantia->numero_pedido, 1, 1, 'C', true);
        $pdf->Ln(4);
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Fecha:');
        $pdf->SetFont('Arial', '', 10);
        $meses = ["January" => "Enero", "February" => "Febrero", "March" => "Marzo", "April" => "Abril", "May" => "Mayo", "June" => "Junio", "July" => "Julio", "August" => "Agosto", "September" => "Septiembre", "October" => "Octubre", "November" => "Noviembre", "December" => "Diciembre"];
        $fecha_actual = date("d") . " de " . $meses[date("F")] . " del " . date("Y");
        $pdf->Cell(65, 6, $fecha_actual);
        $pdf->Ln();
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Creado por:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(65, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->creador));
        $pdf->Ln();
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Motivo:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(65, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->motivo));
        $y_fin_col_derecha = $pdf->GetY();

        $pdf->SetY(max($y_fin_col_izquierda, $y_fin_col_derecha) + 5);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, $tipo_texto_productos, 0, 1, 'L', true);
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Cell(60, 8, 'SKU / Codigo', 1, 0, 'C', true);
        $pdf->Cell(70, 8, iconv('UTF-8', 'windows-1252', 'Descripción'), 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'No. de Serie', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        if (!$productos->isEmpty()) {
            foreach ($productos as $producto) {
                if (empty($producto->series)) {
                    $producto->series[] = 'N/A';
                }

                $series_string = implode("\n", $producto->series);

                $y_before_text = $pdf->GetY();
                $pdf->SetX(300);
                $pdf->MultiCell(70, 7, iconv('UTF-8', 'windows-1252', $producto->descripcion));
                $height_desc = $pdf->GetY() - $y_before_text;

                $pdf->SetXY(300, $y_before_text);
                $pdf->MultiCell(60, 7, $series_string);
                $height_series = $pdf->GetY() - $y_before_text;

                $row_height = max($height_desc, $height_series);
                $pdf->SetXY(10, $y_before_text);

                $pdf->MultiCell(60, 7, $producto->sku);
                $pdf->SetXY(70, $y_before_text);
                $pdf->MultiCell(70, 7, iconv('UTF-8', 'windows-1252', $producto->descripcion));
                $pdf->SetXY(140, $y_before_text);
                $pdf->MultiCell(60, 7, $series_string, 0, 'C');

                $pdf->Line(10, $y_before_text, 10, $y_before_text + $row_height);
                $pdf->Line(70, $y_before_text, 70, $y_before_text + $row_height);
                $pdf->Line(140, $y_before_text, 140, $y_before_text + $row_height);
                $pdf->Line(200, $y_before_text, 200, $y_before_text + $row_height);
                $pdf->Line(10, $y_before_text + $row_height, 200, $y_before_text + $row_height);

                $pdf->SetY($y_before_text + $row_height);
            }
        }

        if (!$seguimientos->isEmpty()) {
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(0, 8, 'OBSERVACIONES', 0, 1, 'L', true);
            $pdf->Ln(4);
            $pdf->SetFont('Arial', '', 10);
            foreach ($seguimientos as $seguimiento) {
                $texto_limpio = "> " . strip_tags(str_replace("&nbsp;", " ", $seguimiento->seguimiento));
                $pdf->MultiCell(190, 5, iconv('UTF-8', 'windows-1252', $texto_limpio));
                $pdf->Ln(2);
            }
        }

        $pdf->SetAutoPageBreak(false);

        $pdf->SetY(-60);

        $pdf->SetX(20);
        $pdf->Cell(80, 5, '', 'T', 0, 'C');
        $pdf->SetX(110);
        $pdf->Cell(80, 5, '', 'T', 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetX(20);
        $pdf->Cell(80, 6, 'Firma del Supervisor', 0, 0, 'C');
        $pdf->SetX(110);
        $pdf->Cell(80, 6, 'Firma del Tecnico', 0, 1, 'C');

        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(80, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->tecnico), 0, 1, 'C');

        $pdf->SetY(-15);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(128);
        $pdf->Cell(0, 10, 'Pagina ' . $pdf->PageNo(), 0, 0, 'C');

        $pdf->SetAutoPageBreak(true, 15);

        $file_name  = ($esDevolucion ? "DEVOLUCION_" : "GARANTIA_") . $informacion_garantia->id . "_" . time() . ".pdf";
        $pdf_data   = $pdf->Output($file_name, 'S');

        $response->error = 0;
        $response->name = $file_name;
        $response->file = $pdf_data;

        return $response;
    }
    public function soporte_garantia_devolucion_devolucion_revision_data(Request $request)
    {
        $auth = json_decode($request->auth);
        $documentos = $this->garantia_devolucion_raw_data(12, "1,2", $auth->id);

        return response()->json([
            'code' => 200,
            'ventas' => $documentos,
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_revision_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            if ($data->disponible == 0) {
                $resultado = self::terminar_devolucion($data->documento, $data->documento_garantia);

                // Si la función interna devuelve un error (ej. al crear la NC),
                // lo retornamos al frontend y detenemos el proceso.
                if ($resultado->error) {
                    return response()->json([
                        'code' => 500,
                        'message' => $resultado->message
                    ]);
                }

                // La función 'terminar_devolucion' ya actualiza la fase a 100 (Finalizado).
                // Aquí solo actualizamos el campo 'disponible_venta'.
                DB::table('documento_garantia')->where('id', $data->documento_garantia)->update([
                    'disponible_venta' => $data->disponible
                ]);

                // Opcional: Añadimos el mensaje del resultado al seguimiento para más detalle.
                $data->seguimiento .= " " . $resultado->message;
            } else {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 4,
                    'disponible_venta' => $data->disponible
                ]);
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_indemnizacion_data()
    {
        $documentos = $this->garantia_devolucion_raw_data(4, "1,2"); # Fase del documento y tipo de documento

        return response()->json([
            'code' => 200,
            'ventas' => $documentos,
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_indemnizacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $finalMessage = "Documento guardado correctamente.";

        if ($data->terminar) {
            if ($data->indemnizacion) {
                // Si se termina como 'indemnización', solo se cambia la fase.
                DB::table('documento_garantia')->where('id', $data->documento_garantia)->update([
                    'id_fase' => 5 // Se asume Fase de Indemnización
                ]);
            }
        }

        // El seguimiento se inserta en todos los casos para registrar la acción.
        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => $finalMessage
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_reclamo_data()
    {
        $documentos = $this->garantia_devolucion_raw_data(5, "1,2");

        return response()->json([
            'code' => 200,
            'ventas' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_reclamo_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $finalMessage = "Documento guardado correctamente.";

        if ($data->terminar) {
            // Se llama a la función interna que se encarga de todo el proceso final
            $resultado = self::terminar_devolucion(
                $data->documento,
                $data->documento_garantia
            );

            // Si la función interna devuelve un error, se retorna al frontend
            if ($resultado->error) {
                return response()->json([
                    'code' => 500,
                    'message' => $resultado->message
                ]);
            }

            // Si fue exitoso, se usa el mensaje de la función interna
            $finalMessage = $resultado->message;
        }

        // El seguimiento se inserta siempre para registrar la acción
        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => $finalMessage
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_historial_data(Request $request)
    {
        $data = json_decode($request->input("data"));

        $documentos = $this->garantia_devolucion_raw_data(0, "1,2", 0, $data->fecha_inicial, $data->fecha_final, $data->documento);

        return response()->json([
            'documentos' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Seguimiento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_garantia_recibir_data()
    {
        set_time_limit(0);

        $documentos = $this->garantia_devolucion_raw_data(1, 3);

        $tecnicos = Usuario::whereHas("subnivelesbynivel", function ($query) {
            return $query->where("id_nivel", UsuarioNivel::SOPORTE);
        })
            ->where("usuario.status", 1)
            ->get();

        $paqueterias = Paqueteria::get();

        return response()->json([
            'code' => 200,
            'ventas' => $documentos,
            'paqueterias' => $paqueterias,
            'tecnicos' => $tecnicos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_recibir_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        // 1) Validación de series (como ya lo teníamos antes)
        if (!empty($data->productos) && is_array($data->productos)) {
            $series_originales_raw = DB::select('CALL sp_obtenerSeriesPorDocumento(?)', [$data->documento]);

            $series_originales = [];
            foreach ($series_originales_raw as $serie_db) {
                $sku = DB::table('modelo')->where('id', $serie_db->id_modelo)->value('sku');
                if (!isset($series_originales[$sku])) {
                    $series_originales[$sku] = [];
                }
                $series_originales[$sku][] = $serie_db->serie;
            }

            foreach ($data->productos as $producto) {
                $skuProducto = trim($producto->sku ?? '');
                $seriesIngresadas = isset($producto->series) && is_array($producto->series)
                    ? $producto->series
                    : [];

                if (count($seriesIngresadas) === 0) {
                    continue;
                }

                if (!isset($series_originales[$skuProducto])) {
                    return response()->json([
                        'code'    => 500,
                        'message' => "El producto con SKU {$skuProducto} no pertenece a la venta original."
                    ]);
                }

                foreach ($seriesIngresadas as $serie_ingresada) {
                    $serieLimpia = trim($serie_ingresada);
                    if (!in_array($serieLimpia, $series_originales[$skuProducto])) {
                        return response()->json([
                            'code'    => 500,
                            'message' => "La serie '{$serieLimpia}' no corresponde al producto {$skuProducto} en la venta original."
                        ]);
                    }
                }
            }
        }

        // 2) Notificaciones + actualización documento
        if ($data->terminar) {
            if (!empty($data->notificados)) {
                $usuarios = [];

                $notificacion['titulo'] = "Garantia del documento " . $data->documento_garantia;
                $notificacion['message'] = "Se ha asignado un paquete para ti para que lo revises.";
                $notificacion['tipo'] = "success";

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data' => json_encode($notificacion)
                ]);

                $notificacion['id'] = $notificacion_id;

                foreach ($data->notificados as $usuario) {
                    DB::table('notificacion_usuario')->insert([
                        'id_usuario'      => $usuario->id,
                        'id_notificacion' => $notificacion_id
                    ]);
                    $usuarios[] = $usuario->id;
                }

                if (!empty($usuarios)) {
                    $notificacion['usuario'] = $usuarios;
                    //                    event(new PusherEvent(json_encode($notificacion)));
                }
            }

            DB::table('documento_garantia')
                ->where(['id' => $data->documento_garantia])
                ->update([
                    'id_fase'              => 3,
                    'guia_llegada'         => $data->guia,
                    'id_paqueteria_llegada' => $data->paqueteria,
                    'asigned_to'           => $data->tecnico
                ]);
        }

        // 3) Guardar seguimiento
        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario'   => $auth->id,
            'seguimiento'  => $data->seguimiento
        ]);

        // 4) Guardar series en documento_garantia_producto_series
        if (!empty($data->productos)) {
            // Primero obtenemos los productos ya ligados a esta garantía
            $productos_garantia = DB::table('documento_garantia_producto')
                ->where('id_garantia', $data->documento_garantia)
                ->get()->keyBy('producto'); // clave = SKU del producto

            foreach ($data->productos as $producto) {
                $skuProducto = trim($producto->sku ?? '');
                $seriesIngresadas = isset($producto->series) && is_array($producto->series)
                    ? $producto->series
                    : [];

                if (count($seriesIngresadas) === 0) {
                    continue;
                }

                if (isset($productos_garantia[$skuProducto])) {
                    $id_doc_garantia_producto = $productos_garantia[$skuProducto]->id;

                    foreach ($seriesIngresadas as $serie_ingresada) {
                        DB::table('documento_garantia_producto_series')->insert([
                            'id_documento_garantia_producto' => $id_doc_garantia_producto,
                            'serie' => trim($serie_ingresada)
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'code'    => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function buscarUsuario(Request $request)
    {
        $criterio = trim((string) $request->input('criterio', ''));

        // Si viene vacío, regresa lista vacía (tu front revisa length)
        if ($criterio === '') {
            return response()->json([
                'code' => 200,
                'usuarios' => [],
            ]);
        }

        // Normaliza para búsqueda LIKE
        $like = '%' . str_replace('%', '\%', $criterio) . '%';

        // Búsqueda en USUARIOS de SOPORTE o SISTEMAS por NOMBRE (y opcionalmente CORREO)
        $usuarios = DB::table('usuario')
            ->select('id', 'nombre')
            ->whereIn('area', ['SOPORTE', 'SISTEMAS'])
            ->where(function ($q) use ($like) {
                $q->where('nombre', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like); // opcional: quita si no lo quieres
            })
            ->where('status', 1)                 // opcional: sólo activos
            ->orderBy('nombre', 'asc')                   // evita traer demasiados
            ->get();

        return response()->json([
            'code' => 200,
            'usuarios' => $usuarios,
        ]);
    }

    public function soporte_garantia_devolucion_garantia_revision_data(Request $request)
    {
        $auth = json_decode($request->auth);
        $documentos = $this->garantia_devolucion_raw_data(3, 3, $auth->id);

        return response()->json([
            'code' => 200,
            'ventas' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_revision_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            if ($data->reparado) {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 99,
                    'disponible_venta' => $data->reparado
                ]);
            } else {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 6,
                    'disponible_venta' => $data->reparado
                ]);
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_garantia_cambio_data(Request $request)
    {
        $documentos = $this->garantia_devolucion_raw_data(6, 3);

        foreach ($documentos as $documento) {
            $documento->almacenes = DB::select("SELECT
                                                    empresa_almacen.id,
                                                    almacen.almacen
                                                FROM empresa_almacen
                                                INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                                WHERE empresa_almacen.id_empresa = " . $documento->id_empresa . "
                                                AND almacen.status = 1
                                                AND almacen.id != 0
                                                ORDER BY almacen.almacen ASC");
        }

        return response()->json([
            'code' => 200,
            'ventas' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_cambio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!$data || !isset($data->documento, $data->documento_garantia)) {
            return response()->json(['code' => 400, 'message' => 'Faltan parámetros (documento / documento_garantia).']);
        }

        // Documento original para heredar metadatos básicos
        $doc = DB::table('documento')->where('id', $data->documento)->first();
        if (!$doc) {
            return response()->json(['code' => 404, 'message' => 'Documento original no encontrado.']);
        }

        // --- Validación ÚNICA: si es nuevo SKU, validar stock en almacén seleccionado con tu SP ---
        if (empty($data->mismo_producto)) {
            if (empty($data->almacen_salida) || empty($data->nuevo_sku) || empty($data->cantidad_nueva)) {
                return response()->json(['code' => 400, 'message' => 'Faltan datos: almacén / nuevo_sku / cantidad_nueva.']);
            }

            $modelo = DB::table('modelo')->where('sku', trim($data->nuevo_sku))->first();
            if (!$modelo) {
                return response()->json(['code' => 404, 'message' => "No existe el SKU {$data->nuevo_sku}."]);
            }

            $cantReq = (int)$data->cantidad_nueva;
            if ($cantReq < 1) {
                return response()->json(['code' => 400, 'message' => 'La cantidad del nuevo SKU debe ser mayor a 0.']);
            }

            // === AQUÍ USAMOS TU SP TAL CUAL ===
            $sp = DB::select("CALL sp_calcularExistenciaGeneral(?, ?, ?, ?)", [
                $modelo->sku,
                (int)$data->almacen_salida,
                1,
                2
            ]);

            if (empty($sp)) {
                return response()->json([
                    'code' => 409,
                    'message' => "SKU {$modelo->sku}: sin registros de existencia en el almacén seleccionado."
                ]);
            }

            $hit = collect($sp)->first(function ($r) use ($modelo) {
                return strcasecmp($r->codigo, $modelo->sku) === 0;
            }) ?: $sp[0];

            $disponible = (int)($hit->disponible ?? 0);
            if ($disponible < $cantReq) {
                return response()->json([
                    'code' => 409,
                    'message' => "SKU {$modelo->sku}: disponible {$disponible}, requerido {$cantReq} en el almacén seleccionado."
                ]);
            }
        }

        DB::beginTransaction();
        try {
            // 1) NOTA DE CRÉDITO por productos de la GARANTÍA (reutilizamos tu servicio)
            $nc = DocumentoService::crearNotaCreditoConEgreso($data->documento, 0, $data->documento_garantia, 1);
            if (!empty($nc->error)) {
                DB::rollBack();
                return response()->json(['code' => 500, 'message' => 'Error al crear Nota de crédito: ' . ($nc->mensaje ?? 'desconocido')]);
            }

            // 2) TRASPASO a garantías por la garantía (reutilizamos tu servicio)
            $tras = InventarioService::crear_traspaso_devolucion($data->documento, $data->documento_garantia, 1);
            if (!empty($tras->error)) {
                DB::rollBack();
                return response()->json(['code' => 500, 'message' => 'Error al crear Traspaso: ' . ($tras->message ?? 'desconocido')]);
            }

            // 3) NUEVO PEDIDO DE VENTA (pagado, paquetería=106, id_entidad=3, fase=3)
            //    Líneas en función de mismo_producto
            $lineas = [];
            if (!empty($data->mismo_producto)) {
                if (empty($data->productos_anteriores) || !is_array($data->productos_anteriores)) {
                    DB::rollBack();
                    return response()->json(['code' => 400, 'message' => 'No hay productos para el nuevo pedido.']);
                }

                foreach ($data->productos_anteriores as $p) {
                    $cant = (int)($p->cantidad ?? 0);
                    if ($cant < 1) continue;

                    $modelo = DB::table('modelo')->where('sku', trim($p->sku))->first();
                    if (!$modelo) {
                        DB::rollBack();
                        return response()->json(['code' => 404, 'message' => "No existe el SKU {$p->sku}."]);
                    }

                    $lineas[] = [
                        'id_modelo' => $modelo->id,
                        'sku'       => $modelo->sku,
                        'cantidad'  => $cant,
                        'precio'    => $this->precioParaPedido($data->documento, $modelo->id, (float)($modelo->costo ?? 0)),
                    ];
                }

                if (empty($lineas)) {
                    DB::rollBack();
                    return response()->json(['code' => 400, 'message' => 'Todas las cantidades están en 0.']);
                }

                // si en tu UI también eliges almacén para mismo producto, úsalo; si no, dejamos el del doc original
                $almacenPedido = !empty($data->almacen_salida) ? (int)$data->almacen_salida : (int)$doc->id_almacen_principal_empresa;

                $pedido = $this->crearPedidoVentaBasico($doc, $lineas, $almacenPedido, (int)($auth->id ?? 1), (int)$data->documento_garantia);
            } else {
                // nuevo SKU (ya está validado el stock con el SP arriba)
                $modelo = DB::table('modelo')->where('sku', trim($data->nuevo_sku))->first(); // ya validado arriba
                $lineas[] = [
                    'id_modelo' => $modelo->id,
                    'sku'       => $modelo->sku,
                    'cantidad'  => (int)$data->cantidad_nueva,
                    'precio'    => $this->precioParaPedido($data->documento, $modelo->id, (float)($modelo->costo ?? 0)),
                ];

                $pedido = $this->crearPedidoVentaBasico($doc, $lineas, (int)$data->almacen_salida, (int)($auth->id ?? 1), (int)$data->documento_garantia);
            }

            if (!empty($pedido->error)) {
                DB::rollBack();
                return response()->json(['code' => 500, 'message' => 'Error al crear el pedido: ' . ($pedido->message ?? 'desconocido')]);
            }

            // Cerrar garantía
            DB::table('documento_garantia')->where('id', $data->documento_garantia)->update(['id_fase' => 99]);

            // Seguimientos
            if (!empty($data->seguimiento)) {
                DB::table('documento_garantia_seguimiento')->insert([
                    'id_documento' => $data->documento_garantia,
                    'id_usuario'   => $auth->id ?? 1,
                    'seguimiento'  => $data->seguimiento
                ]);
            }
            DB::table('documento_garantia_seguimiento')->insert([
                'id_documento' => $data->documento_garantia,
                'id_usuario'   => $auth->id ?? 1,
                'seguimiento'  => "NC {$nc->id_nota_credito}, Traspaso {$tras->id_traspaso}, Pedido {$pedido->id_pedido}."
            ]);

            DB::commit();

            return response()->json([
                'code'    => 200,
                'message' => "Documento guardado correctamente.<br>NC: {$nc->id_nota_credito}<br>Traspaso: {$tras->id_traspaso}<br>Pedido: {$pedido->id_pedido}",
                'ids'     => [
                    'nota_credito' => $nc->id_nota_credito,
                    'traspaso'     => $tras->id_traspaso,
                    'pedido'       => $pedido->id_pedido
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => 'Error al procesar: ' . $e->getMessage()]);
        }
    }

    /** Precio para el pedido: intenta precio de la venta original para ese modelo; si no, cae a fallback. */
    private function precioParaPedido(int $idDocumentoOriginal, int $idModelo, float $fallback): float
    {
        $mov = DB::table('movimiento')
            ->where('id_documento', $idDocumentoOriginal)
            ->where('id_modelo', $idModelo)
            ->orderBy('id', 'asc')
            ->first();

        return $mov ? (float)$mov->precio : $fallback;
    }

    /** Crea el documento de venta básico (pagado, paquetería=106, id_entidad=3, fase=3) y sus movimientos. */
    private function crearPedidoVentaBasico($docOriginal, array $lineas, int $idEmpresaAlmacenDestino, int $idUsuario, int $idGarantia): \stdClass
    {
        $r = new \stdClass();
        $r->error = 0;
        $r->message = '';
        $r->id_pedido = null;

        // encabezado
        $pedidoId = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => $idEmpresaAlmacenDestino, // almacén seleccionado en la vista
            'id_tipo'            => 2,           // 1 = Venta (ajusta si tu catálogo usa otro id)
            'id_periodo'         => $docOriginal->id_periodo,
            'id_cfdi'            => $docOriginal->id_cfdi,
            'id_marketplace_area' => $docOriginal->id_marketplace_area,
            'id_usuario'         => $idUsuario,
            'id_entidad'         => 3,           // SIEMPRE 3 (como pediste)
            'id_moneda'          => $docOriginal->id_moneda,
            'id_paqueteria'      => 106,         // SIEMPRE 106 (como pediste)
            'id_fase'            => 3,           // para surtir
            'tipo_cambio'        => $docOriginal->tipo_cambio ?? 1,
            'referencia'         => 'Garantía #' . $idGarantia,
            'observacion'        => 'Pedido creado a partir de la garantía ' . $idGarantia,
            'status'             => 1,
        ]);

        foreach ($lineas as $ln) {
            DB::table('movimiento')->insertGetId([
                'id_documento' => $pedidoId,
                'id_modelo'    => $ln['id_modelo'],
                'cantidad'     => $ln['cantidad'],
                'precio'       => $ln['precio'],
                'garantia'     => 0,
                'modificacion' => 'N/A',
                'regalo'       => 0
            ]);
        }

        $r->id_pedido = $pedidoId;
        return $r;
    }


    public function getProductoBySku($sku)
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
            return response()->json([
                'code' => 500,
                'message' => "No se encontro el producto con SKU {$sku}."
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Producto encontrado.',
            'data' => $modelo,
        ]);
    }


    public function soporte_garantia_devolucion_garantia_cambio_documento(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $usuario_info = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . " AND status = 1");

        if (empty($usuario_info)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información sobre el usuario, favor de contactar a un administrador."
            ]);
        }

        $usuario_info = $usuario_info[0];

        $pdf = new Fpdf();

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(69, 90, 100);

        # Informacion de la empresa
        # OMG Logo
        $pdf->Image("img/omg.png", 5, 10, 60, 20, 'png');

        $pdf->SetFont('Arial', 'B', 12);

        $pdf->Cell(70, 10, "");
        $pdf->Cell(40, 10, "SOLICITUD DE PRODUCTO(S) POR GARANTIA " . $data->documento . "");

        $pdf->SetFont('Arial', '', 10);

        $pdf->Ln(30);
        $pdf->Cell(100, 10, 'OMG INTERNATIONAL SA DE CV');
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'Industria Vidriera #105, Fracc. Industrial Zapopan Norte');
        $pdf->Ln(5);
        $pdf->Cell(20, 10, $usuario_info->nombre);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'soporte@omg.com.mx');

        # Información del cliente
        $pdf->Ln(20);
        $pdf->Cell(100, 10, '');
        $pdf->Cell(10, 10, 'INFORMACION DE LA GARANTIA');

        $pdf->SetFont('Arial', 'B', 10);

        setlocale(LC_ALL, "es_MX");

        $pdf->Ln(5);
        $pdf->Cell(100, 10, '');
        $pdf->Cell(30, 10, 'Fecha: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, strftime("%A %d de %B del %Y"));

        $pdf->Ln(5);
        $pdf->Cell(100, 10, '');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Pedido:');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $data->documento);

        $pdf->Ln(20);

        $pdf->Cell(40, 10, "CODIGO", "T");
        $pdf->Cell(120, 10, "DESCRIPCION", "T");
        $pdf->Cell(20, 10, "CANTIDAD", "T");
        $pdf->Ln();

        foreach ($data->productos_anteriores as $producto) {
            if ($producto->cambio) {
                $producto_info = DB::select("SELECT sku, descripcion FROM modelo WHERE id = " . $producto->id_modelo . "")[0];

                $pdf->Cell(40, 10, $producto_info->sku, "T");
                $pdf->Cell(120, 10, strlen($producto_info->descripcion) > 50 ? substr($producto_info->descripcion, 0, 50) : $producto_info->descripcion, "T");
                $pdf->Cell(20, 10, $producto->cantidad_peticion, "T");
                $pdf->Ln();
            }
        }

        $pdf_name = uniqid() . ".pdf";
        $pdf_data = $pdf->Output($pdf_name, 'S');
        $file_name = "SOLICITUD_PRODUCTO_GARANTIA_" . $data->documento . ".pdf";

        return response()->json([
            'code' => 200,
            'file' => base64_encode($pdf_data),
            'name' => $file_name
        ]);
    }

    public function soporte_garantia_devolucion_garantia_pedido_data(Request $request)
    {
        $garantias_pendientes = DB::select("SELECT id FROM documento_garantia WHERE id_fase = 7 AND id_tipo = 3");
        $periodos = DB::select("SELECT id, periodo FROM documento_periodo WHERE status = 1");
        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");
        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        $usos_venta = DB::select("SELECT * FROM documento_uso_cfdi");
        $metodos = DB::select("SELECT * FROM metodo_pago");
        $marketplaces_publico = DB::select("SELECT marketplace.marketplace FROM marketplace_area INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id WHERE marketplace_area.publico = 1 GROUP BY marketplace.marketplace");
        $monedas = DB::select("SELECT * FROM moneda");

        $documentos = $this->garantia_devolucion_raw_data(7, 3);

        $documentos = DB::select("SELECT
                                    documento.*,
                                    documento.id AS documento_id,
                                    documento_garantia.id AS documento_garantia,
                                    documento_entidad.id AS id_entidad,
                                    documento_entidad.razon_social AS cliente,
                                    documento_entidad.*,
                                    documento_direccion.id AS id_direccion,
                                    documento_direccion.*,
                                    area.area,
                                    marketplace.marketplace,
                                    paqueteria.paqueteria,
                                    usuario.nombre AS usuario,
                                    empresa.bd
                                FROM documento
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN documento_garantia_re ON documento.id = documento_garantia_re.id_documento
                                INNER JOIN documento_garantia ON documento_garantia_re.id_garantia = documento_garantia.id
                                INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                INNER JOIN documento_entidad ON documento_entidad.id = documento.id_entidad
                                LEFT JOIN documento_direccion ON documento.id = documento_direccion.id_documento
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE documento_garantia.id_fase = 7 AND documento_garantia.id_tipo = 2");

        foreach ($documentos as $documento) {
            $seguimiento_garantia = DB::select("SELECT
                                                    documento_garantia_seguimiento.seguimiento, 
                                                    documento_garantia_seguimiento.created_at, 
                                                    usuario.nombre 
                                                FROM documento_garantia_seguimiento 
                                                INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id
                                                WHERE id_documento = " . $documento->documento_id . "");

            $documento->seguimiento_garantia = $seguimiento_garantia;
        }

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        almacen.id AS id_almacen,
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ASC");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code' => 200,
            'ventas' => $documentos,
            'metodos' => $metodos,
            'monedas' => $monedas,
            'periodos' => $periodos,
            'empresas' => $empresas,
            'almacenes' => $almacenes,
            'usos_venta' => $usos_venta,
            'paqueterias' => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_garantia_pedido_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $documento = DB::table('documento')->insertGetId([
            'documento_extra' => "",
            'id_almacen_principal_empresa' => $data->documento->almacen,
            'id_periodo' => $data->documento->periodo,
            'id_cfdi' => $data->documento->uso_venta,
            'id_marketplace_area' => 15,
            'id_usuario' => $auth->id,
            'id_moneda' => $data->documento->moneda,
            'id_paqueteria' => $data->documento->paqueteria,
            'id_fase' => 3,
            'no_venta' => $data->documento->venta,
            'tipo_cambio' => $data->documento->tipo_cambio,
            'referencia' => $data->documento->referencia,
            'observacion' => $data->documento->observacion,
            'info_extra' => $data->documento->info_extra,
            'fulfillment' => 0,
            'pagado' => ($data->documento->precio_cambiado) ? 0 : 1,
            'series_factura' => $data->documento->series_factura,
            'mkt_fee' => $data->documento->mkt_fee,
            'mkt_shipping_total' => $data->documento->costo_envio,
            'mkt_shipping_total_cost' => $data->documento->costo_envio_total,
            'mkt_shipping_id' => $data->documento->mkt_shipping,
            'mkt_user_total' => $data->documento->total_user,
            'mkt_total' => $data->documento->total,
            'mkt_created_at' => $data->documento->mkt_created_at,
            'started_at' => $data->documento->fecha_inicio
        ]);

        $existe_cliente = DB::select("SELECT id FROM documento_entidad WHERE RFC = '" . TRIM($data->cliente->rfc) . "'");

        if ($data->cliente->rfc != 'XAXX010101000') {
            if (empty($existe_cliente)) {
                DB::table('documento_entidad')->where(['id' => $data->cliente->id])->update([
                    'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                    'rfc' => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                    'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                    'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                    'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
                ]);

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_entidad' => $data->cliente->id
                ]);
            } else {
                # Sí el cliente ya éxiste, se atualiza la información y se relaciona la venta con el cliente encontrado
                DB::table('documento_entidad')->where(['id' => $existe_cliente[0]->id])->update([
                    'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                    'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                    'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                    'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
                ]);

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_entidad' => $existe_cliente[0]->id
                ]);
            }
        } else {
            DB::table('documento_entidad')->where(['id' => $data->cliente->id])->update([
                'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                'rfc' => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
            ]);

            DB::table('documento')->where(['id' => $documento])->update([
                'id_entidad' => $data->cliente->id
            ]);
        }

        DB::table('documento_direccion')->insert([
            'id_documento' => $documento,
            'id_direccion_pro' => $data->documento->direccion_envio->colonia,
            'calle' => $data->documento->direccion_envio->calle,
            'numero' => $data->documento->direccion_envio->numero,
            'numero_int' => $data->documento->direccion_envio->numero_int,
            'colonia' => $data->documento->direccion_envio->colonia_text,
            'ciudad' => $data->documento->direccion_envio->ciudad,
            'estado' => $data->documento->direccion_envio->estado,
            'codigo_postal' => $data->documento->direccion_envio->codigo_postal,
            'referencia' => ''
        ]);

        foreach ($data->documento->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . trim($producto->codigo) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'sku' => mb_strtoupper(trim($producto->codigo), 'UTF-8'),
                    'descripcion' => mb_strtoupper(trim($producto->descripcion), 'UTF-8'),
                    'costo' => mb_strtoupper(trim($producto->costo), 'UTF-8'),
                    'alto' => mb_strtoupper(trim($producto->alto), 'UTF-8'),
                    'ancho' => mb_strtoupper(trim($producto->ancho), 'UTF-8'),
                    'largo' => mb_strtoupper(trim($producto->largo), 'UTF-8'),
                    'peso' => mb_strtoupper(trim($producto->peso), 'UTF-8'),
                    'tipo' => ($producto->servicio) ? 2 : 1
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $modelo,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->precio,
                'garantia' => $producto->garantia,
                'modificacion' => $producto->modificacion,
                'regalo' => $producto->regalo
            ]);

            if (TRIM($producto->modificacion) != "") {
                DB::table('documento')->where(['id' => $documento])->update([
                    'modificacion' => 1,
                    'id_fase' => 2
                ]);

                $modificacion = 1;
            }
        }

        foreach ($data->documento->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $dropboxService = new DropboxService();
                $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                DB::table('documento_archivo')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => $auth->id,
                    'nombre' => $archivo->nombre,
                    'dropbox' => $response['id']
                ]);
            }
        }


        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->documento->seguimiento
        ]);

        DB::table('documento_garantia')->where(['id' => $data->documento->documento_garantia])->update([
            'id_fase' => 100
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento " . $documento . " creado correctamente.",
            'tipo' => "success"
        ]);
    }

    public function soporte_garantia_devolucion_garantia_envio_data()
    {
        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1 AND id != 9");
        $documentos = $this->garantia_devolucion_raw_data(99, 3);

        return response()->json([
            'code' => 200,
            'ventas' => $documentos,
            'paqueterias' => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_garantia_envio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                'id_fase' => 100,
                'id_paqueteria_envio' => $data->paqueteria,
                'guia_envio' => $data->guia
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_data(Request $request)
    {
        $data = json_decode($request->input("data"));

        $documentos = $this->garantia_devolucion_raw_data(0, 3, 0, $data->fecha_inicial, $data->fecha_final, $data->documento);

        return response()->json([
            'documentos' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_documento($documento)
    {
        $response = self::documento_garantia($documento);

        if ($response->error) {
            return response()->json([
                'code' => 500,
                'message' => $response->mensaje
            ]);
        }

        return response()->json([
            'code' => 200,
            'name' => $response->name,
            'file' => base64_encode($response->file)
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Seguimiento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_servicio_data()
    {
        $tecnicos = Usuario::whereHas("subnivelesbynivel", function ($query) {
            return $query->where("id_nivel", UsuarioNivel::SOPORTE);
        })
            ->where("usuario.status", 1)
            ->get();

        return response()->json([
            'tecnicos' => $tecnicos
        ]);
    }

    public function soporte_garantia_devolucion_servicio_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $usuario_info = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . " AND status = 1");
        $tecnico_info = DB::select("SELECT nombre FROM usuario WHERE id = " . $data->tecnico . " AND status = 1");

        if (empty($usuario_info)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información sobre el usuario, favor de contactar a un administrador."
            ]);
        }

        if (empty($tecnico_info)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información sobre el tecnico, favor de contactar a un administrador."
            ]);
        }

        $usuario_info = $usuario_info[0];
        $tecnico_info = $tecnico_info[0];

        $servicio = DB::table('documento_garantia')->insertGetId([
            'id_tipo' => 3,
            'id_causa' => 0,
            'id_fase' => 3,
            'created_by' => $auth->id,
            'asigned_to' => $data->tecnico
        ]);

        DB::table('documento_garantia_contacto')->insertGetId([
            'id_garantia' => $servicio,
            'nombre' => $data->nombre,
            'telefono' => $data->telefono,
            'correo' => $data->correo
        ]);

        foreach ($data->productos as $producto) {
            DB::table('documento_garantia_producto')->insert([
                'id_garantia' => $servicio,
                'producto' => $producto->producto,
                'cantidad' => $producto->cantidad
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $servicio,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        $response = self::documento_servicio($servicio);

        if ($response->error) {
            return response()->json([
                'code' => 200,
                'message' => "Servicio creado correctamente " . $servicio . ", no pudo ser generado el PDF, favor de descargar en el historial, error: " . $response->mensaje
            ]);
        }

        return response()->json([
            'code' => 200,
            'file' => base64_encode($response->file),
            'name' => $response->name,
            'message' => "Servicio guardado correctamente con el número: " . $servicio . ""
        ]);
    }

    public function soporte_garantia_devolucion_servicio_revision_data(Request $request)
    {
        $servicios = DB::select("SELECT 
                                        documento_garantia.id, 
                                        documento_garantia.created_at,
                                        usuario.nombre
                                    FROM documento_garantia 
                                    INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                    WHERE id_fase = 3 AND id_tipo = 3");

        foreach ($servicios as $servicio) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $servicio->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $servicio->id . "");

            $seguimiento = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $servicio->id . "");

            $servicio->contacto = $contacto[0];
            $servicio->productos = $productos;
            $servicio->seguimiento = $seguimiento;
        }

        return response()->json([
            'code' => 200,
            'garantias' => $servicios
        ]);
    }

    public function soporte_garantia_devolucion_servicio_revision_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $json['message'] = "Seguimiento guardado correctamente.";
        $seguimiento_extra = "";

        if ($data->terminar) {
            if (!$data->tiene_reparacion) {
                if (!$data->tiene_costo) {
                    DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                        'id_fase' => 99,
                        'tiene_reparacion' => $data->tiene_reparacion,
                        'tiene_costo' => 0,
                        'costo_total' => 0,
                    ]);
                } else {
                    $existe_codigo_servicio = DB::select("SELECT id FROM modelo WHERE sku = 'ZZGZ0004'");

                    if (empty($existe_codigo_servicio)) {
                        return response()->json([
                            'code' => 500,
                            'message' => "No éxiste el servicio 'Servicio a laptop' en el CRM, favor de contactar a un administrador."
                        ]);
                    }

                    $datos_cliente = DB::select("SELECT 
                                                    documento_garantia_contacto.* 
                                                FROM documento_garantia 
                                                INNER JOIN documento_garantia_contacto ON documento_garantia.id = documento_garantia_contacto.id_garantia
                                                WHERE documento_garantia.id = " . $data->documento . "")[0];

                    $entidad_pedido = DB::table('documento_entidad')->insertGetId([
                        'tipo' => 1,
                        'razon_social' => $datos_cliente->nombre,
                        'rfc' => 'XAXX010101000',
                        'telefono' => $datos_cliente->telefono,
                        'telefono_alt' => 0,
                        'correo' => $datos_cliente->correo
                    ]);

                    $documento_pedido = DB::table('documento')->insertGetId([
                        'id_almacen_principal_empresa' => 1,
                        'id_almacen_secundario_empresa' => 1,
                        'id_tipo' => 2,
                        'id_periodo' => 1,
                        'id_cfdi' => 3,
                        'id_marketplace_area' => 1,
                        'id_usuario' => $auth->id,
                        'id_moneda' => 3,
                        'id_paqueteria' => 6,
                        'id_fase' => 5,
                        'factura_folio' => '',
                        'tipo_cambio' => 1,
                        'referencia' => 'N/A',
                        'info_extra' => 'N/A',
                        'observacion' => '1', // Status de la compra
                        'credito' => 0,
                        'expired_at' => '0000-00-00 00:00:00'
                    ]);

                    DB::table('documento_direccion')->insert([
                        'id_documento' => $documento_pedido,
                        'id_direccion_pro' => '0',
                        'contacto' => 'N/A',
                        'calle' => '',
                        'numero' => '',
                        'numero_int' => '',
                        'colonia' => '',
                        'ciudad' => '',
                        'estado' => '',
                        'codigo_postal' => '',
                        'referencia' => 'N/A'
                    ]);

                    DB::table('documento')->where(['id' => $documento_pedido])->update([
                        'id_entidad' => $entidad_pedido
                    ]);

                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documento_pedido,
                        'id_modelo' => $existe_codigo_servicio[0]->id,
                        'cantidad' => 1,
                        'precio' => $data->costo_total / 1.16,
                        'garantia' => 0,
                        'modificacion' => 'N/A',
                        'regalo' => 0
                    ]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento_pedido,
                        'id_usuario' => $auth->id,
                        'seguimiento' => "Este pedido fue generado para pagar el servicio " . $data->documento . ""
                    ]);

                    DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                        'id_fase' => 99,
                        'tiene_reparacion' => $data->tiene_reparacion,
                        'tiene_costo' => 1,
                        'costo_total' => $data->costo_total,
                    ]);

                    $seguimiento_extra .= "<p>Este documento generó un pedido para cobrar el servicio, número de pedido: " . $documento_pedido . " </p><br>" .
                        "<p>Nota: Sí el cliente necesita factura, puedes editar el pedido en la secciona de ventas.</p>";

                    $json['message'] = "Documento guardado correctamente. Un nuevo pedido fue generado para cubrir los costos del servicio, número de pedido: " . $documento_pedido . "";
                }
            } else {
                DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                    'id_fase' => 8,
                    'tiene_reparacion' => $data->tiene_reparacion,
                    'tiene_costo' => 0,
                    'costo_total' => 0,
                ]);

                $json['message'] = "Documento guardado correctamente.";
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento . $seguimiento_extra
        ]);

        $json['code'] = 200;

        return response()->json($json);
    }

    public function soporte_garantia_devolucion_servicio_envio_data()
    {
        $garantias_pendientes = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 99 AND id_tipo = 3");

        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1 AND id != 9");
        $documentos = array();

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto = $contacto[0];
            $garantia->productos = $productos;
            $garantia->seguimiento = $seguimiento;
        }

        return response()->json([
            'code' => 200,
            'garantias' => $garantias_pendientes,
            'paqueterias' => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_servicio_envio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                'id_fase' => 100,
                'id_paqueteria_envio' => $data->paqueteria,
                'guia_envio' => $data->guia
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_servicio_historial_data(Request $request, $fecha_inicial, $fecha_final)
    {
        $garantias_pendientes = DB::select("SELECT
                                                documento_garantia.id,
                                                documento_garantia_causa.causa,
                                                documento_garantia_fase.fase,
                                                documento_garantia.guia_llegada,
                                                documento_garantia.guia_envio,
                                                documento_garantia.id_paqueteria_llegada AS id_paqueteria_llegada,
                                                documento_garantia.id_paqueteria_envio AS id_paqueteria_envio,
                                                (SELECT paqueteria FROM paqueteria WHERE id = id_paqueteria_llegada) AS paqueteria_llegada,
                                                (SELECT paqueteria FROM paqueteria WHERE id = id_paqueteria_envio) AS paqueteria_envio,
                                                documento_garantia.created_by AS created_by,
                                                documento_garantia.asigned_to AS asigned_to,
                                                (SELECT nombre FROM usuario WHERE id = created_by) as creador,
                                                (SELECT nombre FROM usuario WHERE id = asigned_to) as tecnico,
                                                documento_garantia.created_at
                                            FROM documento_garantia
                                            INNER JOIN documento_garantia_causa ON documento_garantia.id_causa = documento_garantia_causa.id
                                            INNER JOIN documento_garantia_fase ON documento_garantia.id_fase = documento_garantia_fase.id
                                            WHERE documento_garantia.id_tipo = 3
                                            AND documento_garantia.created_at BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'");

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");

            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto = $contacto[0];
            $garantia->productos = $productos;
            $garantia->seguimiento = $seguimiento;
        }

        return response()->json([
            'code' => 200,
            'garantias' => $garantias_pendientes
        ]);
    }

    public function soporte_garantia_devolucion_servicio_historial_documento($documento)
    {
        $response = self::documento_servicio($documento);

        if ($response->error) {
            return response()->json([
                'code' => 500,
                'message' => $response->mensaje
            ]);
        }

        return response()->json([
            'code' => 200,
            'name' => $response->name,
            'file' => base64_encode($response->file)
        ]);
    }

    public function soporte_garantia_devolucion_servicio_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Segumiento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_data(Request $request)
    {
        $garantias_pendientes = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 8 AND id_tipo = 3");

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto = $contacto[0];
            $garantia->productos = $productos;
            $garantia->seguimiento = $seguimiento;
        }

        return response()->json([
            'code' => 200,
            'garantias' => $garantias_pendientes
        ]);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $seguimiento_extra = "";

        $json['message'] = "Seguimiento guardado correctamente.";

        if ($data->terminar) {
            if ($data->cotizacion_aceptada && $data->costo_total < 1) {
                return response()->json([
                    'code' => 500,
                    'message' => "El costo total para el cliente debe de ser mayor a 0."
                ]);
            }

            if ($data->costo_total > 0) {
                $existe_codigo_servicio = DB::select("SELECT id FROM modelo WHERE sku = 'ZZGZ0004'");

                if (empty($existe_codigo_servicio)) {
                    return response()->json([
                        'code' => 500,
                        'message' => "No éxiste el servicio 'Servicio a laptop' en el CRM, favor de contactar a un administrador."
                    ]);
                }

                $datos_cliente = DB::select("SELECT 
                                                documento_garantia_contacto.* 
                                            FROM documento_garantia 
                                            INNER JOIN documento_garantia_contacto ON documento_garantia.id = documento_garantia_contacto.id_garantia
                                            WHERE documento_garantia.id = " . $data->documento . "")[0];

                $entidad_pedido = DB::table('documento_entidad')->insertGetId([
                    'tipo' => 1,
                    'razon_social' => $datos_cliente->nombre,
                    'rfc' => 'XAXX010101000',
                    'telefono' => $datos_cliente->telefono,
                    'telefono_alt' => 0,
                    'correo' => $datos_cliente->correo
                ]);

                $documento_pedido = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa' => 1,
                    'id_tipo' => 2,
                    'id_periodo' => 1,
                    'id_cfdi' => 3,
                    'id_marketplace_area' => 18,
                    'id_usuario' => $auth->id,
                    'id_moneda' => 3,
                    'id_paqueteria' => 6,
                    'id_fase' => 5,
                    'factura_folio' => '',
                    'tipo_cambio' => 1,
                    'referencia' => 'N/A',
                    'info_extra' => 'N/A',
                    'observacion' => '1', // Status de la compra
                    'credito' => 0,
                    'expired_at' => '0000-00-00 00:00:00'
                ]);

                DB::table('documento_direccion')->insert([
                    'id_documento' => $documento_pedido,
                    'id_direccion_pro' => '0',
                    'contacto' => 'N/A',
                    'calle' => '',
                    'numero' => '',
                    'numero_int' => '',
                    'colonia' => '',
                    'ciudad' => '',
                    'estado' => '',
                    'codigo_postal' => '',
                    'referencia' => 'N/A'
                ]);

                DB::table('documento')->where(['id' => $documento_pedido])->update([
                    'id_entidad' => $entidad_pedido
                ]);

                DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento_pedido,
                    'id_modelo' => $existe_codigo_servicio[0]->id,
                    'cantidad' => 1,
                    'precio' => $data->costo_total / 1.16,
                    'garantia' => 0,
                    'modificacion' => 'N/A',
                    'regalo' => 0
                ]);

                DB::table('seguimiento')->insert([
                    'id_documento' => $documento_pedido,
                    'id_usuario' => $auth->id,
                    'seguimiento' => "Este pedido fue generado para pagar el servicio " . $data->documento . ""
                ]);

                $seguimiento_extra .= "<p>Este documento generó un pedido para cobrar el servicio, número de pedido: " . $documento_pedido . " </p><br>" .
                    "<p>Nota: Sí el cliente necesita factura, puedes editar el pedido en la secciona de ventas.</p>";

                $json['message'] = "Documento guardado correctamente. Un nuevo pedido fue generado para cubrir los costos del servicio, número de pedido: " . $documento_pedido . "";
            } else {
                $json['message'] = "Documento guardado correctamente.";
            }

            DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                'id_fase' => ($data->cotizacion_aceptada) ? 9 : 99,
                'tiene_costo' => ($data->costo_total > 0) ? 1 : 0,
                'costo_total' => $data->costo_total,
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento . $seguimiento_extra
        ]);

        $json['code'] = 200;

        return response()->json($json);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_crear(Request $request)
    {
        $productos = json_decode($request->input('productos'));
        $documento = $request->input('documento');
        $auth = json_decode($request->auth);

        $existe_usuario = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . " AND status = 1");

        if (empty($existe_usuario)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información del usuario."
            ]);
        }

        $existe_usuario = $existe_usuario[0];

        $info_cliente = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $documento . "");

        if (empty($info_cliente)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información sobre el cliente."
            ]);
        }

        $info_cliente = $info_cliente[0];

        $pdf = new Fpdf();

        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 24);

        $pdf->Image("img/omg.png", 10, 15, 60, 20, 'png');

        //Datos de la venta
        $pdf->Cell(68, 30, '');
        $pdf->Cell(10, 30, iconv('UTF-8', 'windows-1252', 'COTIZACIÓN'));
        $pdf->Ln(21);

        setlocale(LC_ALL, "es_MX");
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(110, 50, '');
        $pdf->Cell(10, 50, "Zapopan Jalisco " . strftime("%d de %B del %Y"));
        $pdf->Ln(15);

        $pdf->Cell(10, 40, '');
        $pdf->Cell(10, 40, iconv('UTF-8', 'windows-1252', $info_cliente->nombre));
        $pdf->Ln(5);
        $pdf->Cell(10, 40, '');
        $pdf->Cell(10, 40, 'P r e s e n t e');
        $pdf->Ln(30);

        $pdf->Cell(25, 40, '');
        $pdf->MultiCell(150, 5, iconv('UTF-8', 'windows-1252', 'Por este medio presentamos a usted la cotización de los equipos que nos solicitó, los cuales ponemos a su consideración, esperando poder contar con el privilegio de servirle.'));

        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, 'DESCRIPCION', "1", 0, 'C');
        $pdf->Cell(25, 10, 'CANTIDAD', "1", 0, 'C');
        $pdf->Cell(20, 10, 'PRECIO', "1", 0, 'C');
        $pdf->Cell(30, 10, 'TOTAL', "1", 0, 'C');
        $pdf->Ln();

        $total = 0;

        foreach ($productos as $producto) {
            $pdf->Cell(25, 40, '');
            $pdf->Cell(71, 10, (strlen($producto->producto) > 40) ? substr($producto->producto, 0, 40) : $producto->producto, "1", 0, 'C');
            $pdf->Cell(25, 10, $producto->cantidad, "1", 0, 'C');
            $pdf->Cell(20, 10, '$ ' . (float)$producto->precio, "1", 0, 'C');
            $pdf->Cell(30, 10, '$ ' . $producto->cantidad * $producto->precio, "1", 0, 'C');
            $pdf->Ln();

            $total += $producto->cantidad * $producto->precio / 1.16;
        }

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, '', "1", 0, 'C');
        $pdf->Cell(30, 10, '', "1", 0, 'C');
        $pdf->Ln();

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, '', "1", 0, 'C');
        $pdf->Cell(30, 10, '', "1", 0, 'C');
        $pdf->Ln();

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, 'Subtotal', "1", 0, 'C');
        $pdf->Cell(30, 10, '$ ' . $total, "1", 0, 'C');
        $pdf->Ln();

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, 'IVA', "1", 0, 'C');
        $pdf->Cell(30, 10, '$ ' . (($total * 1.16) - $total), "1", 0, 'C');
        $pdf->Ln();

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, 'TOTAL', "1", 0, 'C');
        $pdf->Cell(30, 10, '$ ' . ceil(($total * 1.16)), "1", 0, 'C');
        $pdf->Ln(20);

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 40, '');
        $pdf->MultiCell(165, 5, iconv('UTF-8', 'windows-1252', 'Los precios son expresados en pesos y ya incluyen el impuesto al valor agregado (IVA) vigencia de esta cotización 15 días'));
        $pdf->Ln(20);

        $pdf->Cell(10, 40, '');
        $pdf->Cell(10, 10, 'Quedo atento de sus comentarios');
        $pdf->Ln(5);

        $pdf->Cell(10, 40, '');
        $pdf->Cell(10, 10, iconv('UTF-8', 'windows-1252', $existe_usuario->nombre));


        $pdf_name = uniqid() . ".pdf";
        $pdf_data = $pdf->output($pdf_name, 'S');

        return response()->json([
            'code' => 200,
            'file' => base64_encode($pdf_data),
            'name' => "COTIZACION_" . $info_cliente->nombre . "_" . $documento . ".pdf"
        ]);
    }

    public function soporte_garantia_devolucion_servicio_reparacion_data(Request $request)
    {
        $garantias_pendientes = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 9 AND id_tipo = 3");

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");

            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto = $contacto[0];
            $garantia->productos = $productos;
            $garantia->seguimiento = $seguimiento;
        }

        return response()->json([
            'code' => 200,
            'garantias' => $garantias_pendientes
        ]);
    }

    public function soporte_garantia_devolucion_servicio_reparacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                'id_fase' => 99
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente:"
        ]);
    }

    public function garantia_devolucion_raw_data($fase = 0, $tipo, $usuario = 0, $fecha_inicial = "", $fecha_final = "", $garantia_pedido = "")
    {
        $query = "";

        if (is_string($tipo) && strpos($tipo, ',') !== false) {
            // Caso: "2,3" → lista IN (2,3)
            $query = "documento_garantia.id_tipo IN (" . $tipo . ")";
        } else {
            // Caso: un número (int o string simple)
            $query = "documento_garantia.id_tipo IN (" . (int)$tipo . ")";
        }

        if (!empty($garantia_pedido)) {
            $query .= " AND (documento_garantia.id = " . $garantia_pedido . " OR documento.id = " . $garantia_pedido . ")";
        } else {
            if ($usuario != 0) {
                $query .= " AND documento_garantia.asigned_to = " . $usuario;
            }

            if ($fase != 0) {
                $query .= " AND documento_garantia.id_fase = " . $fase;
            }

            if (!empty($fecha_inicial)) {
                $query .= ' AND documento_garantia.created_at BETWEEN "' . $fecha_inicial . ' 00:00:00" AND "' . $fecha_final . ' 23:59:59"';
            }
        }

        $documentos = DB::select("SELECT 
                                documento.id,
                                documento.documento_extra,
                                documento.created_at, 
                                documento_garantia.id AS documento_garantia,
                                documento_garantia_causa.causa,
                                documento_garantia_fase.fase,
                                documento_garantia.guia_llegada,
                                documento_garantia.guia_envio,
                                documento_garantia.parcial,
                                documento_garantia.id_paqueteria_llegada AS id_paqueteria_llegada,
                                (SELECT paqueteria FROM paqueteria WHERE id = id_paqueteria_llegada) AS paqueteria_llegada,
                                (SELECT paqueteria FROM paqueteria WHERE id = id_paqueteria_envio) AS paqueteria_envio,
                                documento_garantia.created_by AS created_by,
                                documento_garantia.asigned_to AS asigned_to,
                                (SELECT nombre FROM usuario WHERE id = created_by) as creador,
                                (SELECT nombre FROM usuario WHERE id = asigned_to) as tecnico,
                                documento_entidad.razon_social AS cliente,
                                documento_entidad.rfc,
                                documento_entidad.correo,
                                documento_entidad.telefono,
                                documento_entidad.telefono_alt,
                                empresa_almacen.id_empresa,
                                marketplace.marketplace, 
                                marketplace_area.publico,
                                area.area, 
                                paqueteria.paqueteria, 
                                usuario.nombre AS usuario
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN documento_garantia_re ON documento.id = documento_garantia_re.id_documento
                            INNER JOIN documento_garantia ON documento_garantia_re.id_garantia = documento_garantia.id
                            INNER JOIN documento_garantia_causa ON documento_garantia.id_causa = documento_garantia_causa.id
                            INNER JOIN documento_garantia_fase ON documento_garantia.id_fase = documento_garantia_fase.id
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE " . $query);

        foreach ($documentos as $documento) {
            $productos = DB::table('documento_garantia_producto as dgp')
                ->join('modelo', 'dgp.producto', '=', 'modelo.sku')
                ->join('movimiento', 'modelo.id', '=', 'movimiento.id_modelo')
                ->select(
                    'modelo.id AS id_modelo',
                    'modelo.sku',
                    'modelo.serie',
                    'modelo.costo',
                    'movimiento.id',
                    'modelo.descripcion',
                    'dgp.cantidad', // Se usa la cantidad de la tabla de devolución, no la original
                    'movimiento.precio',
                    'movimiento.garantia',
                    'movimiento.regalo',
                    DB::raw('0 AS cambio')
                )
                // Asegura que los productos pertenezcan a la garantía/devolución correcta
                ->where('dgp.id_garantia', $documento->documento_garantia)
                // Asegura que los datos de movimiento (precio, etc.) correspondan a la venta original
                ->where('movimiento.id_documento', $documento->id)
                ->get();

            foreach ($productos as $producto) {
                $producto->series = array();
            }

            $seguimiento_venta = DB::select("SELECT 
                                                    seguimiento.seguimiento, 
                                                    seguimiento.created_at, 
                                                    usuario.nombre 
                                                FROM seguimiento 
                                                INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                                WHERE id_documento = " . $documento->id . "");

            $seguimiento_garantia = DB::select("SELECT 
                                                    documento_garantia_seguimiento.seguimiento, 
                                                    documento_garantia_seguimiento.created_at, 
                                                    usuario.nombre 
                                                FROM documento_garantia_seguimiento 
                                                INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                                WHERE id_documento = " . $documento->documento_garantia . "");

            $archivos = DB::select("SELECT
                                        usuario.id,
                                        usuario.nombre AS usuario,
                                        documento_archivo.nombre AS archivo,
                                        documento_archivo.dropbox
                                    FROM documento_archivo
                                    INNER JOIN usuario ON documento_archivo.id_usuario = usuario.id
                                    WHERE documento_archivo.id_documento = " . $documento->id . " AND documento_archivo.status = 1");

            $documento->seguimiento_venta = $seguimiento_venta;
            $documento->seguimiento_garantia = $seguimiento_garantia;
            $documento->productos = $productos;
            $documento->archivos = $archivos;
        }

        return $documentos;
    }

    private function documento_servicio($documento)
    {
        $response = new \stdClass();

        $informacion_servicio = DB::select("SELECT
                                                documento_garantia.id,
                                                documento_garantia.asigned_to,
                                                documento_garantia.created_by,
                                                (SELECT nombre FROM usuario WHERE id = asigned_to) AS tecnico,
                                                (SELECT nombre FROM usuario WHERE id = created_by) AS creador,
                                                documento_garantia_contacto.nombre AS cliente,
                                                documento_garantia_contacto.telefono,
                                                documento_garantia_contacto.correo
                                            FROM documento_garantia
                                            INNER JOIN documento_garantia_contacto ON documento_garantia.id = documento_garantia_contacto.id_garantia
                                            WHERE documento_garantia.id = " . $documento . "");

        if (empty($informacion_servicio)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del servicio, favor de verificar e intentar de nuevo.";

            return $response;
        }

        $informacion_servicio = $informacion_servicio[0];

        $productos = DB::select("SELECT producto, cantidad FROM documento_garantia_producto WHERE id_garantia = " . $documento . "");

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de los productos del servicio, favor de verificar e intentar de nuevo.";

            return $response;
        }

        $seguimientos = DB::select("SELECT * FROM documento_garantia_seguimiento WHERE id_documento = " . $documento . "");

        $pdf = new Fpdf();

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(69, 90, 100);

        # Informacion de la empresa
        # OMG Logo
        $pdf->Image("img/omg.png", 5, 10, 60, 20, 'png');

        $pdf->SetFont('Arial', 'B', 25);

        $pdf->Cell(100, 10, "");
        $pdf->Cell(40, 10, "NO. SERVICIO: " . $informacion_servicio->id);

        $pdf->SetFont('Arial', '', 10);

        $pdf->Ln(30);
        $pdf->Cell(100, 10, 'OMG INTERNATIONAL SA DE CV');
        $pdf->Cell(25, 10, 'TECNICO: ');
        $pdf->Cell(10, 10, $informacion_servicio->tecnico);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'Industria Vidriera #105, Fracc. Industrial Zapopan Norte');
        $pdf->Ln(5);
        $pdf->Cell(20, 10, $informacion_servicio->creador);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'soporte@omg.com.mx');

        # Información del cliente
        $pdf->Ln(20);
        $pdf->Cell(100, 10, 'INFORMACION DEL CLIENTE');
        $pdf->Cell(10, 10, 'INFORMACION DEL SERVICIO');

        $pdf->SetFont('Arial', 'B', 10);

        setlocale(LC_ALL, "es_MX");

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_servicio->cliente, 'UTF-8')));
        $pdf->Cell(30, 10, 'Fecha: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, strftime("%A %d de %B del %Y"));

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_servicio->correo, 'UTF-8')));

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'No. Servicio: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $informacion_servicio->id);

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_servicio->telefono, 'UTF-8')));

        $pdf->Ln(20);

        $pdf->Cell(150, 10, "Descripcion", "T");
        $pdf->Cell(40, 10, "Cantidad", "T");
        $pdf->Ln();

        foreach ($productos as $producto) {
            $pdf->Cell(150, 10, $producto->producto, "T");
            $pdf->Cell(40, 10, $producto->cantidad, "T");
            $pdf->Ln();
        }

        if (!empty($seguimientos)) {
            $pdf->ln();
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(100, 40, "OBSERVACIONES");

            $pdf->Ln(25);
            $pdf->SetFont('Arial', '', 10);

            foreach ($seguimientos as $seguimiento) {
                $seguimiento->seguimiento = str_replace("&nbsp;", " ", $seguimiento->seguimiento);

                $pdf->MultiCell(190, 5, "> " . strip_tags($seguimiento->seguimiento));
            }
        }

        $pdf->Ln(50);
        $pdf->Cell(190, 10, "Firma de recibido", 0, 0, "C");

        $pdf->Ln(20);
        $pdf->Cell(190, 10, "", "B", 0, "C");

        $pdf_name = uniqid() . ".pdf";
        $pdf_data = $pdf->Output($pdf_name, 'S');
        $file_name = "SERVICIO_" . $informacion_servicio->cliente . "_" . $informacion_servicio->id . ".pdf";

        $response->error = 0;
        $response->name = $file_name;
        $response->file = $pdf_data;

        return $response;
    }

    private function documento_garantia($documento)
    {
        $response = new \stdClass();

        $informacion_garantia = DB::table('documento_garantia as dg')
            ->join('documento_garantia_re as dgr', 'dg.id', '=', 'dgr.id_garantia')
            ->join('documento as d', 'dgr.id_documento', '=', 'd.id')
            ->join('documento_entidad as de', 'd.id_entidad', '=', 'de.id')
            ->leftJoin('usuario as tecnico', 'dg.asigned_to', '=', 'tecnico.id')
            ->join('usuario as creador', 'dg.created_by', '=', 'creador.id')
            ->leftJoin('documento_garantia_causa as dgc', 'dg.id_causa', '=', 'dgc.id')
            ->select(
                'dg.id',
                'dg.id_tipo',
                'd.id as numero_pedido',
                'tecnico.nombre as tecnico',
                'creador.nombre as creador',
                'de.razon_social as cliente',
                'de.telefono',
                'de.correo',
                'dgc.causa as motivo'
            )
            ->where('dg.id', $documento)->first();

        if (!$informacion_garantia) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la garantía.";
            return $response;
        }

        $esDevolucion = in_array($informacion_garantia->id_tipo, [1, 2]);
        $tipo_texto_titulo = $esDevolucion ? 'REPORTE DE DEVOLUCION' : 'REPORTE DE GARANTIA';
        $tipo_texto_detalles = $esDevolucion ? 'DETALLES DE LA DEVOLUCION' : 'DETALLES DE LA GARANTIA';
        $tipo_texto_numero = $esDevolucion ? 'No. Devolucion' : 'No. Garantia';
        $tipo_texto_productos = $esDevolucion ? 'PRODUCTOS EN DEVOLUCION' : 'PRODUCTOS EN GARANTIA';

        $productos_garantia = DB::table('documento_garantia_producto as dgp')
            ->leftJoin('modelo as m', 'dgp.producto', '=', 'm.sku')
            ->select('dgp.producto as sku', 'dgp.cantidad', 'm.descripcion')
            ->where('dgp.id_garantia', $documento)->get();

        $seguimientos = DB::table('documento_garantia_seguimiento')
            ->where('id_documento', $documento)->get();

        $pdf = new Fpdf();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Image(base_path('public/img/omg.png'), 10, 8, 50);
        $pdf->SetFont('Arial', 'B', 28);
        $pdf->SetTextColor(220, 53, 69);
        $pdf->Cell(80);
        $pdf->Cell(100, 10, $tipo_texto_titulo, 0, 0, 'C');
        $pdf->Ln(25);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY(40);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(95, 8, 'INFORMACION DEL CLIENTE', 0, 1, 'L', true);
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Cliente:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->cliente));
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Correo:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->correo));
        $pdf->Ln();
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, iconv('UTF-8', 'windows-1252', 'Teléfono:'));
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->telefono));

        $pdf->SetY(40);
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(90, 8, $tipo_texto_detalles, 0, 1, 'L', true);
        $pdf->Ln(2);

        $y_datos_importantes = $pdf->GetY();
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetDrawColor(222, 226, 230);

        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(44, 7, $tipo_texto_numero, 0, 1, 'C');
        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(44, 10, $informacion_garantia->id, 1, 0, 'C', true);

        $pdf->SetY($y_datos_importantes);
        $pdf->SetX(155);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(45, 7, 'Pedido Original', 0, 1, 'C');
        $pdf->SetX(155);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(45, 10, $informacion_garantia->numero_pedido, 1, 1, 'C', true);

        $pdf->Ln(4);

        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Fecha:');
        $pdf->SetFont('Arial', '', 10);
        $meses = ["January" => "Enero", "February" => "Febrero", "March" => "Marzo", "April" => "Abril", "May" => "Mayo", "June" => "Junio", "July" => "Julio", "August" => "Agosto", "September" => "Septiembre", "October" => "Octubre", "November" => "Noviembre", "December" => "Diciembre"];
        $fecha_actual = date("d") . " de " . $meses[date("F")] . " del " . date("Y");
        $pdf->Cell(65, 6, $fecha_actual);
        $pdf->Ln();

        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Creado por:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(65, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->creador));
        $pdf->Ln();

        $pdf->SetX(110);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 6, 'Motivo:');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(65, 6, iconv('UTF-8', 'windows-1252', $informacion_garantia->motivo));

        $pdf->SetY(105);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, $tipo_texto_productos, 0, 1, 'L', true);
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Cell(40, 8, 'SKU / Codigo', 1, 0, 'C', true);
        $pdf->Cell(120, 8, iconv('UTF-8', 'windows-1252', 'Descripción'), 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Cantidad', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 10);
        if (!$productos_garantia->isEmpty()) {
            foreach ($productos_garantia as $producto) {
                $y_before_text = $pdf->GetY();
                $pdf->SetX(300);
                $pdf->MultiCell(120, 7, iconv('UTF-8', 'windows-1252', $producto->descripcion));
                $row_height = $pdf->GetY() - $y_before_text;
                $pdf->SetXY(10, $y_before_text);

                $pdf->MultiCell(40, 7, $producto->sku);
                $pdf->SetXY(50, $y_before_text);
                $pdf->MultiCell(120, 7, iconv('UTF-8', 'windows-1252', $producto->descripcion));
                $pdf->SetXY(170, $y_before_text);
                $pdf->MultiCell(30, 7, $producto->cantidad, 0, 'C');

                $pdf->Line(10, $y_before_text, 10, $y_before_text + $row_height);
                $pdf->Line(50, $y_before_text, 50, $y_before_text + $row_height);
                $pdf->Line(170, $y_before_text, 170, $y_before_text + $row_height);
                $pdf->Line(200, $y_before_text, 200, $y_before_text + $row_height);
                $pdf->Line(10, $y_before_text + $row_height, 200, $y_before_text + $row_height);

                $pdf->SetY($y_before_text + $row_height);
            }
        }

        if (!$seguimientos->isEmpty()) {
            $pdf->Ln(10);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(0, 8, 'OBSERVACIONES', 0, 1, 'L', true);
            $pdf->Ln(4);
            $pdf->SetFont('Arial', '', 10);
            foreach ($seguimientos as $seguimiento) {
                $texto_limpio = "> " . strip_tags(str_replace("&nbsp;", " ", $seguimiento->seguimiento));
                $pdf->MultiCell(190, 5, iconv('UTF-8', 'windows-1252', $texto_limpio));
                $pdf->Ln(2);
            }
        }

        $pdf->SetAutoPageBreak(false);

        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }
        $pdf->SetY(-45);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 10, 'Firma de Recibido', 0, 1, 'C');
        $pdf->SetX(60);
        $pdf->Cell(90, 10, '', 'T', 0, 'C');

        $pdf->SetY(-15);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(128);
        $pdf->Cell(0, 10, 'Pagina ' . $pdf->PageNo(), 0, 0, 'C');

        $pdf->SetAutoPageBreak(true, 15);

        $file_name  = ($esDevolucion ? "DEVOLUCION_" : "GARANTIA_") . $informacion_garantia->id . "_" . time() . ".pdf";
        $pdf_data   = $pdf->Output($file_name, 'S');

        $response->error = 0;
        $response->name = $file_name;
        $response->file = $pdf_data;

        return $response;
    }

    private function make_json($json)
    {
        header('Content-Type: application/json');

        return json_encode($json);
    }

    /**
     * Finaliza un proceso de devolución/garantía, creando la NC y actualizando el estado.
     * Es una función interna para ser llamada por otros métodos del controlador.
     *
     * @param int $id_documento_original
     * @param int $id_garantia
     * @return \stdClass
     */
    public function terminar_devolucion(int $id_documento_original, int $id_garantia): \stdClass
    {
        $response = new \stdClass();

        // 1. Se crea la Nota de Crédito (como ya la teníamos)
        $response_nc = DocumentoService::crearNotaCreditoConEgreso($id_documento_original, 0, $id_garantia);

        if ($response_nc->error) {
            $response->error = 1;
            $response->message = "Error al crear la nota de crédito: " . $response_nc->mensaje;
            return $response;
        }

        // 2. Se crea el Traspaso llamando a nuestra nueva función
        $response_traspaso = InventarioService::crear_traspaso_devolucion($id_documento_original, $id_garantia);
        $seguimiento_adicional = "";

        if ($response_traspaso->error) {
            // Si el traspaso falla, lo registramos pero no detenemos el proceso,
            // ya que la NC ya se hizo. Esto es una decisión de negocio, se puede cambiar.
            $seguimiento_adicional = " ADVERTENCIA: " . $response_traspaso->message;
        } else {
            $seguimiento_adicional = " " . $response_traspaso->message;
        }

        // 3. Actualizamos la fase del documento de garantía a "Finalizado"
        DB::table('documento_garantia')
            ->where('id', $id_garantia)
            ->update(['id_fase' => 100]);

        // 4. Se agrega un seguimiento final con el resultado de ambas operaciones
        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $id_garantia,
            'id_usuario' => 1,
            'seguimiento' => "Proceso finalizado. Se generó la Nota de Crédito ID: " . $response_nc->id_nota_credito . "." . $seguimiento_adicional
        ]);

        $response->error = 0;
        $response->message = "Proceso de devolución finalizado." . $seguimiento_adicional;

        return $response;
    }

    public static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'SC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'RTE'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }
}
