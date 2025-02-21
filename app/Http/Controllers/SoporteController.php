<?php

namespace App\Http\Controllers;

use App\Models\Enums\DocumentoTipo;
use App\Models\Enums\DocumentoFase;
use App\Models\Enums\DocumentoStatus;
use App\Models\Enums\DocumentoGarantiaTipo;
use App\Models\Enums\DocumentoGarantiaFase;
use App\Models\Enums\HttpStatusCode;
use App\Models\Enums\UsuarioNivel;

use App\Models\Documento;
use App\Models\Usuario;
use App\Models\Paqueteria;
use App\Models\DocumentoGarantiaCausa;

use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use App\Events\PusherEvent;
use Exception;
use DB;

class SoporteController extends Controller
{
    /* Soporte > revisión */
    public function soporte_revision_data(Request $request)
    {
        $ventas = DB::select("SELECT 
                                documento.id,
                                documento.documento_extra,
                                documento.created_at, 
                                documento_entidad.razon_social AS cliente,
                                documento_entidad.rfc,
                                documento_entidad.correo,
                                documento_entidad.telefono,
                                documento_entidad.telefono_alt,
                                marketplace.marketplace, 
                                marketplace_area.publico,
                                area.area, 
                                paqueteria.paqueteria, 
                                usuario.nombre AS usuario
                            FROM documento
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE documento.id_fase = 2
                            AND documento.modificacion = 1
                            AND documento.problema = 0
                            AND documento.id_tipo = 2
                            AND documento.status = 1");

        foreach ($ventas as $venta) {
            $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    movimiento.cantidad, 
                                    movimiento.modificacion
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

            $seguimiento = DB::select("SELECT 
                                            seguimiento.*, 
                                            usuario.nombre 
                                        FROM seguimiento 
                                        INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                        WHERE id_documento = " . $venta->id . "");

            $venta->seguimiento = $seguimiento;
            $venta->productos   = $productos;
        }

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas
        ]);
    }

