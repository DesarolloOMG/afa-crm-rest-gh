<?php

namespace App\Http\Services;

use Httpful\Exception\ConnectionErrorException;
use Httpful\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mailgun\Mailgun;

class ShopifyService
{
    public static function venta($venta, $marketplace_id)
    {
        $response = new \stdClass();
        $response->error = 0;

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
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

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $request = \Httpful\Request::get($marketplace->extra_1 . "admin/api/2020-07/orders/" . $venta . ".json")
            ->addHeader('Authorization', "Basic " .  base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->send();

        $response_data = json_decode($request->raw_body);

        if (property_exists($response_data, 'errors')) {
            $response->error = 1;
            $response->mensaje = $response_data->errors;

            return $response;
        }

        $response->data = $response_data->order;

        return $response;
    }

    public static function importarVentasMasiva($marketplace_id)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
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

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $api = $marketplace->app_id;
        $token = $marketplace->secret;
        $path = str_replace('https://', '@', $marketplace->extra_1);


        $todasLasOrdenes = array();
        $url = "https://" . $api . ":" . $token . "" . $path . "admin/api/2024-01/orders.json?financial_status=paid";

        do {
            $respuestapi = \Httpful\Request::get($url)->send();
            $ventas_pending = @json_decode($respuestapi);

            if (!empty($ventas_pending->orders)) {
                $todasLasOrdenes = array_merge($todasLasOrdenes, $ventas_pending->orders);
            }

            $page_info = self::parse_next_page_url($respuestapi->raw_headers);
            if ($page_info !== null) {

                $url = "https://" . $api . ":" . $token . "" . $path . "admin/api/2024-01/orders.json?&page_info=" . $page_info;
            } else {
                $url = null;
            }
        } while ($url !== null);

        foreach ($todasLasOrdenes as $venta) {
            $venta->Error = 0;

            $existe_venta = DB::table("documento")
                ->where("no_venta", $venta->id)
                ->where("id_marketplace_area", $marketplace_id)
                ->first();

            if (!empty($existe_venta) || $existe_venta !== null) {
                $venta->Error = 1;
                $venta->ErrorMessage = "La venta " . $venta->id . " ya está registrada en el sistema.";
            }

            $productos = is_array($venta->line_items) ? $venta->line_items : [$venta->line_items];

            $importar = self::importarVentaIndividual($venta, $productos, $marketplace_id);

            if ($importar->error == 1) {
                $venta->Error = 1;
                $venta->ErrorMessage = $importar->mensaje;
            }
        }

        //        self::enviarEmailErroresImportacion($marketplace_id, $errores, "de importación de ventas de SHOPIFY " . date("Y-m-d H:i:s") . "");

        $response->code = 200;
        $response->message = "Pedidos Importados correctamente";
        $response->ventas = $todasLasOrdenes;

        return $response;
    }

