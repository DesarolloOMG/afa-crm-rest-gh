<?php

namespace App\Http\Services;

use App\Models\Enums\DocumentoTipo;
use App\Models\Enums\DocumentoFase;
use App\Models\Enums\DocumentoPaqueteria;

use App\Events\PusherEvent;
use Exception;
use DB;

class DocumentoService
{
    public static function crearOrdenCompra($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $response->error = 1;

        $info_documento = DB::select("SELECT
                                        documento.id_moneda,
                                        documento.id_periodo,
                                        documento.tipo_cambio,
                                        documento.info_extra,
                                        documento.created_at,
                                        documento_entidad.id_erp,
                                        documento_entidad.rfc,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        empresa_almacen.id_erp AS id_almacen,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    INNER JOIN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                                    INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($info_documento)) {
            $response->mensaje = "No se encontró información sobre el documento proporcionado" . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        # Se obtene información de los productos de la compra
        $productos = DB::select("SELECT
                                    modelo.sku,
                                    movimiento.cantidad,
                                    movimiento.precio AS precio_unitario,
                                    movimiento.descuento,
                                    0 AS descuento,
                                    '' AS comentarios,
                                    IF ('" . $info_documento->rfc . "' = 'XEXX010101000', 2, 5) AS impuesto
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");
        # Sí no se encuentran productos de la compra, se regresa un error
        if (empty($productos)) {
            $response->mensaje = "No se encontró información de los productos de la orden de compra " . $documento . "" . self::logVariableLocation();

            return $response;
        }

        $info_extra = json_decode($info_documento->info_extra);

        $proveedor_documento = ($info_documento->rfc == 'XEXX010101000') ? $info_documento->id_erp : $info_documento->rfc;

        try {
            # Se genera la orden de compra en Comercial
            $array_pro = array(
                'bd' => $info_documento->bd,
                'password' => config("webservice.token"),
                'folio' => $documento,
                'fecha_documento' => date("Y-m-d", strtotime($info_documento->created_at)),
                'proveedor' => $proveedor_documento,
                'titulo' => '',
                'almacen' => $info_documento->id_almacen,
                'fecha_entrega' => date("Y-m-d"),
                'fecha_entrega_doc' => date("Y-m-d"),
                'divisa' => $info_documento->id_moneda,
                'tipo_cambio' => $info_documento->tipo_cambio,
                'condicion_pago' => $info_documento->id_periodo,
                'descuento_global' => 0,
                'metodo_pago' => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                'forma_pago' => $info_extra->metodo_pago,
                'uso_cfdi' => $info_documento->uso_cfdi,
                'comentarios' => $info_extra->comentarios,
                'productos' => json_encode($productos)
            );

            $crear_orden_compra = \Httpful\Request::post(config('webservice.url') . 'ordenes/compra/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_pro, \Httpful\Mime::FORM)
                ->send();

            $crear_orden_compra_raw = $crear_orden_compra->raw_body;
            $crear_orden_compra = @json_decode($crear_orden_compra_raw);
            # Si sucede algún error, se regresa el mensaje
            if (empty($crear_orden_compra)) {
                $response->mensaje = "No fue posible crear la orden de compra en comercial, error desconocido." . self::logVariableLocation();
                $response->raw = $crear_orden_compra_raw;

                return $response;
            }

            if ($crear_orden_compra->error == 1) {
                $response->mensaje = "No fue posible crear la compra orden de compra en comercial, error: " . $crear_orden_compra->mensaje . "" . self::logVariableLocation();

                return $response;
            }
            # Sí todo salió correcto, se actualiza la compra con el ID del documento generado en Comercial
            DB::table('documento')->where(['id' => $documento])->update([
                'documento_extra' => $crear_orden_compra->id,
                'imported_at' => date("Y-m-d H:i:s")
            ]);
        } catch (Exception $e) {
            $response->mensaje = "Ocurrió un error al crear la orden de compra, mensaje de error: " . $e->getMessage() . "" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function crearRecepcionOrdenCompra($documento, $movimientos)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $response->error = 1;

        $info_documento = DB::select("SELECT
                                        documento.id_moneda,
                                        documento.id_periodo,
                                        documento.tipo_cambio,
                                        documento.info_extra,
                                        documento.created_at,
                                        documento_entidad.id_erp,
                                        documento_entidad.rfc,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        empresa_almacen.id_erp AS id_almacen,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    INNER JOIN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                                    INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($info_documento)) {
            $response->mensaje = "No se encontró información sobre el documento proporcionado" . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        # Se obtene información de los productos de la compra
        $productos = DB::select("SELECT
                                    modelo.sku,
                                    documento_recepcion.cantidad,
                                    '' AS comentarios
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                INNER JOIN documento_recepcion ON movimiento.id = documento_recepcion.id_movimiento
                                WHERE documento_recepcion.id IN (" . implode(",", $movimientos) . ")");

        # Sí no se encuentran productos de la compra, se regresa un error
        if (empty($productos)) {
            $response->mensaje = "No se encontró información de los productos de la recepción de orden de compra " . $documento . "" . self::logVariableLocation();

            return $response;
        }

        $info_extra = json_decode($info_documento->info_extra);

        $proveedor_documento = ($info_documento->rfc == 'XEXX010101000') ? $info_documento->id_erp : $info_documento->rfc;

        try {
            # Se genera la orden de compra en Comercial
            $array_pro = array(
                'bd' => $info_documento->bd,
                'password' => config("webservice.token"),
                'folio' => $movimientos[0],
                'serie' => '',
                'fecha_documento' => date("Y-m-d", strtotime($info_documento->created_at)),
                'fecha_entrega_doc' => date("Y-m-d"),
                'proveedor' => $proveedor_documento,
                'titulo' => 'Recepcion de mercancia de la orden de compra ' . $documento . ' [' . implode(",", $movimientos) . ']',
                'almacen' => $info_documento->id_almacen,
                'comentarios' => $info_extra->comentarios,
                'productos' => json_encode($productos)
            );

            $crear_recepcion_orden = \Httpful\Request::post(config('webservice.url') . 'recepciones/compra/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_pro, \Httpful\Mime::FORM)
                ->send();

            $crear_recepcion_orden_raw = $crear_recepcion_orden->raw_body;
            $crear_recepcion_orden = @json_decode($crear_recepcion_orden_raw);
            # Si sucede algún error, se regresa el mensaje
            if (empty($crear_recepcion_orden)) {
                $response->mensaje = "No fue posible crear la recepción de orden de compra en comercial, error desconocido." . self::logVariableLocation();
                $response->raw = $crear_recepcion_orden_raw;

                return $response;
            }

            if ($crear_recepcion_orden->error == 1) {
                $response->mensaje = "No fue posible crear la recepción de orden de compra en comercial, error: " . $crear_recepcion_orden->mensaje . "" . self::logVariableLocation();

                return $response;
            }
            # Sí todo salió correcto, se actualiza la compra con el ID del documento generado en Comercial
            foreach ($movimientos as $movimiento) {
                DB::table("documento_recepcion")->where("id", $movimiento)->update([
                    "documento_erp" => $crear_recepcion_orden->id
                ]);
            }
        } catch (Exception $e) {
            $response->mensaje = "Ocurrió un error al crear la recepción de orden de compra, mensaje de error: " . $e->getMessage() . "" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->id = $crear_recepcion_orden->id;

        return $response;
    }

    public static function crearCompra($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();
        # Se obtene información general de la compra para generar el documento en Comercial
        try {
            $info_compra = DB::select("SELECT
                                        documento.id_moneda,
                                        documento.id_periodo,
                                        documento.tipo_cambio,
                                        documento.factura_serie,
                                        documento.factura_folio,
                                        documento.observacion,
                                        documento.info_extra,
                                        documento.referencia,
                                        documento.comentario AS metodo_pago,
                                        documento.pedimento,
                                        documento.uuid,
                                        documento.expired_at,
                                        documento_entidad.id_erp,
                                        documento_entidad.rfc,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        empresa_almacen.id_erp AS id_almacen,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    INNER JOIN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                                    INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . "");
            # Si no se encuentra la información, se regresa un error
            if (empty($info_compra)) {
                $response->error = 1;
                $response->mensaje = "No se encontró información el documento, favor contactar a un administrador." . self::logVariableLocation();

                return $response;
            }

            $info_compra = $info_compra[0];
            # Se obtene información de los productos de la compra
            $productos = DB::select("SELECT
                                        modelo.sku,
                                        movimiento.cantidad,
                                        movimiento.precio AS precio_unitario,
                                        0 AS descuento,
                                        '' AS comentarios,
                                        IF ('" . $info_compra->rfc . "' = 'XEXX010101000', 2, 5) AS impuesto
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $documento . "");
            # Sí no se encuentran productos de la compra, se regresa un error
            if (empty($productos)) {
                $response->error = 1;
                $response->mensaje = "No se encontró información de los productos, favor de contactar a un administrador." . self::logVariableLocation();

                return $response;
            }

            $proveedor_documento = ($info_compra->rfc == 'XEXX010101000') ? $info_compra->id_erp : $info_compra->rfc;

            # Se genera la compra en Comercial
            $array_pro = array(
                'bd' => $info_compra->bd,
                'password' => config("webservice.token"),
                'serie' => $info_compra->factura_serie,
                'folio' => $info_compra->factura_folio,
                'fecha' => $info_compra->expired_at,
                'proveedor' => $proveedor_documento,
                'titulo' => ($info_compra->referencia != 'N/A') ? $info_compra->referencia : "",
                'almacen' => $info_compra->id_almacen,
                'fecha_entrega_doc' => '',
                'divisa' => $info_compra->id_moneda,
                'tipo_cambio' => $info_compra->tipo_cambio,
                'condicion_pago' => $info_compra->id_periodo,
                'descuento_global' => 0,
                'metodo_pago' => ($info_compra->id_periodo == 1) ? "PUE" : "PPD",
                'forma_pago' => (strlen($info_compra->metodo_pago) == 1) ? "0" . $info_compra->metodo_pago : $info_compra->metodo_pago,
                'uso_cfdi' => $info_compra->uso_cfdi,
                'comentarios' => "",
                'productos' => json_encode($productos),
                'internacional' => ($info_compra->rfc == 'XEXX010101000') ? true : false,
                'SA' => ($info_compra->observacion == "1") ? true : false,
                'uuid' => ($info_compra->uuid != 'N/A') ? $info_compra->uuid : ""
            );

            if ($info_compra->pedimento != 'N/A') {
                $pedimento = json_decode($info_compra->pedimento);

                if ($pedimento->id != "") {
                    $array_pro["pedimentoid"] = $pedimento->id;
                }
            }

            $crear_compra = \Httpful\Request::post(config('webservice.url') . 'FacturasCompra/Insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_pro, \Httpful\Mime::FORM)
                ->send();

            $crear_compra_raw = $crear_compra->raw_body;
            $crear_compra = @json_decode($crear_compra_raw);
            # Si sucede algún error, se regresa el mensaje
            if (empty($crear_compra)) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la compra en comercial, error desconocido." . self::logVariableLocation();
                $response->raw = $crear_compra_raw;
                $response->data = $array_pro;

                return $response;
            }

            if ($crear_compra->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la compra en comercial, error: " . $crear_compra->mensaje . "" . self::logVariableLocation();
                $response->data = $array_pro;

                return $response;
            }
            # Sí todo salió correcto, se actualiza la compra con el ID del documento generado en Comercial
            DB::table('documento')->where(['id' => $documento])->update([
                'documento_extra' => $crear_compra->id,
                'imported_at' => date("Y-m-d H:i:s")
            ]);

            if ($info_compra->observacion == "1") {
                $movimientos_recepcionados = json_decode($info_compra->info_extra);

                if (is_array($movimientos_recepcionados)) {
                    foreach ($movimientos_recepcionados as $movimiento) {
                        DB::table("documento_recepcion")->where("id", $movimiento)->update([
                            "documento_erp_compra" => $crear_compra->id
                        ]);
                    }
                }
            }

            if ($info_compra->uuid != 'N/A') {
                try {
                    $array_compra = [
                        "bd"        => $info_compra->bd,
                        "password"  => config("webservice.token"),
                        "documento" => $crear_compra->id,
                        "uuid"      => $info_compra->uuid
                    ];

                    \Httpful\Request::post(config('webservice.url') . 'Compra/Add/UUID/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')->body($array_compra, \Httpful\Mime::FORM)->send();
                } catch (Exception $e) {
                }
            }

            $response->error = 0;
            $response->id = $crear_compra->id;

            return $response;
        } catch (Exception $e) {
            # Si sucede algún error, se regresa el mensaje
            $response->error = 1;
            $response->mensaje = "No fue posible crear la compra en comercial, error: " . $e->getMessage() . " - Ln: " . $e->getLine() . "" . self::logVariableLocation();
            $response->raw = $e->getTraceAsString();
            $response->data = $array_pro;

            return $response;
        }
    }

    public static function crearFactura($documento, $refacturacion, $cce)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $errores_seguimiento = "";
        $extra_message = "";

        $producto_url = config('webservice.url') . "producto/Consulta/Productos/SKU/";

        if (!file_exists("logs")) {
            mkdir("logs", 0777, true);
        }
        # Se obtiene información general del pedido para generar la factura en Comercial
        $info_documento = DB::select("SELECT
                                        documento.id_fase,
                                        documento.id_tipo,
                                        documento.id_moneda,
                                        documento.id_periodo,
                                        documento.tipo_cambio,
                                        documento.anticipada,
                                        documento.fulfillment,
                                        documento.referencia,
                                        documento.observacion,
                                        documento.series_factura,
                                        documento.documento_extra,
                                        documento.mkt_coupon,
                                        documento.id_marketplace_area,
                                        documento.addenda_orden_compra,
                                        documento.addenda_solicitud_pago,
                                        documento.addenda_tipo_documento,
                                        documento.addenda_factura_asociada,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        documento.id_paqueteria,
                                        empresa.bd,
                                        empresa.empresa AS empresa_razon_social,
                                        empresa.rfc AS empresa_rfc,
                                        empresa_almacen.id_erp AS id_almacen,
                                        marketplace_area.serie AS serie_factura,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");
        # Si no se encuentra información del documento, se regresa un mensaje de error
        if (empty($info_documento)) {
            $response->error = 1;
            $response->key = 0;
            $response->mensaje = "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        if ($info_documento->id_tipo == 2 && ($info_documento->id_fase < 5 || $info_documento->id_fase > 6)) {
            $response->error = 1;
            $response->key = 0;
            $response->mensaje = "No se puede crear la factura." . self::logVariableLocation();

            return $response;
        }

        if ($info_documento->id_paqueteria >= 0) {
            # Se busca información del cliente del pedido
            $info_entidad = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento_entidad
                                INNER JOIN documento_entidad_re ON documento_entidad.id = documento_entidad_re.id_entidad
                                WHERE documento_entidad_re.id_documento = " . $documento . "
                                AND documento_entidad.tipo = 1");
            # Si no se encuentra información del cliente, se regresa un mensaje de error
            if (empty($info_entidad)) {
                $response->error = 1;
                $response->key = 0;
                $response->mensaje = "No se encontró la información del cliente." . self::logVariableLocation();

                ComodinService::insertar_seguimiento($documento, "No se encontró la información del cliente." . self::logVariableLocation());

                return $response;
            }

            $info_entidad = $info_entidad[0];
            # Se busca informaciónd el pago del pedido
            $forma_pago = DB::select("SELECT
                                    id_metodopago
                                FROM documento_pago 
                                INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                WHERE id_documento = " . $documento . "");
            # Si no se encuentra información del pago, se genera uno generico con metodo de pago 31 (intermediario pagos)
            if ($info_documento->publico == 0) {
                if (empty($forma_pago)) {
                    $pago = DB::table('documento_pago')->insertGetId([
                        'id_usuario' => 1,
                        'id_metodopago' => 99,
                        'id_vertical' => 0,
                        'id_categoria' => 0,
                        'tipo' => 1,
                        'origen_importe' => 1,
                        'destino_importe' => 1,
                        'folio' => "",
                        'entidad_origen' => 1,
                        'origen_entidad' => $info_entidad->rfc,
                        'entidad_destino' => 1,
                        'destino_entidad' => 1,
                        'referencia' => '',
                        'clave_rastreo' => '',
                        'autorizacion' => '',
                        'origen_fecha_operacion' => date('Y-m-d'),
                        'origen_fecha_afectacion' => date('Y-m-d'),
                        'destino_fecha_operacion' => date('Y-m-d'),
                        'destino_fecha_afectacion' => date('Y-m-d'),
                        'cuenta_cliente' => ''
                    ]);

                    DB::table('documento_pago_re')->insert([
                        'id_documento' => $documento,
                        'id_pago' => $pago
                    ]);

                    $forma_pago = DB::select("SELECT
                                        id_metodopago,
                                        destino_entidad
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $documento . "");
                }

                $forma_pago = $forma_pago[0];
            } else {
                $forma_pago = new \stdClass();

                $forma_pago->id_metodopago = 31;
                $forma_pago->destino_entidad = 1;
            }
            # Se obtienen información de los productos del pedido
            $productos = DB::select("SELECT
                                    '' AS id,
                                    movimiento.id AS id_movimiento,
                                    modelo.id AS id_modelo,
                                    modelo.sku,
                                    modelo.serie,
                                    movimiento.cantidad,
                                    movimiento.precio,
                                    movimiento.garantia,
                                    movimiento.regalo,
                                    movimiento.modificacion,
                                    movimiento.comentario AS comentarios,
                                    movimiento.addenda AS addenda_numero_entrada_almacen,
                                    IF(movimiento.retencion, 15, 5) AS impuesto,
                                    0 AS descuento
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");
            # Si no se encuentran productos, se regresa un mensaje de error
            if (empty($productos)) {
                $response->error = 1;
                $response->key = 0;
                $response->mensaje = "No se encontraron los productos del documento." . self::logVariableLocation();

                ComodinService::insertar_seguimiento($documento, "No se encontraron los productos del documento." . self::logVariableLocation());

                return $response;
            }

            $response->error = 0;
            $response->data = $info_documento;
            # Si el pedido no es de linio y no es anticipada, y si es anticipada tiene que estar en la fase 3 para poder generar la factura
            if (!$info_documento->anticipada || ($info_documento->anticipada && $info_documento->id_fase == 3)) {
                $total_documento = 0;

                # Sí el marketplace tiene registrada una empresa externa, el pedido se hace a nombre de esa empresa en pro y en la base de datos de la empresa se hace el pedo a nombre del cliente
                $empresa_externa = DB::select("SELECT
                                            marketplace_area_empresa.utilidad,
                                            empresa.bd,
                                            empresa.rfc
                                        FROM documento
                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                        INNER JOIN marketplace_area_empresa ON marketplace_area.id = marketplace_area_empresa.id_marketplace_area
                                        INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                        WHERE documento.id = " . $documento);

                # Se verifica que existan los productos en la empresa externa (si es que hay una) y si no existen, se crean
                foreach ($productos as $producto) {
                    $comentarios = "";

                    if ($info_documento->series_factura) {
                        $comentarios .= "SN: ";

                        if ($producto->serie) {
                            $series = DB::select("SELECT
                                                producto.serie
                                            FROM movimiento_producto
                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                            WHERE movimiento_producto.id_movimiento = " . $producto->id_movimiento . "");

                            foreach ($series as $serie) {
                                //                                $apos = `'`;
                                //                                //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                                //                                if (str_contains($serie, $apos)) {
                                //                                    $serie = addslashes($serie);
                                //                                }
                                $serie = str_replace(["'", '\\'], '', $serie);
                                $comentarios .= $serie->serie . " ";
                            }
                        }
                    }

                    $producto_data = @json_decode(file_get_contents($producto_url . $info_documento->bd . "/" . trim(rawurlencode($producto->sku))));

                    if (empty($producto_data)) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "Ocurrió un error al buscar el producto " . trim(rawurlencode($producto->sku)) . " en el ERP del documento " . $documento . " en la empresa: " . $info_documento->bd . "" . self::logVariableLocation();

                        ComodinService::insertar_seguimiento($documento, "Ocurrió un error al buscar el producto " . trim(rawurlencode($producto->sku)) . " en el ERP del documento " . $documento . " en la empresa: " . $info_documento->bd . "" . self::logVariableLocation());

                        return $response;
                    }

                    $comentarios .= is_numeric($producto->garantia) ? " Garantía: " . $producto->garantia . " días" : " Garantía: Con fabricante";

                    $producto->comentarios .= " " . $comentarios;
                    $producto->precio_original = $producto->precio;
                    $producto->precio_unitario = $producto->precio;
                    $producto->precio_utilidad = $producto->precio;
                    $producto->costo = (float)$producto_data[0]->ultimo_costo < 1 ? 1 : (float)$producto_data[0]->ultimo_costo;

                    $total_documento += $producto->cantidad * $producto->precio * 1.16 * $info_documento->tipo_cambio;

                    unset($producto->id_movimiento);

                    if (!empty($empresa_externa)) {
                        # Se cambia el precio de los productos para que tome el costo más la utilidad asignada en el marketplace, de estar vacia o ser menor a 1%, se le asigna el 8%
                        $utilidad = empty($empresa_externa[0]->utilidad) ? 8 : ($empresa_externa[0]->utilidad < 1 ? 8 : (float)$empresa_externa[0]->utilidad);
                        $producto->precio_utilidad = ((float)$producto->costo / ((100 - (float)$utilidad) / 100) * 1.16);
                        $producto->precio_unitario = $producto->precio_utilidad;

                        $producto_data_externo = @json_decode(file_get_contents($producto_url . $empresa_externa[0]->bd . "/" . trim(rawurlencode($producto->sku))));

                        if (empty($producto_data_externo)) {
                            $producto_data = @json_decode(file_get_contents($producto_url . $info_documento->bd . "/" . trim(rawurlencode($producto->sku))));

                            if (empty($producto_data)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", ya que no se encontró el producto: " . trim(rawurlencode($producto->sku)) . " en la empresa principal" . self::logVariableLocation();

                                ComodinService::insertar_seguimiento($documento, "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: "
                                    . $empresa_externa[0]->bd . ", ya que no se encontró el producto: " . trim(rawurlencode($producto->sku)) . " en la empresa principal" . "" . self::logVariableLocation());

                                return $response;
                            }

                            try {
                                $producto_data = $producto_data[0];

                                $array_producto = array(
                                    'password' => config('webservice.token'),
                                    'bd' => $empresa_externa[0]->bd,
                                    'tipo' => $producto_data->tipo,
                                    'clave' => $producto_data->sku,
                                    'producto' => $producto_data->producto,
                                    'descripcion' => $producto_data->producto,
                                    'claveprodserv' => $producto_data->claveprodserv,
                                    'claveunidad' => $producto_data->claveunidad,
                                    'alto' => property_exists($producto_data, "alto") ? $producto_data->alto : 0,
                                    'ancho' => property_exists($producto_data, "ancho") ? $producto_data->ancho : 0,
                                    'largo' => property_exists($producto_data, "largo") ? $producto_data->largo : 0,
                                    'peso' => property_exists($producto_data, "peso") ? $producto_data->peso : 0,
                                    'refurbished' => property_exists($producto_data, "peso") ? $producto_data->refurbished : 0,
                                    'numero_parte' => property_exists($producto_data, "peso") ? $producto_data->numero_parte : $producto_data->sku,
                                    'unidad' => $producto_data->unidad,
                                );

                                $crear_producto = \Httpful\Request::post(config('webservice.url') . "producto/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                                    ->body($array_producto, \Httpful\Mime::FORM)
                                    ->send();

                                $crear_producto_raw = $crear_producto->raw_body;
                                $crear_producto = @json_decode($crear_producto);

                                if (empty($crear_producto)) {
                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", error: desconocido." . self::logVariableLocation();
                                    $response->raw = $crear_producto_raw;

                                    ComodinService::insertar_seguimiento($documento, "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: ". $empresa_externa[0]->bd . ", error: desconocido." . self::logVariableLocation());

                                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Raw Data: " . base64_encode($crear_producto_raw) . "." . PHP_EOL, FILE_APPEND);

                                    return $response;
                                }

                                if ($crear_producto->error == 1) {
                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", error: " . $crear_producto->mensaje . "." . self::logVariableLocation();

                                    ComodinService::insertar_seguimiento($documento, "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", error: " . $crear_producto->mensaje . "." . self::logVariableLocation());

                                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_producto->mensaje . "." . PHP_EOL, FILE_APPEND);

                                    return $response;
                                }
                            } catch (Exception $e) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD " . $empresa_externa[0]->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

                                ComodinService::insertar_seguimiento($documento, "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD " . $empresa_externa[0]->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation());

                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                                return $response;
                            }
                        }
                    }
                }

                //Fix Entidades

//                if ($info_entidad->rfc != 'XAXX010101000') {
//                    # Se verifica que exista el cliente en Comercial, si no, se crea
//                    $crear_entidad = self::crearEntidad($info_entidad, $info_documento->bd);
//
//                    if ($crear_entidad->error) {
//                        return $crear_entidad;
//                    }
//                }

                # Se genera la factura en Comercial
                $cliente_documento = (!empty($empresa_externa)) ? ($info_documento->bd != $empresa_externa[0]->bd ? $empresa_externa[0]->rfc : $info_entidad->rfc) : (($info_entidad->rfc == 'XEXX010101000') ? $info_entidad->id_erp : $info_entidad->rfc);


                try {
                    $array_pro = array(
                        "bd" => $info_documento->bd,
                        "password" => config('webservice.token'),
                        "prefijo" => $info_documento->serie_factura,
                        "folio" => $documento,
                        "fecha" => "",
                        "cliente" => $cliente_documento,
                        "titulo" => $info_documento->marketplace,
                        "almacen" => $info_documento->id_almacen,
                        "fecha_entrega_doc" => "",
                        "divisa" => $info_documento->id_moneda,
                        "tipo_cambio" => $info_documento->tipo_cambio,
                        "condicion_pago" => (!empty($empresa_externa)) ? 3 : $info_documento->id_periodo,
                        "descuento_global" => $info_documento->mkt_coupon,
                        "productos" => json_encode($productos),
                        "metodo_pago" => (!empty($empresa_externa)) ? "PPD" : (($info_documento->id_periodo == 1) ? "PUE" : "PPD"),
                        "forma_pago" => (!empty($empresa_externa)) ? "03" : ((strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago),
                        "uso_cfdi" => $info_documento->uso_cfdi,
                        "comentarios" => $info_documento->observacion,
                        'addenda' => 1,
                        'addenda_orden_compra' => $info_documento->addenda_orden_compra,
                        'addenda_solicitud_pago' => $info_documento->addenda_solicitud_pago,
                        'addenda_tipo_documento ' => $info_documento->addenda_tipo_documento,
                        'addenda_factura_asociada' => $info_documento->addenda_factura_asociada,
                        "cce" => $cce
                    );

                    $crear_documento = \Httpful\Request::post(config('webservice.url') . "facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                        ->body($array_pro, \Httpful\Mime::FORM)
                        ->send();

                    $crear_documento_raw = $crear_documento->raw_body;
                    $crear_documento = @json_decode($crear_documento);
                    # Si sucedio algo mal, se regresa un mensaje de error
                    if (empty($crear_documento)) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: desconocido" . self::logVariableLocation();
                        $response->raw = $crear_documento_raw;
                        $response->data = $array_pro;

                        ComodinService::insertar_seguimiento($documento, "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: desconocido" . self::logVariableLocation());

                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($crear_documento_raw) . "." . PHP_EOL, FILE_APPEND);

                        return $response;
                    }
                    # Si sucedio algo mal, se regresa un mensaje de error
                    if ($crear_documento->error == 1) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: " . $crear_documento->mensaje . " 430" . self::logVariableLocation();
                        $response->data = $array_pro;

                        ComodinService::insertar_seguimiento($documento, "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: " . $crear_documento->mensaje . " 430" . self::logVariableLocation());

                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $crear_documento->mensaje . "." . PHP_EOL, FILE_APPEND);

                        return $response;
                    }
                } catch (Exception $e) {
                    # Si sucedio algo mal, se regresa un mensaje de error
                    $response->error = 1;
                    $response->key = 0;
                    $response->mensaje = "No fue posible crear la factura en el ERP con la BD: " . $info_documento->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

                    ComodinService::insertar_seguimiento($documento, "No fue posible crear la factura en el ERP con la BD: " . $info_documento->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation());

                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                    return $response;
                }

                # Se actualiza el ID del documento generado en Comercial en el pedido del CRM
                $factura_id = $crear_documento->id;

                DB::table('documento')->where(['id' => $documento])->update([
                    'documento_extra' => $crear_documento->id,
                    'importado' => 1,
                    'imported_at' => date('Y-m-d H:i:s')
                ]);

                # Si el marketplace tiene una empresa externa, se procede a crear la factura en la misma
                if (!empty($empresa_externa)) {
                    if ($info_documento->bd != $empresa_externa[0]->bd) {
                        # Si no existe la entidad en la empresa externa, se crea
                        //Fix Entidades
//                        $crear_entidad = self::crearEntidad($info_entidad, $empresa_externa[0]->bd);
//
//                        if ($crear_entidad->error) {
//                            return $crear_entidad;
//                        }

                        # Se regresa el precio original de la venta para crear la factura en la empresa externa, que va hacia el cliente
                        foreach ($productos as $producto) {
                            $producto->precio_unitario = $producto->precio_original;
                        }

                        # Se crea la factura en la empresa externa
                        try {
                            $array_pro = array(
                                "bd" => $empresa_externa[0]->bd,
                                "password" => config('webservice.token'),
                                "prefijo" => $info_documento->serie_factura,
                                "folio" => $documento,
                                "fecha" => "",
                                "cliente" => ($info_entidad->rfc == 'XEXX010101000') ? $info_entidad->id_erp : $info_entidad->rfc,
                                "titulo" => $info_documento->marketplace,
                                "almacen" => 1,
                                "fecha_entrega_doc" => "",
                                "divisa" => $info_documento->id_moneda,
                                "tipo_cambio" => $info_documento->tipo_cambio,
                                "condicion_pago" => $info_documento->id_periodo,
                                "descuento_global" => 0,
                                "productos" => $productos,
                                "metodo_pago" => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                                "forma_pago" => (strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago,
                                "uso_cfdi" => $info_documento->uso_cfdi,
                                "comentarios" => $info_documento->observacion,
                                'addenda' => 1,
                                'addenda_orden_compra' => $info_documento->addenda_orden_compra,
                                'addenda_solicitud_pago' => $info_documento->addenda_solicitud_pago,
                                'addenda_tipo_documento ' => $info_documento->addenda_tipo_documento,
                                'addenda_factura_asociada' => $info_documento->addenda_factura_asociada,
                                "cce" => $cce
                            );

                            $crear_documento_externa = \Httpful\Request::post(config('webservice.url') . 'facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                                ->body($array_pro, \Httpful\Mime::FORM)
                                ->send();

                            $crear_documento_externa_raw = $crear_documento_externa->raw_body;
                            $crear_documento_externa = @json_decode($crear_documento_externa);
                            # Si sucede algo mal al generar la factura en la empresa externa, se elimina la factura de la empresa principal
                            if (empty($crear_documento_externa)) {
                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Raw Data: " . base64_encode($crear_documento_externa_raw) . "." . PHP_EOL, FILE_APPEND);

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                $response->raw = $crear_documento_externa;

                                return $response;
                            }

                            if ($crear_documento_externa->error == 1) {
                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_documento_externa->mensaje . ". 511" . PHP_EOL, FILE_APPEND);

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_documento_externa->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }

                            $factura_id = $crear_documento_externa->id;
                            # Se actualiza el ID de la factura externa en el campo factura_folio, para tenerlo como referencia
                            DB::table('documento')->where(['id' => $documento])->update([
                                'factura_folio' => $crear_documento_externa->id
                            ]);

                            # Al crear la factura, también se tiene que crear la compra (para saldar el inventario)
                            $documento_compra_data = DB::select("SELECT * FROM documento WHERE id = " . $documento . "");

                            if (empty($documento_compra_data)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible obtener información del documento " . $documento . " para generar la compra en la empresa externa con la BD " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }
                            # Se copía la información del pedido cambiando algunos campos para convertirlo en compra
                            $documento_compra_data[0]->factura_folio = $documento_compra_data[0]->id;

                            unset($documento_compra_data[0]->id);

                            $documento_compra_data[0]->id_tipo = 1;
                            $documento_compra_data[0]->id_fase = 100;
                            $documento_compra_data[0]->documento_extra = 'N/A';
                            $documento_compra_data[0]->factura_serie = $info_documento->serie_factura;
                            $documento_compra_data[0]->comentario = 3;
                            $documento_compra_data[0]->expired_at = $documento_compra_data[0]->created_at;
                            $documento_compra_data[0]->id_cfdi = 1;
                            $documento_compra_data[0]->observacion = "Compra generada a partir del pedido " . $documento . ", para saldar el inventario de la empresa con la BD " . $empresa_externa[0]->bd . "";

                            $id_almacen_empresa = DB::select("SELECT
                                                        empresa_almacen.id
                                                    FROM empresa_almacen
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    WHERE empresa.bd = " . $empresa_externa[0]->bd . "
                                                    AND empresa_almacen.id_erp = 1");

                            if (empty($id_almacen_empresa)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible obtener el almacén del documento " . $documento . " para generar la compra en la empresa externa con la BD " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }

                            $documento_compra_data[0]->id_almacen_principal_empresa = $id_almacen_empresa[0]->id;

                            # Se crea el documento de compra en CRM con los mismos datos del pedido
                            $id_compra_omg = DB::table('documento')->insertGetId((array)$documento_compra_data[0]);
                            # Se verifica que exista el proveedor OMG si no existe, se crea
                            $existe_proveedor_crm = DB::select("SELECT id FROM documento_entidad WHERE rfc = '" . $info_documento->empresa_rfc . "' AND tipo = 2");

                            if (empty($existe_proveedor_crm)) {
                                $id_proveedor_omg = DB::table('documento_entidad')->insertGetId([
                                    'tipo' => 2,
                                    'razon_social' => $info_documento->empresa_razon_social,
                                    'rfc' => $info_documento->empresa_rfc,
                                    'telefono' => 0,
                                    'correo' => 0
                                ]);
                            } else {
                                $id_proveedor_omg = $existe_proveedor_crm[0]->id;
                            }
                            # Se relaciona el proveedor OMG con la compra recien creada
                            DB::table('documento_entidad_re')->insert([
                                'id_entidad' => $id_proveedor_omg,
                                'id_documento' => $id_compra_omg
                            ]);

                            # Se relacionan los productos del pedido a la compra, para hacer el match
                            foreach ($productos as $producto) {
                                DB::table('movimiento')->insertGetId([
                                    'id_documento' => $id_compra_omg,
                                    'id_modelo' => $producto->id_modelo,
                                    'cantidad' => $producto->cantidad,
                                    'precio' => $producto->precio_utilidad,
                                    'garantia' => $producto->garantia,
                                    'modificacion' => $producto->modificacion,
                                    'comentario' => $producto->comentarios,
                                    'regalo' => $producto->regalo
                                ]);
                            }

                            # Se intenta crear la compra en Comercial, si sucede algo mal, se eliminara tanto la compra en crm como la factura generada en Comercial
                            $crear_compra_omg = self::crearCompra($id_compra_omg);

                            if ($crear_compra_omg->error) {
                                self::__eliminarCompra($id_compra_omg);
                                DB::table('documento')->where(['id' => $id_compra_omg])->delete();

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la compra del pedido " . $documento . " en la empresa con la BD " . $empresa_externa[0]->bd . ", mensaje de error: " . $crear_compra_omg->mensaje . ". " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                $response->raw = property_exists($crear_compra_omg, 'raw') ? $crear_compra_omg->raw : 0;

                                return $response;
                            }
                        } catch (Exception $e) {
                            # Si sucede algo mal, se eliminara tanto la compra en crm como la factura generada en Comercial
                            if (isset($id_compra_omg)) {
                                self::__eliminarCompra($id_compra_omg);
                                DB::table('documento')->where(['id' => $id_compra_omg])->delete();
                            }

                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->key = 0;
                            $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "<br><br>" . $e->getTraceAsString() . "<br><br>, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                            $response->raw = isset($crear_documento_externa_raw) ? $crear_documento_externa_raw : 0;

                            return $response;
                        }
                    }
                }
                //INGRESO LINIO
                if ($info_documento->marketplace != 'LINIO' && $refacturacion == 0 && $info_documento->marketplace != 'LIVERPOOL') {
                    # Si la factura es de contado, se genera el ingreso y se aplica a la factura
                    if ($info_documento->id_periodo == 1) {
                        $tiene_garantia_nota = DB::select("SELECT
                                                documento_garantia.nota
                                            FROM documento_garantia
                                            INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                            WHERE documento_garantia_re.id_documento = " . $documento . "
                                            AND documento_garantia.nota != 'N/A'");

                        if (!empty($tiene_garantia_nota)) {
                            $aplicar_nota_credito = self::saldarFactura($documento, $tiene_garantia_nota[0]->nota, 0);

                            if ($aplicar_nota_credito->error) {
                                self::__eliminarFactura($documento);

                                return $aplicar_nota_credito;
                            }
                        } else {
                            $empresa_ingreso = empty($empresa_externa) ? $info_documento->bd : $empresa_externa[0]->bd;

                            if ($info_documento->publico) {
                                try {
                                    $id_cuenta_bancaria = 1;

                                    switch ($info_documento->marketplace) {
                                        case 'MERCADOLIBRE':

                                            $id_cuenta_bancaria = $empresa_ingreso == '7' ? 11 : ($empresa_ingreso == '2' || $empresa_ingreso == 8 ? 1 : 8);

                                            break;

                                        case 'LINIO':

                                            $id_cuenta_bancaria = ($empresa_ingreso == '7') ? 13 : 1;

                                            break;

                                        case 'AMAZON':

                                            $id_cuenta_bancaria = ($empresa_ingreso == '7') ? 246 : 1;

                                            break;

                                            // case 'CLAROSHOP':
                                            // case 'SEARS':

                                            //     $id_cuenta_bancaria = ($empresa_ingreso == '7') ? 264 : 1;

                                            //     break;
                                            //!! RELEASE T1 reempalzar

                                        case 'CLAROSHOP':
                                        case 'SEARS':
                                        case 'SANBORNS':

                                            $id_cuenta_bancaria = ($empresa_ingreso == '7') ? 264 : 1;

                                            break;

                                        case 'WALMART':

                                            $id_cuenta_bancaria = ($empresa_ingreso == '7') ? 245 : 1;

                                            break;

                                        default:
                                            $id_cuenta_bancaria = $forma_pago->destino_entidad; # Cuenta a la que se hará el ingreso del documento para luego pagar la factura

                                            break;
                                    }

                                    #230125 si es shopify, se resta el cupon
                                    if ($info_documento->marketplace == 'SHOPIFY'){
                                        $total_documento -= $info_documento->mkt_coupon;
                                    }

                                    # Se genera el ingreso de la factura, si el marketplace tiene empresa externa, se genera el ingreso y salda la factura de la empresa externa,
                                    $ingreso = array(
                                        "bd" => $empresa_ingreso,
                                        "password" => config('webservice.token'),
                                        "folio" => "",
                                        "monto" => $total_documento,
                                        "fecha_operacion" => date('Y-m-d'),
                                        "origen_entidad" => 1, # Tipo de entidad, 1 = Cliente
                                        "origen_cuenta" => $info_entidad->rfc == 'XEXX010101000' ? $info_entidad->id_erp : $info_entidad->rfc, # RFC O ID DEL CLIENTE
                                        "destino_entidad" => 1, # Tipo de entidad, 1 = Cuenta bancaria
                                        "destino_cuenta" => $id_cuenta_bancaria, # ID de la cuenta bancaria
                                        "forma_pago" => 31,
                                        "cuenta" => "",
                                        "clave_rastreo" => "",
                                        "numero_aut" => "",
                                        "referencia" => is_null($info_documento->referencia) ? "" : $info_documento->referencia,
                                        "descripcion" => $info_documento->marketplace . " - Ingreso del pedido " . $documento,
                                        "comentarios" => ""
                                    );

                                    $crear_ingreso = \Httpful\Request::post(config('webservice.url') . "Ingresos/Insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                                        ->body($ingreso, \Httpful\Mime::FORM)
                                        ->send();

                                    $crear_ingreso_raw = $crear_ingreso->raw_body;
                                    $crear_ingreso = @json_decode($crear_ingreso);
                                    # Si sucede algo al generar el ingreso de la factura se elimina la factura de la empresa principal y si el marketplace tiene empresa externa, se elimina la factura y la compra generadas
                                    if (empty($crear_ingreso)) {
                                        if (isset($id_compra_omg)) {
                                            self::__eliminarCompra($id_compra_omg);

                                            DB::table('documento')->where(['id' => $id_compra_omg])->delete();
                                        }

                                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Raw Data: " . base64_encode($crear_ingreso_raw) . "." . PHP_EOL, FILE_APPEND);

                                        $response->error = 1;
                                        $response->key = 0;
                                        $response->mensaje = "No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                        $response->raw = $crear_ingreso_raw;
                                        $response->data = $ingreso;

                                        return $response;
                                    }
                                    # Si sucede algo al generar el ingreso de la factura se elimina la factura de la empresa principal y si el marketplace tiene empresa externa, se elimina la factura y la compra generadas
                                    if ($crear_ingreso->error == 1) {
                                        if (!empty($empresa_externa)) {
                                            if (isset($id_compra_omg)) {
                                                self::__eliminarCompra($id_compra_omg);
                                                DB::table('documento')->where(['id' => $id_compra_omg])->delete();
                                            }
                                        }

                                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Mensaje de error: " . $crear_ingreso->mensaje . "." . PHP_EOL, FILE_APPEND);

                                        $response->error = 1;
                                        $response->key = 0;
                                        $response->mensaje = "No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Mensaje de error: " . $crear_ingreso->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                        $response->data = $ingreso;

                                        return $response;
                                    }
                                } catch (Exception $e) {
                                    # Si sucede algo al generar el ingreso de la factura se elimina la factura de la empresa principal y si el marketplace tiene empresa externa, se elimina la factura y la compra generadas
                                    if (!empty($empresa_externa)) {
                                        if (isset($id_compra_omg)) {
                                            self::__eliminarCompra($id_compra_omg);
                                            DB::table('documento')->where(['id' => $id_compra_omg])->delete();
                                        }
                                    }

                                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible crear el ingreso del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Mensaje de error: " . $e->getMessage() . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                    return $response;
                                }

                                $id_ingreso = $crear_ingreso->id;
                                # Se salda la factura, dependiendo si tiene o no empresa externa el marketplace, será la factura que se saldará
                                try {
                                    $saldar_factura_data = array(
                                        "bd" => $empresa_ingreso,
                                        "password" => config('webservice.token'),
                                        "documento" => $factura_id,
                                        "operacion" => $id_ingreso
                                    );

                                    $saldar_factura = \Httpful\Request::post(config('webservice.url') . "CobroCliente/Pagar/FacturaCliente/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                                        ->body($saldar_factura_data, \Httpful\Mime::FORM)
                                        ->send();

                                    $saldar_factura_raw = $saldar_factura->raw_body;
                                    $saldar_factura = @json_decode($saldar_factura);
                                    # Si ocurré un error al saldar la factura, se eliminará las facturas e ingreso generados, así como la compra (si es que aplica)
                                    if (empty($saldar_factura)) {
                                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura con el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Raw Data: " . base64_encode($saldar_factura_raw) . "." . PHP_EOL, FILE_APPEND);

                                        $eliminar_ingreso = self::__eliminarMovimientoFlujo($id_ingreso, $documento, $empresa_ingreso);

                                        $response->error = 1;
                                        $response->key = 0;
                                        $response->mensaje = "No fue posible saldar la factura con el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", error desconocido, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "." . $eliminar_ingreso->mensaje . "" . self::logVariableLocation();
                                        $response->data = $saldar_factura_data;
                                        $response->raw = $saldar_factura_raw;

                                        return $response;
                                    }

                                    if ($saldar_factura->error == 1) {
                                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura con el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", Mensaje de error: " . $saldar_factura->mensaje . "." . PHP_EOL, FILE_APPEND);

                                        $eliminar_ingreso = self::__eliminarMovimientoFlujo($id_ingreso, $documento, $empresa_ingreso);

                                        $response->error = 1;
                                        $response->key = 0;
                                        $response->mensaje = "No fue posible saldar la factura con el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", error desconocido, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "." . $eliminar_ingreso->mensaje . "" . self::logVariableLocation();
                                        $response->data = $saldar_factura_data;

                                        return $response;
                                    }

                                    $existe_ingreso = DB::select("SELECT id_pago FROM documento_pago_re WHERE id_documento = " . $documento . "");

                                    if (empty($existe_ingreso)) {
                                        $pago = DB::table('documento_pago')->insertGetId([
                                            'id_usuario' => 1,
                                            'id_metodopago' => 31,
                                            'id_vertical' => 0,
                                            'id_categoria' => 0,
                                            'tipo' => 1,
                                            'origen_importe' => 0,
                                            'destino_importe' => ROUND($total_documento),
                                            'folio' => $id_ingreso,
                                            'entidad_origen' => 1,
                                            'origen_entidad' => $info_entidad->rfc,
                                            'entidad_destino' => 1,
                                            'destino_entidad' => $id_cuenta_bancaria,
                                            'referencia' => '',
                                            'clave_rastreo' => '',
                                            'autorizacion' => '',
                                            'destino_fecha_operacion' => date('Y-m-d'),
                                            'destino_fecha_afectacion' => '',
                                            'cuenta_cliente' => ''
                                        ]);

                                        DB::table('documento_pago_re')->insert([
                                            'id_documento' => $documento,
                                            'id_pago' => $pago
                                        ]);

                                        DB::table('documento')->where(['id' => $documento])->update([
                                            'pagado' => 1
                                        ]);
                                    } else {
                                        DB::table('documento_pago')->where(['id' => $existe_ingreso[0]->id_pago])->update([
                                            'folio' => $id_ingreso
                                        ]);

                                        DB::table('documento')->where(['id' => $documento])->update([
                                            'pagado' => 1
                                        ]);
                                    }
                                } catch (Exception $e) {
                                    $eliminar_ingreso = self::__eliminarMovimientoFlujo($id_ingreso, $documento, $empresa_ingreso);

                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible saldar la factura con el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_ingreso . ", error desconocido, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "." . $eliminar_ingreso->mensaje . "" . self::logVariableLocation();

                                    return $response;
                                }
                            } else {
                                $tiene_ingreso = DB::select("SELECT
                                                    documento_pago.*
                                                FROM documento_pago
                                                INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                                WHERE documento_pago_re.id_documento = " . $documento . "");

                                if (!empty($tiene_ingreso) && !$info_documento->anticipada) {
                                    if (empty($tiene_ingreso[0]->folio)) {
                                        $crear_ingreso = self::crearMovimientoFlujo($tiene_ingreso[0], $empresa_ingreso);

                                        if ($crear_ingreso->error) {
                                            self::__eliminarFactura($documento);

                                            return $crear_ingreso;
                                        }

                                        $saldar_factura = self::saldarFactura($documento, $crear_ingreso->id, 1);

                                        if ($saldar_factura->error) {
                                            self::__eliminarFactura($documento);
                                            self::__eliminarMovimientoFlujo($crear_ingreso->id, $documento, $empresa_ingreso);

                                            return $saldar_factura;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $response->error = 0;
        $response->key = 0;
        $response->id = isset($crear_documento) ? $crear_documento->id : null;
        $response->mensaje = "Documento guardado correctamente. " . $extra_message;
        $response->developer = $crear_documento ?? null;

        return $response;
    }

    public static function crearFacturaAutoAzur($documento, $cce)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $errores_seguimiento = "";
        $extra_message = "";

        $producto_url = config('webservice.url') . "producto/Consulta/Productos/SKU/";

        if (!file_exists("logs")) {
            mkdir("logs", 0777, true);
        }
        # Se obtiene información general del pedido para generar la factura en Comercial
        $info_documento = DB::select("SELECT
                                        documento.id_fase,
                                        documento.id_moneda,
                                        documento.id_periodo,
                                        documento.tipo_cambio,
                                        documento.anticipada,
                                        documento.fulfillment,
                                        documento.referencia,
                                        documento.observacion,
                                        documento.series_factura,
                                        documento.documento_extra,
                                        documento.mkt_coupon,
                                        documento.id_marketplace_area,
                                        documento.addenda_orden_compra,
                                        documento.addenda_solicitud_pago,
                                        documento.addenda_tipo_documento,
                                        documento.addenda_factura_asociada,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        documento.id_paqueteria,
                                        empresa.bd,
                                        empresa.empresa AS empresa_razon_social,
                                        empresa.rfc AS empresa_rfc,
                                        empresa_almacen.id_erp AS id_almacen,
                                        marketplace_area.serie AS serie_factura,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");
        # Si no se encuentra información del documento, se regresa un mensaje de error
        if (empty($info_documento)) {
            $response->error = 1;
            $response->key = 0;
            $response->mensaje = "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        if ($info_documento->id_paqueteria >= 0) {
            # Se busca información del cliente del pedido
            $info_entidad = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento_entidad
                                INNER JOIN documento_entidad_re ON documento_entidad.id = documento_entidad_re.id_entidad
                                WHERE documento_entidad_re.id_documento = " . $documento . "
                                AND documento_entidad.tipo = 1");
            # Si no se encuentra información del cliente, se regresa un mensaje de error
            if (empty($info_entidad)) {
                $response->error = 1;
                $response->key = 0;
                $response->mensaje = "No se encontró la información del cliente." . self::logVariableLocation();

                return $response;
            }

            $info_entidad = $info_entidad[0];
            # Se busca informaciónd el pago del pedido
            $forma_pago = DB::select("SELECT
                                    id_metodopago
                                FROM documento_pago 
                                INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                WHERE id_documento = " . $documento . "");
            # Si no se encuentra información del pago, se genera uno generico con metodo de pago 31 (intermediario pagos)
            if ($info_documento->publico == 0) {
                if (empty($forma_pago)) {
                    $pago = DB::table('documento_pago')->insertGetId([
                        'id_usuario' => 1,
                        'id_metodopago' => 99,
                        'id_vertical' => 0,
                        'id_categoria' => 0,
                        'tipo' => 1,
                        'origen_importe' => 1,
                        'destino_importe' => 1,
                        'folio' => "",
                        'entidad_origen' => 1,
                        'origen_entidad' => $info_entidad->rfc,
                        'entidad_destino' => 1,
                        'destino_entidad' => 1,
                        'referencia' => '',
                        'clave_rastreo' => '',
                        'autorizacion' => '',
                        'origen_fecha_operacion' => date('Y-m-d'),
                        'origen_fecha_afectacion' => date('Y-m-d'),
                        'destino_fecha_operacion' => date('Y-m-d'),
                        'destino_fecha_afectacion' => date('Y-m-d'),
                        'cuenta_cliente' => ''
                    ]);

                    DB::table('documento_pago_re')->insert([
                        'id_documento' => $documento,
                        'id_pago' => $pago
                    ]);

                    $forma_pago = DB::select("SELECT
                                        id_metodopago,
                                        destino_entidad
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $documento . "");
                }

                $forma_pago = $forma_pago[0];
            } else {
                $forma_pago = new \stdClass();

                $forma_pago->id_metodopago = 31;
                $forma_pago->destino_entidad = 1;
            }
            # Se obtienen información de los productos del pedido
            $productos = DB::select("SELECT
                                    '' AS id,
                                    movimiento.id AS id_movimiento,
                                    modelo.id AS id_modelo,
                                    modelo.sku,
                                    modelo.serie,
                                    movimiento.cantidad,
                                    movimiento.precio,
                                    movimiento.garantia,
                                    movimiento.regalo,
                                    movimiento.modificacion,
                                    movimiento.comentario AS comentarios,
                                    movimiento.addenda AS addenda_numero_entrada_almacen,
                                    IF(movimiento.retencion, 15, 5) AS impuesto,
                                    0 AS descuento
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");
            # Si no se encuentran productos, se regresa un mensaje de error
            if (empty($productos)) {
                $response->error = 1;
                $response->key = 0;
                $response->mensaje = "No se encontraron los productos del documento." . self::logVariableLocation();

                return $response;
            }

            $response->error = 0;
            $response->data = $info_documento;
            # Si el pedido no es de linio y no es anticipada, y si es anticipada tiene que estar en la fase 3 para poder generar la factura
            if (!$info_documento->anticipada || ($info_documento->anticipada && $info_documento->id_fase == 3)) {
                $total_documento = 0;

                # Sí el marketplace tiene registrada una empresa externa, el pedido se hace a nombre de esa empresa en pro y en la base de datos de la empresa se hace el pedo a nombre del cliente
                $empresa_externa = DB::select("SELECT
                                            marketplace_area_empresa.utilidad,
                                            empresa.bd,
                                            empresa.rfc
                                        FROM documento
                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                        INNER JOIN marketplace_area_empresa ON marketplace_area.id = marketplace_area_empresa.id_marketplace_area
                                        INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                        WHERE documento.id = " . $documento);

                # Se verifica que existan los productos en la empresa externa (si es que hay una) y si no existen, se crean
                foreach ($productos as $producto) {
                    $comentarios = "";

                    if ($info_documento->series_factura) {
                        $comentarios .= "SN: ";

                        if ($producto->serie) {
                            $series = DB::select("SELECT
                                                producto.serie
                                            FROM movimiento_producto
                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                            WHERE movimiento_producto.id_movimiento = " . $producto->id_movimiento . "");

                            foreach ($series as $serie) {
                                $serie = str_replace(["'", '\\'], '', $serie);
                                $comentarios .= $serie->serie . " ";
                            }
                        }
                    }

                    $producto_data = @json_decode(file_get_contents($producto_url . $info_documento->bd . "/" . trim(rawurlencode($producto->sku))));

                    if (empty($producto_data)) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "Ocurrió un error al buscar el producto " . trim(rawurlencode($producto->sku)) . " en el ERP del documento " . $documento . " en la empresa: " . $info_documento->bd . "" . self::logVariableLocation();

                        return $response;
                    }

                    $comentarios .= is_numeric($producto->garantia) ? " Garantía: " . $producto->garantia . " días" : " Garantía: Con fabricante";

                    $producto->comentarios .= " " . $comentarios;
                    $producto->precio_original = $producto->precio;
                    $producto->precio_unitario = $producto->precio;
                    $producto->precio_utilidad = $producto->precio;
                    $producto->costo = (float)$producto_data[0]->ultimo_costo < 1 ? 1 : (float)$producto_data[0]->ultimo_costo;

                    $total_documento += $producto->cantidad * $producto->precio * 1.16 * $info_documento->tipo_cambio;

                    unset($producto->id_movimiento);

                    if (!empty($empresa_externa)) {
                        # Se cambia el precio de los productos para que tome el costo más la utilidad asignada en el marketplace, de estar vacia o ser menor a 1%, se le asigna el 8%
                        $utilidad = empty($empresa_externa[0]->utilidad) ? 8 : ($empresa_externa[0]->utilidad < 1 ? 8 : (float)$empresa_externa[0]->utilidad);
                        $producto->precio_utilidad = ((float)$producto->costo / ((100 - (float)$utilidad) / 100) * 1.16);
                        $producto->precio_unitario = $producto->precio_utilidad;

                        $producto_data_externo = @json_decode(file_get_contents($producto_url . $empresa_externa[0]->bd . "/" . trim(rawurlencode($producto->sku))));

                        if (empty($producto_data_externo)) {
                            $producto_data = @json_decode(file_get_contents($producto_url . $info_documento->bd . "/" . trim(rawurlencode($producto->sku))));

                            if (empty($producto_data)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", ya que no se encontró el producto: " . trim(rawurlencode($producto->sku)) . " en la empresa principal" . self::logVariableLocation();

                                return $response;
                            }

                            try {
                                $producto_data = $producto_data[0];

                                $array_producto = array(
                                    'password' => config('webservice.token'),
                                    'bd' => $empresa_externa[0]->bd,
                                    'tipo' => $producto_data->tipo,
                                    'clave' => $producto_data->sku,
                                    'producto' => $producto_data->producto,
                                    'descripcion' => $producto_data->producto,
                                    'claveprodserv' => $producto_data->claveprodserv,
                                    'claveunidad' => $producto_data->claveunidad,
                                    'alto' => property_exists($producto_data, "alto") ? $producto_data->alto : 0,
                                    'ancho' => property_exists($producto_data, "ancho") ? $producto_data->ancho : 0,
                                    'largo' => property_exists($producto_data, "largo") ? $producto_data->largo : 0,
                                    'peso' => property_exists($producto_data, "peso") ? $producto_data->peso : 0,
                                    'refurbished' => property_exists($producto_data, "peso") ? $producto_data->refurbished : 0,
                                    'numero_parte' => property_exists($producto_data, "peso") ? $producto_data->numero_parte : $producto_data->sku,
                                    'unidad' => $producto_data->unidad,
                                );

                                $crear_producto = \Httpful\Request::post(config('webservice.url') . "producto/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                                    ->body($array_producto, \Httpful\Mime::FORM)
                                    ->send();

                                $crear_producto_raw = $crear_producto->raw_body;
                                $crear_producto = @json_decode($crear_producto);

                                if (empty($crear_producto)) {
                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", error: desconocido." . self::logVariableLocation();
                                    $response->raw = $crear_producto_raw;

                                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Raw Data: " . base64_encode($crear_producto_raw) . "." . PHP_EOL, FILE_APPEND);

                                    return $response;
                                }

                                if ($crear_producto->error == 1) {
                                    $response->error = 1;
                                    $response->key = 0;
                                    $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", error: " . $crear_producto->mensaje . "." . self::logVariableLocation();

                                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_producto->mensaje . "." . PHP_EOL, FILE_APPEND);

                                    return $response;
                                }
                            } catch (Exception $e) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD " . $empresa_externa[0]->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el producto " . trim(rawurlencode($producto->sku)) . " del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                                return $response;
                            }
                        }
                    }
                }

                # Se genera la factura en Comercial
                $cliente_documento = (!empty($empresa_externa)) ? ($info_documento->bd != $empresa_externa[0]->bd ? $empresa_externa[0]->rfc : $info_entidad->rfc) : (($info_entidad->rfc == 'XEXX010101000') ? $info_entidad->id_erp : $info_entidad->rfc);


                try {
                    $array_pro = array(
                        "bd" => $info_documento->bd,
                        "password" => config('webservice.token'),
                        "prefijo" => $info_documento->serie_factura,
                        "folio" => $documento,
                        "fecha" => "",
                        "cliente" => $cliente_documento,
                        "titulo" => $info_documento->marketplace,
                        "almacen" => $info_documento->id_almacen,
                        "fecha_entrega_doc" => "",
                        "divisa" => $info_documento->id_moneda,
                        "tipo_cambio" => $info_documento->tipo_cambio,
                        "condicion_pago" => (!empty($empresa_externa)) ? 3 : $info_documento->id_periodo,
                        "descuento_global" => $info_documento->mkt_coupon,
                        "productos" => json_encode($productos),
                        "metodo_pago" => (!empty($empresa_externa)) ? "PPD" : (($info_documento->id_periodo == 1) ? "PUE" : "PPD"),
                        "forma_pago" => (!empty($empresa_externa)) ? "03" : ((strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago),
                        "uso_cfdi" => $info_documento->uso_cfdi,
                        "comentarios" => $info_documento->observacion,
                        'addenda' => 1,
                        'addenda_orden_compra' => $info_documento->addenda_orden_compra,
                        'addenda_solicitud_pago' => $info_documento->addenda_solicitud_pago,
                        'addenda_tipo_documento ' => $info_documento->addenda_tipo_documento,
                        'addenda_factura_asociada' => $info_documento->addenda_factura_asociada,
                        "cce" => $cce
                    );

                    $crear_documento = \Httpful\Request::post(config('webservice.url') . "facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                        ->body($array_pro, \Httpful\Mime::FORM)
                        ->send();

                    $crear_documento_raw = $crear_documento->raw_body;
                    $crear_documento = @json_decode($crear_documento);
                    # Si sucedio algo mal, se regresa un mensaje de error
                    if (empty($crear_documento)) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: desconocido" . self::logVariableLocation();
                        $response->raw = $crear_documento_raw;
                        $response->data = $array_pro;

                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($crear_documento_raw) . "." . PHP_EOL, FILE_APPEND);

                        return $response;
                    }
                    # Si sucedio algo mal, se regresa un mensaje de error
                    if ($crear_documento->error == 1) {
                        $response->error = 1;
                        $response->key = 0;
                        $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", error: " . $crear_documento->mensaje . " 430" . self::logVariableLocation();
                        $response->data = $array_pro;

                        file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $crear_documento->mensaje . "." . PHP_EOL, FILE_APPEND);

                        return $response;
                    }
                } catch (Exception $e) {
                    # Si sucedio algo mal, se regresa un mensaje de error
                    $response->error = 1;
                    $response->key = 0;
                    $response->mensaje = "No fue posible crear la factura en el ERP con la BD: " . $info_documento->bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                    return $response;
                }

                # Se actualiza el ID del documento generado en Comercial en el pedido del CRM
                $factura_id = $crear_documento->id;

                DB::table('documento')->where(['id' => $documento])->update([
                    'documento_extra' => $crear_documento->id,
                    'importado' => 1,
                    'imported_at' => date('Y-m-d H:i:s')
                ]);

                # Si el marketplace tiene una empresa externa, se procede a crear la factura en la misma
                if (!empty($empresa_externa)) {
                    if ($info_documento->bd != $empresa_externa[0]->bd) {
                        # Si no existe la entidad en la empresa externa, se crea
                        //Fix Entidades
//                        $crear_entidad = self::crearEntidad($info_entidad, $empresa_externa[0]->bd);
//
//                        if ($crear_entidad->error) {
//                            return $crear_entidad;
//                        }

                        # Se regresa el precio original de la venta para crear la factura en la empresa externa, que va hacia el cliente
                        foreach ($productos as $producto) {
                            $producto->precio_unitario = $producto->precio_original;
                        }

                        # Se crea la factura en la empresa externa
                        try {
                            $array_pro = array(
                                "bd" => $empresa_externa[0]->bd,
                                "password" => config('webservice.token'),
                                "prefijo" => $info_documento->serie_factura,
                                "folio" => $documento,
                                "fecha" => "",
                                "cliente" => ($info_entidad->rfc == 'XEXX010101000') ? $info_entidad->id_erp : $info_entidad->rfc,
                                "titulo" => $info_documento->marketplace,
                                "almacen" => 1,
                                "fecha_entrega_doc" => "",
                                "divisa" => $info_documento->id_moneda,
                                "tipo_cambio" => $info_documento->tipo_cambio,
                                "condicion_pago" => $info_documento->id_periodo,
                                "descuento_global" => 0,
                                "productos" => $productos,
                                "metodo_pago" => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                                "forma_pago" => (strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago,
                                "uso_cfdi" => $info_documento->uso_cfdi,
                                "comentarios" => $info_documento->observacion,
                                'addenda' => 1,
                                'addenda_orden_compra' => $info_documento->addenda_orden_compra,
                                'addenda_solicitud_pago' => $info_documento->addenda_solicitud_pago,
                                'addenda_tipo_documento ' => $info_documento->addenda_tipo_documento,
                                'addenda_factura_asociada' => $info_documento->addenda_factura_asociada,
                                "cce" => $cce
                            );

                            $crear_documento_externa = \Httpful\Request::post(config('webservice.url') . 'facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                                ->body($array_pro, \Httpful\Mime::FORM)
                                ->send();

                            $crear_documento_externa_raw = $crear_documento_externa->raw_body;
                            $crear_documento_externa = @json_decode($crear_documento_externa);
                            # Si sucede algo mal al generar la factura en la empresa externa, se elimina la factura de la empresa principal
                            if (empty($crear_documento_externa)) {
                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Raw Data: " . base64_encode($crear_documento_externa_raw) . "." . PHP_EOL, FILE_APPEND);

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                $response->raw = $crear_documento_externa;

                                return $response;
                            }

                            if ($crear_documento_externa->error == 1) {
                                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_documento_externa->mensaje . ". 511" . PHP_EOL, FILE_APPEND);

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $crear_documento_externa->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }

                            $factura_id = $crear_documento_externa->id;
                            # Se actualiza el ID de la factura externa en el campo factura_folio, para tenerlo como referencia
                            DB::table('documento')->where(['id' => $documento])->update([
                                'factura_folio' => $crear_documento_externa->id
                            ]);

                            # Al crear la factura, también se tiene que crear la compra (para saldar el inventario)
                            $documento_compra_data = DB::select("SELECT * FROM documento WHERE id = " . $documento . "");

                            if (empty($documento_compra_data)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible obtener información del documento " . $documento . " para generar la compra en la empresa externa con la BD " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }
                            # Se copía la información del pedido cambiando algunos campos para convertirlo en compra
                            $documento_compra_data[0]->factura_folio = $documento_compra_data[0]->id;

                            unset($documento_compra_data[0]->id);

                            $documento_compra_data[0]->id_tipo = 1;
                            $documento_compra_data[0]->id_fase = 100;
                            $documento_compra_data[0]->documento_extra = 'N/A';
                            $documento_compra_data[0]->factura_serie = $info_documento->serie_factura;
                            $documento_compra_data[0]->comentario = 3;
                            $documento_compra_data[0]->expired_at = $documento_compra_data[0]->created_at;
                            $documento_compra_data[0]->id_cfdi = 1;
                            $documento_compra_data[0]->observacion = "Compra generada a partir del pedido " . $documento . ", para saldar el inventario de la empresa con la BD " . $empresa_externa[0]->bd . "";

                            $id_almacen_empresa = DB::select("SELECT
                                                        empresa_almacen.id
                                                    FROM empresa_almacen
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    WHERE empresa.bd = " . $empresa_externa[0]->bd . "
                                                    AND empresa_almacen.id_erp = 1");

                            if (empty($id_almacen_empresa)) {
                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible obtener el almacén del documento " . $documento . " para generar la compra en la empresa externa con la BD " . $empresa_externa[0]->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();

                                return $response;
                            }

                            $documento_compra_data[0]->id_almacen_principal_empresa = $id_almacen_empresa[0]->id;

                            # Se crea el documento de compra en CRM con los mismos datos del pedido
                            $id_compra_omg = DB::table('documento')->insertGetId((array)$documento_compra_data[0]);
                            # Se verifica que exista el proveedor OMG si no existe, se crea
                            $existe_proveedor_crm = DB::select("SELECT id FROM documento_entidad WHERE rfc = '" . $info_documento->empresa_rfc . "' AND tipo = 2");

                            if (empty($existe_proveedor_crm)) {
                                $id_proveedor_omg = DB::table('documento_entidad')->insertGetId([
                                    'tipo' => 2,
                                    'razon_social' => $info_documento->empresa_razon_social,
                                    'rfc' => $info_documento->empresa_rfc,
                                    'telefono' => 0,
                                    'correo' => 0
                                ]);
                            } else {
                                $id_proveedor_omg = $existe_proveedor_crm[0]->id;
                            }
                            # Se relaciona el proveedor OMG con la compra recien creada
                            DB::table('documento_entidad_re')->insert([
                                'id_entidad' => $id_proveedor_omg,
                                'id_documento' => $id_compra_omg
                            ]);

                            # Se relacionan los productos del pedido a la compra, para hacer el match
                            foreach ($productos as $producto) {
                                DB::table('movimiento')->insertGetId([
                                    'id_documento' => $id_compra_omg,
                                    'id_modelo' => $producto->id_modelo,
                                    'cantidad' => $producto->cantidad,
                                    'precio' => $producto->precio_utilidad,
                                    'garantia' => $producto->garantia,
                                    'modificacion' => $producto->modificacion,
                                    'comentario' => $producto->comentarios,
                                    'regalo' => $producto->regalo
                                ]);
                            }

                            # Se intenta crear la compra en Comercial, si sucede algo mal, se eliminara tanto la compra en crm como la factura generada en Comercial
                            $crear_compra_omg = self::crearCompra($id_compra_omg);

                            if ($crear_compra_omg->error) {
                                self::__eliminarCompra($id_compra_omg);
                                DB::table('documento')->where(['id' => $id_compra_omg])->delete();

                                $response->error = 1;
                                $response->key = 0;
                                $response->mensaje = "No fue posible crear la compra del pedido " . $documento . " en la empresa con la BD " . $empresa_externa[0]->bd . ", mensaje de error: " . $crear_compra_omg->mensaje . ". " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                                $response->raw = property_exists($crear_compra_omg, 'raw') ? $crear_compra_omg->raw : 0;

                                return $response;
                            }
                        } catch (Exception $e) {
                            # Si sucede algo mal, se eliminara tanto la compra en crm como la factura generada en Comercial
                            if (isset($id_compra_omg)) {
                                self::__eliminarCompra($id_compra_omg);
                                DB::table('documento')->where(['id' => $id_compra_omg])->delete();
                            }

                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->key = 0;
                            $response->mensaje = "No fue posible crear la factura del documento " . $documento . " en la empresa externa con la BD: " . $empresa_externa[0]->bd . ", Mensaje de error: " . $e->getMessage() . "<br><br>" . $e->getTraceAsString() . "<br><br>, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::__eliminarFactura($documento)->mensaje . "" . self::logVariableLocation();
                            $response->raw = isset($crear_documento_externa_raw) ? $crear_documento_externa_raw : 0;

                            return $response;
                        }
                    }
                }
            }
        }

        $response->error = 0; 
        $response->key = 0;
        $response->id = isset($crear_documento) ? $crear_documento->id : null;
        $response->mensaje = "Documento guardado correctamente. " . $extra_message;
        $response->developer = $crear_documento ?? null;

        return $response;
    }

    public static function crearRefacturacion($documento, $option)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $pagos_asociados = array();
        $seguimiento = "";

        if (!file_exists("logs")) {
            mkdir("logs", 0777, true);
        }

        $info_documento = DB::select("SELECT
                            documento.tipo_cambio,
                            documento.referencia,
                            documento.id_almacen_principal_empresa,
                            documento.id_fase,
                            documento.factura_serie,
                            documento.factura_folio,
                            documento.documento_extra,
                            documento.observacion,
                            documento.refacturado,
                            documento_periodo.id AS id_periodo,
                            documento_uso_cfdi.codigo AS uso_cfdi,
                            documento_uso_cfdi.id AS id_cfdi,
                            documento.series_factura,
                            empresa.bd,
                            empresa_almacen.id_erp AS id_almacen,
                            moneda.id AS id_moneda,
                            marketplace_area.id AS id_marketplacea_area,
                            marketplace_area.serie AS serie_factura,
                            marketplace_area.publico,
                            marketplace.marketplace
                        FROM documento
                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                        INNER JOIN moneda ON documento.id_moneda = moneda.id
                        INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                        INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                        WHERE documento.id = " . $documento . "
                        AND documento.status = 1");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador." . self::logVariableLocation();

            return $response;
        }

        $productos = DB::select("SELECT
                                    movimiento.id AS id_movimiento,
                                    movimiento.cantidad,
                                    movimiento.precio AS precio_unitario,
                                    movimiento.id_modelo,
                                    movimiento.comentario AS comentarios,
                                    modelo.serie,
                                    modelo.sku,
                                    modelo.costo,
                                    0 as descuento,
                                    IF(movimiento.retencion, 15, 5) AS impuesto
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE id_documento = " . $documento . "");

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron los productos del documento, favor de contactar a un adiministrador." . self::logVariableLocation();

            return $response;
        }

        $info_entidad = DB::select("SELECT
                            documento_entidad.*
                        FROM documento_entidad
                        INNER JOIN documento_entidad_re ON documento_entidad.id = documento_entidad_re.id_entidad
                        WHERE documento_entidad_re.id_documento = " . $documento . "
                        AND documento_entidad.tipo = 1");

        if (empty($info_entidad)) {
            $response->error = 1;
            $response->mensaje = "No se encontró la información del cliente, favor de contactar al administrador." . self::logVariableLocation();

            return $response;
        }

        if ($info_documento[0]->refacturado) {
            $response->error = 1;
            $response->mensaje = "La venta ha fue refacturada con anterioridad, favor de revisar el seguimiento." . self::logVariableLocation();

            return $response;
        }

        $forma_pago = DB::select("SELECT
                                id_metodopago
                            FROM documento_pago 
                            INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                            WHERE id_documento = " . $documento . "");

        if ($info_documento[0]->publico == 0) {
            if (empty($forma_pago)) {
                $pago = DB::table('documento_pago')->insertGetId([
                    // 'id_usuario'                => $user_id,
                    'id_usuario'                => 1,
                    'id_metodopago'             => 99,
                    'id_vertical'               => 0,
                    'id_categoria'              => 0,
                    // 'id_clasificacion'          => 1,
                    'id_clasificacion'          => 0,
                    'tipo'                      => 1,
                    'origen_importe'            => 0,
                    'destino_importe'           => 0,
                    'folio'                     => "",
                    'entidad_origen'            => 1,
                    'origen_entidad'            =>  $info_entidad[0]->rfc,
                    'entidad_destino'           => '',
                    'destino_entidad'           => '',
                    'referencia'                => '',
                    'clave_rastreo'             => '',
                    'autorizacion'              => '',
                    'destino_fecha_operacion'   => date('Y-m-d'),
                    'destino_fecha_afectacion'  => '',
                    'cuenta_cliente'            => ''
                ]);

                DB::table('documento_pago_re')->insert([
                    'id_documento'  => $documento,
                    'id_pago'       => $pago
                ]);

                $forma_pago = DB::select("SELECT
                                        id_metodopago
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $documento . "");
            }

            $forma_pago = $forma_pago[0];
        } else {
            $forma_pago = new \stdClass();

            $forma_pago->id_metodopago = 31;
        }

        $info_documento = $info_documento[0];
        $info_entidad   = $info_entidad[0];

        if ($info_documento->id_fase < 6) {
            $response->error = 1;
            $response->mensaje = "El documento no puede ser refacturado si no ha sido finalizado." . self::logVariableLocation();

            return $response;
        }

        $empresa_externa = DB::select("SELECT
                                        empresa.rfc,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                    INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "");

        $empresa_movimiento = empty($empresa_externa) ? $info_documento->bd : $empresa_externa[0]->bd;

        $folio_factura = ($info_documento->factura_serie == 'N/A') ? $documento : $info_documento->factura_folio;

        $informacion_factura = @json_decode(file_get_contents(config('webservice.url')  . $empresa_movimiento . '/Factura/Estado/Folio/' . $folio_factura));

        if (empty($informacion_factura)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la factura " . $folio_factura . "." . self::logVariableLocation();

            return $response;
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
            $response->error = 1;
            $response->mensaje = "La factura no se encuentra timbrada." . self::logVariableLocation();

            return $response;
        }

        /* Se desaldan los pagos aplicados en la factura original */
        if ($informacion_factura->pagado > 0) {
            $pagos_asociados = @json_decode(file_get_contents(config('webservice.url') . $empresa_movimiento . '/Documento/' . $informacion_factura->documentoid . '/PagosRelacionados'));

            if (!empty($pagos_asociados)) {
                foreach ($pagos_asociados as $pago) {
                    $pago_id = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                    $eliminar_relacion = self::desaplicarPagoFactura($documento, $pago_id);

                    if ($eliminar_relacion->error) {
                        $relacion_texto = ($pago->pago_con_operacion == 0) ? 'de la nc' : 'del pago';

                        $seguimiento .= "<p>No fue posible eliminar la relación " . $relacion_texto . " con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>";
                    } else {
                        $relacion_texto = ($pago->pago_con_operacion == 0) ? 'de la nc' : 'del pago';

                        $seguimiento .= "<p>Se eliminó la relación " . $relacion_texto . " con el ID " . $pago_id . ", correctamente.</p>";
                    }
                }
            }
        }

        # Se crear el documento nota de credito en el CRM para hacer el movimiento de series
        $crear_nota_credito = self::crearNotaCredito($documento, 2);

        if ($crear_nota_credito->error) {
            return $crear_nota_credito;
        }

        $saldar_factura_nota = self::saldarFactura($documento, $crear_nota_credito->id, 0);

        if ($saldar_factura_nota->error) {
            return $saldar_factura_nota;
        }

        # Despues, se genera un pedido con nueva información del cliente
        $documento_anterior = DB::select("SELECT * FROM documento WHERE id = " . $documento . "")[0];
        $documento_entidad = DB::select("SELECT id_entidad FROM documento_entidad_re WHERE id_documento = " . $documento . "")[0];
        $movimientos_anterior = DB::select("SELECT * FROM movimiento WHERE id_documento = " . $documento . "");
        $documento_direccion = DB::select("SELECT * FROM documento_direccion WHERE id_documento = " . $documento . "");
        $documento_pago = DB::select("SELECT * FROM documento_pago INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago WHERE documento_pago_re.id_documento = " . $documento . "");

        $documento_nuevo = DB::table('documento')->insertGetId([
            'id_tipo' => $documento_anterior->id_tipo,
            'id_almacen_principal_empresa' => $documento_anterior->id_almacen_principal_empresa,
            'id_periodo' => $documento_anterior->id_periodo,
            'id_cfdi' => $documento_anterior->id_cfdi,
            'id_marketplace_area' => $documento_anterior->id_marketplace_area,
            'id_usuario' => $documento_anterior->id_usuario,
            'id_moneda' => $documento_anterior->id_moneda,
            'id_paqueteria' => $documento_anterior->id_paqueteria,
            'id_fase' => $documento_anterior->id_fase,
            'no_venta' => $documento_anterior->no_venta,
            'tipo_cambio' => $documento_anterior->tipo_cambio,
            'referencia' => $documento_anterior->referencia,
            'observacion' => $documento_anterior->observacion,
            'info_extra' => $documento_anterior->info_extra,
            'fulfillment' => $documento_anterior->fulfillment,
            'series_factura' => $documento_anterior->series_factura,
            'pagado' => $documento_anterior->pagado,
            'credito' => $documento_anterior->credito,
            'importado' => $documento_anterior->importado,
            'autorizado' => $documento_anterior->autorizado,
            'mkt_fee' => $documento_anterior->mkt_fee,
            'mkt_shipping_total' => $documento_anterior->mkt_shipping_total,
            'mkt_shipping_total_cost' => $documento_anterior->mkt_shipping_total_cost,
            'mkt_shipping_id' => $documento_anterior->mkt_shipping_id,
            'mkt_user_total' => $documento_anterior->mkt_user_total,
            'mkt_total' => $documento_anterior->mkt_total,
            'mkt_created_at' => $documento_anterior->mkt_created_at,
            'started_at' => $documento_anterior->started_at
        ]);

        DB::table('documento_entidad_re')->insert([
            'id_entidad' => $documento_entidad->id_entidad,
            'id_documento' => $documento_nuevo
        ]);

        # Seguimiento del pedido anterior
        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => "<p>Se generá una nueva factura por refacturación con el folio " . $documento_nuevo . ", la factura con el numero " . $documento . " se paga con la nota de credito generada con el ID " . $crear_nota_credito->id . "</p>"
        ]);

        # Seguimiento del pedido nuevo
        DB::table('seguimiento')->insert([
            'id_documento' => $documento_nuevo,
            'id_usuario' => 1,
            'seguimiento' => "Facturada generada por refacturación del pedido " . $documento . ", esta nueva factura se salda con el pago original del cliente."
        ]);

        foreach ($movimientos_anterior as $movimiento_anterior) {
            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento_nuevo,
                'id_modelo'     => $movimiento_anterior->id_modelo,
                'cantidad'      => $movimiento_anterior->cantidad,
                'precio'        => $movimiento_anterior->precio,
                'garantia'      => $movimiento_anterior->garantia,
                'modificacion'  => $movimiento_anterior->modificacion,
                'comentario'    => $movimiento_anterior->comentario,
                'regalo'        => $movimiento_anterior->regalo
            ]);

            $productos_anteriores = DB::select("SELECT id_producto FROM movimiento_producto WHERE id_movimiento = " . $movimiento_anterior->id . "");

            foreach ($productos_anteriores as $producto_anterior) {
                DB::table('movimiento_producto')->insert([
                    'id_movimiento' => $movimiento,
                    'id_producto'   => $producto_anterior->id_producto
                ]);
            }
        }

        if (!empty($documento_direccion)) {
            DB::table('documento_direccion')->insert([
                'id_documento' => $documento_nuevo,
                'id_direccion_pro' => $documento_direccion[0]->id_direccion_pro,
                'contacto' => $documento_direccion[0]->contacto,
                'calle' => $documento_direccion[0]->calle,
                'numero' => $documento_direccion[0]->numero,
                'numero_int' => $documento_direccion[0]->numero_int,
                'colonia' => $documento_direccion[0]->colonia,
                'ciudad' => $documento_direccion[0]->ciudad,
                'estado' => $documento_direccion[0]->estado,
                'codigo_postal' => $documento_direccion[0]->codigo_postal,
                'referencia' => $documento_direccion[0]->referencia
            ]);
        }

        if (!empty($documento_pago)) {
            DB::table('documento_pago_re')->insert([
                'id_documento' => $documento_nuevo,
                'id_pago' => $documento_pago[0]->id_pago
            ]);
        } else {
            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario'                => 1,
                'id_metodopago'             => 99,
                'id_vertical'               => 0,
                'id_categoria'              => 0,
                'id_clasificacion'          => 1,
                'tipo'                      => 1,
                'origen_importe'            => 0,
                'destino_importe'           => 0,
                'folio'                     => "",
                'entidad_origen'            => 1,
                'origen_entidad'            => "",
                'entidad_destino'           => "",
                'destino_entidad'           => "",
                'referencia'                => "",
                'clave_rastreo'             => "",
                'autorizacion'              => "",
                'destino_fecha_operacion'   => "",
                'destino_fecha_afectacion'  => "",
                'cuenta_cliente'            => ""
            ]);

            DB::table('documento_pago_re')->insert([
                'id_documento'  => $documento_nuevo,
                'id_pago'       => $pago
            ]);
        }

        if (empty($empresa_externa)) {
            //Aqui ta
            $crear_factura_nueva = self::crearFactura($documento_nuevo, 1, 0);

            if ($crear_factura_nueva->error) {
                return $crear_factura_nueva;
            }
        } else {
            //Fix Entidades
//            $crear_entidad = self::crearEntidad($info_entidad, $empresa_movimiento);
//
//            if ($crear_entidad->error) {
//                return $crear_entidad;
//            }

            $array_pro = array(
                "bd" => $empresa_movimiento,
                "password" => config("webservice.token"),
                "prefijo" => $info_documento->factura_serie,
                "folio" => $documento_nuevo,
                "fecha" => "",
                "cliente" => $info_entidad->rfc,
                "titulo" => $info_documento->marketplace,
                "almacen" => 1,
                "fecha_entrega_doc" => "",
                "divisa" => $info_documento->id_moneda,
                "tipo_cambio" => $info_documento->tipo_cambio,
                "condicion_pago" => $info_documento->id_periodo,
                "descuento_global" => 0,
                "productos" => $productos,
                "metodo_pago" => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                "forma_pago" => (strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago,
                "uso_cfdi" => $info_documento->uso_cfdi,
                "comentarios" => $info_documento->observacion,
            );

            $crear_factura_nueva = \Httpful\Request::post(config('webservice.url') . 'facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_pro, \Httpful\Mime::FORM)
                ->send();

            $crear_factura_nueva_raw = $crear_factura_nueva->raw_body;
            $crear_factura_nueva = @json_decode($crear_factura_nueva_raw);

            if (empty($crear_factura_nueva)) {
                $response->error = 1;
                $response->mensaje = "No fue posible generar la refacturación en la empresa externa, error desconocido." . self::logVariableLocation();
                $response->raw = $crear_factura_nueva_raw;

                return $response;
            }

            if ($crear_factura_nueva->error) {
                $response->error = 1;
                $response->mensaje = "No fue posible generar la refacturación en la empresa externa, error: " . $crear_factura_nueva->mensaje . "" . self::logVariableLocation();

                return $response;
            }

            DB::table('documento')->where(['id' => $documento_nuevo])->update([
                'factura_folio' => $crear_factura_nueva->id,
                'refacturado' => 1
            ]);
        }

        DB::table('documento')->where(['id' => $documento_nuevo])->update([
            'documento_extra' => $crear_factura_nueva->id
        ]);

        DB::table('documento')->where(['id' => $documento])->update([
            'refacturado' => 1,
            'refacturado_at' => date("Y-m-d H:i:s"),
            'nota' => $crear_nota_credito->id
        ]);

        if (!empty($pagos_asociados)) {
            foreach ($pagos_asociados as $pago) {
                $pago_id = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;
                $pago_tipo = ($pago->pago_con_operacion == 0) ? 0 : 1;

                $cambiar_cliente_ingreso = self::actualizarClienteIngreso($info_entidad->rfc, $pago_id, $empresa_movimiento);

                if ($cambiar_cliente_ingreso->error) {
                    $seguimiento .= "<p>" . $cambiar_cliente_ingreso->mensaje . "<p>";

                    continue;
                }

                $aplicar_pago_factura = self::saldarFactura($documento_nuevo, $pago_id, $pago_tipo);

                if ($aplicar_pago_factura->error) {
                    $texto_operacion = ($pago->pago_con_operacion == 0) ? 'la nc' : 'el pago';

                    $seguimiento .= "<p>No fue posible aplicar " . $texto_operacion . " con el ID " . $pago_id . ", mensaje de error: " . $aplicar_pago_factura->mensaje . ".</p>";
                } else {
                    $texto_operacion = ($pago->pago_con_operacion == 0) ? 'NC ' : 'Pago';

                    $seguimiento .= "<p>" . $texto_operacion . " con el ID " . $pago_id . " aplicado correctamente a la factura " . $documento_nuevo . ".</p>";
                }
            }
        }

        if ($option) {
            $de = DB::table('documento')->select('documento_extra')->where('id', $documento_nuevo)->first()->documento_extra;

            $array_mover = array(
                'bd' => $empresa_movimiento,
                'documento' => $de,
                'modulo' => 5
            );

            $mover_documento = \Httpful\Request::post(config('webservice.url') . "documento/cambiarmodulo")
                ->body($array_mover, \Httpful\Mime::FORM)
                ->send();

            $mover_documento_raw = $mover_documento->raw_body;
            $mover_documento = @json_decode($mover_documento_raw);

            if (empty($mover_documento)) {
                $seguimiento .= "<p> No fue posible mover el documento de módulo, error desconocido." . self::logVariableLocation() . "</p>";
            }

            if ($mover_documento->error) {
                $seguimiento .= "<p> No fue posible mover el documento de módulo, error: " . $mover_documento->mensaje . "" . self::logVariableLocation() . "</p>";
            }
        }

        $seguimiento = (empty($seguimiento) ? '' : '<br>' . $seguimiento);

        $response->error = 0;
        $response->mensaje = "Refacturación creada correctamente, nueva factura con folio " . $documento_nuevo . " " . $seguimiento . "" . self::logVariableLocation();
        $response->seguimiento = $seguimiento;

        return $response;
    }

    public static function crearMovimiento($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                    documento.id_tipo,
                                    documento.observacion,
                                    documento.id_almacen_principal_empresa AS id_almacen_principal,
                                    documento.id_almacen_secundario_empresa AS id_almacen_secundario,
                                    (
                                        SELECT
                                            id_erp
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_principal
                                    ) AS almacen_entrada,
                                    (
                                        SELECT
                                            id_erp
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_secundario
                                    ) AS almacen_salida
                                FROM documento
                                WHERE id = " . $documento . "");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información sobre el documento " . $documento . ", imposible generar el movimiento." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        $productos = DB::select("SELECT
                                    modelo.sku,
                                    modelo.serie,
                                    modelo.costo,
                                    0 AS descuento,
                                    movimiento.cantidad,
                                    movimiento.id AS id_movimiento,
                                    movimiento.precio AS precio_unitario,
                                    movimiento.comentario AS comentarios,
                                    movimiento.addenda AS addenda_numero_entrada_almacen
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron productos del documento " . $documento . ", imposible realizar movimiento." . self::logVariableLocation();

            return $response;
        }

        $almacen_bd = ($info_documento->id_tipo == "4" || $info_documento->id_tipo == "11") ? $info_documento->id_almacen_secundario : $info_documento->id_almacen_principal;

        $bd = DB::select("SELECT
                            empresa.bd
                        FROM empresa_almacen
                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                        WHERE empresa_almacen.id = " . $almacen_bd . "");

        if (empty($bd)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información sobre al ID de la empresa del documento " . $documento . ", imposible realizar movimiento." . self::logVariableLocation();

            return $response;
        }

        $bd = $bd[0]->bd;

        foreach ($productos as $producto) {
            $comentarios = $producto->serie ? "SERIES: " : "";

            if ($producto->serie) {
                $series = DB::select("SELECT
                                        producto.serie
                                    FROM movimiento_producto
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento_producto.id_movimiento = " . $producto->id_movimiento . "");

                foreach ($series as $serie) {
                    //                    $apos = `'`;
                    //                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                    //                    if (str_contains($serie->serie, $apos)) {
                    //                        $serie->serie = addslashes($serie->serie);
                    //                    }
                    $serie->serie = str_replace(["'", '\\'], '', $serie->serie);
                    $comentarios .= $serie->serie . " ";
                }
            }

            $producto->comentarios = $producto->comentarios . " - " . $comentarios;
        }

        try {
            if ($info_documento->id_tipo == "5" || $info_documento->id_tipo == "10") {
                $array_movimiento = array(
                    'bd'                => $bd,
                    'password'          => config('webservice.token'),
                    'fecha'             => date('Y-m-d H:i:s'),
                    'almacen_origen'    => $info_documento->almacen_salida,
                    'almacen_destino'   => $info_documento->almacen_entrada,
                    'comentarios'       => $info_documento->observacion,
                    'productos'         => json_encode($productos)
                );
            } else if ($info_documento->id_tipo == "11") {
                $array_movimiento = array(
                    "bd"                        => $bd,
                    "password"                  => config('webservice.token'),
                    "prefijo"                   => "I",
                    "folio"                     => $documento,
                    "fecha"                     => "",
                    "cliente"                   => 1, # El ID 1 es el ID de la empresa principal de la Base de datos la cual se toma para crear el documento de uso interno
                    "titulo"                    => "Factura de salida para uso interno del documento " . $documento,
                    "almacen"                   => $info_documento->almacen_salida,
                    "fecha_entrega_doc"         => "",
                    "divisa"                    => 3,
                    "tipo_cambio"               => 1,
                    "condicion_pago"            => 1,
                    "descuento_global"          => 0,
                    "productos"                 => json_encode($productos),
                    "metodo_pago"               => "PUE",
                    "forma_pago"                => "01",
                    "uso_cfdi"                  => "G03",
                    "comentarios"               => $info_documento->observacion,
                    'addenda'                   => 1,
                    'addenda_orden_compra'      => "",
                    'addenda_solicitud_pago'    => "",
                    'addenda_tipo_documento '   => "",
                    'addenda_factura_asociada'  => ""
                );
            } else {
                $array_movimiento = array(
                    'bd'            => $bd,
                    'password'      => config('webservice.token'),
                    'tipo'          => ($info_documento->id_tipo == "3") ? 1 : 2,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'almacen'       => ($info_documento->id_tipo == "3") ? $info_documento->almacen_entrada : $info_documento->almacen_salida,
                    'comentarios'   => $info_documento->observacion,
                    'productos'     => json_encode($productos)
                );
            }

            $tipo_documento = ($info_documento->id_tipo == "5" || $info_documento->id_tipo == "10") ? "MovimientosEntreAlmacenes/Insertar" : ($info_documento->id_tipo == "11" ? "facturas/cliente/insertar" : "EntradasSalidas/Insertar");

            $crear_movimiento = \Httpful\Request::post(config('webservice.url') . $tipo_documento . '/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_movimiento, \Httpful\Mime::FORM)
                ->send();

            $crear_movimiento_raw = $crear_movimiento->raw_body;
            $crear_movimiento = @json_decode($crear_movimiento);

            if (empty($crear_movimiento)) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear el movimiento del documento " . $documento . ", error: " . $crear_movimiento_raw . " " . self::logVariableLocation();
                $response->raw = $crear_movimiento_raw;
                $response->data = $array_movimiento;

                return $response;
            }

            if ($crear_movimiento->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear el movimiento del documento " . $documento . ", error: " . $crear_movimiento->mensaje . "." . self::logVariableLocation();
                $response->data = $array_movimiento;

                return $response;
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'factura_folio' => $crear_movimiento->id,
                'documento_extra' => $crear_movimiento->id
            ]);
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible crear el movimiento del documento " . $documento . ", error: " . $e->getMessage() . "<br><br>" . $e->getTraceAsString() . "" . self::logVariableLocation();

            return $response;
        }

        $response->id = $crear_movimiento->id;
        $response->error = 0;
        $response->mensaje = "Documento creado correctamente.";

        return $response;
    }

    public static function crearMovimientoFlujo($pago, $bd)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $tiene_documento = DB::select("SELECT id_documento FROM documento_pago_re WHERE id_pago = " . $pago->id . "");

        if ($pago->tipo == 1 || $pago->tipo == 0) {
            # Se busca al cliente por RFC para verificar que exista
            $entidad = $pago->tipo == 1 ? $pago->origen_entidad : $pago->destino_entidad;
            $entidad_tipo = $pago->tipo == 1 ? $pago->entidad_origen : $pago->entidad_destino;

            $existe_entidad = new \stdClass();
            $existe_entidad->razon = "";
            $existe_entidad->nombre_oficial = "";

            if (!is_numeric($entidad) && ($entidad_tipo == 1 || $entidad_tipo == 2)) {
                $tipo_consulta = $pago->tipo == 1 ? 'Clientes' : (!empty($tiene_documento)) ? 'Clientes' : 'Proveedores';
                $existe_entidad = @json_decode(file_get_contents(config('webservice.url') . 'Consultas/' . $tipo_consulta . '/' . $bd . '/RFC/' . $entidad));

                if (empty($existe_entidad)) {
                    $response->error = 1;
                    $response->mensaje = "No se encontró la entidad con el RFC " . $entidad . " para generar el movimiento." . self::logVariableLocation();

                    return $response;
                }

                $existe_entidad = $existe_entidad[0];
            }
        }

        if ($tiene_documento) {
            if ($pago->origen_entidad === 'XEXX010101000') {
                $entidad_erp_id = DB::select("SELECT id_erp FROM documento_entidad
                                                INNER JOIN documento_entidad_re ON documento_entidad.id = documento_entidad_re.id_entidad
                                                WHERE documento_entidad_re.id_documento = " . $tiene_documento[0]->id_documento . "");

                if (!empty($entidad_erp_id)) {
                    $pago->origen_entidad = $entidad_erp_id[0]->id_erp;
                }
            }
        }

        $tipo_documento = $pago->tipo == 1 ? 'Ingresos' : ($pago->tipo == 0 ? 'Egresos' : 'TraspasosV2');

        try {
            $movimiento_data = array(
                'bd'                => $bd,
                'password'          => config("webservice.token"),
                'folio'             => '',
                'monto'             => $pago->origen_importe == 0 ? $pago->destino_importe : $pago->origen_importe,
                'tipo_cambio'       => $pago->tipo_cambio,
                'fecha_operacion'   => $pago->origen_fecha_operacion,
                'fecha_operacion_origen'    => $pago->origen_fecha_operacion,
                'fecha_operacion_destino'   => $pago->destino_fecha_operacion,
                'origen_entidad'    => $pago->entidad_origen,
                'origen_cuenta'     => $pago->origen_entidad,
                'destino_entidad'   => $pago->entidad_destino,
                'destino_cuenta'    => $pago->destino_entidad,
                'entidad_origen'    => $pago->entidad_origen,
                'cuenta_origen'     => $pago->origen_entidad,
                'entidad_destino'   => $pago->entidad_destino,
                'cuenta_destino'    => $pago->destino_entidad,
                'forma_pago'        => $pago->id_metodopago,
                'cuenta'            => $pago->cuenta_cliente,
                'clave_rastreo'     => $pago->clave_rastreo,
                'cheque'            => $pago->autorizacion,
                'numero_aut'        => $pago->autorizacion,
                'num_aut'           => $pago->autorizacion,
                'referencia'        => $pago->referencia
            );

            if ($pago->tipo == 1 || $pago->tipo == 0) {
                $movimiento_data['comentarios'] = empty($tiene_documento) ? (($pago->tipo == 1 ? 'Cobro cliente ' : 'Pago proveedor ') . ($pago->tipo == 1 ? $existe_entidad->nombre_oficial : $existe_entidad->razon)) : ($pago->tipo == 1 ? 'Cobro cliente para la factura ' . $tiene_documento[0]->id_documento : 'Egreso del pedido ' . $tiene_documento[0]->id_documento);
                $movimiento_data['descripcion'] = empty($tiene_documento) ? (($pago->tipo == 1 ? 'Cobro cliente ' : 'Pago proveedor ') . ($pago->tipo == 1 ? $existe_entidad->nombre_oficial : $existe_entidad->razon)) : ($pago->tipo == 1 ? 'Cobro cliente para la factura ' . $tiene_documento[0]->id_documento : 'Egreso del pedido ' . $tiene_documento[0]->id_documento);
            }

            $crear_movimiento = \Httpful\Request::post(config('webservice.url') . $tipo_documento . '/Insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($movimiento_data, \Httpful\Mime::FORM)
                ->send();

            $crear_movimiento_raw = $crear_movimiento->raw_body;
            $crear_movimiento = @json_decode($crear_movimiento_raw);

            if (empty($crear_movimiento)) {
                $response->error = 1;
                $response->mensaje = "No fue posible generar el movimiento, error desconocido." . self::logVariableLocation();
                $response->raw = $crear_movimiento_raw;
                $response->data = $movimiento_data;

                return $response;
            }

            if ($crear_movimiento->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible generar el movimiento, error " . $crear_movimiento->mensaje . "" . self::logVariableLocation();
                $response->data = $movimiento_data;

                return $response;
            }

            DB::table('documento_pago')->where(['id' => $pago->id])->update([
                'folio' => $crear_movimiento->id
            ]);

            $response->error = 0;
            $response->id = $crear_movimiento->id;

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible generar el movimiento, error " . $e->getMessage() . "" . self::logVariableLocation();

            return $response;
        }
    }

    public static function crearNotaCredito($documento, $tipo = 0)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                        documento.id,
                                        documento.id_almacen_principal_empresa,
                                        documento.id_moneda,
                                        documento.id_cfdi,
                                        documento.tipo_cambio,
                                        documento.anticipada,
                                        documento.referencia,
                                        documento.factura_serie,
                                        documento.factura_folio,
                                        documento.id_marketplace_area,
                                        documento_periodo.id AS id_periodo,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        empresa.bd,
                                        empresa.almacen_devolucion_garantia_erp,
                                        empresa.almacen_devolucion_garantia_sistema,
                                        empresa.almacen_devolucion_garantia_serie,
                                        empresa_almacen.id_erp AS id_almacen,
                                        marketplace_area.serie AS serie_factura,
                                        marketplace_area.serie_nota,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        $info_entidad = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento_entidad
                                INNER JOIN documento_entidad_re ON documento_entidad.id = documento_entidad_re.id_entidad
                                WHERE documento_entidad_re.id_documento = " . $documento . "
                                AND documento_entidad.tipo = 1");

        if (empty($info_entidad)) {
            $response->error = 1;
            $response->mensaje = "No se encontró la información del cliente." . self::logVariableLocation();

            return $response;
        }

        $info_entidad   = $info_entidad[0];

        $forma_pago = DB::select("SELECT
                                        id_metodopago
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $documento . "");

        if ($info_documento->publico == 0) {
            if (empty($forma_pago)) {
                $pago = DB::table('documento_pago')->insertGetId([
                    'id_usuario'                => 1,
                    'id_metodopago'             => 99,
                    'id_vertical'               => 0,
                    'id_categoria'              => 0,
                    'id_clasificacion'          => 1,
                    'tipo'                      => 1,
                    'origen_importe'            => 0,
                    'destino_importe'           => 0,
                    'folio'                     => "",
                    'entidad_origen'            => 1,
                    'origen_entidad'            => $info_entidad->rfc,
                    'entidad_destino'           => '',
                    'destino_entidad'           => '',
                    'referencia'                => '',
                    'clave_rastreo'             => '',
                    'autorizacion'              => '',
                    'destino_fecha_operacion'   => date('Y-m-d'),
                    'destino_fecha_afectacion'  => '',
                    'cuenta_cliente'            => ''
                ]);

                DB::table('documento_pago_re')->insert([
                    'id_documento'  => $documento,
                    'id_pago'       => $pago
                ]);

                $forma_pago = DB::select("SELECT
                                        id_metodopago,
                                        destino_entidad
                                    FROM documento_pago 
                                    INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                    WHERE id_documento = " . $documento . "");
            }

            $forma_pago = $forma_pago[0];
        } else {
            $forma_pago = new \stdClass();

            $forma_pago->id_metodopago = 31;
            $forma_pago->destino_entidad = 1;
        }

        $productos = DB::select("SELECT
                                    '' AS id,
                                    movimiento.id AS id_movimiento,
                                    modelo.sku,
                                    modelo.serie,
                                    movimiento.id_modelo,
                                    movimiento.cantidad,
                                    movimiento.precio AS precio_unitario,
                                    0 AS descuento,
                                    movimiento.comentario AS comentarios,
                                    movimiento.addenda AS addenda_numero_entrada_almacen,
                                    IF(movimiento.retencion, 15, 5) AS impuesto
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron los productos del documento." . self::logVariableLocation();

            return $response;
        }

        $empresa_externa = DB::select("SELECT
                                        empresa.rfc,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                    INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "");

        $empresa_movimiento = empty($empresa_externa) ? $info_documento->bd : $empresa_externa[0]->bd;

        $response->error = 0;
        $response->data = $info_documento;

        //Fix Entidades
//        $crear_entidad = self::crearEntidad($info_entidad, $empresa_movimiento);
//
//        if ($crear_entidad->error) {
//            return $crear_entidad;
//        }

        $folio_factura = ($info_documento->factura_serie == 'N/A') ? $info_documento->id : $info_documento->factura_folio;

        $factura_data = @json_decode(file_get_contents(config('webservice.url') . $empresa_movimiento . '/Factura/Estado/Folio/' . $folio_factura));

        if (empty($factura_data)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la factura " . $folio_factura . "." . self::logVariableLocation();

            return $response;
        }

        if (is_array($factura_data)) {
            foreach ($factura_data as $factura) {
                if (($factura->eliminado == 0 || $factura->eliminado == null) && ($factura->cancelado == 0 || $factura->cancelado == null)) {
                    $factura_data = $factura;

                    break;
                }
            }
        }

        if (is_array($factura_data)) {
            $response->error = 1;
            $response->mensaje = "Hay más de una factura activa con el folio " . $folio_factura . "." . self::logVariableLocation();

            return $response;
        }

        if (is_null($factura_data->uuid) && $empresa_movimiento != 8) {
            $response->error = 1;
            $response->mensaje = "La factura no se encuentra timbrada." . self::logVariableLocation();

            return $response;
        }

        $titulo_nota = $tipo == 0 ? "Nota de credito por devolucion del pedido " : ($tipo == 1 ? "Nota de credito para el pedido " : ($tipo == 2 ? "Nota de credito por refacturacion del pedido " : "Nota de credito por cancelacion del pedido "));

        try {
            $array_nota = array(
                'bd' => $empresa_movimiento,
                'password' => config("webservice.token"),
                'serie' => $info_documento->serie_nota,
                'fecha' => date('Y-m-d H:i:s'),
                'cliente' => $info_entidad->rfc == 'XEXX010101000' ? $info_entidad->id_erp : $factura_data->rfc, # Se hace la NC al cliente que está en la factura en comercial
                'titulo' => $titulo_nota . $documento,
                'almacen' => $info_documento->id_almacen,
                'divisa' => $info_documento->id_moneda,
                'tipo_cambio' => $info_documento->tipo_cambio,
                'condicion_pago' => $info_documento->id_periodo,
                'metodo_pago' => ($info_documento->id_periodo == 1) ? "PUE" : "PPD",
                'forma_pago' => (strlen($forma_pago->id_metodopago) == 1) ? "0" . $forma_pago->id_metodopago : $forma_pago->id_metodopago,
                'uso_cfdi' => ($factura_data->rfc == "XAXX010101000") ? "S01" : $info_documento->uso_cfdi,
                'comentarios' => is_null($info_documento->referencia) ? "" : $info_documento->referencia,
                'productos' => json_encode($productos)
            );

            $response_nota = \Httpful\Request::post(config('webservice.url') . 'cliente/notacredito/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_nota, \Httpful\Mime::FORM)
                ->send();

            $raw_response_nota = $response_nota->raw_body;
            $response_nota = @json_decode($response_nota);

            if (empty($response_nota)) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", error: desconocido" . self::logVariableLocation();
                $response->raw = $raw_response_nota;
                $response->data = $array_nota;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error:No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", Raw Data: " . base64_encode($raw_response_nota) . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            if ($response_nota->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", error: " . $response_nota->mensaje . "" . self::logVariableLocation();
                $response->data = $array_nota;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error:No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", mensaje de error: " . $response_nota->mensaje . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            DB::table('documento')->where('id', $documento)->update([
                'nota' => $response_nota->id
            ]);

            $response->id = $response_nota->id;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error:No fue posible crear la nota de credito " . $documento . " en el ERP con la BD: " . $empresa_movimiento . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }

        # Se crear el documento nota de credito en el CRM para hacer el movimiento de series
        $documento_nota = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa'  => $info_documento->id_almacen_principal_empresa,
            'id_tipo' => DocumentoTipo::NOTA_CREDITO,
            'id_periodo' => $info_documento->id_periodo,
            'id_cfdi' => $info_documento->id_cfdi,
            'id_marketplace_area' => $info_documento->id_marketplace_area,
            'id_usuario' => 1,
            'id_moneda' => $info_documento->id_moneda,
            'id_fase' => DocumentoFase::VENTA_FINALIZADA,
            'documento_extra' => $response_nota->id,
            'factura_folio' => $response_nota->id,
            'tipo_cambio' => $info_documento->tipo_cambio,
            'referencia' => 'Nota de credito para el pedido ' . $documento,
            'info_extra' => 'N/A',
            'observacion' => 'Nota de credito para el pedido ' . $documento, // Status de la compra
        ]);

        foreach ($productos as $index => $producto) {
            # Se crear el movimiento para el la nota de credito si el producto se va a cambiar
            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento'          => $documento_nota,
                'id_modelo'             => $producto->id_modelo,
                'cantidad'              => $producto->cantidad,
                'precio'                => $producto->precio_unitario,
                'garantia'              => 0,
                'modificacion'          => 'N/A',
                'regalo'                => 0
            ]);

            $series = DB::select("SELECT
                                    producto.id
                                FROM movimiento
                                INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                WHERE movimiento.id = " . $producto->id_movimiento . "");

            foreach ($series as $serie) {
                if ($tipo == 0) {
                    # Se actualiza la información de la serie si es una nota de credito por devolución
                    DB::table('producto')->where(['id' => $serie->id])->update([
                        'status' => 1
                    ]);
                }

                # Se crea la relación de la serie anterior con la nota de credito
                DB::table('movimiento_producto')->insert([
                    'id_movimiento' => $movimiento,
                    'id_producto'   => $serie->id
                ]);
            }
        }

        $response->error = 0;
        $response->id = $response_nota->id;

        return $response;
    }

    public static function crearEntidad($entidad, $bd, $tipo = 1)
    { # Tipo 1 es clientes, 2 proveedores
        set_time_limit(0);
        $response = new \stdClass();

        $tipo_busqueda = $tipo == 1 ? "Clientes" : "Proveedores";
        $tipo_url = $tipo == 1 ? "empresa/cliente/insertar/" : "proveedor/V3/insertar/";

        # Se busca al cliente por RFC para verificar que exista
        $existe_entidad = @json_decode(file_get_contents(config('webservice.url') . 'Consultas/' . $tipo_busqueda . '/' . $bd . '/RFC/' . trim($entidad->rfc)));

        try {
            if (empty($existe_entidad)) {
                # Si no éxiste, se crea primeramente la empresa
                $array_empresa = array(
                    "bd" => $bd,
                    "password" => config("webservice.token"),
                    "contacto" => "",
                    "juridica" => (strlen($entidad->rfc) == 13) ? 2 : 1,
                    "nombre_oficial" => $entidad->razon_social,
                    "n_nombre_comercial" => $entidad->razon_social,
                    "n_rfc" => $entidad->rfc,
                    "n_comentarios" => "",
                    "d_c_p" => "",
                    "d_calle" => "",
                    "d_n_e" => "",
                    "d_n_i" => "",
                    "d_municipio" => "",
                    "d_estado" => "",
                    "d_colonia" => "",
                    "d_comentarios" => "",
                    "name_municipio" => "",
                    "name_estado" => "",
                    "namecolonia" => "",
                    "pais" => property_exists($entidad, "pais") ? $entidad->pais : "412",
                    "limite_credito" => property_exists($entidad, 'limite') ? $entidad->limite : 0,
                    "condicion_pago" => property_exists($entidad, 'condicion') ? $entidad->condicion : 1,
                    "comunicacion" => array(
                        "0" => array(
                            "canal" => 1, # Email
                            "valores" => array(
                                "0" => array(
                                    "nombre" => "Personal",
                                    "valor" => property_exists($entidad, 'correo') ? $entidad->correo : "",
                                )
                            )
                        ),
                        "1" => array(
                            "canal" => 2, # Email
                            "valores" => array(
                                "0" => array(
                                    "nombre" => "Personal",
                                    "valor" => property_exists($entidad, 'telefono') ? $entidad->telefono : "",
                                )
                            )
                        ),
                        "2" => array(
                            "canal" => 3, # Email
                            "valores" => array(
                                "0" => array(
                                    "nombre" => "Personal",
                                    "valor" => property_exists($entidad, 'telefono_alt') ? $entidad->telefono_alt : "",
                                )
                            )
                        )
                    )
                );

                if (!empty($entidad->info_extra)) {
                    $info_extra = json_decode($entidad->info_extra);

                    $array_empresa["pais"] = $info_extra->pais;
                }

                if (!empty($entidad->regimen_id) && $entidad->regimen_id != '0' && $entidad->regimen_id != '6') {
                    $array_empresa["regimen"] = $entidad->regimen_letra;
                }

                if ($entidad->codigo_postal_fiscal != 'N/A' && !empty($entidad->codigo_postal_fiscal)) {
                    $codigo_postal_fiscal = new \stdClass();
                    $codigo_postal_fiscal->cp = $entidad->codigo_postal_fiscal;

                    $array_empresa["direccion_fiscal"] = json_encode($codigo_postal_fiscal);
                }

                $crear_entidad = \Httpful\Request::post(config('webservice.url') . $tipo_url . 'UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                    ->body($array_empresa, \Httpful\Mime::FORM)
                    ->send();

                $crear_entidad_raw = $crear_entidad->raw_body;
                $crear_entidad = @json_decode($crear_entidad);

                if (empty($crear_entidad)) {
                    $response->error = 1;
                    $response->mensaje = "No fue posible crear la entidad en la empresa " . $bd . ", error: desconocido" . self::logVariableLocation();
                    $response->raw = $crear_entidad_raw;

                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la entidad de la empresa: " . $bd . ", Raw Data: " . base64_encode($crear_entidad_raw) . "." . PHP_EOL, FILE_APPEND);

                    return $response;
                }

                if ($crear_entidad->error == 1) {
                    $response->error = 1;
                    $response->mensaje = "No fue posible crear la entidad en la empresa " . $bd . ", error: " . $crear_entidad->mensaje . "" . self::logVariableLocation();
                    $response->data = $crear_entidad_raw;

                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la entidad de la empresa: " . $bd . ", mensaje de error: " . $crear_entidad->mensaje . "." . PHP_EOL, FILE_APPEND);

                    return $response;
                }
            }
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible crear la entidad en la empresa " . $bd . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la entidad de la empresa: " . $bd . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function crearPolizaIngresoEgreso($poliza, $tipo = 1)
    {
        set_time_limit(0);
        # Poliza tipo 1 ingreso, 2 nota credito o documento

        $response = new \stdClasS();
        $response->error = 1;

        $movimento = DB::table("documento_pago")
            ->select("documento_pago.id", "empresa.bd")
            ->join("empresa", "documento_pago.id_empresa", "=", "empresa.id")
            ->where("documento_pago.folio", $poliza->documento)
            ->first();

        $tiene_pedido = DB::table("documento_pago_re")
            ->select("id_documento")
            ->where("id_pago", $movimento->id)
            ->first();

        if (!empty($tiene_pedido)) {
            $empresa_documento = DB::table("documento")
                ->select("empresa.bd")
                ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
                ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
                ->where("documento.id", $tiene_pedido->id_documento)
                ->first();

            $empresa = $empresa_documento->bd;
        } else {
            $empresa = $movimento->bd;
        }

        try {
            $poliza_data = array(
                "bd" => $empresa,
                "password" => config("webservice.token"),
                "fecha" => date("Y-m-d H:i:s", strtotime($poliza->fecha)),
                "poliza_tipo" => $poliza->tipo,
                "poliza_definicion" => $poliza->definicion,
                "poliza_manual" => $poliza->manual,
                "monedaid" => $poliza->moneda,
                "tc" => $poliza->tc,
                "concepto" => $poliza->concepto,
                "documentoid" => $tipo == 1 ? 0 : $poliza->documento,
                "operacionid" => $tipo == 2 ? 0 : $poliza->documento,
                "transacciones" => $poliza->transacciones
            );

            $crear_poliza = \Httpful\Request::post(config('webservice.url') . "Poliza/Insertar")
                ->body($poliza_data, \Httpful\Mime::FORM)
                ->send();

            $crear_poliza_raw = $crear_poliza->raw_body;
            $crear_poliza = @json_decode($crear_poliza);
            # Si sucedio algo mal, se regresa un mensaje de error
            if (empty($crear_poliza)) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la poliza del documento " . $poliza->documento . " en el ERP con la BD: " . $empresa . ", error: desconocido" . self::logVariableLocation();
                $response->raw = $crear_poliza_raw;

                DB::table("documento_pago")->where("id", $movimento->id)->update([
                    "error_poliza" => 1,
                    "error_poliza_mensaje" => $crear_poliza_raw
                ]);

                return $response;
            }
            # Si sucedio algo mal, se regresa un mensaje de error
            if ($crear_poliza->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible crear la poliza del documento " . $poliza->documento . " en el ERP con la BD: " . $empresa . ", error: " . $crear_poliza->mensaje . "" . self::logVariableLocation();
                $response->data = $poliza_data;

                DB::table("documento_pago")->where("id", $movimento->id)->update([
                    "error_poliza" => 1,
                    "error_poliza_mensaje" => $crear_poliza->mensaje
                ]);

                return $response;
            }

            DB::table("documento_pago")->where("id", $movimento->id)->update([
                "error_poliza" => 0,
                "error_poliza_mensaje" => ""
            ]);
        } catch (Exception $e) {
            $response->mensaje = $e->getMessage() . " " . $e->getLine() . "" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->id = $crear_poliza->id;

        return $response;
    }

    public static function actualizarEntidad($entidad, $bd)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $array_empresa = array(
            "bd" => $bd,
            "password" => config("webservice.token"),
            "rfc" => $entidad->rfc,
            "juridica" => (strlen($entidad->rfc) == 13) ? 2 : 1,
            "nombre_oficial" => $entidad->razon_social,
            "n_nombre_comercial" => $entidad->razon_social,
            "n_comentarios" => "",
            "limite_credito" => $entidad->limite ?? 0,
            "condicion_pago" => property_exists($entidad, 'condicion') ? $entidad->condicion : 1,
        );

        if ($entidad->regimen_id != 'N/A' && !empty($entidad->regimen_id) && $entidad->regimen != '6') {
            $array_empresa['regimen'] = $entidad->regimen_id;
        }

        if ($entidad->codigo_postal_fiscal != 'N/A' && !empty($entidad->codigo_postal_fiscal)) {
            $codigo_postal_fiscal = new \stdClass();
            $codigo_postal_fiscal->cp = $entidad->codigo_postal_fiscal;

            $array_empresa["direccion_fiscal"] = json_encode($codigo_postal_fiscal);
        }

        $crear_entidad = \Httpful\Request::post(config('webservice.url') . 'cliente/update/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($array_empresa, \Httpful\Mime::FORM)
            ->send();

        $crear_entidad_raw = $crear_entidad->raw_body;
        $crear_entidad = @json_decode($crear_entidad);

        if (empty($crear_entidad)) {
            $response->error = 1;
            $response->mensaje = "No fue posible actualizar la entidad en la empresa " . $bd . ", error: desconocido" . self::logVariableLocation();
            $response->raw = $crear_entidad_raw;
            $response->data = $array_empresa;

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible actualizar la entidad de la empresa: " . $bd . ", Raw Data: " . base64_encode($crear_entidad_raw) . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }

        if ($crear_entidad->error == 1) {
            $response->error = 1;
            $response->mensaje = "No fue posible actualizar la entidad en la empresa " . $bd . ", error: " . $crear_entidad->mensaje . "" . self::logVariableLocation();
            $response->raw = $crear_entidad_raw;
            $response->data = $array_empresa;

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible actualizar la entidad de la empresa: " . $bd . ", mensaje de error: " . $crear_entidad->mensaje . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }

        $response->error = 0;
        $response->raw = $crear_entidad_raw;
        $response->data = $array_empresa;

        return $response;
    }

    public static function actualizarClienteIngreso($entidad, $ingreso, $bd)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $array = array(
            "bd" => $bd,
            "password" => config("webservice.token"),
            "operacion" => $ingreso,
            "rfc" => $entidad
        );

        $update_ingreso = \Httpful\Request::post(config('webservice.url') . 'Ingreso/CobroCliente/Update/Cliente/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($array, \Httpful\Mime::FORM)
            ->send();

        $update_ingreso_raw = $update_ingreso->raw_body;
        $update_ingreso = @json_decode($update_ingreso);

        if (empty($update_ingreso)) {
            $response->error = 1;
            $response->mensaje = "No fue posible cambiar el cliente del ingreso " . $ingreso . " en la bd " . $bd . ", error: desconocido" . self::logVariableLocation();
            $response->raw = $update_ingreso_raw;

            return $response;
        }

        if ($update_ingreso->error == 1) {
            $response->error = 1;
            $response->mensaje = "No fue posible actualizar la entidad del ingreso " . $ingreso . " en la bd " . $bd . ", error: " . $update_ingreso->mensaje . "" . self::logVariableLocation();
            $response->data = $update_ingreso_raw;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function saldarFactura($documento, $pago_id, $tipo)
    { # Tipo 0 NC, 1 Ingreso
        set_time_limit(0);
        $response = new \stdClass();
        $tipo_url = ($tipo) ? "CobroCliente/Pagar/FacturaCliente" : "Saldar/FacturaCliente/Con/NotaCredito";

        try {
            $info_documento = DB::select("SELECT
                                            documento.documento_extra,
                                            documento.factura_folio,
                                            empresa.bd
                                        FROM documento 
                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                        WHERE documento.id = " . $documento . "")[0];

            if (empty($info_documento->documento_extra) || $info_documento->documento_extra == 'N/A') {
                $response->error = 1;
                $response->mensaje = "No se encontró el ID de la factura, favor de revisar que ya haya sido creada en Comercial." . self::logVariableLocation();

                return $response;
            }

            $empresa_externa = DB::select("SELECT
                                            empresa.bd
                                        FROM documento
                                        INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                        INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                        WHERE documento.id = " . $documento . "");

            $empresa_ingreso = empty($empresa_externa) ? $info_documento->bd : $empresa_externa[0]->bd;

            $saldar_factura = array(
                'bd' => $empresa_ingreso,
                'password' => config("webservice.token")
            );

            if ($tipo) {
                $saldar_factura['documento'] = empty($empresa_externa) ? $info_documento->documento_extra : $info_documento->factura_folio;
                $saldar_factura['operacion'] = $pago_id;
            } else {
                $saldar_factura['factura'] = empty($empresa_externa) ? $info_documento->documento_extra : $info_documento->factura_folio;
                $saldar_factura['notacredito'] = $pago_id;
            }

            $response_nota = \Httpful\Request::post(config('webservice.url') . $tipo_url . '/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($saldar_factura, \Httpful\Mime::FORM)
                ->send();

            $raw_response_nota  = $response_nota->raw_body;
            $response_nota = json_decode($response_nota);

            if (empty($response_nota)) {
                $response->error = 1;
                $response->mensaje = "No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . " en la bd: " . $empresa_ingreso . ", error: desconocido" . self::logVariableLocation();
                $response->raw = $raw_response_nota;
                $response->data = $saldar_factura;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . ": " . $empresa_ingreso . ", Raw Data: " . base64_encode($raw_response_nota) . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            if ($response_nota->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . " en la bd: " . $empresa_ingreso . ", error: " . $response_nota->mensaje . "" . self::logVariableLocation();
                $response->data = $saldar_factura;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . ": " . $empresa_ingreso . ", mensaje de error: " . $response_nota->mensaje . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            $response->error = 0;
            $response->mensaje = "Factura " . $documento . " saldada correctamente con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . "." . self::logVariableLocation();

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $documento . " con " . (($tipo) ? 'el ingreso' : 'la NC') . " con el ID " . $pago_id . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }
    }

    public static function afectarMovimiento($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                        documento.documento_extra,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento." . self::logVariableLocation();

            return $response;
        }

        try {
            $info_documento = $info_documento[0];

            $array_movimiento = array(
                'bd'                    => $info_documento->bd,
                'password'              => config("webservice.token"),
                'documento_movimiento'  => $info_documento->documento_extra,
                'fecha_entrega'         => date('Y-m-d H:i:s')
            );

            $response_afectacion = \Httpful\Request::post(config("webservice.url") . 'MovimientosEntreAlmacenes/Entregar/Update/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                ->body($array_movimiento, \Httpful\Mime::FORM)
                ->send();

            $raw_response_afectacion = $response_afectacion->raw_body;
            $response_afectacion = @json_decode($response_afectacion);

            if (empty($response_afectacion)) {
                $response->error = 1;
                $response->mensaje = "No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", error: desconocido" . self::logVariableLocation();
                $response->raw = $raw_response_afectacion;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", en la bd: " . $info_documento->bd . ", Raw Data: " . base64_encode($raw_response_afectacion) . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            if ($response_afectacion->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", error: " . $response_afectacion->mensaje . "" . self::logVariableLocation();

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", en la bd: " . $info_documento->bd . ", mensaje de error: " . $response_afectacion->mensaje . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            $response->error = 0;

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", error: " . $e->getMessage() . "" . self::logVariableLocation();

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible afectar el traspaso con el ID " . $info_documento->documento_extra . ", en la bd: " . $info_documento->bd . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }
    }

    public static function desaplicarPagoFactura($documento, $pago_id)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                        documento.id,
                                        documento.factura_serie,
                                        documento.factura_folio,
                                        documento.documento_extra,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento." . self::logVariableLocation();

            return $response;
        }

        $empresa_externa = DB::select("SELECT
                                        empresa.rfc,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                    INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "");

        $info_documento = $info_documento[0];

        $empresa_movimiento = empty($empresa_externa) ? $info_documento->bd : $empresa_externa[0]->bd;

        $folio_factura = ($info_documento->factura_serie == 'N/A') ? $info_documento->id : $info_documento->factura_folio;

        $info_factura = @json_decode(file_get_contents(config("webservice.url") . $empresa_movimiento . '/Factura/Estado/Folio/' . $folio_factura));

        if (empty($info_factura)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la factura " . $folio_factura . " en Comercial." . self::logVariableLocation();

            return $response;
        }

        try {
            $eliminar_relacion = file_get_contents(config("webservice.url") . 'Pagos/EliminarRelacion/' . $empresa_movimiento . '/documento/' . $info_factura->documentoid . '/pago/' . $pago_id . '');

            $eliminar_relacion_raw  = $eliminar_relacion;
            $eliminar_relacion      = @json_decode($eliminar_relacion);

            if (empty($eliminar_relacion)) {
                $response->error = 1;
                $response->mensaje = "No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", error: desconocido." . self::logVariableLocation();
                $response->raw = $eliminar_relacion_raw;

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", en la bd: " . $empresa_movimiento . ", Raw Data: " . base64_encode($eliminar_relacion_raw) . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            if ($eliminar_relacion->error) {
                $response->error = 1;
                $response->mensaje = "No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", error: " . $eliminar_relacion->mensaje . "." . self::logVariableLocation();

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", error: " . $eliminar_relacion->mensaje . "." . PHP_EOL, FILE_APPEND);

                return $response;
            }

            $response->error = 0;

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", error: " . $e->getMessage() . "." . self::logVariableLocation();

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relación del pago " . $pago_id . " de la factura " . $folio_factura . ", error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            return $response;
        }
    }

    public static function existenciaProducto($producto, $almacen)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $empresa_almacen
            = DB::table('empresa_almacen')
            ->select('empresa.empresa', 'almacen.almacen')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->where('empresa_almacen.id', $almacen)
            ->first();;

        $empresa = DB::select("SELECT
                                empresa.bd
                            FROM empresa_almacen
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            WHERE empresa_almacen.id = " . $almacen . "");

        if (empty($empresa)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la empresa perteneciente al almacén." . self::logVariableLocation();

            return $response;
        }

        $empresa = $empresa[0]->bd;

        $total_pendientes = DB::select("SELECT
                                            IFNULL(SUM(movimiento.cantidad), 0) AS total
                                        FROM movimiento
                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                        INNER JOIN documento ON movimiento.id_documento = documento.id
                                        WHERE modelo.sku = '" . $producto . "'
                                        AND documento.id_almacen_principal_empresa = " . $almacen . "
                                        AND documento.status = 1
                                        AND documento.id_tipo = 2
                                        AND documento.anticipada = 0
                                        AND documento.id_fase IN (2,3,4,5,7)")[0]->total;

        $pendientes_pretransferencia = DB::select("SELECT
                                                        IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                    FROM documento
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto . "'
                                                    AND documento.id_almacen_secundario_empresa = " . $almacen . "
                                                    AND documento.id_tipo = 9
                                                    AND documento.status = 1
                                                    AND documento.id_fase IN (401, 402, 403, 404)")[0]->cantidad;

        $pendientes_recibir = DB::select("SELECT
                                            movimiento.id AS movimiento_id,
                                            modelo.sku,
                                            modelo.serie,
                                            movimiento.completa,
                                            movimiento.cantidad_aceptada,
                                            movimiento.cantidad,
                                            (SELECT
                                                COUNT(*) AS cantidad
                                            FROM movimiento
                                            INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                            WHERE movimiento.id = movimiento_id) AS recepcionadas
                                        FROM documento
                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                        WHERE documento.id_tipo = 1
                                        AND documento.status = 1
                                        AND modelo.sku = '" . $producto . "'
                                        AND documento.id_almacen_principal_empresa = " . $almacen . "
                                        AND documento.id_fase = 93");

        $total_pendientes_recibir = 0;

        foreach ($pendientes_recibir as $pendiente) {
            if ($pendiente->serie) {
                $total_pendientes_recibir += $pendiente->cantidad - $pendiente->recepcionadas;
            } else {
                $total_pendientes_recibir += $pendiente->cantidad - $pendiente->cantidad_aceptada;
            }
        }

        $total_pendientes += $total_pendientes_recibir;
        $total_pendientes += $pendientes_pretransferencia;
        $producto = trim(str_replace("%20", " ", $producto));

        $existe_producto = @json_decode(file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $empresa . '/' . rawurlencode($producto)));

        if (empty($existe_producto)) {
            $response->error = 1;
            $response->mensaje = "Producto no encontrado en el ERP, favor de verificar." . self::logVariableLocation();

            return $response;
        }

        if (is_array($existe_producto)) {
            $existe_producto = $existe_producto[0];
        }

        if (property_exists($existe_producto, "error")) {
            $response->error = 1;
            $response->mensaje = "Error al buscar el producto en el ERP, mensaje de error: " . $existe_producto->mensaje . "." . self::logVariableLocation();

            return $response;
        }
        //!COMENTADO PARA NO DETENER BUEN FIN
        // if ($existe_producto->tipo == 4) {
        //     $response->error = 1;
        //     $response->mensaje = "No está permitido crear ventas masivamente con codigos de servicio.";

        //     return $response;
        // }

        if (empty($existe_producto->existencias->almacenes)) {
            $response->error = 1;
            $response->mensaje = "Producto sin existencias. Empresa: " . $empresa_almacen->empresa . 'Almacen: ' . $empresa_almacen->almacen . self::logVariableLocation();

            return $response;
        }

        $response->tipo = 1;

        $almacen_erp = DB::select("SELECT
                                        almacen.almacen,
                                        empresa_almacen.id_erp 
                                    FROM empresa_almacen 
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id = " . $almacen . "");

        $response->error = 0;
        $response->existencia = 0;

        foreach ($existe_producto->existencias->almacenes as $almacen_producto) {
            if ($almacen_erp[0]->id_erp == $almacen_producto->almacenid) {
                $response->existencia = (int) ($almacen_producto->fisico - $total_pendientes);
            }
        }

        return $response;
    }

    public static function authy($usuario, $token)
    {
        $response = new \stdClass();

        $authy = DB::select("SELECT authy FROM usuario WHERE id = " . $usuario . "");

        if (empty($authy)) {
            $response->error = 1;
            $response->mensaje = "Usuario no encontrado." . self::logVariableLocation();

            return $response;
        }

        $authy = $authy[0]->authy;

        try {
            $authy_request = new \Authy\AuthyApi(config("authy.token"));

            $verification = $authy_request->verifyToken($authy, $token);

            if (!$verification->ok()) {
                $response->error = 1;
                $response->mensaje = "Token incorrecto" . self::logVariableLocation();

                return $response;
            }

            $response->error = 0;

            return $response;
        } catch (\Authy\AuthyFormatException $e) {
            $response->error = 1;
            $response->mensaje = $e->getMessage() . "" . self::logVariableLocation();

            return $response;
        }
    }

    public static function __eliminarFactura($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                        documento.documento_extra,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento, favor de corroborar e intentar de nuevo." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        $empresa_externa = DB::select("SELECT
                                            empresa.bd,
                                            documento.factura_folio AS documento_extra
                                        FROM documento
                                        INNER JOIN marketplace_area_empresa ON documento.id_marketplace_area = marketplace_area_empresa.id_marketplace_area
                                        INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                        WHERE documento.id = " . $documento . "");

        $pagos_factura = @json_decode(file_get_contents(config('webservice.url') . $info_documento->bd . "/Documento/" . $info_documento->documento_extra . "/PagosRelacionados"));

        if (!empty($pagos_factura)) {
            foreach ($pagos_factura as $pago) {
                $id_pago = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                $eliminar_relacion_raw = file_get_contents(config('webservice.url') . "Pagos/EliminarRelacion/" . $info_documento->bd . "/documento/" . $info_documento->documento_extra . "/pago/" . $id_pago);
                $eliminar_relacion = @json_decode($eliminar_ingreso_raw);

                if (empty($eliminar_relacion)) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relacion de los pagos con la factura " . $documento . " en la empresa principal con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($eliminar_relacion_raw) . "." . PHP_EOL, FILE_APPEND);

                    $response->error = 1;
                    $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();
                    return $response;
                }

                if ($eliminar_relacion->error == 1) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relacion de los pagos con la factura " . $documento . " en la empresa principal con la BD: " . $info_documento->bd . ", Mensaje de error: " . $eliminar_relacion->mensaje . "." . PHP_EOL, FILE_APPEND);

                    $response->error = 1;
                    $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                    return $response;
                }

                $eliminar_ingreso_data = array(
                    "bd"        => $info_documento->bd,
                    "password"  => config('webservice.token'),
                    "operacion" => $id_pago,
                    "ventacrm"  => $documento
                );

                $eliminar_ingreso = \Httpful\Request::post(config('webservice.url') . 'Ingresos/CobroCliente/Eliminar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                    ->body($eliminar_ingreso_data, \Httpful\Mime::FORM)
                    ->send();

                $eliminar_ingreso_raw = $eliminar_ingreso->raw_body;
                $eliminar_ingreso = @json_decode($eliminar_ingreso);

                if (empty($eliminar_ingreso)) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($eliminar_ingreso_raw) . "." . PHP_EOL, FILE_APPEND);

                    $response->error = 1;
                    $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                    return $response;
                }

                if ($eliminar_ingreso->error == 1) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $eliminar_ingreso->mensaje . "." . PHP_EOL, FILE_APPEND);

                    $response->error = 1;
                    $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                    return $response;
                }
            }
        }

        $cancelar_factura_data = array(
            "bd"        => $info_documento->bd,
            "password"  => config('webservice.token'),
            "documento" => $info_documento->documento_extra
        );

        $cancelar_factura = \Httpful\Request::post(config('webservice.url') . 'FacturaCliente/Cancelar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($cancelar_factura_data, \Httpful\Mime::FORM)
            ->send();

        $cancelar_factura_raw = $cancelar_factura->raw_body;
        $cancelar_factura = @json_decode($cancelar_factura);

        if (empty($cancelar_factura)) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($cancelar_factura_raw) . "." . PHP_EOL, FILE_APPEND);

            $response->error    = 1;
            $response->mensaje  = "No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();
            $response->raw = $cancelar_factura_raw;

            return $response;
        }

        if ($cancelar_factura->error == 1) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $cancelar_factura->mensaje . "." . PHP_EOL, FILE_APPEND);

            $response->error    = 1;
            $response->mensaje  = "No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", mensaje de error: " . $cancelar_factura->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

            return $response;
        }

        if (!empty($empresa_externa)) {
            $empresa_externa = $empresa_externa[0];

            if ($empresa_externa->documento_extra != 'N/A') {
                $pagos_factura = @json_decode(file_get_contents(config('webservice.url') . $empresa_externa->bd . "/Documento/" . $empresa_externa->documento_extra . "/PagosRelacionados"));

                if (!empty($pagos_factura)) {
                    foreach ($pagos_factura as $pago) {
                        $id_pago = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                        $eliminar_relacion_raw = file_get_contents(config('webservice.url') . "Pagos/EliminarRelacion/" . $empresa_externa->bd . "/documento/" . $empresa_externa->documento_extra . "/pago/" . $id_pago);
                        $eliminar_relacion = @json_decode($eliminar_ingreso_raw);

                        if (empty($eliminar_relacion)) {
                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relacion de los pagos con la factura " . $documento . " en la empresa externa con la BD: " . $empresa_externa->bd . ", Raw Data: " . base64_encode($eliminar_relacion_raw) . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();
                            return $response;
                        }

                        if ($eliminar_relacion->error == 1) {
                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar la relacion de los pagos con la factura " . $documento . " en la empresa externa con la BD: " . $empresa_externa->bd . ", Mensaje de error: " . $eliminar_relacion->mensaje . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                            return $response;
                        }

                        $eliminar_ingreso_data = array(
                            "bd"        => $empresa_externa->bd,
                            "password"  => config('webservice.token'),
                            "operacion" => $id_pago,
                            "ventacrm"  => $documento
                        );

                        $eliminar_ingreso = \Httpful\Request::post(config('webservice.url') . 'Ingresos/CobroCliente/Eliminar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                            ->body($eliminar_ingreso_data, \Httpful\Mime::FORM)
                            ->send();

                        $eliminar_ingreso_raw = $eliminar_ingreso->raw_body;
                        $eliminar_ingreso = @json_decode($eliminar_ingreso);

                        if (empty($eliminar_ingreso)) {
                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", Raw Data: " . base64_encode($eliminar_ingreso_raw) . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                            return $response;
                        }

                        if ($eliminar_ingreso->error == 1) {
                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_ingreso . " del documento " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", Mensaje de error: " . $eliminar_ingreso->mensaje . "." . PHP_EOL, FILE_APPEND);

                            $response->error = 1;
                            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $documento . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                            return $response;
                        }
                    }
                }

                $cancelar_factura_data = array(
                    "bd"        => $empresa_externa->bd,
                    "password"  => config('webservice.token'),
                    "documento" => $empresa_externa->documento_extra
                );

                $cancelar_factura = \Httpful\Request::post(config('webservice.url') . 'FacturaCliente/Cancelar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                    ->body($cancelar_factura_data, \Httpful\Mime::FORM)
                    ->send();

                $cancelar_factura_raw = $cancelar_factura->raw_body;
                $cancelar_factura = @json_decode($cancelar_factura);

                if (empty($cancelar_factura)) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", Raw Data: " . base64_encode($cancelar_factura_raw) . "." . PHP_EOL, FILE_APPEND);

                    $response->error    = 1;
                    $response->mensaje  = "No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();
                    $response->raw = $cancelar_factura_raw;

                    return $response;
                }

                if ($cancelar_factura->error == 1) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", Mensaje de error: " . $cancelar_factura->mensaje . "." . PHP_EOL, FILE_APPEND);

                    $response->error    = 1;
                    $response->mensaje  = "No fue posible cancelar la factura " . $documento . " en el ERP con la BD: " . $empresa_externa->bd . ", mensaje de error: " . $cancelar_factura->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

                    return $response;
                }
            }
        }

        $response->error = 1;
        $response->mensaje = "La factura fue cancelada." . self::logVariableLocation();

        return $response;
    }

    public static function __eliminarMovimientoFlujo($id_movimiento, $referencia, $bd)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $eliminar_ingreso_data = array(
            "bd"        => $bd,
            "password"  => config('webservice.token'),
            "operacion" => $id_movimiento,
            "ventacrm"  => $referencia
        );

        $eliminar_ingreso = \Httpful\Request::post(config('webservice.url') . 'Ingresos/CobroCliente/Eliminar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($eliminar_ingreso_data, \Httpful\Mime::FORM)
            ->send();

        $eliminar_ingreso_raw = $eliminar_ingreso->raw_body;
        $eliminar_ingreso = @json_decode($eliminar_ingreso);

        if (empty($eliminar_ingreso)) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_movimiento . " del documento " . $referencia . " en el ERP con la BD: " . $bd . ", Raw Data: " . base64_encode($eliminar_ingreso_raw) . "." . PHP_EOL, FILE_APPEND);

            $response->error = 1;
            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $referencia . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::logVariableLocation();

            return $response;
        }

        if ($eliminar_ingreso->error == 1) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible eliminar el ingreso " . $id_movimiento . " del documento " . $referencia . " en el ERP con la BD: " . $bd . ", Mensaje de error: " . $eliminar_ingreso->mensaje . "." . PHP_EOL, FILE_APPEND);

            $response->error = 1;
            $response->mensaje = "No fue posible eliminar la relación de los ingresos con la factura " . $referencia . ", por lo tanto lo se canceló la factura, favor de no volver a tratar de crear el documento hasta que un administrador le indique. " . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->mensaje = "Movimiento con el ID " . $id_movimiento . " eliminado correctamente en la BD " . $bd . " con la referencia " . $referencia . "" . self::logVariableLocation();

        return $response;
    }

    public static function __eliminarCompra($documento)
    {
        set_time_limit(0);
        $response = new \stdClass();

        $info_documento = DB::select("SELECT
                                        documento.documento_extra,
                                        empresa.bd
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($info_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento, favor de corroborar e intentar de nuevo." . self::logVariableLocation();

            return $response;
        }

        $info_documento = $info_documento[0];

        $cancelar_compra_data = array(
            "bd" => $info_documento->bd,
            "password"  => config('webservice.token'),
            "documento" => $info_documento->documento_extra
        );

        $cancela_comprar = \Httpful\Request::post(config('webservice.url') . 'FacturasCompra/Cancelar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($cancelar_compra_data, \Httpful\Mime::FORM)
            ->send();

        $cancela_comprar_raw = $cancela_comprar->raw_body;
        $cancela_comprar = @json_decode($cancela_comprar);

        if (empty($cancela_comprar)) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la compra " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Raw Data: " . base64_encode($cancela_comprar_raw) . "." . PHP_EOL, FILE_APPEND);

            $response->error    = 1;
            $response->mensaje  = "No fue posible cancelar la compra " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();
            $response->raw = $cancela_comprar_raw;

            return $response;
        }

        if ($cancela_comprar->error == 1) {
            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: No fue posible cancelar la compra " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", Mensaje de error: " . $cancela_comprar->mensaje . "." . PHP_EOL, FILE_APPEND);

            $response->error    = 1;
            $response->mensaje  = "No fue posible cancelar la compra " . $documento . " en el ERP con la BD: " . $info_documento->bd . ", mensaje de error: " . $cancela_comprar->mensaje . ", favor de no volver a tratar de crear el documento hasta que un administrador le indique." . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->mensaje = "La compra fue cancelada.";

        return $response;
    }

    public static function tipo_cambio()
    {
        $tipo_cambio    = 0;

        $serie_banxico = "SF46410";
        #$serie_banxico = "SF60653";

        $opts = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $context = stream_context_create($opts);

        try {
            $cliente = new \SoapClient(config('webservice.tc'), array('stream_context' => $context, 'trace' => true));

            $xml_data = simplexml_load_string($cliente->tiposDeCambioBanxico(), "SimpleXMLElement");

            $documento = new DOMDocument;

            $documento->loadXML($xml_data->asXML());

            $monedas = $documento->getElementsByTagName('Series');

            foreach ($monedas as $moneda) {
                $serie = $moneda->getAttribute('IDSERIE');

                $tipo_cambio_data = $moneda->getElementsByTagName('Obs')[0];

                if ($serie === $serie_banxico) {
                    $tipo_cambio = (float) $tipo_cambio_data->getAttribute('OBS_VALUE');
                }
            }
        } catch (Exception $e) {
            $tipo_cambio = 1;
        }

        return $tipo_cambio == 0 ? 1 : $tipo_cambio;
    }


    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'DS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'NTO'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