    public function soporte_revision_guardar(Request $request)
    {
        $data       = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento')->where(['id' => $data->documento])->update([
                'id_fase'       => 3
            ]);

            DB::table('seguimiento')->insert([
                'id_usuario'    => $auth->id,
                'id_documento'  => $data->documento,
                'seguimiento'   => $data->seguimiento . "<p>Fecha de revisión: " . date('d-m-Y H:i:s') . "</p>"
            ]);

            return response()->json([
                'code'  => 200,
                'message'   => "Documento guardado correctamente."
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_usuario'    => $auth->id,
            'id_documento'  => $data->documento,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'      => 200,
            'message'   => "Seguimiento guardado correctamente."
        ]);
    }
    /*  Soporte > garantías y devoluciones */
    public function soporte_garantia_devolucion_data()
    {
        $tipos_documento = DB::select("SELECT * FROM documento_garantia_tipo WHERE id != 3");
        $causas_documento = DB::select("SELECT * FROM documento_garantia_causa WHERE id != 0");

        return response()->json([
            'tipos'     => $tipos_documento,
            'causas'    => $causas_documento
        ]);
    }

    public function soporte_garantia_devolucion_venta($venta)
    {
        $existe_venta = DB::select("SELECT id FROM documento WHERE id = '" . TRIM($venta) . "' AND id_tipo = 2 AND documento.status = 1");
        $venta_id = 0;

        if (empty($existe_venta)) {
            $existe_venta_serie = DB::select("SELECT
                                                documento.id
                                            FROM documento
                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                            INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                            WHERE producto.serie = '" . $venta . "'
                                            AND documento.status = 1");

            if (empty($existe_venta_serie)) {
                return response()->json([
                    'code'  => 404,
                    'message'   => "No se encontróm la venta."
                ]);
            }

            $venta_id = $existe_venta_serie[0]->id;
        } else {
            $venta_id = $existe_venta[0]->id;
        }

        $productos = DB::select("SELECT
                                        modelo.sku,
                                        modelo.descripcion,
                                        '' as series
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $venta_id . "");
        return response()->json([
            'code'  => 200,
            'productos' => $productos
        ]);
    }

    public function soporte_garantia_devolucion_eliminar_documento(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_authy = DocumentoService::authy($auth->id, $data->authy_code);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje
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
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $informacion_documento = Documento::select(
            "documento.id",
            "documento.id_fase",
            "documento.factura_serie",
            "documento.factura_folio",
            "empresa.bd"
        )
            ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
            ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
            ->where("documento.id_tipo", DocumentoTipo::VENTA)
            ->where("documento.status", DocumentoStatus::ACTIVO)
            ->where("documento.id", $data->venta)
            ->first();

        if (!$informacion_documento) {
            $informacion_documento = Documento::join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
                ->select(
                    "documento.id",
                    "documento.id_fase",
                    "documento.factura_serie",
                    "documento.factura_folio",
                    "empresa.bd"
                )
                ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
                ->join("movimento", "documento.id", "=", "movimiento.id_documento")
                ->join("movimiento_producto", "movimiento.id", "=", "movimiento_producto.id_movimiento")
                ->join("producto", "movimiento_producto.id_producto", "=", "producto.id")
                ->where("producto.serie", trim($data->venta))
                ->where("documento.status", DocumentoStatus::ACTIVO)
                ->first();

            if (!$informacion_documento) {
                return response()->json([
                    'message' => "No se encontró la venta."
                ]);
            }
        }

        if ($informacion_documento->id_fase < DocumentoFase::PENDIENTE_FACTURA) {
            return response()->json([
                'message' => "No es posible generar un documento de devolución o garantía ya que el producto no ha sido enviado."
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        $existe_devolucion = DB::table("documento_garantia")
            ->select("documento_garantia.id")
            ->join("documento_garantia_re", "documento_garantia.id", "=", "documento_garantia_re.id_garantia")
            ->where("documento_garantia_re.id_documento", $informacion_documento->id)
            ->where("documento_garantia.id_tipo", DocumentoGarantiaTipo::DEVOLUCION)
            ->first();

        if ($existe_devolucion) {
            return response()->json([
                'message' => "Ya éxiste una devolución generada a partir de esa venta. ID " . $existe_devolucion->id
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        $factura_folio = $informacion_documento->factura_folio == 'N/A' ? $informacion_documento->id : $informacion_documento->factura_folio;

        $informacion_factura = @json_decode(file_get_contents(config('webservice.url') . $informacion_documento->bd . '/Factura/Estado/Folio/' . $factura_folio));

        if (empty($informacion_factura)) {
            return response()->json([
                'message' => "No se encontró la factura del pedido " . $informacion_documento->id
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        if (is_array($informacion_factura)) {
            foreach ($informacion_factura as $factura) {
                if (($factura->eliminado == DocumentoStatus::INACTIVO || $factura->eliminado == DocumentoStatus::NULL) && ($factura->cancelado == DocumentoStatus::INACTIVO || $factura->cancelado == DocumentoStatus::NULL)) {
                    $informacion_factura = $factura;

                    break;
                }
            }
        }

        if (($informacion_factura->cancelado != DocumentoStatus::INACTIVO && $informacion_factura->cancelado != DocumentoStatus::NULL) || ($informacion_factura->eliminado != DocumentoStatus::INACTIVO && $informacion_factura->eliminado != DocumentoStatus::NULL)) {
            return response()->json([
                'message' => "No es posible generar el documento ya que la factura se encuentra cancelada."
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        $informacion_usuario = Usuario::find($auth->id);

        if (!$informacion_usuario) {
            return response()->json([
                'message' => "No se encontró información sobre el usuario"
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        $informacion_cliente = DB::table("documento")
            ->select("documento_entidad.*")
            ->join("documento_entidad", "documento.id_entidad", "=", "documento_entidad.id")
            ->where("documento.id", $informacion_documento->id)
            ->first();

        if (!$informacion_cliente) {
            return response()->json([
                'message' => "No se encontró información sobre el cliente."
            ], HttpStatusCode::NOT_ACCEPTABLE);
        }

        $documento_garantia = DB::table('documento_garantia')->insertGetId([
            'id_tipo' => $data->tipo,
            'id_causa' => $data->causa,
            'id_fase' => $data->tipo == DocumentoGarantiaTipo::DEVOLUCION ? DocumentoGarantiaFase::DEVOLUCION_PENDIENTE : DocumentoGarantiaFase::GARANTIA_PENDIENTE_LLEGADA,
            'no_reclamo' => $data->reclamo,
            'created_by' => $auth->id,
            'parcial' => $data->parcial
        ]);

        DB::table('documento_garantia_re')->insertGetId([
            'id_documento' => $informacion_documento->id,
            'id_garantia' => $documento_garantia
        ]);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento' => $documento_garantia,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->seguimiento
        ]);

        foreach ($data->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                    ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body($archivo_data)
                    ->send();

                $documento_archivo = DB::table('documento_archivo')->insert([
                    'id_documento' => $informacion_documento->id,
                    'id_usuario' => $auth->id,
                    'nombre' => $archivo->nombre,
                    'dropbox' => $response->body->id
                ]);

                DB::table('documento_garantia_archivo')->insert([
                    'id_archivo' => $documento_archivo,
                    'id_garantia' => $documento_garantia
                ]);
            }
        }

        $file_name = "";
        $file_data = "";

        if ($data->tipo == DocumentoGarantiaTipo::DEVOLUCION_PARCIAL_GARANTIA) {
            foreach ($data->productos as $producto) {
                DB::table('documento_garantia_producto')->insert([
                    'id_garantia' => $documento_garantia,
                    'producto' => $producto->series,
                    'cantidad' => 0
                ]);
            }

            $response = self::documento_garantia($documento_garantia);

            if (!$response->error) {
                $file_data = base64_encode($response->file);
                $file_name = $response->name;
            }
        }

        return response()->json([
            "message" => "Documento creado correctamente con el siguiente número: " . $documento_garantia,
            "file" => $file_name,
            "name" => $file_name
        ]);
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

        $documentos = $this->garantia_devolucion_raw_data(DocumentoGarantiaFase::DEVOLUCION_PENDIENTE, DocumentoGarantiaTipo::DEVOLUCION);

        return response()->json([
            'causas' => $causas,
            'ventas' => $documentos,
            'tecnicos' => $tecnicos,
            'paqueterias' => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            foreach ($data->productos as $producto) {
                if (COUNT($producto->series) > 0) {
                    if (COUNT($producto->series) != (int) $producto->cantidad) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "La cantidad de series agregadas no concuerda con la cantidad del producto, favor de revisar en intentar de nuevo."
                        ]);
                    }

                    foreach ($producto->series as $serie) {
//                        $apos = `'`;
//                        //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
//                        if (str_contains($serie, $apos)) {
//                            $serie = addslashes($serie);
//                        }
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
                                'code'  => 500,
                                'message'   => "La serie " . $serie . " no corresponde a su producto asignado, favor de verificar e intentar de nuevo."
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
                                'code'  => 500,
                                'message'   => "El documento no cuenta con ningun movimiento que contenga el sku " . trim($producto->sku) . ""
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

            $info_entidad = DB::select("SELECT
                                documento_entidad.*
                            FROM documento
                            INNER JOIN documento_entidad ON documento_entidad.id = documento.id_entidad
                            WHERE documento.id = " . $data->documento . "
                            AND documento_entidad.tipo = 1");

            if (empty($info_documento)) {
                return response()->json([
                    'code'  => 501,
                    'message'   => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
                ]);
            }

            if (empty($info_entidad)) {
                return response()->json([
                    'code'  => 501,
                    'message'   => "No se encontró la información del cliente, favor de contactar al administrador."
                ]);
            }

            if (empty($productos)) {
                return response()->json([
                    'code'  => 404,
                    'message'   => "No se encontraron productos del documento, favor de contactar al administrador."
                ]);
            }

            $info_documento = $info_documento[0];

            # Se relaciona las series a las partidas de la venta para llevar un registro
            foreach ($data->productos as $producto) {
                foreach ($producto->series as $serie) {
//                    $apos = `'`;
//                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
//                    if (str_contains($serie, $apos)) {
//                        $serie = addslashes($serie);
//                    }
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
                        'id_producto'   => $id_serie
                    ]);

                    DB::table('producto')->where(['id' => $id_serie])->update([
                        'status'    => 0
                    ]);
                }
            }

            # Se crear la nota de credito a partir del pedido
            $crear_nota_credito = DocumentoService::crearNotaCredito($data->documento);

            if ($crear_nota_credito->error) {
                return response()->json([
                    'code' => 500,
                    'message' => $crear_nota_credito->mensaje . " 512",
                    'raw' => property_exists($crear_nota_credito, "raw") ? $crear_nota_credito->raw : 0,
                    'data' => property_exists($crear_nota_credito, "data") ? $crear_nota_credito->data : 0,
                ]);
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

            if (!empty($empresa_externa)) {
                $info_documento_almacenes = DB::select("SELECT almacen_devolucion_garantia_sistema FROM empresa WHERE rfc = '" . $empresa_externa[0]->rfc . "'");

                $info_factura_empresa_externa = @json_decode(file_get_contents(config('webservice.url') . $empresa_externa[0]->bd  . '/Factura/Estado/Folio/' . $data->documento));

                if (empty($info_factura_empresa_externa)) {
                    return response()->json([
                        'code' => 500,
                        'message' => "No se encontró información de la factura en la empresa externa con la BD " . $empresa_externa[0]->bd . ", favor de eliminar la nota de credito generada manualmente"
                    ]);
                }

                if (is_array($info_factura_empresa_externa)) {
                    $info_factura_empresa_externa = $info_factura_empresa_externa[0];
                }
            }

            if (!$venta_fba) {
                $seguimiento_traspaso = "";

                if (!empty($empresa_externa)) {
                    $almacen_secundario_empresa = DB::select("SELECT
                                                                empresa_almacen.id
                                                            FROM empresa_almacen
                                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                            WHERE empresa_almacen.id_erp = " . $info_factura_empresa_externa->almacenid . "
                                                            AND empresa.bd = " . $empresa_externa[0]->bd . "");

                    if (empty($almacen_secundario_empresa)) {
                        return response()->json([
                            'code' => 500,
                            'message' => "No se encontró de la factura registrado en el sistema, favor de eliminar la nota de credito manualmente."
                        ]);
                    }
                }

                $documento_traspaso = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa'  => !empty($empresa_externa) ? $info_documento_almacenes[0]->almacen_devolucion_garantia_sistema : $info_documento->almacen_devolucion_garantia_sistema,
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
                            //Aqui se quita
//                            $apos = `'`;
//                            //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
//                            if (str_contains($serie, $apos)) {
//                                $serie = addslashes($serie);
//                            }
                            $serie = str_replace(["'", '\\'], '', $serie);
                            $existe_serie = DB::select("SELECT id FROM producto WHERE serie = '" . TRIM($serie) . "'");

                            if (empty($existe_serie)) {
                                $id_serie = DB::table('producto')->insertGetId([
                                    'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                    'serie' => trim($serie),
                                    'status' => 1
                                ]);
                            } else {
                                $id_serie = $existe_serie[0]->id;

                                DB::table('producto')->where(['id' => $existe_serie[0]->id])->update([
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

                $crear_traspaso = DocumentoService::crearMovimiento($documento_traspaso);

                if ($crear_traspaso->error) {
                    DB::table('documento')->where(['id' => $documento_traspaso])->delete();

                    return response()->json([
                        'code'  => 500,
                        'message'   => $crear_traspaso->mensaje
                    ]);
                }

                $seguimiento_traspaso .= "<p>Traspaso creado correctamente con el ID " . $crear_traspaso->id . ".</p>";

                $afectar_traspaso = DocumentoService::afectarMovimiento($documento_traspaso);

                if ($afectar_traspaso->error) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => $afectar_traspaso->mensaje
                    ]);
                }

                $seguimiento_traspaso .= "<p>Traspaso con el ID " . $crear_traspaso->id . " afectado correctamente.</p>";

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

            # De desaplican los pagos y notas de credito que tenga la factura y se genera una NC en general
            $seguimentos_pagos  = "<br><br>";
            $folio_factura  = ($info_documento->factura_serie == 'N/A') ? $data->documento : $info_documento->factura_folio;
            $info_factura   = @json_decode(file_get_contents(config("webservice.url") . $info_documento->bd . '/Factura/Estado/Folio/' . $folio_factura));

            if (empty($info_factura)) {
                return response()->json([
                    'code' => 200,
                    'message' => "Documento guardado correctamente, NC: " . $nota_id . ", Traspaso: " . $traspaso_id . ", no fue posible desasociar el pago ni pagar la factura con la NC por que no se encontró la factura."
                ]);
            }

            $pagos_asociados = @json_decode(file_get_contents(config('webservice.url') . $info_documento->bd . '/Documento/' . $info_factura->documentoid . '/PagosRelacionados'));

            if (!empty($pagos_asociados)) {
                foreach ($pagos_asociados as $pago) {
                    $pago_id = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                    $eliminar_relacion = DocumentoService::desaplicarPagoFactura($data->documento, $pago_id);

                    if ($eliminar_relacion->error) {
                        $seguimentos_pagos .= "<p>No fue posible eliminar la relación " . ($pago->pago_con_operacion == 0) ? 'de la nc' : 'del pago' . " con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>";
                    } else {
                        $seguimentos_pagos .= "<p>Se eliminó la relación " . ($pago->pago_con_operacion == 0) ? 'de la nc' : 'del pago' . " con el ID " . $pago_id . ", correctamente.</p>";
                    }
                }
            } else {
                $seguimentos_pagos .= "<p>No hay pagos relacionados a la factura " . $folio_factura . "</p>";
            }

            $saldar_factura = DocumentoService::saldarFactura($data->documento, $crear_nota_credito->id, 0);

            $seguimentos_pagos .= "<p>" . $saldar_factura->mensaje . ".</p>";

            DB::table('seguimiento')->insert([
                'id_documento' => $data->documento,
                'id_usuario' => $auth->id,
                'seguimiento' => $seguimentos_pagos
            ]);
        }

        # Sí la devolución tiene evidencia en archivos, se suben a dropbox y se relacionan al pedido
        if (!empty($data->archivos)) {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                        ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento'  =>  $data->documento,
                        'id_usuario'    =>  $auth->id,
                        'nombre'        =>  $archivo->nombre,
                        'dropbox'       =>  $response->body->id
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
    }

    public function soporte_garantia_devolucion_devolucion_revision_data(Request $request)
    {
        $auth       = json_decode($request->auth);
        $documentos = $this->garantia_devolucion_raw_data(3, 1, $auth->id);

        return response()->json([
            'code'      => 200,
            'ventas'    => $documentos,
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_revision_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            if ($data->disponible) {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 100,
                    'disponible_venta' => $data->disponible
                ]);
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
            'code'  => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_indemnizacion_data()
    {
        $documentos = $this->garantia_devolucion_raw_data(4, 1); # Fase del documento y tipo de documento

        return response()->json([
            'code'      => 200,
            'ventas'    => $documentos,
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_indemnizacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            if ($data->indemnizacion) {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 5
                ]);
            } else {
                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 100
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

    public function soporte_garantia_devolucion_devolucion_reclamo_data()
    {
        $documentos = $this->garantia_devolucion_raw_data(5, 1);

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_devolucion_reclamo_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                'id_fase' => 100
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

    public function soporte_garantia_devolucion_devolucion_historial_data(Request $request)
    {
        $data = json_decode($request->input("data"));

        $documentos = $this->garantia_devolucion_raw_data(0, 1, 0, $data->fecha_inicial, $data->fecha_final, $data->documento);

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

        $documentos = $this->garantia_devolucion_raw_data(1, 2);

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

        if ($data->terminar) {
            if (!empty($data->notificados)) {
                $usuarios = array();

                $notificacion['titulo']     = "¡Llegó un paquete para ti!";
                $notificacion['message']    = "El área de logistica ha indicado que ha llegado un paquete para tí.";
                $notificacion['tipo']       = "success"; // success, warning, danger
                $notificacion['link']       = "/soporte/garantia-devolucion/garantia/historial/" . $data->documento;

                $notificacion_id = DB::table('notificacion')->insertGetId([
                    'data'  => json_encode($notificacion)
                ]);

                $notificacion['id']         = $notificacion_id;

                foreach ($data->notificados as $usuario) {
                    DB::table('notificacion_usuario')->insert([
                        'id_usuario'        => $usuario->id,
                        'id_notificacion'   => $notificacion_id
                    ]);

                    array_push($usuarios, $usuario->id);
                }

                if (!empty($usuarios)) {
                    $notificacion['usuario']    = $usuarios;

                    event(new PusherEvent(json_encode($notificacion)));
                }
            }

            $es_devolucion_parcial = DB::select("SELECT parcial FROM documento_garantia WHERE id = " . $data->documento_garantia . "")[0]->parcial;

            DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                'id_fase' => 3,
                'guia_llegada' => $data->guia,
                'id_paqueteria_llegada' => $data->paqueteria,
                'asigned_to' => $data->tecnico
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

    public function soporte_garantia_devolucion_garantia_revision_data(Request $request)
    {
        $auth = json_decode($request->auth);
        $documentos = $this->garantia_devolucion_raw_data(3, 2, $auth->id);

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos
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
            'id_documento'  => $data->documento_garantia,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_garantia_cambio_data(Request $request)
    {
        $documentos = $this->garantia_devolucion_raw_data(6, 2);

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
            'code'  => 200,
            'ventas'    => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_cambio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $series_cambiadas = array();
        $notaresponse = array();

        if ($data->terminar) {
            if ($data->nota) {
                $es_devolucion_parcial = DB::select("SELECT parcial FROM documento_garantia WHERE id = " . $data->documento_garantia . "")[0]->parcial;

                $productos_nota = array();

                $info_documento = DB::select("SELECT
                                    documento.id_almacen_principal_empresa,
                                    documento.tipo_cambio,
                                    documento.referencia,
                                    documento_periodo.id AS id_periodo,
                                    documento_uso_cfdi.codigo AS uso_cfdi,
                                    documento_uso_cfdi.id AS id_cfdi,
                                    documento.series_factura,
                                    empresa.bd,
                                    empresa.almacen_devolucion_garantia_erp,
                                    empresa.almacen_devolucion_garantia_sistema,
                                    empresa.almacen_devolucion_garantia_serie,
                                    empresa_almacen.id_erp AS id_almacen,
                                    marketplace_area.id AS id_marketplacea_area,
                                    marketplace_area.serie AS serie_factura,
                                    marketplace_area.publico,
                                    moneda.id AS id_moneda
                                FROM documento
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN moneda ON documento.id_moneda = moneda.id
                                INNER JOIN documento_periodo ON documento.id_periodo
                                INNER JOIN documento_uso_cfdi ON documento.id_cfdi
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                WHERE documento.id = " . $data->documento . "");

                $forma_pago = DB::select("SELECT
                                        id_metodopago
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $data->documento . "");

                $info_entidad = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento
                                INNER JOIN documento_entidad ON documento_entidad.id = documento.id_entidad
                                WHERE documento.id = " . $data->documento . "
                                AND documento_entidad.tipo = 1");

                if (empty($info_documento)) {
                    $json['code'] = 501;
                    $json['message'] = "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador.";

                    return $this->make_json($json);
                }

                if (empty($info_entidad)) {
                    $json['code']   = 500;
                    $json['message']    = "No se encontró la información del cliente, favor de contactar al administrador.";

                    return $this->make_json($json);
                }

                if ($info_documento[0]->publico == 0) {
                    if (empty($forma_pago)) {
                        $pago = DB::table('documento_pago')->insertGetId([
                            'id_usuario' => $auth->id,
                            'id_metodopago' => 99,
                            'id_vertical' => 0,
                            'id_categoria' => 0,
                            'id_clasificacion' => 1,
                            'tipo' => 1,
                            'origen_importe' => 0,
                            'destino_importe' => 0,
                            'folio' => "",
                            'entidad_origen' => 1,
                            'origen_entidad' =>  $info_entidad[0]->rfc,
                            'entidad_destino' => '',
                            'destino_entidad' => '',
                            'referencia' => '',
                            'clave_rastreo' => '',
                            'autorizacion' => '',
                            'destino_fecha_operacion' => date('Y-m-d'),
                            'destino_fecha_afectacion' => '',
                            'cuenta_cliente' => ''
                        ]);

                        DB::table('documento_pago_re')->insert([
                            'id_documento' => $data->documento,
                            'id_pago' => $pago
                        ]);

                        $forma_pago = DB::select("SELECT
                                                id_metodopago
                                            FROM documento_pago 
                                            INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                            WHERE id_documento = " . $data->documento . "");
                    }

                    $forma_pago = $forma_pago[0];
                } else {
                    $forma_pago = new \stdClass();

                    $forma_pago->id_metodopago = 31;
                }

                $info_documento = $info_documento[0];
                $info_entidad = $info_entidad[0];

                foreach ($data->productos_anteriores as $producto) {
                    if ($producto->cambio) {
                        if ($producto->serie) {
                            $cantidad_cambio = 0;

                            foreach ($producto->series as $serie) {
                                if ($serie->cambio) {
                                    $cantidad_cambio++;
                                }
                            }

                            if ($cantidad_cambio == 0) {
                                $json['code'] = 500;
                                $json['message'] = "No se encontraron series para el producto " . $producto->sku . "";

                                return $this->make_json($json);
                            }

                            $producto_object = new \stdClass();
                            $producto_object->cantidad = $cantidad_cambio;
                            $producto_object->sku = $producto->sku;
                            $producto_object->precio_unitario = $producto->precio;
                            $producto_object->costo = $producto->precio;
                            $producto_object->descuento = 0;
                            $producto_object->impuesto = 16;
                            $producto_object->comentarios = "";

                            array_push($productos_nota, $producto_object);
                        } else {
                            if ($producto->cantidad < 1) {
                                $json['code'] = 500;
                                $json['message'] = "La cantidad es incorrecta para el producto " . $producto->sku . "";

                                return $this->make_json($json);
                            }

                            $producto_object = new \stdClass();
                            $producto_object->cantidad = $producto->cantidad;
                            $producto_object->sku = $producto->sku;
                            $producto_object->precio_unitario = $producto->precio / 1.16;
                            $producto_object->costo = $producto->precio / 1.16;
                            $producto_object->descuento = 0;
                            $producto_object->impuesto = 16;
                            $producto_object->comentarios = "";

                            array_push($productos_nota, $producto_object);
                        }

                        # Verificar que exista la cantidad necesaria para hacer el traspaso despues de crear la nota

                        $existencia_codigo = DocumentoService::existenciaProducto($producto->sku, $info_documento->id_almacen_principal_empresa);

                        if ($existencia_codigo->error) {
                            return response()->json([
                                "code" => 500,
                                "message" => "Error al consultar la existencia del código " . $producto->sku . ", mensaje de error: " . $existencia_codigo->mensaje . ""
                            ]);
                        }

                        if (((int) $existencia_codigo->existencia + (int) $producto->cantidad) < $producto->cantidad) {
                            return response()->json([
                                "code" => 500,
                                "message" => "No hay suficiente existencia para procesar la solicitud<br><br>Cantidad con nota: " . ((int) $existencia_codigo->existencia + (int) $producto->cantidad) . "<br>Cantidad solicitada: " . $producto->cantidad . "<br><br>Favor de revisiar el inventario con el encargado"
                            ]);
                        }
                    }
                }

                try {
                    $array_nota = array(
                        'bd' => $info_documento->bd,
                        'password' => config("webservice.token"),
                        'serie' => "D" . $info_documento->serie_factura,
                        'fecha' => date('Y-m-d H:i:s'),
                        'cliente' => $info_entidad->rfc == 'XEXX010101000' ? $info_entidad->id_erp : $info_entidad->rfc,
                        'titulo' => 'Nota de credito por garantia del pedido ' . $data->documento . ' - ' . $data->documento_garantia,
                        'almacen' => $info_documento->id_almacen,
                        'divisa' => $info_documento->id_moneda,
                        'tipo_cambio' => $info_documento->tipo_cambio,
                        'condicion_pago' => $info_documento->id_periodo,
                        'metodo_pago' => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                        'forma_pago' => (strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago,
                        'uso_cfdi' => $info_documento->uso_cfdi,
                        'comentarios' => (is_null($info_documento->referencia)) ? '' : $info_documento->referencia,
                        'productos' => json_encode($productos_nota)
                    );

                    $response_nota = \Httpful\Request::post(config('webservice.url') . 'cliente/notacredito/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                        ->body($array_nota, \Httpful\Mime::FORM)
                        ->send();

                    $raw_response_nota  = $response_nota->body;
                    $response_nota      = @json_decode($response_nota);

                    if (empty($response_nota)) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No fue posible crear la nota de credito del documento " . $data->documento . ", error: desconocido",
                            'raw'   => $raw_response_nota
                        ]);
                    }

                    if ($response_nota->error == 1) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No fue posible generar la nota de credito del documento " . $data->documento . ", error: " . $response_nota->mensaje . ""
                        ]);
                    }

                    DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                        'nota'  => $response_nota->id
                    ]);
                    array_push($notaresponse, $response_nota->id);
                } catch (Exception $e) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "Ocurrió un error al generar la NC en comercial, mensaje de error: " . $e->getMessage(),
                        'raw'   => $raw_response_nota,
                        'data'  => $array_nota
                    ]);
                }

                # Se crear el documento nota de credito en el CRM para hacer el movimiento de series
                $documento_nota = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa' => $info_documento->id_almacen_principal_empresa,
                    'id_tipo' => 6,
                    'id_periodo' => $info_documento->id_periodo,
                    'id_cfdi' => $info_documento->id_cfdi,
                    'id_marketplace_area' => $info_documento->id_marketplacea_area,
                    'id_usuario' => $auth->id,
                    'id_moneda' => $info_documento->id_moneda,
                    'id_paqueteria' => 6,
                    'id_fase' => 100,
                    'factura_folio' => $response_nota->id,
                    'tipo_cambio' => $info_documento->tipo_cambio,
                    'referencia' => 'N/A',
                    'info_extra' => 'N/A',
                    'observacion' => 'Nota de credito para el pedido ' . $data->documento . '', // Status de la compra
                ]);

                foreach ($data->productos_anteriores as $producto) {
                    if ($producto->cambio) {
                        # Se crear el movimiento para el la nota de credito si el producto se va a cambiar
                        $movimiento = DB::table('movimiento')->insertGetId([
                            'id_documento' => $documento_nota,
                            'id_modelo' => $producto->id_modelo,
                            'cantidad' => ($producto->serie) ? $cantidad_cambio : $producto->cantidad,
                            'precio' => $producto->precio,
                            'garantia' => 0,
                            'modificacion' => 'N/A',
                            'regalo' => 0
                        ]);

                        if ($producto->serie) {
                            foreach ($producto->series as $serie) {
                                //Aqui se quita
//                                $apos = `'`;
//                                //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
//                                if (str_contains($serie->serie, $apos)) {
//                                    $serie->serie = addslashes($serie->serie);
//                                }
                                $serie->serie = str_replace(["'", '\\'], '', $serie->serie);
                                if ($serie->cambio) {
                                    # Se crea la relación de la serie anterior con la nota de credito
                                    $existe_serie = DB::select("SELECT id FROM producto WHERE serie = '" . $serie->serie . "'");

                                    if (empty($existe_serie)) {
                                        $serie_id = DB::table('producto')->insertGetId([
                                            'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                            'serie' => $serie->serie
                                        ]);
                                    } else {
                                        $serie_id = $existe_serie[0]->id;
                                    }

                                    DB::table('movimiento_producto')->insert([
                                        'id_movimiento' => $movimiento,
                                        'id_producto' => $serie_id
                                    ]);
                                }
                            }
                        }
                    }
                }
                try {
                    $array_traspaso = array(
                        'bd' => $info_documento->bd,
                        'password' => config("webservice.token"),
                        'fecha' => date('Y-m-d H:i:s'),
                        'almacen_origen' => $info_documento->id_almacen,
                        'almacen_destino' => $info_documento->almacen_devolucion_garantia_erp,
                        'comentarios' => 'Traspaso entre almacenes por garantia ' . $data->documento_garantia,
                        'productos' => json_encode($productos_nota)
                    );

                    $response_traspaso = \Httpful\Request::post('http://201.7.208.53:11903/api/adminpro/MovimientosEntreAlmacenes/Insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                        ->body($array_traspaso, \Httpful\Mime::FORM)
                        ->send();

                    $raw_response_traspaso = $response_traspaso->raw_body;
                    $response_traspaso = @json_decode($response_traspaso);

                    if (empty($response_traspaso)) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "Ocurrió un error al generar el traspaso del documento " . $data->documento . " en comercial, error: desconocido",
                            'raw'   => $raw_response_traspaso,
                            'data'  => $array_traspaso
                        ]);
                    }

                    if ($response_traspaso->error == 1) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "Ocurrió un error al generar el traspaso del documento " . $data->documento . ", mensaje de error: " . $response_traspaso->mensaje
                        ]);
                    }
                } catch (Exception $e) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "Ocurrió un error al generar el traspaso del documento " . $data->documento . ", mensaje de error: " . $e->getMessage()
                    ]);
                }

                # Se crear el documento de traspaso en el CRM para hacer el movimiento de series
                $documento_traspaso = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa' => $info_documento->almacen_devolucion_garantia_sistema,
                    'id_almacen_secundario_empresa' => $info_documento->id_almacen_principal_empresa,
                    'id_tipo' => 5,
                    'id_periodo' => 1,
                    'id_cfdi' => 1,
                    'id_marketplace_area' => 1,
                    'id_usuario' => $auth->id,
                    'id_moneda' => 3,
                    'id_paqueteria' => 6,
                    'id_fase' => 100,
                    'factura_folio' => $response_traspaso->id,
                    'tipo_cambio' => 1,
                    'referencia' => 'N/A',
                    'info_extra' => 'N/A',
                    'observacion' => 'Traspaso entre almacenes por garantía ' . $data->documento_garantia, // Status de la compra
                ]);

                foreach ($data->productos_anteriores as $producto) {
                    if ($producto->cambio) {
                        $cantidad_cambio = 0;
                        # Si el producto lleva series, se cuenta cuantas series se cambiaran para declararlo en el movimiento
                        if ($producto->serie) {
                            foreach ($producto->series as $serie) {
                                if ($serie->cambio) {
                                    $cantidad_cambio++;
                                }
                            }
                        }
                        # Se crear el movimiento para el traspaso si el producto se va a cambiar
                        $movimiento = DB::table('movimiento')->insertGetId([
                            'id_documento'          => $documento_traspaso,
                            'id_modelo'             => $producto->id_modelo,
                            'cantidad'              => ($producto->serie) ? $cantidad_cambio : $producto->cantidad,
                            'precio'                => $producto->precio,
                            'garantia'              => 0,
                            'modificacion'          => 'N/A',
                            'regalo'                => 0
                        ]);

                        if ($producto->serie) {
                            foreach ($producto->series as $serie) {
                                //Aqui se quita
//                                $apos = `'`;
//                                //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
//                                if (str_contains($serie->serie, $apos)) {
//                                    $serie->serie = addslashes($serie->serie);
//                                }
                                $serie->serie = str_replace(["'", '\\'], '', $serie->serie);
                                if ($serie->cambio) {
                                    # Se crea la relación de la serie anterior con la nota de credito
                                    $existe_serie = DB::select("SELECT id FROM producto WHERE serie = '" . $serie->serie . "'");

                                    if (empty($existe_serie)) {
                                        $serie_id = DB::table('producto')->insertGetId([
                                            'id_almacen'    => $info_documento->almacen_devolucion_garantia_serie,
                                            'serie'         => $serie->serie
                                        ]);
                                    } else {
                                        $serie_id = $existe_serie[0]->id;

                                        # La serie anterior se cambia al almacén de refacciones (13) y se deja un comentario del movimiento
                                        DB::table('producto')->where(['id' => $serie_id])->update([
                                            'id_almacen'    => $info_documento->almacen_devolucion_garantia_serie,
                                            'status'        => 1
                                        ]);
                                    }

                                    # Se crea la relación de la serie anterior con el traspaso
                                    DB::table('movimiento_producto')->insert([
                                        'id_movimiento' => $movimiento,
                                        'id_producto'   => $serie_id
                                    ]);
                                }
                            }
                        }
                    }
                }

                try {
                    $array_afectar_traspaso = array(
                        'bd'                    => $info_documento->bd,
                        'password'              => config("webservice.token"),
                        'documento_movimiento'  => $response_traspaso->id,
                        'fecha_entrega'         => date('Y-m-d H:i:s')
                    );

                    $response_afectar_traspaso = \Httpful\Request::post('http://201.7.208.53:11903/api/adminpro/MovimientosEntreAlmacenes/Entregar/Update/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                        ->body($array_afectar_traspaso, \Httpful\Mime::FORM)
                        ->send();

                    $raw_response_afectar_traspaso = $response_afectar_traspaso->raw_body;
                    $response_afectar_traspaso = @json_decode($response_afectar_traspaso);

                    if (empty($response_afectar_traspaso)) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No fue posible afectar el traspaso " . $response_traspaso->id . ", error: desconocido",
                            'raw'   => $raw_response_afectar_traspaso,
                            'data'  => $array_afectar_traspaso
                        ]);
                    }

                    if ($response_afectar_traspaso->error == 1) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No fue posible afectar el traspaso " . $response_traspaso->id . ", favor de afectar manualmente, mensaje de error: " . $response_afectar_traspaso->mensaje . ""
                        ]);
                    }
                } catch (Exception $e) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No fue posible afectar el traspaso " . $response_traspaso->id . ", favor de afectar manualmente, mensaje de error: " . $e->getMessage() . ""
                    ]);
                }

                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase'           => ($es_devolucion_parcial) ? 100 : 7
                ]);
            } else {
                $productos_traspaso = array();

                $puede_continuar = 0;

                $almacen_id = DB::select("SELECT id_almacen FROM empresa_almacen WHERE id = " . $data->almacen . "");

                if (empty($almacen_id)) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No se encontró el almacén especificado. Favor de contactar a un administrador."
                    ]);
                }

                $almacen_id = $almacen_id[0]->id_almacen;

                $info_documento = DB::select("SELECT
                                    documento.id_almacen_principal_empresa,
                                    documento.tipo_cambio,
                                    documento.referencia,
                                    documento_periodo.id AS id_periodo,
                                    documento_uso_cfdi.codigo AS uso_cfdi,
                                    documento_uso_cfdi.id AS id_cfdi,
                                    documento.series_factura,
                                    empresa.bd,
                                    empresa.almacen_devolucion_garantia_erp,
                                    empresa.almacen_devolucion_garantia_sistema,
                                    empresa.almacen_devolucion_garantia_serie,
                                    empresa_almacen.id_erp AS id_almacen,
                                    marketplace_area.id AS id_marketplacea_area,
                                    marketplace_area.serie AS serie_factura,
                                    marketplace_area.publico,
                                    moneda.id AS id_moneda
                                FROM documento
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN moneda ON documento.id_moneda = moneda.id
                                INNER JOIN documento_periodo ON documento.id_periodo
                                INNER JOIN documento_uso_cfdi ON documento.id_cfdi
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                WHERE documento.id = " . $data->documento . "");

                if (empty($info_documento)) {
                    return response()->json([
                        'code'  => 404,
                        'message'   => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
                    ]);
                }

                $info_documento = $info_documento[0];

                # Validación que existan las series.
                foreach ($data->productos_anteriores as $producto) {
                    if ($producto->cambio) {
                        if ($producto->serie) {
                            $cantidad_cambio = 0;

                            foreach ($producto->series as $serie) {
                                $apos = `'`;
                                //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                                if (str_contains($serie->serie_nueva, $apos)) {
                                    $serie->serie_nueva = addslashes($serie->serie_nueva);
                                }
                                if ($serie->cambio) {
                                    $existe_serie = DB::select("SELECT
                                                                    producto.id,
                                                                    modelo.id AS modelo_id,
                                                                    producto.id_almacen,
                                                                    producto.status
                                                                FROM producto
                                                                INNER JOIN movimiento_producto ON producto.id = movimiento_producto.id_producto
                                                                INNER JOIN movimiento ON movimiento_producto.id_movimiento = movimiento.id
                                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                WHERE producto.serie = '" . $serie->serie_nueva . "'");

                                    if (empty($existe_serie)) {
                                        return response()->json([
                                            'code'  => 404,
                                            'message'   => "La serie " . $serie->serie_nueva . " no se encuentra registrada como disponible en el sistema, favor de contactar a un adminstrador."
                                        ]);
                                    }

                                    $existe_serie = $existe_serie[0];

                                    if ($existe_serie->modelo_id != $producto->id_modelo) {
                                        return response()->json([
                                            'code'  => 404,
                                            'message'   => "La serie " . $serie->serie_nueva . " no pertenece al producto el cual ha sido ingresada."
                                        ]);
                                    }

                                    if ($existe_serie->id_almacen != $almacen_id) {
                                        return response()->json([
                                            'code'  => 404,
                                            'message'   => "La serie " . $serie->serie_nueva . " no se encuentra en el almacén seleccionado."
                                        ]);
                                    }

                                    $apos = `'`;
                                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                                    if (str_contains($serie->serie, $apos)) {
                                        $serie->serie = addslashes($serie->serie);
                                    }

                                    $existe_serie_anterior = DB::select("SELECT
                                                                            producto.id,
                                                                            modelo.id AS id_modelo
                                                                        FROM producto
                                                                        INNER JOIN movimiento_producto ON producto.id = movimiento_producto.id_producto
                                                                        INNER JOIN movimiento ON movimiento_producto.id_movimiento = movimiento.id
                                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                        WHERE producto.serie = '" . $serie->serie . "'");

                                    if (empty($existe_serie_anterior)) {
                                        return response()->json([
                                            'code'  => 404,
                                            'message'   => "La serie " . $serie->serie . " no se encontró en el sistema, favor de verificar que la serie esté registrada en el producto correcto."
                                        ]);
                                    }

                                    $existe_serie_anterior = $existe_serie_anterior[0];

                                    if ($existe_serie_anterior->id_modelo != $producto->id_modelo) {
                                        return response()->json([
                                            'code'  => 404,
                                            'message'   => "La serie " . $serie->serie . " no pertenece al producto el cual ha sido ingresada."
                                        ]);
                                    }

                                    $cantidad_cambio++;
                                }
                            }

                            if ($cantidad_cambio == 0) {
                                return response()->json([
                                    'code'  => 500,
                                    'message'   => "No se encontraron series para el producto " . $producto->sku . ""
                                ]);
                            }

                            $producto_object                = new \stdClass();
                            $producto_object->cantidad      = $cantidad_cambio;
                            $producto_object->sku           = $producto->sku;
                            $producto_object->costo         = $producto->costo;
                            $producto_object->comentarios   = "";

                            array_push($productos_traspaso, $producto_object);
                        } else {
                            $producto_object                = new \stdClass();
                            $producto_object->cantidad      = $producto->cantidad;
                            $producto_object->sku           = $producto->sku;
                            $producto_object->costo         = $producto->costo;
                            $producto_object->comentarios   = "";

                            array_push($productos_traspaso, $producto_object);
                        }
                    }
                }

                if (COUNT($productos_traspaso) == 0) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No se encontraron productos a cambiar, favor de contactar con el administrador."
                    ]);
                }

                # Se crear el documento de traspaso en el CRM para hacer el movimiento de series
                $documento_traspaso = DB::table('documento')->insertGetId([
                    'id_almacen_principal_empresa'  => $info_documento->almacen_devolucion_garantia_sistema,
                    'id_almacen_secundario_empresa' => $data->almacen,
                    'id_tipo'                       => 5,
                    'id_periodo'                    => 1,
                    'id_cfdi'                       => 1,
                    'id_marketplace_area'           => 1,
                    'id_usuario'                    => $auth->id,
                    'id_moneda'                     => 3,
                    'id_paqueteria'                 => 6,
                    'id_fase'                       => 100,
                    'tipo_cambio'                   => 1,
                    'referencia'                    => 'N/A',
                    'info_extra'                    => 'N/A',
                    'observacion'                   => 'Traspaso entre almacenes por garantía ' . $data->documento_garantia,
                ]);

                foreach ($data->productos_anteriores as $producto) {
                    if ($producto->cambio) {
                        $cantidad_cambio = 0;
                        # Si el producto lleva series, se cuenta cuantas series se cambiaran para declararlo en el movimiento
                        if ($producto->serie) {
                            foreach ($producto->series as $serie) {
                                if ($serie->cambio) {
                                    $cantidad_cambio++;
                                }
                            }
                        }
                        # Se crear el movimiento para el traspaso si el producto se va a cambiar
                        $movimiento = DB::table('movimiento')->insertGetId([
                            'id_documento'          => $documento_traspaso,
                            'id_modelo'             => $producto->id_modelo,
                            'cantidad'              => ($producto->serie) ? $cantidad_cambio : $producto->cantidad,
                            'precio'                => $producto->precio,
                            'garantia'              => 0,
                            'modificacion'          => 'N/A',
                            'regalo'                => 0
                        ]);

                        if ($producto->serie) {
                            foreach ($producto->series as $serie) {
                                $apos = `'`;
                                //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                                if (str_contains($serie->serie, $apos)) {
                                    $serie->serie = addslashes($serie->serie);
                                }
                                if ($serie->cambio) {
                                    $serie_anterior = DB::select("SELECT id, id_almacen, extra, status FROM producto WHERE serie = '" . $serie->serie . "'")[0];

                                    # Se crea la relación de la serie anterior con el traspaso
                                    DB::table('movimiento_producto')->insert([
                                        'id_movimiento' => $movimiento,
                                        'id_producto'   => $serie_anterior->id
                                    ]);
                                    $apos = `'`;
                                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                                    if (str_contains($serie->serie_nueva, $apos)) {
                                        $serie->serie_nueva = addslashes($serie->serie_nueva);
                                    }
                                    # Se obtiene el ID de la nueva serie
                                    $serie_nueva = DB::select("SELECT id, id_almacen, status, extra FROM producto WHERE serie = '" . $serie->serie_nueva . "'")[0];
                                    $serie_nueva->movimiento_venta = $producto->id;

                                    # Se crea la relación de la serie nueva con el movimiento de la serie anterior
                                    DB::table('movimiento_producto')->insert([
                                        'id_movimiento' => $producto->id,
                                        'id_producto'   => $serie_nueva->id
                                    ]);

                                    # La serie anterior se cambia al almacén de refacciones (13) y se deja un comentario del movimiento
                                    DB::table('producto')->where(['id' => $serie_anterior->id])->update([
                                        'id_almacen'    => $info_documento->almacen_devolucion_garantia_serie,
                                        'status'        => 1,
                                        'extra'         => 'Se cambió por la serie ' . $serie->serie_nueva . ' por garantía, y esta se traspasa a garantías.'
                                    ]);

                                    # La serie nueva se queda no disponible por que ya está relacionada a la venta y se deja un comentario del movimiento
                                    DB::table('producto')->where(['id' => $serie_nueva->id])->update([
                                        'status'    => 0,
                                        'extra'     => 'Se cambió por la serie ' . $serie->serie . ' por garantía, la otra fue enviada a garantías.'
                                    ]);

                                    array_push($series_cambiadas, $serie_anterior);
                                    array_push($series_cambiadas, $serie_nueva);
                                }
                            }
                        }
                    }
                }

                $crear_traspaso = DocumentoService::crearMovimiento($documento_traspaso);

                if ($crear_traspaso->error) {
                    foreach ($series_cambiadas as $serie) {
                        DB::table('producto')->where(['id' => $serie->id])->update([
                            'id_almacen'    => $serie->id_almacen,
                            'status'        => $serie->status,
                            'extra'         => $serie->extra
                        ]);
                    }

                    DB::table('documento')->where(['id' => $documento_traspaso])->delete();

                    return response()->json([
                        'code'  => 500,
                        'message'   => $crear_traspaso->mensaje
                    ]);
                }

                $afectar_traspaso = DocumentoService::afectarMovimiento($documento_traspaso);

                if ($afectar_traspaso->error) {
                    foreach ($series_cambiadas as $serie) {
                        # Las series nuevas cambiadas, se les borra el movimiento de la venta, para que no se duplique
                        if (property_exists($serie, "movimiento_venta")) {
                            DB::table("movimiento_producto")->where(["id_movimeinto" => $serie_anterior->movimiento_venta, "id_producto" => $serie->id])->delete();
                        }

                        DB::table('producto')->where(['id' => $serie->id])->update([
                            'id_almacen'    => $serie->id_almacen,
                            'status'        => $serie->status,
                            'extra'         => $serie->extra
                        ]);
                    }

                    DB::table('documento')->where(['id' => $documento_traspaso])->delete();

                    return response()->json([
                        'code'  => 500,
                        'message'   => $afectar_traspaso->mensaje
                    ]);
                }

                DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                    'id_fase' => 99
                ]);
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento_garantia,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento_garantia,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "Documento guardado correctamente. <br/>Se crea la nota de credito número: " . $notaresponse[0]
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente. <br/>Se crea la nota de credito número: " . $notaresponse[0]
        ]);
    }

    public function soporte_garantia_devolucion_garantia_cambio_documento(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $usuario_info = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . " AND status = 1");

        if (empty($usuario_info)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre el usuario, favor de contactar a un administrador."
            ]);
        }

        $usuario_info = $usuario_info[0];

        $pdf = app('FPDF');

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

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "SOLICITUD_PRODUCTO_GARANTIA_" . $data->documento . ".pdf";

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    public function soporte_garantia_devolucion_garantia_pedido_data(Request $request)
    {
        $garantias_pendientes   = DB::select("SELECT id FROM documento_garantia WHERE id_fase = 7 AND id_tipo = 2");
        $periodos               = DB::select("SELECT id, periodo FROM documento_periodo WHERE status = 1");
        $paqueterias            = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");
        $empresas               = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        $usos_venta             = DB::select("SELECT * FROM documento_uso_cfdi");
        $metodos                = DB::select("SELECT * FROM metodo_pago");
        $marketplaces_publico   = DB::select("SELECT marketplace.marketplace FROM marketplace_area INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id WHERE marketplace_area.publico = 1 GROUP BY marketplace.marketplace");
        $monedas                = DB::select("SELECT * FROM moneda");

        $documentos = $this->garantia_devolucion_raw_data(7, 2);

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
            $seguimiento_garantia   = DB::select("SELECT
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
            'code'  => 200,
            'ventas'    => $documentos,
            'metodos'   => $metodos,
            'monedas'   => $monedas,
            'periodos'  => $periodos,
            'empresas'  => $empresas,
            'almacenes' => $almacenes,
            'usos_venta'    => $usos_venta,
            'paqueterias'   => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_garantia_pedido_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $documento = DB::table('documento')->insertGetId([
            'documento_extra'               => "",
            'id_almacen_principal_empresa'  => $data->documento->almacen,
            'id_periodo'                    => $data->documento->periodo,
            'id_cfdi'                       => $data->documento->uso_venta,
            'id_marketplace_area'           => 15,
            'id_usuario'                    => $auth->id,
            'id_moneda'                     => $data->documento->moneda,
            'id_paqueteria'                 => $data->documento->paqueteria,
            'id_fase'                       => 3,
            'no_venta'                      => $data->documento->venta,
            'tipo_cambio'                   => $data->documento->tipo_cambio,
            'referencia'                    => $data->documento->referencia,
            'observacion'                   => $data->documento->observacion,
            'info_extra'                    => $data->documento->info_extra,
            'fulfillment'                   => 0,
            'pagado'                        => ($data->documento->precio_cambiado) ? 0 : 1,
            'series_factura'                => $data->documento->series_factura,
            'mkt_fee'                       => $data->documento->mkt_fee,
            'mkt_shipping_total'            => $data->documento->costo_envio,
            'mkt_shipping_total_cost'       => $data->documento->costo_envio_total,
            'mkt_shipping_id'               => $data->documento->mkt_shipping,
            'mkt_user_total'                => $data->documento->total_user,
            'mkt_total'                     => $data->documento->total,
            'mkt_created_at'                => $data->documento->mkt_created_at,
            'started_at'                    => $data->documento->fecha_inicio
        ]);

        $existe_cliente = DB::select("SELECT id FROM documento_entidad WHERE RFC = '" . TRIM($data->cliente->rfc) . "'");

        if ($data->cliente->rfc != 'XAXX010101000') {
            if (empty($existe_cliente)) {
                DB::table('documento_entidad')->where(['id' => $data->cliente->id])->update([
                    'razon_social'  => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                    'rfc'           => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                    'telefono'      => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                    'telefono_alt'  => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                    'correo'        => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
                ]);

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_entidad'    => $data->cliente->id
                ]);
            } else {
                # Sí el cliente ya éxiste, se atualiza la información y se relaciona la venta con el cliente encontrado
                DB::table('documento_entidad')->where(['id' => $existe_cliente[0]->id])->update([
                    'razon_social'  => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                    'telefono'      => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                    'telefono_alt'  => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                    'correo'        => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
                ]);

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_entidad'    => $existe_cliente[0]->id
                ]);
            }
        } else {
            DB::table('documento_entidad')->where(['id' => $data->cliente->id])->update([
                'razon_social'  => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                'rfc'           => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                'telefono'      => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                'telefono_alt'  => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                'correo'        => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
            ]);

            DB::table('documento')->where(['id' => $documento])->update([
                'id_entidad'    => $data->cliente->id
            ]);
        }

        DB::table('documento_direccion')->insert([
            'id_documento'      => $documento,
            'id_direccion_pro'  => $data->documento->direccion_envio->colonia,
            'calle'             => $data->documento->direccion_envio->calle,
            'numero'            => $data->documento->direccion_envio->numero,
            'numero_int'        => $data->documento->direccion_envio->numero_int,
            'colonia'           => $data->documento->direccion_envio->colonia_text,
            'ciudad'            => $data->documento->direccion_envio->ciudad,
            'estado'            => $data->documento->direccion_envio->estado,
            'codigo_postal'     => $data->documento->direccion_envio->codigo_postal,
            'referencia'        => ''
        ]);

        foreach ($data->documento->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . trim($producto->codigo) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'sku'           => mb_strtoupper(trim($producto->codigo), 'UTF-8'),
                    'descripcion'   => mb_strtoupper(trim($producto->descripcion), 'UTF-8'),
                    'costo'         => mb_strtoupper(trim($producto->costo), 'UTF-8'),
                    'alto'          => mb_strtoupper(trim($producto->alto), 'UTF-8'),
                    'ancho'         => mb_strtoupper(trim($producto->ancho), 'UTF-8'),
                    'largo'         => mb_strtoupper(trim($producto->largo), 'UTF-8'),
                    'peso'          => mb_strtoupper(trim($producto->peso), 'UTF-8'),
                    'tipo'          => ($producto->servicio) ? 2 : 1
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento,
                'id_modelo'     => $modelo,
                'cantidad'      => $producto->cantidad,
                'precio'        => ($producto->regalo) ? 0.8620 : $producto->precio,
                'garantia'      => $producto->garantia,
                'modificacion'  => $producto->modificacion,
                'regalo'        => $producto->regalo
            ]);

            if (TRIM($producto->modificacion) != "") {
                DB::table('documento')->where(['id' => $documento])->update([
                    'modificacion'  => 1,
                    'id_fase'       => 2
                ]);

                $modificacion = 1;
            }
        }

        foreach ($data->documento->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body($archivo_data)
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento'  =>  $documento,
                    'id_usuario'    =>  $auth->id,
                    'nombre'        =>  $archivo->nombre,
                    'dropbox'       =>  $response->body->id
                ]);
            }
        }

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->documento->seguimiento
        ]);

        DB::table('documento_garantia')->where(['id' => $data->documento->documento_garantia])->update([
            'id_fase' => 100
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento " . $documento . " creado correctamente.",
            'tipo'  => "success"
        ]);
    }

    public function soporte_garantia_devolucion_garantia_envio_data()
    {
        $paqueterias            = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1 AND id != 9");
        $documentos             = $this->garantia_devolucion_raw_data(99, 2);

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos,
            'paqueterias'   => $paqueterias
        ]);
    }

    public function soporte_garantia_devolucion_garantia_envio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            DB::table('documento_garantia')->where(['id' => $data->documento_garantia])->update([
                'id_fase'               => 100,
                'id_paqueteria_envio'   => $data->paqueteria,
                'guia_envio'            => $data->guia
            ]);
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento_garantia,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_data(Request $request)
    {
        $data = json_decode($request->input("data"));

        $documentos = $this->garantia_devolucion_raw_data(0, 2, 0, $data->fecha_inicial, $data->fecha_final, $data->documento);

        return response()->json([
            'documentos' => $documentos
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_documento($documento)
    {
        $response = self::documento_garantia($documento);

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje
            ]);
        }

        return response()->json([
            'code'  => 200,
            'name'  => $response->name,
            'file'  => base64_encode($response->file)
        ]);
    }

    public function soporte_garantia_devolucion_garantia_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento_garantia,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Seguimiento guardado correctamente."
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
            'tecnicos'  => $tecnicos
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
                'code'  => 500,
                'message'   => "No se encontró información sobre el usuario, favor de contactar a un administrador."
            ]);
        }

        if (empty($tecnico_info)) {
            return response()->json([
                'code'  => 500,
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
                'code'  => 200,
                'message'   => "Servicio creado correctamente " . $servicio . ", no pudo ser generado el PDF, favor de descargar en el historial, error: " . $response->mensaje
            ]);
        }

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($response->file),
            'name'  => $response->name,
            'message'   => "Servicio guardado correctamente con el número: " . $servicio . ""
        ]);
    }

    public function soporte_garantia_devolucion_servicio_revision_data(Request $request)
    {
        $servicios   = DB::select("SELECT 
                                        documento_garantia.id, 
                                        documento_garantia.created_at,
                                        usuario.nombre
                                    FROM documento_garantia 
                                    INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                    WHERE id_fase = 3 AND id_tipo = 3");

        foreach ($servicios as $servicio) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $servicio->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $servicio->id . "");

            $seguimiento    = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $servicio->id . "");

            $servicio->contacto     = $contacto[0];
            $servicio->productos    = $productos;
            $servicio->seguimiento  = $seguimiento;
        }

        return response()->json([
            'code'  => 200,
            'garantias' => $servicios
        ]);
    }

    public function soporte_garantia_devolucion_servicio_revision_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $json['message']    = "Seguimiento guardado correctamente.";
        $seguimiento_extra  = "";

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
                            'code'  => 500,
                            'message'   => "No éxiste el servicio 'Servicio a laptop' en el CRM, favor de contactar a un administrador."
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
                        'id_documento'  => $documento_pedido,
                        'id_direccion_pro'  => '0',
                        'contacto'          => 'N/A',
                        'calle'             => '',
                        'numero'            => '',
                        'numero_int'        => '',
                        'colonia'           => '',
                        'ciudad'            => '',
                        'estado'            => '',
                        'codigo_postal'     => '',
                        'referencia'        => 'N/A'
                    ]);

                    DB::table('documento')->where(['id' => $documento_pedido])->update([
                        'id_entidad'    => $entidad_pedido
                    ]);

                    DB::table('movimiento')->insertGetId([
                        'id_documento'  => $documento_pedido,
                        'id_modelo'     => $existe_codigo_servicio[0]->id,
                        'cantidad'      => 1,
                        'precio'        => $data->costo_total / 1.16,
                        'garantia'      => 0,
                        'modificacion'  => 'N/A',
                        'regalo'        => 0
                    ]);

                    DB::table('seguimiento')->insert([
                        'id_documento'  => $documento_pedido,
                        'id_usuario'    => $auth->id,
                        'seguimiento'   => "Este pedido fue generado para pagar el servicio " . $data->documento . ""
                    ]);

                    DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                        'id_fase' => 99,
                        'tiene_reparacion' => $data->tiene_reparacion,
                        'tiene_costo' => 1,
                        'costo_total' => $data->costo_total,
                    ]);

                    $seguimiento_extra .= "<p>Este documento generó un pedido para cobrar el servicio, número de pedido: " . $documento_pedido . " </p><br>" .
                        "<p>Nota: Sí el cliente necesita factura, puedes editar el pedido en la secciona de ventas.</p>";

                    $json['message']    = "Documento guardado correctamente. Un nuevo pedido fue generado para cubrir los costos del servicio, número de pedido: " . $documento_pedido . "";
                }
            } else {
                DB::table('documento_garantia')->where(['id' => $data->documento])->update([
                    'id_fase' => 8,
                    'tiene_reparacion' => $data->tiene_reparacion,
                    'tiene_costo' => 0,
                    'costo_total' => 0,
                ]);

                $json['message']    = "Documento guardado correctamente.";
            }
        }

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento . $seguimiento_extra
        ]);

        $json['code']   = 200;

        return response()->json($json);
    }

    public function soporte_garantia_devolucion_servicio_envio_data()
    {
        $garantias_pendientes   = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 99 AND id_tipo = 3");

        $paqueterias            = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1 AND id != 9");
        $documentos             = array();

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento    = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto     = $contacto[0];
            $garantia->productos    = $productos;
            $garantia->seguimiento  = $seguimiento;
        }

        return response()->json([
            'code'  => 200,
            'garantias' => $garantias_pendientes,
            'paqueterias'   => $paqueterias
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
        $garantias_pendientes   = DB::select("SELECT
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

            $seguimiento    = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto     = $contacto[0];
            $garantia->productos    = $productos;
            $garantia->seguimiento  = $seguimiento;
        }

        return response()->json([
            'code'  => 200,
            'garantias' => $garantias_pendientes
        ]);
    }

    public function soporte_garantia_devolucion_servicio_historial_documento($documento)
    {
        $response = self::documento_servicio($documento);

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje
            ]);
        }

        return response()->json([
            'code'  => 200,
            'name'  => $response->name,
            'file'  => base64_encode($response->file)
        ]);
    }

    public function soporte_garantia_devolucion_servicio_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento_garantia_seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Segumiento guardado correctamente."
        ]);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_data(Request $request)
    {
        $garantias_pendientes   = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 8 AND id_tipo = 3");

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");
            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento    = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto     = $contacto[0];
            $garantia->productos    = $productos;
            $garantia->seguimiento  = $seguimiento;
        }

        return response()->json([
            'code'  => 200,
            'garantias' => $garantias_pendientes
        ]);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $seguimiento_extra  = "";

        $json['message']    = "Seguimiento guardado correctamente.";

        if ($data->terminar) {
            if ($data->cotizacion_aceptada && $data->costo_total < 1) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "El costo total para el cliente debe de ser mayor a 0."
                ]);
            }

            if ($data->costo_total > 0) {
                $existe_codigo_servicio = DB::select("SELECT id FROM modelo WHERE sku = 'ZZGZ0004'");

                if (empty($existe_codigo_servicio)) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No éxiste el servicio 'Servicio a laptop' en el CRM, favor de contactar a un administrador."
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
                    'id_almacen_principal_empresa' => 114,
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

        $json['code']   = 200;

        return response()->json($json);
    }

    public function soporte_garantia_devolucion_servicio_cotizacion_crear(Request $request)
    {
        $productos  = json_decode($request->input('productos'));
        $documento  = $request->input('documento');
        $auth = json_decode($request->auth);

        $existe_usuario = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . " AND status = 1");

        if (empty($existe_usuario)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del usuario."
            ]);
        }

        $existe_usuario = $existe_usuario[0];

        $info_cliente = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $documento . "");

        if (empty($info_cliente)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre el cliente."
            ]);
        }

        $info_cliente = $info_cliente[0];

        $pdf = app('FPDF');

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
            $pdf->Cell(20, 10, '$ ' . (float) $producto->precio, "1", 0, 'C');
            $pdf->Cell(30, 10, '$ ' . (float) round($producto->cantidad * $producto->precio, 2), "1", 0, 'C');
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
        $pdf->Cell(30, 10, '$ ' . round($total, 2), "1", 0, 'C');
        $pdf->Ln();

        $pdf->Cell(25, 40, '');
        $pdf->Cell(71, 10, '', "1", 0, 'C');
        $pdf->Cell(25, 10, '', "1", 0, 'C');
        $pdf->Cell(20, 10, 'IVA', "1", 0, 'C');
        $pdf->Cell(30, 10, '$ ' . round((($total * 1.16) - $total), 2), "1", 0, 'C');
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


        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->output($pdf_name, 'S');

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => "COTIZACION_" . $info_cliente->nombre . "_" . $documento . ".pdf"
        ]);
    }

    public function soporte_garantia_devolucion_servicio_reparacion_data(Request $request)
    {
        $garantias_pendientes   = DB::select("SELECT 
                                                documento_garantia.id, 
                                                documento_garantia.created_at,
                                                usuario.nombre
                                            FROM documento_garantia 
                                            INNER JOIN usuario ON documento_garantia.created_by = usuario.id
                                            WHERE id_fase = 9 AND id_tipo = 3");

        foreach ($garantias_pendientes as $garantia) {
            $contacto = DB::select("SELECT * FROM documento_garantia_contacto WHERE id_garantia = " . $garantia->id . "");

            $productos = DB::select("SELECT * FROM documento_garantia_producto WHERE id_garantia = " . $garantia->id . "");

            $seguimiento    = DB::select("SELECT 
                                                documento_garantia_seguimiento.seguimiento, 
                                                documento_garantia_seguimiento.created_at, 
                                                usuario.nombre 
                                            FROM documento_garantia_seguimiento 
                                            INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id 
                                            WHERE id_documento = " . $garantia->id . "");

            $garantia->contacto     = $contacto[0];
            $garantia->productos    = $productos;
            $garantia->seguimiento  = $seguimiento;
        }

        return response()->json([
            'code'  => 200,
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
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento guardado correctamente:"
        ]);
    }

    public function garantia_devolucion_raw_data($fase = 0, $tipo, $usuario = 0, $fecha_inicial = "", $fecha_final = "", $garantia_pedido = "")
    {
        $query = "";

        if (!empty($garantia_pedido)) {
            $query = " AND (documento_garantia.id = " . $garantia_pedido . " OR documento.id = " . $garantia_pedido . ")";
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
                            WHERE documento_garantia.id_tipo = " . $tipo . "
                            " . $query);

        foreach ($documentos as $documento) {
            $productos = DB::select("SELECT
                                        modelo.id AS id_modelo,
                                        modelo.sku,
                                        modelo.serie,
                                        modelo.costo,
                                        movimiento.id,
                                        modelo.descripcion,
                                        movimiento.cantidad,
                                        movimiento.precio,
                                        movimiento.garantia,
                                        movimiento.regalo,
                                        0 AS cambio
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $documento->id . "");

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

            $nota = DB::select("SELECT estado FROM garantia_nota_autorizacion WHERE documento = $documento->id ORDER BY created_at desc");

            $documento->seguimiento_venta = $seguimiento_venta;
            $documento->seguimiento_garantia = $seguimiento_garantia;
            $documento->productos = $productos;
            $documento->archivos = $archivos;
            $documento->nota_pendiente = $nota ? $nota[0]->estado : 0;
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

        $pdf = app('FPDF');

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

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "SERVICIO_" . $informacion_servicio->cliente . "_" . $informacion_servicio->id . ".pdf";

        $response->error = 0;
        $response->name = $file_name;
        $response->file = $pdf_data;

        return $response;
    }

    private function documento_garantia($documento)
    {
        $response = new \stdClass();

        $informacion_garantia = DB::select("SELECT
                                                documento_garantia.id,
                                                documento_garantia.asigned_to,
                                                documento_garantia.created_by,
                                                (SELECT nombre FROM usuario WHERE id = asigned_to) AS tecnico,
                                                (SELECT nombre FROM usuario WHERE id = created_by) AS creador,
                                                documento_entidad.razon_social AS cliente,
                                                documento_entidad.telefono,
                                                documento_entidad.correo
                                            FROM documento_garantia
                                            INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                            INNER JOIN documento_entidad_re ON documento_garantia_re.id_documento = documento_entidad_re.id_documento
                                            INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                            WHERE documento_garantia.id = " . $documento . "");

        if (empty($informacion_garantia)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la garantía, favor de verificar e intentar de nuevo.";

            return $response;
        }

        $informacion_garantia = $informacion_garantia[0];

        $productos = DB::select("SELECT
                                    modelo.sku,
                                    modelo.descripcion
                                FROM documento_garantia
                                INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                INNER JOIN movimiento ON documento_garantia_re.id_documento = movimiento.id_documento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE documento_garantia.id = " . $documento . "");

        $productos_garantia = DB::select("SELECT producto, cantidad FROM documento_garantia_producto WHERE id_garantia = " . $documento . "");

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de los productos de la garantía, favor de verificar e intentar de nuevo.";

            return $response;
        }

        foreach ($productos as $index => $producto) {
            $producto->descripcion .= " - " . (array_key_exists($index, $productos_garantia) ? $productos_garantia[$index]->producto : "");
        }

        $seguimientos = DB::select("SELECT * FROM documento_garantia_seguimiento WHERE id_documento = " . $documento . "");

        $pdf = app('FPDF');

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
        $pdf->Cell(40, 10, "NO. GARANTIA: " . $informacion_garantia->id);

        $pdf->SetFont('Arial', '', 10);

        $pdf->Ln(30);
        $pdf->Cell(100, 10, 'OMG INTERNATIONAL SA DE CV');
        $pdf->Cell(25, 10, 'TECNICO: ');
        $pdf->Cell(10, 10, $informacion_garantia->tecnico);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'Industria Vidriera #105, Fracc. Industrial Zapopan Norte');
        $pdf->Ln(5);
        $pdf->Cell(20, 10, $informacion_garantia->creador);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'soporte@omg.com.mx');

        # Información del cliente
        $pdf->Ln(20);
        $pdf->Cell(100, 10, 'INFORMACION DEL CLIENTE');
        $pdf->Cell(10, 10, 'INFORMACION DE LA GARANTIA');

        $pdf->SetFont('Arial', 'B', 10);

        setlocale(LC_ALL, "es_MX");

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_garantia->cliente, 'UTF-8')));
        $pdf->Cell(30, 10, 'Fecha: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, strftime("%A %d de %B del %Y"));

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_garantia->correo, 'UTF-8')));

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'No. Garantia: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $informacion_garantia->id);

        $pdf->Ln(5);
        $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($informacion_garantia->telefono, 'UTF-8')));

        $pdf->Ln(20);

        $pdf->Cell(40, 10, "Codigo", "T");
        $pdf->Cell(150, 10, "Descripcion", "T");
        $pdf->Ln();

        foreach ($productos as $producto) {
            $pdf->Cell(40, 10, $producto->sku, "T");
            $pdf->Cell(150, 10, $producto->descripcion, "T");
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

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "GARANTIA_" . $informacion_garantia->cliente . "_" . $informacion_garantia->id . ".pdf";

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
}
