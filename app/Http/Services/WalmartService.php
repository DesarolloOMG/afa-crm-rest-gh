<?php

namespace App\Http\Services;

use DateTime;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use DB;
use stdClass;

class WalmartService
{
    public static function venta($venta, $marketplace_id, $full = 0)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador.";

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        if ($full) {
            $url = config("webservice.walmart_endpoint") . "v3/orders/wfsorders?customerOrderId=" . $venta;

            $request = \Httpful\Request::get($url)
                ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
                ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
                ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
                ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
                ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
                ->addHeader('WM_MARKET', 'mx')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->send();
        } else {
            $request = \Httpful\Request::get(config("webservice.walmart_endpoint") . "v3/orders?purchaseOrderId=" . $venta)
                ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
                ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
                ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
                ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
                ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
                ->addHeader('WM_MARKET', 'mx')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->send();
        }

        $request = json_decode($request->raw_body);

        if (property_exists($request, "error")) {
            if (property_exists($request, "error_description")) {
                $response->mensaje = $request->error_description;
            } else {
                $response->mensaje = $request;
            }

            return $response;
        }

        if (empty($request->order)) {
            $response->mensaje = "No se encontró la venta con el ID proporcionado " . $venta;

            return $response;
        }

        $response->error = 0;
        $response->data = $request->order[0];