    protected static function parse_next_page_url($raw_headers)
    {
        set_time_limit(0);
        $matches = [];
        if (preg_match('/<([^>]+)>; rel="next"/', $raw_headers, $matches)) {
            $url = $matches[1];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);
            if (isset($query_params['page_info'])) {
                return $query_params['page_info'];
            }
        }
        return null;
    }

    public static function importarVentaIndividual($venta_data, $productos, $marketplace_area)
    {
        $total_pago = 0;
        $response = new \stdClass();
        $response->error = 0;

        $existe_documento = DB::table("documento")
            ->where("no_venta", $venta_data->id)
            ->first();

        if (!empty($existe_documento)) {
            $response->error = 1;
            $response->mensaje = "Ya existe la venta";
            return $response;
        } else {
            $existe_documento_2 = DB::table("documento")
                ->where("no_venta", $venta_data->order_number)
                ->first();

            if (!empty($existe_documento_2)) {
                $response->error = 1;
                $response->mensaje = "Ya existe la venta";
                return $response;
            }
        }

        $entidad = DB::table('documento_entidad')->insertGetId([ //billing address
            'razon_social' => $venta_data->billing_address->name ?? 0,  //name
            'rfc' => mb_strtoupper('XAXX010101000', 'UTF-8'),
            'telefono' => $venta_data->billing_address->phone ?? 0, //phone
            'telefono_alt' => "0",
            'correo' => $venta_data->customer->email ?? 0 //customer-email
        ]);

        if (is_null($entidad)) {
            $response->error = 1;
            $response->mensaje = "No se creó la entidad";
        }

        $documento = DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_almacen_principal_empresa' => 133, //122 empresa de prueba
            'id_marketplace_area' => $marketplace_area, //35 arome-shopify
            'id_usuario' => 1, //1 omg
            'id_paqueteria' => $venta_data->paqueteria_id ?? 1, //omg 13
            'id_fase' => 1, //1 Pedido
            'id_modelo_proveedor' => 0, //null
            'id_entidad' => $entidad,
            'no_venta' => $venta_data->id ?? 0, //order_number
            'referencia' => $venta_data->order_number, //id
            'observacion' => "Pedido Importado " . $marketplace_area . " V3", //null
            'info_extra' => "N/A", //null
            'fulfillment' => 0, //0
            'comentario' => $venta_data->id ?? 0, //id
            'mkt_publicacion' => "N/A", //null
            'mkt_total' => $venta_data->current_total_price ?? 0, //current_total_price
            'mkt_fee' => 0,
            'mkt_coupon' => $venta_data->current_total_discounts ?? 0, //total_discounts
            'mkt_shipping_total' => $venta_data->total_shipping_price_set->shop_money->amount ?? 0, //total_shipping_price-shop_money-amount
            'mkt_created_at' => $venta_data->created_at ?? 0, //created_at
            'mkt_user_total' => 0, //0
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        if (is_null($documento)) {
            $response->error = 1;
            $response->mensaje = "No se creó el documento";
        }

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => "<h2>PEDIDO IMPORTADO AUTOMATICAMENTE</h2>"
        ]);

        $estado = DB::table('estados')->where('name', $venta_data->shipping_address->province)->first();

        $direccion = DB::table('documento_direccion')->insert([ //shipping_address
            'id_documento' => $documento,
            'id_direccion_pro' => 0,
            'contacto' => $venta_data->shipping_address->name ?? 0, //name
            'calle' => $venta_data->shipping_address->address1 ?? 0, //address1
            'numero' => "N/A",
            'numero_int' => "N/A",
            'colonia' => "N/A",
            'ciudad' => $venta_data->shipping_address->city ?? 0, //city
            'estado' => $estado->code2 ?? 0, //province
            'codigo_postal' => $venta_data->shipping_address->zip ?? 0, //zip
            'referencia' => $venta_data->shipping_address->address2 ?? 0 //address2
        ]);

        if (is_null($direccion)) {
            $response->error = 1;
            $response->mensaje = "No se creó la direccion";
        }

        foreach ($productos as $producto) {
            //validar primero el sku y obtener el id_modelo

            if ($producto->sku != "") {
                if ($producto->title != "TARJETA DE REGALO") {
                    $existe_modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                    if (!empty($existe_modelo)) {

                        $precioProducto = $producto->price / 1.16;

                        $movimiento = DB::table('movimiento')->insertGetId([
                            'id_documento' => $documento,
                            'id_modelo' => $existe_modelo->id,
                            'cantidad' => $producto->quantity ?? 0, //quantity
                            'precio' => $precioProducto, //price
                            'garantia' => 0, //null
                            'modificacion' => '',
                            'regalo' => '' //null
                        ]);

                        if (is_null($movimiento)) {
                            $response->error = 1;
                            $response->mensaje = "No se creó el movimiento del producto " . $producto->sku;
                        }

                        $total_pago += ($producto->quantity ?? 0) * $precioProducto;
                    } else {
                        $response->error = 1;
                        $response->mensaje = "No existe el producto en la bd " . $producto->sku;
                    }
                }
            }
        }

        if ($venta_data->total_shipping_price_set->shop_money->amount > 0) {
            $movimientoEnvio = DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => 22165,
                'cantidad' => 1, //quantity
                'precio' => $venta_data->total_shipping_price_set->shop_money->amount / 1.16, //price
                'garantia' => 0, //null
                'modificacion' => '',
                'regalo' => '' //null
            ]);

            if (is_null($movimientoEnvio)) {
                $response->error = 1;
                $response->mensaje = "No se creó el movimiento del gasto de envio";
            }

            $total_pago += $venta_data->total_shipping_price_set->shop_money->amount;
        }

        $pago = DB::table('documento_pago')->insertGetId([
            'id_usuario' => 1,
            'id_metodopago' => 31,
            'id_vertical' => 0,
            'id_categoria' => 0,
            'id_clasificacion' => 0,
            'tipo' => 1,
            'origen_importe' => 0,
            'destino_importe' => $total_pago - $venta_data->total_discounts, //puede ser total_price
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

        if (is_null($pago)) {
            $response->error = 1;
            $response->mensaje = "No se creó el pago";
        }

        DB::table('documento_pago_re')->insert([
            'id_documento' => $documento,
            'id_pago' => $pago
        ]);

        if ($response->error == 1) {
            DB::rollback();
        }

        return $response;
    }

    public static function crearGuiaEnvia($data, $creador, $email)
    {
        $paqueteria = DB::select("SELECT paqueteria FROM paqueteria WHERE id = " . $data->paqueteria . "");

        $estado = DB::table('estados')->where('name', mb_strtoupper($data->info_remitente->direccion->estado, 'UTF-8'))->first();

        $origen = array(
            'nombre' => $creador,
            'empresa' => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
            'email' => $email,
            'telefono' => mb_strtoupper($data->info_remitente->telefono, 'UTF-8'),
            'calle' => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
            'numero' => '',
            'colonia' => mb_strtoupper($data->info_remitente->direccion->colonia, 'UTF-8'),
            'ciudad' => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
            'estado' => $estado->code2,
            'cp' => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
            'referencia' => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8')
        );

        $estado2 = DB::table('estados')->where('name', mb_strtoupper($data->info_destinatario->direccion->estado, 'UTF-8'))->first();

        $destino = array(
            'nombre' => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
            'empresa' => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
            'email' => mb_strtoupper($data->info_destinatario->email, 'UTF-8'),
            'telefono' => mb_strtoupper($data->info_destinatario->telefono, 'UTF-8'),
            'calle' => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
            'numero' => '',
            'colonia' => mb_strtoupper($data->info_destinatario->direccion->colonia, 'UTF-8'),
            'ciudad' => mb_strtoupper($data->info_destinatario->direccion->ciudad, 'UTF-8'),
            'estado' => $estado2->code2,
            'cp' => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
            'referencia' => mb_strtoupper($data->info_destinatario->direccion->referencia, 'UTF-8')
        );

        $paqueteria = $paqueteria[0];

        $palabra = explode(' ', $paqueteria->paqueteria);

        $nombre_paqueteria = end($palabra);

        ///
        $array_shopify = array(
            'paqueteria' => $nombre_paqueteria,
            'servicio' => $data->tipo_envio,
            'tipo_envio' => 1,
            'comentarios' => "N/A",
            'asegurado' => $data->seguro > 0 ? 1 : 0,
            'seguro_valor' => $data->seguro,
            'contenido' => mb_strtoupper($data->contenido, 'UTF-8'),
            'referencia' => "N/A",
            'peso' => $data->peso,
            'alto' => $data->alto,
            'ancho' => $data->ancho,
            'largo' => $data->largo,
            'origen' => $origen,
            'destino' => $destino
        );

        return \Httpful\Request::post(config("webservice.paqueterias") . 'api/Envia/CrearGuia')
            ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
            ->body($array_shopify, \Httpful\Mime::FORM)
            ->send();
    }

    public static function crearGuia($documento, $direccion, $informacion, $usuario_data, $informacion_cliente, $informacion_empresa)
    {
        $info_documento = DB::table('documento')->where('id', $documento)->first();

        $destino_telefono = empty($informacion_cliente->telefono) ? "1" : (is_numeric(trim($informacion_cliente->telefono)) ? ((float) $informacion_cliente->telefono < 1 ? "1" : $informacion_cliente->telefono) : "1");

        $origen = array(
            'nombre' => $usuario_data->nombre ?? "Sin Nombre",
            'empresa' => $informacion_empresa->empresa ?? "Sin empresa",
            'email' => $usuario_data->email,
            'telefono' => "3336151770",
            'calle' => "INDUSTRIA MADERERA",
            'numero' => "226A",
            'colonia' => "ZAPOPAN INDUSTRIAL NORTE",
            'ciudad' => "ZAPOPAN",
            'estado' => "JA",
            'cp' => "45130",
            'referencia' => "ENTRE INDUSTRIA TEXTIL E INDUSTRIA VIDRIERA"
        );

        $estado = DB::table('estados')->where('name', $direccion->estado)->first();

        $destino = array(
            'nombre' => $direccion->contacto,
            'empresa' => $informacion_cliente->razon_social,
            'email' => $informacion_cliente->correo,
            'telefono' => $destino_telefono,
            'calle' => $direccion->calle,
            'numero' => $direccion->numero,
            'colonia' => $direccion->colonia,
            'ciudad' => $direccion->ciudad,
            'estado' => $estado->code2,
            'cp' => $direccion->codigo_postal,
            'referencia' => $direccion->referencia
        );

        $palabra = explode(' ', $informacion->paqueteria);
        $nombre_paqueteria = end($palabra);

        ///
        $array_shopify = array(
            'paqueteria' => $nombre_paqueteria,
            'servicio' => $direccion->tipo_envio,
            'tipo_envio' => 1,
            'comentarios' => $info_documento->no_venta . "-" . $documento,
            'asegurado' => 0,
            'seguro_valor' => 0,
            'contenido' => $direccion->contenido,
            'referencia' => $info_documento->referencia,
            'peso' => 1,
            'alto' => 15,
            'ancho' => 15,
            'largo' => 20,
            'origen' => $origen,
            'destino' => $destino
        );

        return \Httpful\Request::post(config("webservice.paqueterias") . 'api/Envia/CrearGuia')
            ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
            ->body($array_shopify, \Httpful\Mime::FORM)
            ->send();
    }

    public static function cotizarGuia($documento, $direccion, $usuario_data, $informacion_cliente, $informacion_empresa)
    {
        $info_documento = DB::table('documento')->where('id', $documento)->first();

        $destino_telefono = empty($informacion_cliente->telefono) ? "1" : (is_numeric(trim($informacion_cliente->telefono)) ? ((float) $informacion_cliente->telefono < 1 ? "1" : $informacion_cliente->telefono) : "1");

        $origen = array(
            'nombre' => $usuario_data->nombre ?? "Sin Nombre",
            'empresa' => $informacion_empresa->empresa ?? "Sin empresa",
            'email' => $usuario_data->email,
            'telefono' => "3336151770",
            'calle' => "INDUSTRIA MADERERA",
            'numero' => "226A",
            'colonia' => "ZAPOPAN INDUSTRIAL NORTE",
            'ciudad' => "ZAPOPAN",
            'estado' => "JA",
            'cp' => "45130",
            'referencia' => "ENTRE INDUSTRIA TEXTIL E INDUSTRIA VIDRIERA"
        );

        $estado = DB::table('estados')->where('name', $direccion->estado)->first();

        $destino = array(
            'nombre' => $direccion->contacto,
            'empresa' => $informacion_cliente->razon_social,
            'email' => $informacion_cliente->correo,
            'telefono' => $destino_telefono,
            'calle' => $direccion->calle,
            'numero' => $direccion->numero,
            'colonia' => $direccion->colonia,
            'ciudad' => $direccion->ciudad,
            'estado' => $estado->code2,
            'cp' => $direccion->codigo_postal,
            'referencia' => $direccion->referencia
        );

        ///
        $array_shopify = array(
            'comentarios' => $info_documento->no_venta . "-" . $documento,
            'asegurado' => 0,
            'seguro_valor' => 0,
            'contenido' => $direccion->contenido,
            'referencia' => $info_documento->referencia,
            'peso' => 1,
            'alto' => 15,
            'ancho' => 15,
            'largo' => 20,
            'origen' => $origen,
            'destino' => $destino
        );

        $cotizacion = \Httpful\Request::post(config("webservice.paqueterias") . 'api/Envia/Cotizar')
            ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
            ->body($array_shopify, \Httpful\Mime::FORM)
            ->send();

        $cotizacion_raw = $cotizacion->raw_body;
        $cotizacion = @json_decode($cotizacion_raw);

        return $cotizacion;
    }

    public static function actualizarPedido($marketplace_id, $orden, $guia)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
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

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $api = $marketplace->app_id;
        $token = $marketplace->secret;
        $path = str_replace('https://', '@', $marketplace->extra_1);

        $ainfo_full = self::obtener_info_full($orden, $marketplace_id);
        $info_full = json_decode($ainfo_full);

        $json = [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => [
                    [
                        'fulfillment_order_id' => $info_full->fulfillment_orders[0]->id
                    ]
                ],
                'fulfillment_order_line_items' => []
            ]
        ];

        foreach ($info_full->fulfillment_orders[0]->line_items as $line_item) {
            $json['fulfillment']['fulfillment_order_line_items'][] = [
                'id' => $line_item->id,
                'quantity' => $line_item->quantity
            ];
        }

        $paqueteria = DB::table('manifiesto')->where('manifiesto.guia', $guia)
            ->join('paqueteria', 'paqueteria.id', '=', 'manifiesto.id_paqueteria')
            ->select('paqueteria.paqueteria', 'paqueteria.id')->first();

        $palabra = explode(' ', $paqueteria->paqueteria);
        $nombre_paqueteria = end($palabra);

        $json['fulfillment']['tracking_info'] = [
            'number' => $guia,
            'url' =>  $paqueteria->id > 100 ? "https://envia.com/rastreo?label=" . $guia . "&cntry_code=mx" : '',
            'company' => $nombre_paqueteria
        ];
        return \Httpful\Request::post("https://" . $api . ":" . $token . "" . $path . "admin/api/2023-07/fulfillments.json")
            ->sendsJson()  // Indica que estás enviando JSON
            ->body(json_encode($json))  // Convierte el arreglo asociativo en JSON
            ->send();
    }

    public static function obtener_info_full($orden, $marketplace_id)
    {
        $response = new \stdClass();

        //!Sacar las chidas
        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
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

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $api = $marketplace->app_id;
        $token = $marketplace->secret;
        $path = str_replace('https://', '@', $marketplace->extra_1);

        $raw_ventas_pending = self::request_data_full($api, $token, $orden, $path);

        if (empty($raw_ventas_pending)) {
            $response->error = 1;
            $response->mensaje = "No fue posible obtener el fullfilment order de Shopify, favor de contactar a un administrador. Error: SS1";
            $response->raw = $raw_ventas_pending;

            return $response;
        }

        return $raw_ventas_pending;
    }

    public static function enviarEmailErroresImportacion($marketplace_id, $errores, $titulo_email)
    {
        $emails = "";

        $view = view('email.notificacion_error_importacion_shopify')->with([
            "errores" => $errores,
            "anio" => date("Y")
        ]);

        $usuarios = DB::select("SELECT
                                usuario.email
                            FROM usuario
                            INNER JOIN usuario_marketplace_area ON usuario.id = usuario_marketplace_area.id_usuario
                            WHERE usuario_marketplace_area.id_marketplace_area = " . $marketplace_id . "
                            AND usuario.status = 1
                            GROUP BY usuario.email");

        foreach ($usuarios as $usuario) {
            $emails .= $usuario->email . ";";
        }

        $emails = substr($emails, 0, -1);

        $mg = Mailgun::create(config("mailgun.token"));
        $mg->sendMessage(config("mailgun.domain"), array(
            'from' => config("mailgun.email_from"),
            'to' => $emails,
            'subject' => 'Reporte ' . $titulo_email,
            'html' => $view
        ));
    }

    private static function request_data($parameters, $api, $token, $path)
    {
        // Sort parameters by name.
        ksort($parameters);

        // URL encode the parameters.
        $encoded = array();
        foreach ($parameters as $name => $value) {
            $encoded[] = rawurlencode($name) . '=' . rawurlencode((is_array($value)) ? json_encode(array_values($value)) : $value);
        }

        // Concatenate the sorted and URL encoded parameters into a string.
        $concatenated = implode('&', $encoded);

        // Replace with the URL of your API host. 
        $url = "https://" . $api . ":" . $token . "" . $path . "admin/api/2023-07/orders.json?" . $concatenated;

        // Build Query String
        $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $response = \Httpful\Request::get($url)->send();

        return $response;
    }

    private static function request_data_full($api, $token, $order, $path)
    {
        // Replace with the URL of your API host.
        $url = "https://" . $api . ":" . $token . "" . $path . "admin/api/2023-07/orders/" . $order . "/fulfillment_orders.json";

        $response = \Httpful\Request::get($url)->send();

        return $response;
    }
}
