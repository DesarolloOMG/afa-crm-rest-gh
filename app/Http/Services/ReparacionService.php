<?php

namespace App\Http\Services;

use DB;

class ReparacionService
{
    public static function autorizacionNota($data, $hay_traspaso, $auth)
    {
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
                                documento.nota,
                                marketplace.marketplace,
                                empresa.bd,
                                empresa.almacen_devolucion_garantia_erp,
                                empresa.almacen_devolucion_garantia_sistema,
                                empresa.almacen_devolucion_garantia_serie
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

            if (!$info_entidad) {
                return response()->json([
                    'code' => 501,
                    'message' => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
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
            $crear_nota_credito = $info_documento->nota;

            if ($crear_nota_credito == 'N/A' || !$crear_nota_credito) {
                return response()->json([
                    'code' => 500,
                    'message' => 'No existe NDC'
                ]);
            }

            # Sí la venta es de AMAZON y es fulfillment, no se hace traspaso
            $venta_fba = $hay_traspaso;

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
                            $serie = str_replace(["'", '\\'], '', $serie);
                            $existe_serie = DB::select("SELECT id FROM producto WHERE serie = '" . TRIM($serie) . "'");

                            if (empty($existe_serie)) {
                                $id_serie = DB::table('producto')->insertGetId([
                                    'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                    'id_modelo' =>  $producto->id_modelo,
                                    'serie' => trim($serie),
                                    'status' => 1
                                ]);
                            } else {
                                $id_serie = $existe_serie[0]->id;

                                DB::table('producto')->where(['id' => $existe_serie[0]->id])->update([
                                    'id_almacen' => $info_documento->almacen_devolucion_garantia_serie,
                                    'id_modelo' =>  $producto->id_modelo,
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

//                $crear_traspaso = InventarioService::aplicarMovimiento($documento_traspaso);

                $seguimiento_traspaso .= "<p>Traspaso con el ID " . $documento_traspaso . " afectado correctamente.</p>";

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

//                event(new PusherEvent(json_encode($notificacion)));
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

            $saldar_factura = DocumentoService::saldarFactura($data->documento, $crear_nota_credito, 0);

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

                    $dropboxService = new DropboxService();
                    $dropboxResponse = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                    if (isset($dropboxResponse['id'])) {
                        DB::table('documento_archivo')->insert([
                            'id_documento'  =>  $data->documento,
                            'id_usuario'    =>  $auth->id,
                            'nombre'        =>  $archivo->nombre,
                            'dropbox'       =>  $dropboxResponse['id'],
                        ]);
                    }
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

    public static function autorizacionNotaVenta($id_usuario, $documento, $id_autorizacion)
    {
        $seguimiento = "";

        $info_documento = DB::select("SELECT 
                                        documento.factura_serie, 
                                        documento.factura_folio,
                                        documento.documento_extra,
                                        documento.nota,
                                        documento_entidad.rfc,
                                        empresa.bd,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento 
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($info_documento)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
            ]);
        }

        $info_documento = $info_documento[0];

        if ($info_documento->nota == 'N/A') {
            return response()->json([
                "code" => 500,
                "message" => "Na existe una nota de credito relacionada con este pedido con el ID " . $documento
            ]);
        }

        $folio_factura = ($info_documento->factura_serie == 'N/A') ? $documento : $info_documento->factura_folio;

        $informacion_factura = @json_decode(file_get_contents(config('webservice.url')  . $info_documento->bd . '/Factura/Estado/Folio/' . $folio_factura));

        if (empty($informacion_factura)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información de la factura " . $folio_factura
            ]);
        }

        if (is_array($informacion_factura)) {
            foreach ($informacion_factura as $factura) {
                if (($factura->eliminado == 0 || $factura->eliminado == null) && ($factura->cancelado == 0 || $factura->cancelado == null)) {
                    $informacion_factura = $factura;

                    break;
                }
            }
        }

        if (is_null($informacion_factura->uuid)) {
            return response()->json([
                'code' => 200,
                'message' => "La factura no se encuentra timbrada."
            ]);
        }
        $crear_nota_credito = $info_documento->nota;

        if ($informacion_factura->pagado > 0) {
            if (!$info_documento->publico) {
                return response()->json([
                    "code" => 500,
                    "message" => "No se puede crear la NC por que la factura tiene pagos asociados, favor de desaplicar e intentar de nuevo."
                ]);
            }

            $pagos_asociados = @json_decode(file_get_contents(config('webservice.url') . $info_documento->bd . '/Documento/' . $info_documento->documento_extra . '/PagosRelacionados'));

            if (!empty($pagos_asociados)) {
                foreach ($pagos_asociados as $pago) {
                    if ($pago->pago_con_documento != 0) {
                        return response()->json([
                            "code" => 500,
                            "message" => "La factura ya tiene aplicada una NC en el ERP con el ID " . $pago->pago_con_documento
                        ]);
                    }
                }

                foreach ($pagos_asociados as $pago) {
                    $pago_id = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                    $eliminar_relacion = DocumentoService::desaplicarPagoFactura($documento, $pago_id);

                    if ($eliminar_relacion->error) {
                        $seguimiento .= ($pago->pago_con_operacion == 0) ? "<p>No fue posible eliminar la relación de la nc con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>" : "<p>No fue posible eliminar la relación del pago con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>";
                    } else {
                        $seguimiento .= ($pago->pago_con_operacion == 0) ? "<p>Se eliminó la relación de la nc con el ID " . $pago_id . ", correctamente.</p>" : "<p>Se eliminó la relación del pago con el ID " . $pago_id . " correctamente. </p>";
                    }
                }
            }
        }

        $crear_nota_credito = $info_documento->nota;

        $saldar_factura_nota = DocumentoService::saldarFactura($documento, $crear_nota_credito, 0);

        if ($saldar_factura_nota->error) {
            return response()->json([
                'code' => 500,
                'message' => $saldar_factura_nota->mensaje
            ]);
        }

        $seguimiento .= "<p>Factura saldada correctamente con la NC: " . $crear_nota_credito . "</p>";

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $id_usuario,
            'seguimiento'   => $seguimiento
        ]);

        DB::table('documento_nota_autorizacion')->where(['id' => $id_autorizacion])->update([
            'estado' => 2,
            'id_autoriza' => $id_usuario,
            'authorized_at' => date("Y-m-d H:i:s")
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $id_usuario,
            'seguimiento'   => "<p>Se autoriza la creación de la nota de crédito</p>"
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Factura saldada correctamente con la NC " . $crear_nota_credito,
            'nota' => $crear_nota_credito
        ]);
    }
}