        return $response;
    }

    public static function documento($documento, $marketplace_id)
    {
        $response = new \stdClass();
        $response->error = 1;
        $archivos = array();

        $guia = DB::select("SELECT referencia FROM documento WHERE id = " . $documento . "");

        if (empty($guia)) {
            $response->mensaje = "No se encontró información del documento para descargar el documento de embarque.";

            return $response;
        }

        $guia = trim($guia[0]->referencia);

        if (str_contains($guia, ',')) {
            $guia = str_replace(',', '', $guia);
        }

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador.";

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $request = \Httpful\Request::get(config("webservice.walmart_endpoint") . "v3/orders/label/" . $guia)
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
            ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            //            ->addHeader('Accept', 'application/pdf')
            ->send();

        if (is_null($request->body)) {
            $response->mensaje = "No se encontró la guía de embarque para el documento.";

            return $response;
        }

        $response->error = 0;
        $response->file = base64_encode($request->body);
        $response->pdf = 0;

        return $response;
    }

    public static function actualizarEnvio($documento)
    {
        $response = new \stdClass();
        $response->error = 1;

        $venta = DB::select("SELECT no_venta, id_marketplace_area FROM documento WHERE id = " . $documento . "")[0];

        if (empty($venta)) {
            $response->mensaje = "No se encontró información del documento para descargar el documento de embarque.";

            return $response;
        }

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $venta->id_marketplace_area . "");

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador.";

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $request = \Httpful\Request::get(config("webservice.walmart_endpoint") . "v3/orders/" . $venta->no_venta . "/ship")
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
            ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->send();

        return response()->json([
            'Respuesta' => $request
        ]);
    }

    public static function token($marketplace)
    {
        $response = new \stdClass();
        $response->error = 1;

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $data = array(
            "grant_type" => "client_credentials"
        );

        $request_data = \Httpful\Request::post(config("webservice.walmart_endpoint") . "v3/token")
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->body($data, \Httpful\Mime::FORM)
            ->send();

        $request_raw = $request_data->raw_body;
        $request = json_decode($request_raw);

        if (property_exists($request, "error")) {
            $response->mensaje = $request->error_description . ", line 169";
            $response->raw = $request_data;

            return $response;
        }

        $response->error = 0;
        $response->token = $request->access_token;

        return $response;
    }

    public static function obtener_ventas_ack($marketplace_area, $data)
    {
        $response = new \stdClass();
        $response->error = 1;

        $fechaInicio = !empty($data->fecha_inicio) ? (new DateTime($data->fecha_inicio))->format(DateTime::ATOM) : null;
        $fechaFin = !empty($data->fecha_final) ? (new DateTime($data->fecha_final))->format(DateTime::ATOM) : null;

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_area);

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $totalOrders = 0;
        $limit = 100;
        $orders = [];

        do {
            $offset = count($orders);
            $url = config("webservice.walmart_endpoint") . "v3/orders?statusCodeFilter=Acknowledged&limit=$limit&offset=$offset";

            if ($fechaInicio) {
                $url .= "&createdStartDate=$fechaInicio";
            }
            if ($fechaFin) {
                $url .= "&createdEndDate=$fechaFin";
            }

            $request = \Httpful\Request::get($url)
                ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
                ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
                ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
                ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
                ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
                ->addHeader('WM_MARKET', 'mx')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->send();

            $request = json_decode($request->raw_body);

            if (property_exists($request, "severity")) {
                if (property_exists($request, "error_description")) {
                    $response->mensaje = $request->code . ": " . $request->error_description;
                } else {
                    $response->mensaje = $request->code . ": " . $request->description;
                }
                return $response;
            }

            if (property_exists($request, 'meta')) {
                $totalOrders = $request->meta->totalCount;
            }

            $orders = array_merge($orders, $request->order);
        } while (count($orders) < $totalOrders);

        $response->error = 0;
        $response->data = $orders;

        return $response;
    }

    public static function obtener_ventas_full($marketplace_area, $data)
    {
        $response = new \stdClass();
        $response->error = 1;
        $codeFilter = $data->codeFilter;

        // Convertir fechas al formato epoch
        $fechaInicio = !empty($data->fecha_inicio) ? (new DateTime($data->fecha_inicio))->format(DateTime::ATOM) : null;
        $fechaFin = !empty($data->fecha_final) ? (new DateTime($data->fecha_final))->format(DateTime::ATOM) : null;

        $marketplace = DB::select("SELECT
                                marketplace_area.id,
                                marketplace_api.app_id,
                                marketplace_api.secret
                            FROM marketplace_area
                            INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE marketplace_area.id = " . $marketplace_area);

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $totalOrders = 0;
        $limit = 100;
        $orders = [];

        do {
            $offset = count($orders);
            $url = config("webservice.walmart_endpoint") . "v3/orders/wfsorders?statusCodeFilter=$codeFilter&limit=$limit&offset=$offset";

            // Añadir fechas a la URL si están definidas
            if ($fechaInicio) {
                $url .= "&createdStartDate=$fechaInicio";
            }
            if ($fechaFin) {
                $url .= "&createdEndDate=$fechaFin";
            }

            $request = \Httpful\Request::get($url)
                ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
                ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
                ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
                ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
                ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
                ->addHeader('WM_MARKET', 'mx')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->send();

            $request = json_decode($request->raw_body);

            if (property_exists($request, "severity")) {
                if (property_exists($request, "error_description")) {
                    $response->mensaje = $request->code . ": " . $request->error_description;
                } else {
                    $response->mensaje = $request->code . ": " . $request->description;
                }
                return $response;
            }

            if (property_exists($request, 'meta')) {
                $totalOrders = $request->meta->totalCount;
            }

            $orders = array_merge($orders, $request->order);
        } while (count($orders) < $totalOrders);

        $response->error = 0;
        $response->data = $orders;

        return $response;
    }


    public static function importarVentas($marketplace_area, $data)
    {
        set_time_limit(0);

        $full = $data->fulfillment;


        if ($full) {
            if ($marketplace_area != 36) {
                $ventas_full = self::obtener_ventas_full($marketplace_area, $data);

                foreach ($ventas_full->data as $ventaf) {
                    // Verificar si el documento ya existe
                    $existe = DB::select("SELECT * FROM documento WHERE no_venta = " . $ventaf->customerOrderId);

                    if ($existe) {
                        $ventaf->Error = 1;
                        $ventaf->ErrorMessage = "Ya existe la venta en CRM.";
                        $ventaf->purchaseOrderId = "N/A";
                        $ventaf->Documentos = implode(',', array_column($existe, 'id'));
                        continue;
                    }

                    $importarfull = self::importarVentaIndividual($ventaf, $marketplace_area, $full);

                    $ventaf->Error = $importarfull->error;
                    $ventaf->purchaseOrderId = "N/A";
                    $ventaf->ErrorMessage = $importarfull->mensaje;
                    if ($importarfull->error) {
                        $ventaf->Documentos = "";
                    } else {
                        $ventaf->Documentos = $importarfull->documentos;
                    }
                }

                return $ventas_full;
            }
        } else {
            $ventas = self::obtener_ventas_ack($marketplace_area, $data);

            foreach ($ventas->data as $venta) {
                $existe = DB::select("SELECT * FROM documento WHERE no_venta = " . $venta->purchaseOrderId);

                if ($existe) {
                    $venta->Error = 1;
                    $venta->ErrorMessage = "Ya existe la venta en CRM.";
                    $venta->Documentos = implode(',', array_column($existe, 'id'));
                    continue;
                }

                $importar = self::importarVentaIndividual($venta, $marketplace_area, $full);

                $venta->Error = $importar->error;
                $venta->ErrorMessage = $importar->mensaje;
                if ($importar->error) {
                    $venta->Documentos = "";
                } else {
                    $venta->Documentos = $importar->documentos;
                }
            }

            return $ventas;
        }
    }

    //    private static function existeVenta($venta) {
    //        $existe_venta = DB::table('documento')
    //            ->where('no_venta', $venta)
    //            ->first();
    //
    //        return !empty($existe_venta);
    //    }

    public static function importarVentaIndividual($venta, $marketplace_area, $fullfilment)
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 0;
        $documentosStr = "";

        try {
            // Inserta la entidad (dirección de facturación)
            $entidadId = self::insertarEntidad($venta);
            if (!$entidadId) throw new Exception("No se creó la entidad");

            for ($i = 0; $i < $venta->totalLines; $i++) {
                $paqueteria_id = self::obtenerPaqueteriaId($venta, $fullfilment, $i);
                if (!$paqueteria_id) throw new Exception("Hubo un problema al obtener la paqueteria");

                $documentoId = self::insertarDocumento($venta, $marketplace_area, $fullfilment, $paqueteria_id, $i);
                if (!$documentoId) throw new Exception("No se creó el documento");

                $documentosStr .= ($documentosStr === "" ? "" : ",") . $documentoId;
                self::insertarMovimiento($venta, $documentoId, $i);
                self::insertarSeguimiento($documentoId, "PEDIDO IMPORTADO AUTOMATICAMENTE");
                self::relacionarEntidadDocumento($entidadId, $documentoId);

                $direccionId = self::insertarDireccion($venta, $documentoId);
                if (!$direccionId) throw new Exception("No se creó la dirección");

                $pagoId = self::insertarPago($venta, $documentoId, $i);
                if (!$pagoId) throw new Exception("No se creó el pago");
            }

            if ($fullfilment) {
                $documentos = DB::table('documento')->where('status', 1)->where('no_venta', $venta->customerOrderId)->get();
                foreach ($documentos as $doc) {
                    $factura = DocumentoService::crearFactura($doc->id, 0, 0);
                    if ($factura->error) {
                        self::insertarSeguimiento($doc->id, $factura->mensaje ?? "Error desconocido");
                        self::actualizarDocumentoFase($doc->id);
                    }
                }
            }

            $response->error = 0;
            $response->mensaje = "Importado correctamente";
            $response->documentos = $documentosStr;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = $e->getMessage();
            $response->documentos = $documentosStr;
        }

        return $response;
    }

    private static function obtenerPaqueteriaId($venta, $fullfilment, $index)
    {
        if (!$fullfilment) {
            $carrier = $venta->shipments[$index]->carrier;
            $paqueteria = DB::table('paqueteria')->where('paqueteria', $carrier)->first();
            return $paqueteria->id ?? 16;
        }
        return 16;
    }

    private static function insertarEntidad($venta)
    {
        return DB::table('documento_entidad')->insertGetId([
            'razon_social' => $venta->billingInfo->postalAddress->name ?? 0,
            'rfc' => mb_strtoupper('XAXX010101000', 'UTF-8'),
            'telefono' => $venta->billingInfo->phone ?? 0,
            'telefono_alt' => "0",
            'correo' => $venta->customerEmailId ?? "N/A"
        ]);
    }

    private static function insertarDocumento($venta, $marketplace_area, $fullfilment, $paqueteria_id, $index)
    {
        return DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_tipo' => 2,
            'id_almacen_principal_empresa' => $fullfilment ? 125 : ($marketplace_area == 64 ? 114 : 101),
            'id_marketplace_area' => $marketplace_area,
            'id_usuario' => 1,
            'id_paqueteria' => $paqueteria_id,
            'id_fase' => $fullfilment ? 6 : 1,
            'id_modelo_proveedor' => 0,
            'no_venta' => $fullfilment ? $venta->customerOrderId ?? "N/A" : $venta->purchaseOrderId ?? "N/A",
            'referencia' => isset($venta->shipments[$index]) ? $venta->shipments[$index]->trackingNumber ?? "Sin informacion de la guía" : "N/A",
            'observacion' => "Pedido Importado Walmart Api V07",
            'info_extra' => $index,
            'fulfillment' => $fullfilment,
            'comentario' => $venta->customerOrderId ?? 0,
            'mkt_publicacion' => "N/A",
            'mkt_total' => $venta->orderLines[$index]->item->unitPrice->amount ?? 0,
            'mkt_fee' => 0,
            'mkt_coupon' => 0,
            'mkt_shipping_total' => 0,
            'mkt_created_at' => $venta->orderDate ?? 0,
            'mkt_user_total' => 0,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function insertarSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => "<h2>{$mensaje}</h2>"
        ]);
    }

    private static function insertarMensajeSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => $mensaje
        ]);
    }

    private static function relacionarEntidadDocumento($entidadId, $documentoId)
    {
        DB::table('documento_entidad_re')->insert([
            'id_entidad' => $entidadId,
            'id_documento' => $documentoId
        ]);
    }

    private static function insertarDireccion($venta, $documentoId)
    {
        return DB::table('documento_direccion')->insertGetId([
            'id_documento' => $documentoId,
            'id_direccion_pro' => 0,
            'contacto' => $venta->shippingInfo->postalAddress->name ?? 0,
            'calle' => $venta->shippingInfo->postalAddress->address1 ?? 0,
            'numero' => $venta->shippingInfo->postalAddress->address2 ?? 0,
            'numero_int' => "N/A",
            'colonia' => $venta->shippingInfo->postalAddress->address3 ?? 0,
            'ciudad' => $venta->shippingInfo->postalAddress->city ?? 0,
            'estado' => $venta->shippingInfo->postalAddress->state ?? "0",
            'codigo_postal' => $venta->shippingInfo->postalAddress->postalCode ?? 0,
            'referencia' => $venta->shippingInfo->postalAddress->address4 ?? 0
        ]);
    }

    private static function insertarMovimiento($venta, $documentoId, $index)
    {
        $modelo = DB::table('modelo')->where('sku', $venta->orderLines[$index]->item->sku)->first();
        $modeloId = $modelo ? $modelo->id : DB::table('modelo_sinonimo')->where('codigo', $venta->orderLines[$index]->item->sku)->value('id_modelo');

        if (!empty($modeloId) || $modeloId) {
            self::insertarMensajeSeguimiento($documentoId, "Producto insertado correctamente" . $venta->orderLines[$index]->item->sku);
            DB::table('movimiento')->insertGetId([
                'id_documento' => $documentoId,
                'id_modelo' => $modeloId,
                'cantidad' => $venta->orderLines[$index]->orderLineQuantity->amount ?? 1,
                'precio' => $venta->orderLines[$index]->item->unitPrice->amount / 1.16,
                'garantia' => 90,
                'modificacion' => '',
                'regalo' => ''
            ]);
        } else {
            $publicaciones = DB::table('marketplace_publicacion as mp')
                                ->join('marketplace_publicacion_producto as mpp', 'mpp.id_publicacion', '=', 'mp.id')
                                ->where('mp.publicacion_id', $venta->orderLines[$index]->item->sku)
                                ->select('mpp.*')
                                ->get();

            if(!empty($publicaciones)){
                foreach ($publicaciones as $publicacion) {
                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documentoId,
                        'id_modelo' => $publicacion->id_modelo,
                        'cantidad' => $venta->orderLines[$index]->orderLineQuantity->amount * $publicacion->cantidad ?? 1 * $publicacion->cantidad,
                        'precio' => ($venta->orderLines[$index]->item->unitPrice->amount / 1.16)*($publicacion->porcentaje/100),
                        'garantia' => $publicacion->garantia,
                        'modificacion' => '',
                        'regalo' => $publicacion->regalo,
                    ]);
                }
            } else {
                self::insertarMensajeSeguimiento($documentoId, "No se encontro la relacion del producto con la publicacion." . $venta->orderLines[$index]->item->sku);
            }
        }
    }

    private static function insertarPago($venta, $documentoId, $index)
    {
        $pagoId = DB::table('documento_pago')->insertGetId([
            'id_usuario' => 1,
            'id_metodopago' => 31,
            'id_vertical' => 0,
            'id_categoria' => 0,
            'id_clasificacion' => 0,
            'tipo' => 1,
            'origen_importe' => 0,
            'destino_importe' => $venta->orderLines[$index]->item->unitPrice->amount,
            'folio' => "",
            'entidad_origen' => 1,
            'origen_entidad' => 'XAXX010101000',
            'entidad_destino' => "",
            'destino_entidad' => '',
            'referencia' => '',
            'clave_rastreo' => '',
            'autorizacion' => '',
            'destino_fecha_operacion' => date('Y-m-d'),
            'destino_fecha_afectacion' => '',
            'cuenta_cliente' => ''
        ]);

        if ($pagoId) {
            DB::table('documento_pago_re')->insert([
                'id_documento' => $documentoId,
                'id_pago' => $pagoId
            ]);
        }

        return $pagoId;
    }

    private static function actualizarDocumentoFase($documentoId)
    {
        DB::table('documento')->where(['id' => $documentoId])->update([
            'id_fase' => 5
        ]);
    }

    public static function actualizar_estado_envio($marketplace_id, $purchaseOrderId, $shipmentIndex)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador" . $log;
            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace);

        if ($token->error) {
            return $token;
        }

        $venta = WalmartService::venta($purchaseOrderId, $marketplace_id);
        $venta = $venta->data;

        $url = config("webservice.walmart_endpoint") . "v3/orders/" . $purchaseOrderId . "/ship";

        $shipments = [];

        // Iterar sobre cada envío en `$venta->shipments`
        foreach ($venta->shipments as $shipment) {
            // Validar si el envío ya fue entregado
            if ($shipment->status === "Shipment Delivered") {
                continue; // Omitir el envío ya procesado
            }

            if ($shipment->status === "Shipment Canceled") {
                $documento = DB::table('documento')->where('no_venta', $purchaseOrderId)->first();
                CorreoService::enviarMensaje($documento->id, "Envio Cancelado en Walmart");
                DB::table('seguimiento')->insertGetId([
                    'id_documento' => $documento->id,
                    'id_usuario' => 1,
                    'seguimiento' => "El envio esta cancelado. Envio a problemas"
                ]);
                continue; // Omitir este envío
            }

            $shipmentLines = [];

            // Iterar sobre todas las líneas dentro del envío
            foreach ($shipment->shipmentLines as $shipmentLine) {
                $shipmentLines[] = [
                    "primeLineNo" => $shipmentLine->primeLineNo ?? 0,
                    "shipmentLineNo" => $shipmentLine->shipmentLineNo ?? 0,
                    "quantity" => [
                        "unitOfMeasurement" => $shipmentLine->quantity->unitOfMeasurement ?? "EACH",
                        "amount" => $shipmentLine->quantity->amount ?? 1
                    ]
                ];
            }

            // Construir el envío
            $shipments[] = [
                "shipmentLines" => $shipmentLines,
                "carrier" => $shipment->carrier ?? "WALMART",
                "trackingNumber" => $shipment->trackingNumber ?? "",
                "trackingURL" => $shipment->trackingURL ?? ""
            ];
        }

// Construir el cuerpo de la solicitud
        $body = ["shipments" => $shipments];

        $request = \Httpful\Request::post($url)
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
            ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
            ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Accept', 'application/json')
            ->body(json_encode($body))
            ->send();

        $response->error = 0;
        $response->data = $request;

        return $response;
    }



    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'SE'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'MART'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
