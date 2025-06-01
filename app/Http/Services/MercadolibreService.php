<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Services;

use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MP;
use ZipArchive;

class MercadolibreService
{
    public static function venta($venta, $marketplace_id)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace->app_id, $marketplace->secret);
        //$seller = self::seller($marketplace->extra_2, $token);

        $venta = str_replace("%20", " ", $venta);
        $ventas = [];

        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($opts);

        //$informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->seller->id . "&q=" . rawurlencode($venta) . "&sort=date_desc&access_token=" . $token));
        $informacion_paquete = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "packs/" . rawurlencode($venta), false, $context));

        if (empty($informacion_paquete)) {
            $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . rawurlencode($venta), false, $context));

            if (empty($informacion_venta)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . $log;

                return $response;
            }

            array_push($ventas, $informacion_venta);
        }
        else {
            foreach ($informacion_paquete->orders as $venta_paquete) {
                $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . rawurlencode($venta_paquete->id), false, $context));

                if (empty($informacion_venta)) {
                    $log = self::logVariableLocation();

                    $response->error = 1;
                    $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . $log;

                    return $response;
                }

                array_push($ventas, $informacion_venta);
            }
        }

        foreach ($ventas as $venta) {
            $venta->productos = array();

            $pack_id = explode(".", empty($venta->pack_id) ? $venta->id : sprintf('%lf', $venta->pack_id))[0];

            $envio = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta->id . "/shipments?access_token=" . $token));
            $mensajes = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "messages/packs/" . $pack_id . "/sellers/" . $venta->seller->id . "?access_token=" . $token));

            $venta->mensajes = empty($mensajes) ? [] : $mensajes->messages;

            if (!empty($envio)) {
                if ($envio->status == "to_be_agreed" || $envio->status == "shipping_deferred") {
                    $venta->shipping = 0;
                } else {
                    $envio->costo = $envio->shipping_option->cost;
                    $venta->shipping = $envio;
                }
            } else {
                $venta->shipping = 0;
            }

            foreach ($venta->payments as $payment) {
                $detalle_pago = @json_decode(file_get_contents("https://api.mercadopago.com/v1/payments/" . $payment->id . "?access_token=" . $token));

                $payment->more_details = $detalle_pago;
            }

            foreach ($venta->order_items as $item) {
                $existe_publicacion = DB::select("SELECT
                                                    marketplace_publicacion.id,
                                                    empresa_almacen.id_almacen
                                                FROM marketplace_publicacion 
                                                INNER JOIN empresa_almacen ON marketplace_publicacion.id_almacen_empresa = empresa_almacen.id
                                                WHERE publicacion_id = '" . $item->item->id . "'");

                if (!empty($existe_publicacion)) {
                    $productos_publicacion = DB::select("SELECT
                                                            marketplace_publicacion_producto.id_modelo,
                                                            marketplace_publicacion_producto.garantia,
                                                            (marketplace_publicacion_producto.cantidad * " . $item->quantity . ") AS cantidad,
                                                            marketplace_publicacion_producto.regalo,
                                                            modelo.sku,
                                                            modelo.descripcion
                                                        FROM marketplace_publicacion_producto 
                                                        INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                                        WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                    if (!empty($productos_publicacion)) {
                        $existe_publicacion[0]->productos = $productos_publicacion;

                        array_push($venta->productos, $existe_publicacion[0]);
                    }
                }
            }
        }

        $response->error = 0;
        $response->data = $ventas;

        return $response;
    }

    public static function venta2($venta, $marketplace_id)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);

        $venta = str_replace("%20", " ", $venta);

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . rawurlencode($venta) . "&sort=date_desc&access_token=" . $token));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . $log . "" . $venta;

            return $response;
        }

        if (empty($informacion_venta->results)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "La venta no fue encontrada en los sistemas de Mercadolibre." . $log;

            return $response;
        }

        $response->data = $informacion_venta->results;

        return $response;
    }

    public static function ventas($credenciales, $publicacion)
    {
        set_time_limit(0);

        $response = new \stdClass();
        $ventas = array();
        $packs = array();

        $token = self::token($credenciales->app_id, $credenciales->secret);
        $seller = self::seller($credenciales->extra_2, $token);

        $fecha_inicial = date("Y-m-d\T00:00:00.000\Z", strtotime($credenciales->fecha_inicio));
        $fecha_final = date("Y-m-d\T00:00:00.000\Z", strtotime($credenciales->fecha_final . " +1 day"));

        $publicaciones_full = 0;

        $ventas = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&order.date_created.from=" . $fecha_inicial . "&order.date_created.to=" . $fecha_final . "&search_type=scan&scroll_id=" . $scroll_id . "&access_token=" . $token));

        $limite = $ventas->paging->limit;
        $total_ciclo = ceil($ventas->paging->total / $limite);
        $offset = 0;

        for ($i = 0; $i < $total_ciclo; $i++) {
            $ventas = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&offset=" . $offset . "&order.date_created.from=" . $fecha_inicial . "&order.date_created.to=" . $fecha_final . "&scroll_id=" . $scroll_id . "&access_token=" . $token));

            if (empty($ventas)) {
                break;
            }

            foreach ($ventas->results as $venta) {
                $existe_pack = 0;

                $venta->pack_id = explode(".", empty($venta->pack_id) ? $venta->id : sprintf('%lf', $venta->pack_id))[0];
                $venta->shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta->id . "/shipments?access_token=" . $token));

                if (empty($venta->shipping)) continue;

                if (!property_exists($venta->shipping, "logistic_type")) continue;

                # if ($venta->shipping->logistic_type != "fulfillment") continue;

                foreach ($packs as $pack) {
                    if ($venta->pack_id === $pack->id) {
                        array_push($pack->ventas, $venta);

                        $existe_pack = 1;
                    }
                }

                if (!$existe_pack) {
                    $pack_object = new \stdClass();
                    $pack_object->id = $venta->pack_id;
                    $pack_object->es_paquete = !($venta->pack_id == $venta->id);
                    $pack_object->ventas = array();

                    array_push($pack_object->ventas, $venta);
                    array_push($packs, $pack_object);
                }
            }

            $offset += $limite;
        }

        foreach ($packs as $pack) {
            $ventas = array();

            foreach ($pack->ventas as $venta) {
                $venta_data = new \stdClass();

                if ($venta->status === "cancelled") {
                    $venta_data->cancelada = true;
                } else {
                    $venta_data->cancelada = false;
                }

                if (property_exists($venta, "mediations")) {
                    foreach ($venta->mediations as $mediation) {
                        $informacion_mediacion = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "v1/claims/" . $mediation->id . "?access_token=" . $token . ""));

                        if (!empty($informacion_mediacion)) {
                            if (strpos($informacion_mediacion->status, 'cancel') !== false) {
                                $venta_data->cancelada = true;
                            } else {
                                $venta_data->cancelada = false;
                            }
                        }
                    }
                }

                $productos = array();

                foreach ($venta->order_items as $item) {
                    $atributos = "";
                    $categoria = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "categories/" . $item->item->category_id));

                    foreach ($item->item->variation_attributes as $atributo) {
                        $atributos .= $atributo->name . ": " . $atributo->value_name . "\n";
                    }

                    $producto_data = new \stdClass();
                    $producto_data->id = $item->item->id;
                    $producto_data->titulo = $item->item->title;
                    $producto_data->cantidad = $item->quantity;
                    $producto_data->categoria = empty($categoria) ? "" : $categoria->name;
                    $producto_data->atributos = $atributos;

                    array_push($productos, $producto_data);
                }

                $venta_data->venta = $venta->id;
                $venta_data->total = $venta->total_amount;
                $venta_data->cliente = $venta->buyer->nickname;
                $venta_data->fecha = $venta->date_closed;
                $venta_data->reclamos = property_exists($venta, "mediations") ? (empty($venta->mediations) ? 0 : 1) : 0;
                $venta_data->productos = $productos;
                $venta_data->canal_venta = $venta->context->site;

                if ($venta->shipping) {
                    $venta_data->paqueteria = property_exists($venta->shipping, 'tracking_method') ? $venta->shipping->tracking_method : '';
                    $venta_data->guia = property_exists($venta->shipping, 'tracking_number') ? $venta->shipping->tracking_number : '';
                    $venta_data->fulfillment = property_exists($venta->shipping, 'logistic_type') ? ($venta->shipping->logistic_type == 'fulfillment' ? 1 : 0) : '';
                }

                array_push($ventas, $venta_data);
            }

            $pack->ventas = $ventas;
        }

        return $packs;
    }

    public static function importarVentas($marketplace_id, $publicacion_id, $fecha_inicial = "Y-m-01\T00:00:00.000\Z", $fecha_final = "Y-m-d\T00:00:00.000\Z")
    {
        set_time_limit(0);

        $response = new \stdClass();
        $error = array();
        $datas = array();

        $marketplace_info = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.extra_2,
                                            marketplace_api.app_id,
                                            marketplace_api.secret
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace_info)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

            return $response;
        }

        if (!file_exists("logs")) {
            mkdir("logs", 777);
            mkdir("logs/mercadolibre", 777);
        }

        $marketplace_info = $marketplace_info[0];

        $token = self::token($marketplace_info->app_id, $marketplace_info->secret);
        $seller = self::seller($marketplace_info->extra_2, $token);

        if (empty($seller)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del pseudonimo de la cuenta, favor de contactar al administrador." . $log;

            return $response;
        }

        $fecha_inicial = date("Y-m-d\T00:00:00.000\Z", strtotime($fecha_inicial . "-1 day"));
        $fecha_final = date("Y-m-d\T00:00:00.000\Z", strtotime($fecha_final . "+1 day"));

        $url = config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&access_token=" . $token;

        if (!empty($publicacion_id)) {
            $url .= "&q=" . $publicacion_id;
        } else {
            $url .= "&order.date_created.from=" . $fecha_inicial . "&order.date_created.to=" . $fecha_final;
        }

        $ventas = json_decode(file_get_contents($url));
        $limite = $ventas->paging->limit;
        $total_ciclo = ceil($ventas->paging->total / $limite);
        $offset = 0;
        $packs = array();
        # Agrupar ventas por PACK ID
        for ($i = 0; $i < $total_ciclo + 1; $i++) {
            $ventas = @json_decode(file_get_contents($url . "&offset=" . $offset));

            if (empty($ventas)) {
                break;
            }

            foreach ($ventas->results as $venta) {
                $existe_pack = 0;

                $venta->pack_id = explode(".", empty($venta->pack_id) ? $venta->id : sprintf('%lf', $venta->pack_id))[0];
                $venta->shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta->id . "/shipments?access_token=" . $token));

                if ($venta->shipping) {
                    $venta->is_buffered = $venta->shipping->status === 'pending' && $venta->shipping->substatus === 'buffered';
                    $venta->is_manufacturing = $venta->shipping->status === 'pending' && $venta->shipping->substatus === 'manufacturing';
                    $venta->is_creating_route = $venta->shipping->status === 'pending' && $venta->shipping->substatus === 'creating_route';
                    $venta->is_ready_to_ship = $venta->shipping->status === 'ready_to_ship' && $venta->shipping->substatus === 'ready_to_print';
                    $venta->is_delivered = $venta->shipping->status === 'delivered';
                } else {
                    $venta->is_buffered = $venta->is_manufacturing = $venta->is_ready_to_ship = $venta->is_creating_route = $venta->is_delivered = false;
                }

                foreach ($packs as $pack) {
                    if ($venta->pack_id === $pack->id) {
                        array_push($pack->ventas, $venta);

                        $existe_pack = 1;
                    }
                }

                if (!$existe_pack) {
                    $pack_object = new \stdClass();
                    $pack_object->id = $venta->pack_id;
                    $pack_object->ventas = array();

                    array_push($pack_object->ventas, $venta);
                    array_push($packs, $pack_object);
                }
            }

            $offset += $limite;
        }

        # Validar diferentes parametros de cada una de las ventas en el paquete, existencia, que no exista la venta, etc.
        foreach ($packs as $pack) {
            $pack->error = 0;
            $pack->mensaje = "";
            $pack->ventas_relacionadas = "";

            $existe_pack = DB::select("SELECT id FROM documento WHERE comentario = '" . $pack->id . "' AND status = 1");

            if (!empty($existe_pack)) {

                $venta_p = $pack->ventas[0];

                if ($venta_p->is_delivered) {
                    self::actualizarDelivered_com($existe_pack);

                    $pack->error = 1;
                    $pack->mensaje = "El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a delivered. Marketplace: ";

                    file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a delivered." . PHP_EOL, FILE_APPEND);

                    continue;
                }

                if ($venta_p->is_ready_to_ship) {
                    self::actualizarRTS_com($existe_pack);

                    $pack->error = 1;
                    $pack->mensaje = "El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a ready to ship.";

                    file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a ready to ship. " . PHP_EOL, FILE_APPEND);

                    continue;
                }

                $pack->error = 1;
                $pack->mensaje = "El pack " . $pack->id . " ya ha sido importado";

                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: El pack " . $pack->id . " ya ha sido importado " . PHP_EOL, FILE_APPEND);

                continue;
            }

            $venta_principal = $pack->ventas[0];
            $venta_principal->productos = array();
            $venta_principal->almacen = 0;
            $venta_principal->proveedor = 0;
            $venta_principal->fase = 1;
            $venta_principal->error = 0;
            $venta_principal->total_fee = 0;
            $venta_principal->total_envio = 0;

            $pack->venta_principal = $venta_principal;

            foreach ($pack->ventas as $venta) {
                $pack->ventas_relacionadas .= $venta->id . ",";
                $pack->venta_principal->publicacion = $venta->order_items[0]->item->id;
                $pack->venta_principal->pack = $pack->id;
                $pack->venta_principal->total_envio = is_object($venta->shipping) ? (property_exists($venta->shipping, "shipping_option") ? $venta->shipping->shipping_option->list_cost : 0) : 0;

                if (!empty($venta->mediations)) {
                    foreach ($venta->mediations as $mediation) {
                        $informacion_mediacion = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "v1/claims/" . $mediation->id . "?access_token=" . $token . ""));

                        if (!empty($informacion_mediacion)) {
                            if ($informacion_mediacion->status != "claim_closed" && $informacion_mediacion->status != "dispute_closed") {
                                $pack->error = 1;
                                $pack->mensaje = "La venta " . $venta->id . " contiene un reclamo en la plataforma de Mercadolibre.";

                                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " contiene un reclamo en la plataforma de Mercadolibre. " . PHP_EOL, FILE_APPEND);

                                unset($pack->ventas);

                                continue 3;
                            }
                        }
                    }
                }

                $total_productos = 0;
                $existe_venta = DB::select("SELECT id FROM documento WHERE no_venta = '" . $venta->id . "'");

                if (!empty($existe_venta)) {

                    if ($venta->is_delivered) {
                        self::actualizarDelivered_doc_o($venta->id);
                        $pack->error = 1;
                        $pack->mensaje = "El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a delivered.";

                        file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya ha sido importado anteriormente, se detectó cambio a delivered, pedido: " . $existe_venta[0]->id . " " . PHP_EOL, FILE_APPEND);

                        unset($pack->ventas);

                        continue 2;
                    }

                    if ($venta->is_ready_to_ship) {
                        self::actualizarRTS_doc_o($venta->id);
                        $pack->error = 1;
                        $pack->mensaje = "El pack " . $pack->id . " ya ha sido importado anteriormente, se detectó cambio a ready to ship.";

                        file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya ha sido importado anteriormente, se detectó cambio a ready to ship, pedido: " . $existe_venta[0]->id . " " . PHP_EOL, FILE_APPEND);

                        unset($pack->ventas);

                        continue 2;
                    }

                    $pack->error = 1;
                    $pack->mensaje = "La venta " . $venta->id . " ya existe registrada en el sistema, pedido: " . $existe_venta[0]->id . "";


                    file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya existe en el sistema, pedido: " . $existe_venta[0]->id . " " . PHP_EOL, FILE_APPEND);

                    unset($pack->ventas);

                    continue 2;
                }

                if (empty($venta->shipping)) {
                    $pack->error = 1;
                    $pack->mensaje = "No se encontró información del envio en los sistemas de mercadolibre de la venta " . $venta->id . "";

                    file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: No se encontró información del envio en los sistemas de mercadolibre de la venta " . $venta->id . " " . PHP_EOL, FILE_APPEND);

                    unset($pack->ventas);

                    continue 2;
                }
                if ($venta->shipping) {
                    if ($venta->shipping->status == "pending") {
                        $pack->venta_principal->fase = 1;
                    }
                }

                foreach ($venta->order_items as $item) {
                    $pack->venta_principal->total_fee += (float)$item->sale_fee * (int)$item->quantity;

                    $existe_publicacion = DB::select("SELECT 
                                                            marketplace_publicacion.id, 
                                                            marketplace_publicacion.id_almacen_empresa,
                                                            marketplace_publicacion.id_almacen_empresa_fulfillment,
                                                            marketplace_publicacion.id_proveedor,
                                                            marketplace_publicacion.shipping_null,
                                                            empresa.bd,
                                                            empresa_almacen.id_erp AS id_almacen
                                                    FROM marketplace_publicacion 
                                                    INNER JOIN empresa_almacen ON marketplace_publicacion.id_almacen_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    WHERE publicacion_id = '" . $item->item->id . "'");

                    if (empty($existe_publicacion)) {
                        $pack->error = 0;
                        $pack->venta_principal->seguimiento = "No se encontró la publicación de la venta " . $venta->id . " registrada en el sistema, por lo tanto, no hay relación de productos " . $item->item->id . "";
                        $pack->venta_principal->fase = 1;
                        $pack->venta_principal->error = 1;

                        file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: No se encontró la publicación de la venta " . $venta->id . " registrada en el sistema, por lo tanto, no hay relación de productos " . $item->item->id . " " . PHP_EOL, FILE_APPEND);

                        unset($pack->ventas);

                        continue 3;
                    }

                    $existe_publicacion = $existe_publicacion[0];
                    $pack->venta_principal->shipping_null = $existe_publicacion->shipping_null ?? 0;

                    $extra_query = !is_null($item->item->variation_id) ? " AND etiqueta = '" . $item->item->variation_id . "'" : "";
                    $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $existe_publicacion->id . $extra_query);

                    if (empty($productos_publicacion)) {
                        $pack->error = 0;
                        $pack->venta_principal->seguimiento = "No hay relación entre productos y la publicación " . $item->item->id . " en la venta " . $venta->id . "";
                        $pack->venta_principal->fase = 1;
                        $pack->venta_principal->error = 1;

                        file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: No hay relación entre productos y la publicación " . $item->item->id . " en la venta " . $venta->id . " " . PHP_EOL, FILE_APPEND);

                        unset($pack->ventas);

                        continue 3;
                    }

                    $porcentaje_total = 0;

                    foreach ($productos_publicacion as $producto) {
                        $porcentaje_total += $producto->porcentaje;
                    }

                    if ($porcentaje_total != 100) {
                        $pack->error = 0;
                        $pack->venta_principal->seguimiento = "Los productos de la publicación " . $item->item->id . " no suman un porcentaje total de 100%.";
                        $pack->venta_principal->fase = 1;
                        $pack->venta_principal->error = 1;

                        file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: Los productos de la publicación " . $item->item->id . " no suman un porcentaje total de 100%. " . PHP_EOL, FILE_APPEND);

                        unset($pack->ventas);

                        continue 3;
                    }

                    $pack->venta_principal->almacen = property_exists($venta->shipping, 'logistic_type') ? ($venta->shipping->logistic_type == 'fulfillment' ? $existe_publicacion->id_almacen_empresa_fulfillment : $existe_publicacion->id_almacen_empresa) : $existe_publicacion->id_almacen_empresa;
                    $pack->venta_principal->proveedor = $existe_publicacion->id_proveedor;

                    # La publicacion es dropshipping, y no se checa existencias
                    if ($existe_publicacion->id_proveedor == 0) {
                        foreach ($productos_publicacion as $producto) {
                            $producto->precio = round(($producto->porcentaje * $item->unit_price / 100) / $producto->cantidad, 6);
                            $producto->cantidad = $producto->cantidad * $item->quantity;

                            $producto_sku = DB::select("SELECT sku FROM modelo WHERE id = " . $producto->id_modelo . "")[0]->sku;

                            $existencia = DocumentoService::existenciaProducto($producto_sku, $pack->venta_principal->almacen);
                            $empresa_info = DB::table("empresa_almacen")->where("id", $pack->venta_principal->almacen)->first();
                            $empresa_bd = DB::table("empresa")->where("id", $empresa_info->id_empresa)->first();

                            $pendientes_bo = DB::select("SELECT
                                                        IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                    FROM documento
                                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto_sku . "'
                                                    AND empresa.bd = " . $empresa_bd->bd . "
                                                    AND empresa_almacen.id_erp = " . $empresa_info->id_erp . "
                                                    AND documento.id_tipo = 2
                                                    AND documento.status = 1
                                                    AND documento.id_fase = 1")[0]->cantidad;

                            $pendientes_surtir = DB::select("SELECT
                                                        IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                    FROM documento
                                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto_sku . "'
                                                    AND empresa.bd = " . $empresa_bd->bd . "
                                                    AND empresa_almacen.id_erp = " . $empresa_info->id_erp . "
                                                    AND documento.id_tipo = 2
                                                    AND documento.status = 1
                                                    AND documento.anticipada = 0
                                                    AND documento.id_fase IN (2, 3)")[0]->cantidad;

                            $pendientes_importar = DB::select("SELECT
                                                    IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                FROM documento
                                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE modelo.sku = '" . $producto_sku . "'
                                                AND empresa.bd = " . $empresa_bd->bd . "
                                                AND empresa_almacen.id_erp = " . $empresa_info->id_erp . "
                                                AND documento.id_tipo = 2
                                                AND documento.status = 1
                                                AND documento.anticipada = 0
                                                AND documento.id_fase BETWEEN 4 AND 5")[0]->cantidad;

                            $pendientes_pretransferencia = DB::select("SELECT
                                                                IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                            FROM documento
                                                            INNER JOIN empresa_almacen ON documento.id_almacen_secundario_empresa = empresa_almacen.id
                                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                            WHERE modelo.sku = '" . $producto_sku . "'
                                                            AND empresa.bd = " . $empresa_bd->bd . "
                                                            AND empresa_almacen.id_erp = " . $empresa_info->id_erp . "
                                                            AND documento.id_tipo = 9
                                                            AND documento.status = 1
                                                            AND documento.id_fase IN (401, 402, 403, 404)")[0]->cantidad;


                            if ($existencia->error) {
                                $pack->error = 0;
                                $pack->venta_principal->seguimiento = "Ocurrió un error al buscar la existencia del producto " . $producto_sku . " en la venta " . $venta->id . ", mensaje de error: " . $existencia->mensaje . "";
                                $pack->venta_principal->fase = 1;
                                $pack->venta_principal->error = 1;

                                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: Ocurrió un error al buscar la existencia del producto " . $producto_sku . " en la venta " . $venta->id . ", mensaje de error: " . $existencia->mensaje . " " . PHP_EOL, FILE_APPEND);

                                unset($pack->ventas);

                                continue 3;
                            }

                            $existencia_real = $existencia->existencia - $pendientes_bo - $pendientes_surtir - $pendientes_importar - $pendientes_pretransferencia;

                            if ((int)$existencia->existencia < (int)$producto->cantidad) {
                                $pack->error = 0;
                                $pack->venta_principal->seguimiento = "No hay suficiente existencia para procesar la venta " . $venta->id . " en el almacén " . $pack->venta_principal->almacen . " del producto " . $producto_sku . "";
                                $pack->venta_principal->fase = 1;
                                $pack->venta_principal->error = 1;

                                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: No hay suficiente existencia para procesar la venta " . $venta->id . " en el almacén " . $pack->venta_principal->almacen . " del producto " . $producto_sku . " " . PHP_EOL, FILE_APPEND);

                                unset($pack->ventas);

                                continue 3;
                            }
                        }
                    } else {
                        foreach ($productos_publicacion as $producto) {
                            $producto->precio = round(($producto->porcentaje * $item->unit_price / 100) / $producto->cantidad, 6);
                            $producto->cantidad = $producto->cantidad * $item->quantity;

                            $existe_en_proveedor = DB::table("modelo_proveedor_producto")
                                ->where("id_modelo", $producto->id_modelo)
                                ->where("id_modelo_proveedor", $existe_publicacion->id_proveedor)
                                ->first();

                            if (!$existe_en_proveedor) {
                                $pack->error = 0;
                                $pack->venta_principal->seguimiento = "No existe relación entre productos de la publicación y codigos de proveedor, favor de crear la relación para poder continuar con el proceso.";
                                $pack->venta_principal->fase = 1;
                                $pack->venta_principal->error = 1;

                                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . "-" . $publicacion_id . ".log", date("H:i:s") . " Error: No existe relación entre productos de la publicación y codigos de proveedor de la venta " . $venta->id . " " . PHP_EOL, FILE_APPEND);

                                unset($pack->ventas);

                                continue 3;
                            }
                        }
                    }

                    $pack->venta_principal->productos = array_merge($pack->venta_principal->productos, $productos_publicacion);
                    $pack->venta_principal->fase = property_exists($venta->shipping, 'logistic_type') ? ($venta->shipping->logistic_type == 'fulfillment' ? 6 : 3) : 1;

                    if ($pack->venta_principal->almacen == 34) {
                        $pack->venta_principal->fase = 1;
                        $pack->venta_principal->error = 1;
                    }
                }

                unset($pack->ventas);
            }
        }

        # Importar el paquete de ventas que no dió error en las validaciones
        foreach ($packs as $pack) {
            $pack->documento = 'N/A';

            if ($pack->error) {
                continue;
            }

            $response_venta = self::importarVenta($pack->venta_principal, $marketplace_id, 1);

            if ($response_venta->error) {
                $pack->error = 1;
                $pack->mensaje = "No se pudieron procesar las ventas relacionadas al paquete " . $pack->id . ", mensaje de error " . $response_venta->mensaje . "";

                continue;
            }

            $pack->mensaje = $response_venta->mensaje;
            $pack->documento = $response_venta->documento;
        }

        return [$packs, $url];
    }

    public static function importarVenta($venta, $marketplace, $usuario)
    {
        $response = new \stdClass();

        $existe_venta = DB::select("SELECT id FROM documento WHERE no_venta = '" . $venta->id . "' AND status = 1");
        if ($venta->id == 2000009252429830) {
            dump("Existe venta en crm");
            dump($existe_venta);
            dump(empty($existe_venta));
        }

        if (!empty($existe_venta)) {

            if ($venta->is_delivered) {

                self::actualizarDelivered_doc($venta->id);
                $response->error = 1;
                $response->mensaje = "Venta " . $venta->id . " actualizada correctamente a delivered";
                $response->documento = $existe_venta[0]->id;
                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya ha sido importado anteriormente, se detectó cambio a delivered, pedido: " . $existe_venta[0]->id . "" . PHP_EOL, FILE_APPEND);

                return $response;
            }
            if ($venta->is_ready_to_ship) {
                self::actualizarRTS_doc($venta->id);
                $response->error = 1;
                $response->mensaje = "Venta " . $venta->id . " actualizada correctamente";
                $response->documento = $existe_venta[0]->id;
                file_put_contents("logs/mercadolibre/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya ha sido importado anteriormente, se detectó cambio a ready to ship, pedido: " . $existe_venta[0]->id . "" . PHP_EOL, FILE_APPEND);

                return $response;
            }

            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "La venta " . $venta->id . " ya existe en el sistema, pedido: " . $existe_venta[0]->id . "" . $log;

            file_put_contents("logs/mercadolibre/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " ya existe en el sistema, pedido: " . $existe_venta[0]->id . "" . PHP_EOL, FILE_APPEND);

            return $response;
        }

        if ($venta->status == "cancelled") {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "La venta " . $venta->id . " se encuentra cancelada o es invalida según los sistemas de mercadolibre, favor de verificar" . $log;

            file_put_contents("logs/mercadolibre/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La venta " . $venta->id . " se encuentra cancelada o es invalida según los sistemas de mercadolibre, favor de verificar" . PHP_EOL, FILE_APPEND);

            return $response;
        }

        if (empty($venta->shipping)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontro informacion del envío de la venta " . $venta->id . "" . $log;

            file_put_contents("logs/mercadolibre/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: No se encontro informacion del envío de la venta " . $venta->id . "" . PHP_EOL, FILE_APPEND);

            return $response;
        }

        if (empty($venta->productos)) {
            $venta->fase = 1;
        }

        $fulfillment = 0;
        $marketplace_fee = 0;
        $marketplace_coupon = 0;
        $id_paqueteria = 1;

        if (property_exists($venta->shipping, 'logistic_type')) {
            if ($venta->shipping->logistic_type == 'fulfillment') {
                $id_paqueteria = 9;
                $fulfillment = 1;
            } else {
                $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

                foreach ($paqueterias as $paqueteria) {
                    if ($venta->shipping->tracking_method == 'Express' && $paqueteria->id == 2) {
                        $id_paqueteria = 2;
                    } else {
                        if ($paqueteria->paqueteria == explode(" ", $venta->shipping->tracking_method)[0]) {
                            $id_paqueteria = $paqueteria->id;
                        }
                    }
                }
            }
        } else {
            $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

            foreach ($paqueterias as $paqueteria) {
                if ($venta->shipping->tracking_method == 'Express' && $paqueteria->id == 2) {
                    $id_paqueteria = 2;
                } else {
                    if ($paqueteria->paqueteria == explode(" ", $venta->shipping->tracking_method)[0]) {
                        $id_paqueteria = $paqueteria->id;
                    }
                }
            }
        }

        if ($id_paqueteria == 19) {
            $id_paqueteria = 14;
        }

        if ($venta->proveedor != 0) {
            $fulfillment = 1;
            $venta->fase = 6;
        }

        foreach ($venta->payments as $payment) {
            $marketplace_fee_pago = property_exists($payment, 'marketplace_fee') ? $payment->marketplace_fee : 0;
            $total_ml = $payment->transaction_amount - $payment->shipping_cost - $marketplace_fee_pago + $payment->coupon_amount;

            $referencia = $payment->id;

            if ($payment->status == "approved") {
                $marketplace_fee += (float)$marketplace_fee_pago;
                $marketplace_coupon += (float)$payment->coupon_amount;
            }
        }

        $entidad = DB::table('documento_entidad')->insertGetId([
            'razon_social' => 'PUBLICO GENERAL',
            'rfc' => mb_strtoupper('XAXX010101000', 'UTF-8'),
            'telefono' => "0",
            'telefono_alt' => "0",
            'correo' => "0"
        ]);

        $documento = DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_almacen_principal_empresa' => $venta->almacen,
            'id_marketplace_area' => $marketplace,
            'id_usuario' => $usuario,
            'id_paqueteria' => $id_paqueteria,
            'id_fase' => $venta->is_buffered ? 7 : ($venta->is_creating_route ? 1 : ($venta->is_manufacturing ? 1 : (property_exists($venta, 'fase') ? $venta->fase : 1))),
            'id_modelo_proveedor' => $venta->proveedor,
            'no_venta' => $venta->id,
            'referencia' => $referencia,
            'observacion' => $venta->buyer->nickname,
            'info_extra' => json_encode($venta->shipping),
            'shipping_null' => $venta->shipping_null ?? 0,
            'fulfillment' => $fulfillment,
            'comentario' => property_exists($venta, 'pack') ? $venta->pack : "",
            'mkt_publicacion' => property_exists($venta, 'publicacion') ? $venta->publicacion : "N/A",
            'mkt_total' => $venta->total_amount,
            'mkt_fee' => property_exists($venta, "total_fee") ? $venta->total_fee : $marketplace_fee,
            'mkt_coupon' => $marketplace_coupon,
            'mkt_shipping_total' => property_exists($venta, "total_envio") ? $venta->total_envio : ((property_exists($venta->shipping, 'cost') ? ($venta->shipping->cost ? $venta->shipping->cost : 0) : $venta->shipping->base_cost) ? $venta->shipping->base_cost : 0),
            'mkt_created_at' => $venta->date_created,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $usuario,
            'seguimiento' => "<h2>PEDIDO IMPORTADO AUTOMATICAMENTE</h2>"
        ]);

        if (property_exists($venta, 'seguimiento')) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $usuario,
                'seguimiento' => $venta->seguimiento
            ]);
        }

        if ($venta->is_buffered) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $usuario,
                'seguimiento' => "<p>Se manda a fase de pedido por falta de guía. Estará disponible los próximos días, consulte el marketplace</p>"
            ]);
        }

        if ($venta->is_manufacturing) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $usuario,
                'seguimiento' => "<p>Se manda a fase de pedido por falta de producto. Notificar a Mercadolibre</p>"
            ]);
        }

        if ($venta->is_creating_route) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $usuario,
                'seguimiento' => "<p>Se manda a fase de pedido por falta de guía. consulte el marketplace</p>"
            ]);
        }

        DB::table('documento_entidad_re')->insert([
            'id_entidad' => $entidad,
            'id_documento' => $documento
        ]);

        try {
            $direccion = @json_decode(file_get_contents(config("webservice.url") . 'Consultas/CP/' . $venta->shipping->receiver_address->zip_code));

            if ($direccion->code == 200) {
                $estado = $direccion->estado[0]->estado;
                $ciudad = $direccion->municipio[0]->municipio;
                $colonia = "";
                $id_direccion_pro = "";

                foreach ($direccion->colonia as $colonia_text) {
                    if (strtolower($colonia_text->colonia) == strtolower($venta->shipping->receiver_address->neighborhood->name)) {
                        $colonia = $colonia_text->colonia;
                        $id_direccion_pro = $colonia_text->codigo;
                    }
                }
            } else {
                $estado = $venta->shipping->receiver_address->state->name;
                $ciudad = $venta->shipping->receiver_address->city->name;
                $colonia = $venta->shipping->receiver_address->neighborhood->name;
                $id_direccion_pro = "";
            }
        } catch (Exception $e) {
            $estado = $venta->shipping->receiver_address->state->name;
            $ciudad = $venta->shipping->receiver_address->city->name;
            $colonia = $venta->shipping->receiver_address->neighborhood->name;
            $id_direccion_pro = "";
        }

        DB::table('documento_direccion')->insert([
            'id_documento' => $documento,
            'id_direccion_pro' => $id_direccion_pro,
            'contacto' => mb_strtoupper($venta->shipping->receiver_address->receiver_name, 'UTF-8'),
            'calle' => mb_strtoupper($venta->shipping->receiver_address->street_name, 'UTF-8'),
            'numero' => mb_strtoupper($venta->shipping->receiver_address->street_number, 'UTF-8'),
            'numero_int' => mb_strtoupper('', 'UTF-8'),
            'colonia' => $colonia,
            'ciudad' => $ciudad,
            'estado' => $estado,
            'codigo_postal' => mb_strtoupper($venta->shipping->receiver_address->zip_code, 'UTF-8'),
            'referencia' => mb_strtoupper($venta->shipping->receiver_address->comment, 'UTF-8'),
        ]);

        $total_pago = 0;

        $productos_documento = array();

        foreach ($venta->productos as $producto) {
            $existe_en_arreglo = false;

            foreach ($productos_documento as $producto_documento) {
                if ($producto_documento->id_modelo == $producto->id_modelo) {
                    $existe_en_arreglo = true;

                    $producto_documento->cantidad += $producto->cantidad;

                    break;
                }
            }

            if (!$existe_en_arreglo) array_push($productos_documento, $producto);
        }

        foreach ($productos_documento as $producto) {
            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $producto->id_modelo,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->precio / 1.16,
                'garantia' => $producto->garantia,
                'modificacion' => '',
                'regalo' => $producto->regalo
            ]);

            $total_pago += $producto->cantidad * $producto->precio;
        }

        $pago = DB::table('documento_pago')->insertGetId([
            'id_usuario' => $usuario,
            'id_metodopago' => 31,
            'id_vertical' => 0,
            'id_categoria' => 0,
            'id_clasificacion' => 0,
            'tipo' => 1,
            'origen_importe' => 0,
            'destino_importe' => $total_pago,
            'folio' => "",
            'entidad_origen' => 1,
            'origen_entidad' => 'XAXX010101000',
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
            'id_documento' => $documento,
            'id_pago' => $pago
        ]);

        if (property_exists($venta, 'proveedor')) {
            if ($venta->proveedor != 0) {
                # Logica para crear la venta en la API del proveedor
                $tiene_archivos = DB::table("documento_archivo")
                    ->where("id_documento", $documento)
                    ->get()
                    ->toArray();

                /* La venta no tiene achivos de embarque */
                if (empty($tiene_archivos)) {
                    $marketplace_data = DB::select("SELECT
                                                        marketplace_area.id,
                                                        marketplace_api.app_id,
                                                        marketplace_api.secret,
                                                        marketplace_api.extra_2,
                                                        marketplace.marketplace
                                                    FROM marketplace_area
                                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                                    WHERE marketplace_area.id = " . $marketplace . "")[0];

                    $guia = self::documento($venta->id, $marketplace_data, 0 /* tipo 0 es para devuelva el archivo en zpl */);

                    if ($guia->error) {
                        $venta->error = 1;

                        DB::table('documento')->where(['id' => $documento])->update([
                            'id_fase' => 1
                        ]);

                        DB::table('seguimiento')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'seguimiento' => "<p>No fue posible descargar las guías de embarque para solicitar la orden de compra al proveedor B2B, mensaje de error: " . $guia->mensaje . "</p>"
                        ]);
                    } else {
                        $extension = $guia->pdf ? ".pdf" : ".zpl";
                        $nombre = "etiqueta_" . trim($venta->id) . $extension;

                        $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                            ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                            ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $nombre . '" , "mode": "add", "autorename": true}')
                            ->addHeader('Content-Type', 'application/octet-stream')
                            ->body(base64_decode($guia->file))
                            ->send();

                        DB::table('documento_archivo')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'nombre' => $nombre,
                            'dropbox' => $response->body->id,
                            'tipo' => 2
                        ]);

                        switch ($venta->proveedor) {
                            case '4':
                                $crear_pedido_btob = ExelDelNorteService::crearPedido($documento);
                                break;

                            case '5':
                                $crear_pedido_btob = CTService::crearPedido($documento);
                                break;

                            default:
                                $log = self::logVariableLocation();
                                $crear_pedido_btob = new \stdClass();

                                $crear_pedido_btob->error = 1;
                                $crear_pedido_btob->mensaje = "El proveedor no ha sido configurado " . $log;

                                break;
                        }

                        if ($crear_pedido_btob->error) {
                            $venta->error = 1;

                            DB::table('documento')->where(['id' => $documento])->update([
                                'id_fase' => 1
                            ]);

                            DB::table('seguimiento')->insert([
                                'id_documento' => $documento,
                                'id_usuario' => 1,
                                'seguimiento' => "<p>No fue posible crear la venta en el sistema del proveedor B2B, mensaje de error: " . $crear_pedido_btob->mensaje . "</p>"
                            ]);
                        }

                        /* Crear documento de compra */
                        $documento_data = DB::table("documento")->find($documento);

                        $entidad_documento = DB::table("documento_entidad_re")
                            ->join("documento_entidad", "documento_entidad_re.id_entidad", "=", "documento_entidad.id")
                            ->select("documento_entidad.*")
                            ->where("documento_entidad_re.id_documento", $documento_data->id)
                            ->first();

                        $documento_compra = DB::table('documento')->insertGetId([
                            'id_tipo' => 1,
                            'id_almacen_principal_empresa' => $documento_data->id_almacen_principal_empresa,
                            'id_periodo' => $documento_data->id_periodo,
                            'id_cfdi' => $documento_data->id_cfdi,
                            'id_marketplace_area' => $documento_data->id_marketplace_area,
                            'id_usuario' => $auth->id,
                            'id_moneda' => $documento_data->id_moneda,
                            'id_paqueteria' => $documento_data->id_paqueteria,
                            'id_fase' => 94,
                            'id_modelo_proveedor' => $documento_data->id_modelo_proveedor,
                            'factura_serie' => "N/A", # Se insertará cuando contabilidad agregue el XML de la compra
                            'factura_folio' => "N/A",
                            'tipo_cambio' => $documento_data->tipo_cambio,
                            'referencia' => "Compra creada a partir de la venta con el ID " . $documento_data->id,
                            'observacion' => "N/A",
                            'info_extra' => 'N/A',
                            'comentario' => "03",
                            'pedimento' => "N/A",
                            'uuid' => "N/A",
                            'expired_at' => date("Y-m-d H:i:s")
                        ]);

                        # Existe entidad como proveedor
                        $proveedor_btob = DB::table("modelo_proveedor")->find($documento_data->id_modelo_proveedor);

                        $existe_entidad = DB::table("documento_entidad")
                            ->where("rfc", $proveedor_btob->rfc)
                            ->where("tipo", [2, 3])
                            ->first();

                        if (empty($existe_entidad)) {
                            $entidad_id = DB::table('documento_entidad')->insertGetId([
                                'id_erp' => 0,
                                'tipo' => 2,
                                'razon_social' => $proveedor_btob->razon_social,
                                'rfc' => $proveedor_btob->rfc,
                                'telefono' => 'N/A',
                                'correo' => $proveedor_btob->correo
                            ]);
                        } else {
                            $entidad_id = $existe_entidad->id;
                        }

                        DB::table('documento_entidad_re')->insert([
                            'id_documento' => $documento_compra,
                            'id_entidad' => $entidad_id
                        ]);

                        $productos_data = DB::table("movimiento")
                            ->where("id_documento", $documento_data->id)
                            ->get()
                            ->toArray();


                        foreach ($productos_data as $producto) {
                            DB::table('movimiento')->insert([
                                'id_documento' => $documento_data->id,
                                'id_modelo' => $producto->id_modelo,
                                'cantidad' => $producto->cantidad,
                                'precio' => $producto->precio,
                                'garantia' => $producto->garantia,
                                'modificacion' => $producto->modificacion,
                                'comentario' => $producto->comentario,
                                'regalo' => $producto->regalo
                            ]);
                        }
                    }
                }
            }
        }

        if ($venta->error) {
            DB::table("documento")->where(["id" => $documento])->update([
                "id_fase" => 1
            ]);

            $venta->fase = 1;
        }

        if ($venta->fase == 6 && $fulfillment) {
            //Aqui ta
            $factura = DocumentoService::crearFactura($documento, 0, 0);

            if ($factura->error) {
                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => $usuario,
                    'seguimiento' => "<h2>" . $factura->mensaje . "</h2>"
                ]);

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_fase' => 5
                ]);
            }
        }

        $response->error = 0;
        $response->mensaje = "Venta " . $venta->id . " importada correctamente";
        $response->documento = $documento;

        return $response;
    }

    public static function validarPendingBuffered($documento)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret,
                                        documento.no_venta
                                    FROM documento
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    WHERE documento.id = " . $documento . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;
            $response->substatus = '';

            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $marketplace->no_venta . "&sort=date_desc&access_token=" . $token));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;
            $response->substatus = '';

            return $response;
        }

        if (empty($informacion_venta->results)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;
            $response->substatus = '';

            return $response;
        }

        $informacion_venta = $informacion_venta->results[0];

        $shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $marketplace->no_venta . "/shipments?access_token=" . $token));

        if (empty($shipping)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->estatus = false;
            $response->mensaje = "No se encontró información del envío de la venta " . $marketplace->no_venta . ", verificar que no esté cancelada" . $log;
            $response->substatus = '';

            return $response;
        }

        $informacion_venta->shipping = $shipping;

        if ($shipping->status == "cancelled") {
            $response->error = 1;
            $response->estatus = false;
            $response->mensaje = "La venta se encuentra cancelada";
            $response->substatus = $shipping->status;

            return $response;
        }

        if ($shipping->status == "delivered") {
            $response->error = 1;
            $response->estatus = false;
            $response->mensaje = "La venta ya se encuentra surtida en MERCADOLIBRE";
            $response->substatus = $shipping->status;

            return $response;
        }

        if ($shipping->status == 'pending' && $shipping->substatus == 'buffered') {

            $response->error = 0;
            $response->estatus = true;
            $response->substatus = $shipping->substatus;
            $response->mensaje = "El pedido sigue en estatus de preparación y no cuenta con guía" . self::logVariableLocation();

            return $response;
        }

        if ($shipping->status === 'pending' && $shipping->substatus === 'manufacturing') {
            $response->error = 0;
            $response->estatus = true;
            $response->substatus = $shipping->status . " - " . $shipping->substatus;
            $response->mensaje = "El pedido sigue en estatus manufacturing y no cuenta con guía" . self::logVariableLocation();

            return $response;
        }

        if ($shipping->status === 'pending' && $shipping->substatus === 'creating_route') {
            $response->error = 0;
            $response->estatus = true;
            $response->substatus = $shipping->status . " - " . $shipping->substatus;
            $response->mensaje = "El pedido sigue en estatus manufacturing y no cuenta con guía" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;
        $response->estatus = false;
        $response->substatus = $shipping->status . " - " . $shipping->substatus;

        return $response;
    }

    public static function validarVenta($documento)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret,
                                        documento.no_venta
                                    FROM documento
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    WHERE documento.id = " . $documento . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $marketplace->no_venta . "&sort=date_desc&access_token=" . $token));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

            return $response;
        }

        if (empty($informacion_venta->results)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

            return $response;
        }

        $informacion_venta = $informacion_venta->results[0];
        $informacion_venta->shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $marketplace->no_venta . "/shipments?access_token=" . $token));

        if (empty($informacion_venta->shipping)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del envío de la venta " . $marketplace->no_venta . ", verificar que no esté cancelada" . $log;

            return $response;
        }

        $cancelada = 0;

        if ($informacion_venta->status == "cancelled") {
            $cancelada = 1;
        }

        if (property_exists($informacion_venta, "mediations")) {
            foreach ($informacion_venta->mediations as $mediacion) {

                $url = "https://api.mercadolibre.com/post-purchase/v1/claims/" . $mediacion->id;

                $options = [
                    "http" => [
                        "header" => "Authorization: Bearer " . $token
                    ]
                ];

                $context = stream_context_create($options);

                $mediacion->information = file_get_contents($url, false, $context);

                if ($mediacion->information === FALSE) {
                    $log = self::logVariableLocation();

                    $response->error = 1;
                    $response->mensaje = "Error al realizar la solicitud de la mediacion." . $log;

                    return $response;
                } else {
                    $data = json_decode($mediacion->information);
                    if ($data->status != 'closed' && $cancelada != 1) {
                        $log = self::logVariableLocation();

                        $response->error = 1;
                        $response->mensaje = "Revisar la venta " . $marketplace->no_venta . " . Reclamo no cerrado" . $log;

                        return $response;
                    }
                }
            }
        }


        if ($cancelada) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "La venta " . $marketplace->no_venta . " se encuentra cancelada en Mercadolibre" . $log;

            return $response;
        }

        $pack = new \stdClass();
        $pack->id = explode(".", empty($informacion_venta->pack_id) ? $informacion_venta->id : sprintf('%lf', $informacion_venta->pack_id))[0];
        $pack->error = 0;
        $pack->ventas = array();
        $pack->mensaje = "";
        $pack->almacen = "";
        $pack->productos = array();
        $pack->paqueteria = 1;

        if (property_exists($informacion_venta->shipping, 'logistic_type')) {
            if ($informacion_venta->shipping->logistic_type == 'fulfillment') {
                $pack->paqueteria = 9;
            } else {
                $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

                foreach ($paqueterias as $paqueteria) {
                    if ($informacion_venta->shipping->tracking_method == 'Express' && $paqueteria->id == 2) {
                        $pack->paqueteria = 2;
                    } else {
                        if ($paqueteria->paqueteria == explode(" ", $informacion_venta->shipping->tracking_method)[0]) {
                            $pack->paqueteria = $paqueteria->id;
                        }
                    }
                }
            }
        } else {
            $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

            foreach ($paqueterias as $paqueteria) {
                if ($informacion_venta->shipping->tracking_method == 'Express' && $paqueteria->id == 2) {
                    $pack->paqueteria = 2;
                } else {
                    if ($paqueteria->paqueteria == explode(" ", $informacion_venta->shipping->tracking_method)[0]) {
                        $pack->paqueteria = $paqueteria->id;
                    }
                }
            }
        }

        array_push($pack->ventas, $informacion_venta);

        if (!is_null($pack->id) && $pack->id != $informacion_venta->id) {
            $pseudonimo_venta = str_replace(" ", "%20", $informacion_venta->buyer->nickname);
            $ventas_pseudonimo = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . rawurlencode($pseudonimo_venta) . "&sort=date_desc&access_token=" . $token));

            if (empty($ventas_pseudonimo)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las ventas relacionadas al paquete de la venta " . $informacion_venta->id . ": empty response." . $log;

                return $response;
            }

            if (empty($ventas_pseudonimo->results)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las ventas relacionadas al paquete de la venta " . $informacion_venta->id . ": empty results." . $log;

                return $response;
            }

            foreach ($ventas_pseudonimo->results as $venta_pseudonimo) {
                if ($pack->id == $venta_pseudonimo->pack_id && $informacion_venta->id != $venta_pseudonimo->id) {
                    $venta_pseudonimo->shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta_pseudonimo->id . "/shipments?access_token=" . $token));

                    array_push($pack->ventas, $venta_pseudonimo);
                }
            }
        }

        foreach ($pack->ventas as $venta) {
            if ($venta->id != $informacion_venta->id) {
                $existe = DB::select("SELECT id FROM documento WHERE no_venta = '" . $venta->id . "' AND status = 1 AND id_tipo = 2");

                if (!empty($existe)) {

                    $pack->error = 1;
                    $pack->mensaje = "La venta " . $venta->id . " ya fue importada anteriormente con el pedido " . $existe[0]->id;

                    break;
                }
            }

            if (empty($venta->shipping)) {

                $pack->error = 1;
                $pack->mensaje = "No se encontró información del envio en los sistemas de mercadolibre de la venta " . $venta->id;

                break;
            }

            foreach ($venta->order_items as $item) {
                $existe_publicacion = DB::select("SELECT 
                                                        marketplace_publicacion.id, 
                                                        marketplace_publicacion.id_almacen_empresa,
                                                        marketplace_publicacion.id_almacen_empresa_fulfillment,
                                                        empresa.bd,
                                                        empresa_almacen.id_erp AS id_almacen
                                                FROM marketplace_publicacion 
                                                INNER JOIN empresa_almacen ON marketplace_publicacion.id_almacen_empresa = empresa_almacen.id
                                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                WHERE publicacion_id = '" . $item->item->id . "'");

                if (empty($existe_publicacion)) {

                    $pack->error = 1;
                    $pack->mensaje = "No se encontró la publicación de la venta " . $venta->id . " registrada en el sistema, por lo tanto, no hay relación de productos " . $item->item->id;

                    break 2;
                }

                $existe_publicacion = $existe_publicacion[0];

                $extra_query = !is_null($item->item->variation_id) ? " AND etiqueta = '" . $item->item->variation_id . "'" : "";
                $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $existe_publicacion->id . $extra_query);

                if (empty($productos_publicacion)) {
                    $pack->error = 1;
                    $pack->mensaje = "No hay relación entre productos y la publicación " . $item->item->id . " en la venta " . $venta->id;

                    break 2;
                }

                $porcentaje_total = 0;

                foreach ($productos_publicacion as $producto) {
                    $porcentaje_total += $producto->porcentaje;
                }

                if ($porcentaje_total != 100) {

                    $pack->error = 1;
                    $pack->mensaje = "Los productos de la publicación " . $item->item->id . " no suman un porcentaje total de 100%.";

                    break 2;
                }

                $pack->almacen = property_exists($venta->shipping, "logistic_type") ? ($venta->shipping->logistic_type == 'fulfillment' ? $existe_publicacion->id_almacen_empresa_fulfillment : $existe_publicacion->id_almacen_empresa) : $existe_publicacion->id_almacen_empresa;

                foreach ($productos_publicacion as $producto) {
                    $producto->precio = round(($producto->porcentaje * $item->unit_price / 100) / $producto->cantidad, 6);
                    $producto->cantidad = $producto->cantidad * $item->quantity;
                }

                $pack->productos = array_merge($pack->productos, $productos_publicacion);
            }
        }

        return $pack;
    }

    public static function actualizarRTS_com($existe_pack)
    {
        foreach ($existe_pack as $key) {
            DB::table('documento')->where(['id' => $key->id])->update([
                'id_fase' => 3,
                'picking' => 0,
                'picking_by' => 0
            ]);
        }
    }

    public static function actualizarRTS_doc_o($venta)
    {
        DB::table('documento')->where('no_venta', $venta)
            ->where('status', 1)
            ->update([
                'id_fase' => 3,
                'picking' => 0,
                'picking_by' => 0
            ]);
    }

    public static function actualizarRTS_doc($venta)
    {
        DB::table('documento')->where('no_venta', $venta)
            ->where('status', 1)
            ->update([
                'id_fase' => 3,
                'picking' => 0,
                'picking_by' => 0
            ]);
    }

    public static function actualizarDelivered_com($existe_pack)
    {
        foreach ($existe_pack as $key) {
            DB::table('documento')->where(['id' => $key->id])->update([
                'id_fase' => 6
            ]);
        }
    }

    public static function actualizarDelivered_doc_o($venta)
    {
        DB::table('documento')->where('no_venta', $venta)
            ->where('status', 1)
            ->update([
                'id_fase' => 6,
            ]);
    }

    public static function actualizarDelivered_doc($venta)
    {
        DB::table('documento')->where('no_venta', $venta)
            ->where('status', 1)
            ->update([
                'id_fase' => 6,
            ]);
    }

    public static function documento($venta, $credenciales, $tipo = 1 /* 0 ZPL; 1 PDF */)
    {
        $response = new \stdClass();

        $token = self::token($credenciales->app_id, $credenciales->secret);

        //$seller = self::seller(str_replace(" ", "%20", $credenciales->extra_2), $token);

        //$seller_id = $seller->seller->id;

        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($opts);

        //$informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller_id . "&q=" . rawurlencode($venta) . "&access_token=" . $token));

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . rawurlencode($venta), false, $context));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . $log;

            return $response;
        }

        // $informacion_venta = $informacion_venta->results[0];

        $tmp = tempnam('', 'me2');
        rename($tmp, $tmp .= '.zip');
        $file = fopen($tmp, 'r+');

        $url_data = config("webservice.mercadolibre_enpoint") . "shipment_labels?shipment_ids=" . $informacion_venta->shipping->id . "&response_type=zpl2&access_token=" . $token;
        $zpl = @file_get_contents($url_data);

        if (empty($zpl)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró la etiqueta del envío, favor de revisar que el envío no sea enviado de sellcenter ó la venta no esté cancelada." . $log;
            $response->raw = $url_data;

            return $response;
        }

        fwrite($file, $zpl);

        $zip = new ZipArchive;
        $zip->open($tmp);

        $file_data = base64_encode($tipo ? file_get_contents(self::postLabelary($zip->getFromIndex(0), true)) : $zip->getFromIndex(0));

        $response->error = 0;
        $response->file = $file_data;
        $response->pdf = $tipo;

        return $response;
    }

    public static function documentoZPL($venta, $credenciales)
    {
        $response = new \stdClass();

        $token = self::token($credenciales->app_id, $credenciales->secret);

        $seller = self::seller(str_replace(" ", "%20", $credenciales->extra_2), $token);

        $seller_id = $seller->id;

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller_id . "&q=" . $venta . "&access_token=" . $token));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar información de la venta en el sistema exterior." . $log;

            return $response;
        }

        $informacion_venta = $informacion_venta->results[0];

        $tmp = tempnam('', 'me2');
        rename($tmp, $tmp .= '.zip');
        $file = fopen($tmp, 'r+');

        $url_data = config("webservice.mercadolibre_enpoint") . "shipment_labels?shipment_ids=" . $informacion_venta->shipping->id . "&response_type=zpl2&access_token=" . $token;
        $zpl = @file_get_contents($url_data);

        if (empty($zpl)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró la etiqueta del envío, favor de revisar que el envío no sea enviado de sellcenter ó la venta no esté cancelada." . $log;
            $response->raw = $url_data;

            return $response;
        }

        fwrite($file, $zpl);

        $zip = new ZipArchive;
        $zip->open($tmp);

        $response->error = 0;
        $response->file = $zip->getFromIndex(0);

        return $response;
    }

    public static function publicaciones($marketplace_id, $extra_query = "")
    {
        set_time_limit(0);

        $response = new \stdClass();
        $array = array();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        extra_2,
                                        app_id,
                                        secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la API del marketplace con el ID " . $marketplace . "" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);
        $offset = 0;

        $tiendas_oficiales = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/brands"));
        $tiendas_oficiales = !empty($tiendas_oficiales) ? $tiendas_oficiales->brands : [];
        $publicaciones = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/items/search?search_type=scan" . $extra_query . "&access_token=" . $token));

        if (empty($publicaciones)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar las publicaciones en Mercadolibre." . $log;

            return $response;
        }

        $scroll_id = $publicaciones->scroll_id;

        foreach ($publicaciones->results as $publicacion_id) {
            $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion_id . "?access_token=" . $token));

            if (!empty($publicacion_info)) {
                $existe_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion_info->id . "'");

                if (!empty($existe_publicacion)) {
                    $productos_publicacion = DB::select("SELECT
                                                            modelo.sku,
                                                            modelo.descripcion
                                                        FROM marketplace_publicacion_producto 
                                                        INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                                        WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                    $publicacion_data = new \stdClass();
                    $publicacion_data->id = $publicacion_info->id;
                    $publicacion_data->titulo = $publicacion_info->title;
                    $publicacion_data->precio = $publicacion_info->price;
                    $publicacion_data->status = $publicacion_info->status;
                    $publicacion_data->logistica = $publicacion_info->shipping->logistic_type;
                    $publicacion_data->inventario = $publicacion_info->available_quantity;
                    $publicacion_data->productos = $productos_publicacion;
                    $publicacion_data->tienda = "SIN TIENDA OFICIAL";

                    if (!is_null($publicacion_info->official_store_id)) {
                        foreach ($tiendas_oficiales as $tienda) {
                            if ($tienda->official_store_id === $publicacion_info->official_store_id) {
                                $publicacion_data->tienda = $tienda->name;

                                break;
                            }
                        }
                    }

                    array_push($array, $publicacion_data);
                }
            }
        }

        while (!is_null($scroll_id)) {
            $publicaciones = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/items/search?search_type=scan&scroll_id=" . $scroll_id . "&access_token=" . $token));

            if (empty($publicaciones)) {
                break;
            }

            $scroll_id = $publicaciones->scroll_id;

            foreach ($publicaciones->results as $publicacion_id) {
                $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion_id . "?access_token=" . $token));

                if (!empty($publicacion_info)) {
                    $existe_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion_info->id . "'");

                    if (!empty($existe_publicacion)) {
                        $productos_publicacion = DB::select("SELECT
                                                                modelo.sku,
                                                                modelo.descripcion
                                                            FROM marketplace_publicacion_producto 
                                                            INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                                            WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                        $publicacion_data = new \stdClass();
                        $publicacion_data->id = $publicacion_info->id;
                        $publicacion_data->titulo = $publicacion_info->title;
                        $publicacion_data->precio = $publicacion_info->price;
                        $publicacion_data->status = $publicacion_info->status;
                        $publicacion_data->logistica = $publicacion_info->shipping->logistic_type;
                        $publicacion_data->inventario = $publicacion_info->available_quantity;
                        $publicacion_data->productos = $productos_publicacion;
                        $publicacion_data->tienda = "SIN TIENDA OFICIAL";

                        if (!is_null($publicacion_info->official_store_id)) {
                            foreach ($tiendas_oficiales as $tienda) {
                                if ($tienda->official_store_id === $publicacion_info->official_store_id) {
                                    $publicacion_data->tienda = $tienda->name;

                                    break;
                                }
                            }
                        }

                        array_push($array, $publicacion_data);
                    }
                }
            }
        }

        $response->error = 0;
        $response->publicaciones = $array;

        return $response;
    }

    public static function crearPublicacion($marketplace, $data)
    {
        $response = new \stdClass();
        $imagenes_a_borrar = array();

        $publicacion_data = DB::select("SELECT
                                            marketplace_api.app_id,
                                            marketplace_api.secret
                                        FROM marketplace_api
                                        WHERE id_marketplace_area = " . $marketplace . "");

        if (empty($publicacion_data)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }

        $publicacion_data = $publicacion_data[0];

        $token = self::token($publicacion_data->app_id, $publicacion_data->secret);

        $request_data = array(
            "title" => $data->title,
            "category_id" => $data->category->category_id,
            "currency_id" => "MXN",
            "listing_type_id" => $data->listing_type,
            "sale_terms" => array(
                "0" => array(
                    "id" => $data->warranty->type->id,
                    "value_name" => $data->warranty->type->value
                ),
                "1" => array(
                    "id" => $data->warranty->time->id,
                    "value_name" => $data->warranty->time->value . " " . $data->warranty->time->unit
                )
            ),
            "pictures" => array(),
            "attributes" => array()
        );

        /* Si el vendedor requiere tienda oficial */
        if (property_exists($data, "official_store_id")) {
            $request_data["official_store_id"] = $data->official_store_id;
        }

        /* Si el producto no trae variaciones, se pone el precio y cantidad disponible */
        if (empty($data->variations)) {
            $request_data["price"] = $data->price;
            $request_data["available_quantity"] = $data->quantity;
            $request_data["pictures"] = array();

            foreach ($data->pictures as $picture) {
                $raw_image_data = base64_decode(preg_replace('#^' . explode('/', explode(';', $picture->data)[0])[0] . '/\w+;base64,#i', '', $picture->data));
                $image_type = explode(':', substr($picture->data, 0, strpos($picture->data, ';')))[1];

                $image_name = uniqid() . "." . explode('/', $image_type)[1];
                $image_path = "img/mercadolibre_temp/";

                if (file_put_contents($image_path . $image_name, $raw_image_data)) {
                    array_push($request_data["pictures"], ['source' => url() . "/" . $image_path . $image_name]);
                    array_push($imagenes_a_borrar, $image_name);
                }
            }
        } else {
            $request_data["variations"] = $data->variations;

            foreach ($request_data["variations"] as $variation) {
                $variation->picture_ids = array();

                foreach ($variation->pictures_data as $picture) {
                    $raw_image_data = base64_decode(preg_replace('#^' . explode('/', explode(';', $picture->data)[0])[0] . '/\w+;base64,#i', '', $picture->data));
                    $image_type = explode(':', substr($picture->data, 0, strpos($picture->data, ';')))[1];

                    $image_name = uniqid() . "." . explode('/', $image_type)[1];
                    $image_path = "img/mercadolibre_temp/";

                    if (file_put_contents($image_path . $image_name, $raw_image_data)) {
                        array_push($request_data["pictures"], ['source' => url() . "/" . $image_path . $image_name]);
                        array_push($variation->picture_ids, url() . "/" . $image_path . $image_name);
                        array_push($imagenes_a_borrar, $image_name);
                    }
                }

                foreach ($variation->attribute_combinations as $combination) {
                    $combination->name = mb_strtoupper($combination->name);
                }
            }
        }

        foreach ($data->attributes as $attribute) {
            array_push($request_data["attributes"], array(
                "id" => $attribute->id,
                "value_name" => property_exists($attribute, "allowed_units") ? $attribute->value . " " . $attribute->unit : $attribute->value
            ));
        }

        $request_response = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "items")
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->body(json_encode($request_data), \Httpful\Mime::FORM)
            ->send();

        $request_response_raw = $request_response->raw_body;
        $request_response = @json_decode($request_response_raw);

        if (empty($request_response)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al publicar en Mercadolibre, error: empty response (json_encode)" . $log;
            $response->raw = $request_response_raw;
            $response->data = $request_data;

            return $response;
        }

        if ($request_response->status == 400) {
            $error_messages = array();

            foreach ($request_response->cause as $cause) {
                if ($cause->type == "error") {
                    array_push($error_messages, $cause->message . "<br>");
                }
            }
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al publicar en Mercadolibre, error: " . $request_response->message . implode(" ", $error_messages) . "" . $log;
            $response->raw = $request_response->cause;
            $response->data = $request_data;

            return $response;
        }

        $array_description = array(
            "plain_text" => $data->description
        );

        /* La publicación se creó correctamente y se procede a agrega la descripcion */
        $request_description = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "items/" . $request_response->id . "/description")
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->addHeader('Content-Type', 'application/json')
            ->body(json_encode($array_description), \Httpful\Mime::FORM)
            ->send();

        $request_description_raw = $request_description->raw_body;
        $request_description = @json_decode($request_description_raw);

        if (empty($request_description)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "Ocurrió un error al publicar en Mercadolibre, error: empty response (json_encode)" . $log;
            $response->raw = $request_description_raw;
            $response->data = $request_data;

            return $response;
        }

        /* Se borras imagenes unas vez publicadas */
        foreach ($imagenes_a_borrar as $imagen) {
            unlink("img/mercadolibre_temp/" . $imagen);
        }

        $publicacion_id = DB::table('marketplace_publicacion')->insertGetId([
            'id_marketplace_area' => $marketplace,
            'publicacion_id' => $request_response->id,
            'publicacion' => $request_response->title,
            'total' => $request_response->price,
            'status' => $request_response->status,
            'url' => $request_response->permalink,
            'logistic_type' => $request_response->shipping->logistic_type,
            'cantidad_disponible' => $request_response->available_quantity,
            'tipo' => $request_response->listing_type_id,
            'tienda' => is_null($request_response->official_store_id) ? "SIN TIENDA" : $request_response->official_store_id
        ]);

        DB::table('marketplace_publicacion_etiqueta')->where(['id_publicacion' => $publicacion_id])->delete();

        if (property_exists($request_response, "variations")) {
            foreach ($request_response->variations as $variacion) {
                $colores = "";

                foreach ($variacion->attribute_combinations as $combinacion) {
                    $colores .= " " . $combinacion->value_name . " /";
                }

                $colores = trim(substr($colores, 0, -1));

                DB::table('marketplace_publicacion_etiqueta')->insert([
                    'id_publicacion' => $publicacion_id,
                    'id_etiqueta' => $variacion->id,
                    'etiqueta' => "Color",
                    'valor' => $colores,
                    'cantidad' => (int)$variacion->available_quantity,
                ]);
            }
        }

        if (property_exists($request_response, "sale_terms")) {
            foreach ($request_response->sale_terms as $term) {
                if ($term->id == "MANUFACTURING_TIME") {
                    DB::table('marketplace_publicacion')->where(['id' => $publicacion_id])->update([
                        'tee' => (!is_null($term->value_struct) ? $term->value_struct->number : !empty($term->values)) ? $term->values[0]->struct->number : 0
                    ]);
                }
            }
        }

        $response->error = 0;
        $response->mensaje = "Publicación creada correctamente!";

        return $response;
    }

    public static function actualizarPublicacion($data)
    {
        $imagenes_a_borrar = array();

        $response = new \stdClass();
        $response->error = 0;

        $marketplace = DB::table("marketplace_api")
            ->select("marketplace_api.*", "marketplace_publicacion.publicacion_id")
            ->join("marketplace_area", "marketplace_api.id_marketplace_area", "=", "marketplace_area.id")
            ->join("marketplace_publicacion", "marketplace_area.id", "=", "marketplace_publicacion.id_marketplace_area")
            ->where("marketplace_publicacion.id", $data->id)
            ->first();

        if (!$marketplace) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información del marketplace." . $log;

            return $response;
        }

        $token = self::token($marketplace->app_id, $marketplace->secret);

        $publicacion_data_before_update = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $marketplace->publicacion_id . "?access_token=" . $token));

        if (empty($publicacion_data_before_update)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información de la publicación en Mercadolibre" . $log;

            return $response;
        }

        $actualizar_descripcion_data = array(
            "plain_text" => $data->description
        );

        # Se actualiza la descripción en Mercadolibre
        \Httpful\Request::put(config("webservice.mercadolibre_enpoint") . "items/" . $marketplace->publicacion_id . "/description?api_version=2")
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->body(json_encode($actualizar_descripcion_data))
            ->send();

        # Actualizar/Eliminar atributos de la publicación
        $attributes = array();

        foreach ($data->attributes as $attribute) {
            $attribute_value = property_exists($attribute, "allowed_units") ? $attribute->value . " " . $attribute->unit : $attribute->value;

            if ($attribute->value) {
                array_push($attributes, array(
                    "id" => $attribute->id,
                    "value_name" => property_exists($attribute, "delete") ? ($attribute->delete ? null : $attribute_value) : $attribute_value
                ));
            }
        }

        # Actualizar variaciones de un producto
        $variations = array();

        if (!empty($data->variations)) {
            foreach ($data->variations as $variation) {
                # Se elimina la variación
                if (property_exists($variation, "delete")) {
                    if ($variation->delete) {
                        \Httpful\Request::delete(config("webservice.mercadolibre_enpoint") . "items/" . $marketplace->publicacion_id . "/variations/" . $variation->id)
                            ->addHeader('Authorization', 'Bearer ' . $token)
                            ->send();

                        continue;
                    }
                }

                $variation_data = new \stdClass();

                # Se actualizan los atributos de la variacion
                $variation_data->id = $variation->id;
                $variation_data->attributes = $variation->attributes;
                /* Si el precio de la publicación es diferente al del CRM, se actualiza el precio */
                if ($publicacion_data_before_update->base_price != $data->price) {
                    $variation_data->price = $data->price;
                }
                /* Si la cantidad es dfierente a la variación de la publicación, se actualiza cantidad siempre y cuando no sea fulfillment */
                if ($publicacion_data_before_update->shipping->logistic_type != 'fulfillment') {
                    foreach ($publicacion_data_before_update->variations as $varia) {
                        if ($varia->id == $variation->id) {
                            if ($varia->available_quantity != $variation->available_quantity) {
                                $variation_data->available_quantity = $variation->available_quantity;
                            }
                        }
                    }
                }

                $images_per_variation = array();

                foreach ($variation->pictures_data as $picture) {
                    if ($picture->new) {
                        $raw_image_data = base64_decode(preg_replace('#^' . explode('/', explode(';', $picture->data)[0])[0] . '/\w+;base64,#i', '', $picture->data));
                        $image_type = explode(':', substr($picture->data, 0, strpos($picture->data, ';')))[1];

                        $image_name = uniqid() . "." . explode('/', $image_type)[1];
                        $image_path = "img/mercadolibre_temp/";

                        if (file_put_contents($image_path . $image_name, $raw_image_data)) {
                            $image_data = array(
                                "file" => $image_path . $image_name
                            );

                            $image_upload = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "pictures/items/upload")
                                ->addHeader('Content-Type', 'multipart/form-data')
                                ->addHeader('Authorization', 'Bearer ' . $token)
                                ->attach($image_data)
                                ->send();

                            $image_upload_data = json_decode($image_upload->raw_body);

                            if (!property_exists($image_upload_data, "id")) {
                                $log = self::logVariableLocation();

                                $response->error = 1;
                                $response->mensaje = "Ocurrió un error al validar una imagen en los servidores de Mercadolibre, favor de intentar de nuevo" . $log;

                                return $response;
                            }

                            array_push($publicacion_data["pictures"], ['id' => $image_upload_data->id]);
                            array_push($images_per_variation, $image_upload->body->id);
                            array_push($imagenes_a_borrar, $image_name);
                        }
                    } else {
                        if (!$picture->deleted) {
                            array_push($publicacion_data["pictures"], ['id' => $picture->id]);
                            array_push($images_per_variation, $picture->id);
                        }
                    }
                }

                $variation_data->picture_ids = $images_per_variation;

                array_push($variations, $variation_data);
            }
        }

        $publicacion_data = array(
            "attributes" => $attributes,
            "variations" => $variations
        );

        if (empty($data->variations)) {
            if ($publicacion_data_before_update->base_price != $data->price) {
                $publicacion_data["price"] = $data->price;
            }

            if ($publicacion_data_before_update->shipping->logistic_type != 'fulfillment') {
                if ($publicacion_data_before_update->available_quantity != $data->quantity) {
                    $publicacion_data["available_quantity"] = $data->quantity;
                }
            }

            $publicacion_data["pictures"] = array();

            foreach ($data->pictures_data as $picture) {
                if ($picture->new) {
                    $raw_image_data = base64_decode(preg_replace('#^' . explode('/', explode(';', $picture->data)[0])[0] . '/\w+;base64,#i', '', $picture->data));
                    $image_type = explode(':', substr($picture->data, 0, strpos($picture->data, ';')))[1];

                    $image_name = uniqid() . "." . explode('/', $image_type)[1];
                    $image_path = "img/mercadolibre_temp/";

                    if (file_put_contents($image_path . $image_name, $raw_image_data)) {
                        $image_data = array(
                            "file" => $image_path . $image_name
                        );

                        $image_upload = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "pictures/items/upload")
                            ->addHeader('Content-Type', 'multipart/form-data')
                            ->addHeader('Authorization', 'Bearer ' . $token)
                            ->attach($image_data)
                            ->send();

                        $image_upload_data = json_decode($image_upload->raw_body);

                        if (!property_exists($image_upload_data, "id")) {
                            $log = self::logVariableLocation();

                            $response->error = 1;
                            $response->mensaje = "Ocurrió un error al validar una imagen en los servidores de Mercadolibre, favor de intentar de nuevo" . $log;

                            return $response;
                        }

                        array_push($publicacion_data["pictures"], ['id' => $image_upload_data->id]);
                        array_push($imagenes_a_borrar, $image_name);
                    }
                } else {
                    if (!$picture->deleted) {
                        array_push($publicacion_data["pictures"], ['id' => $picture->id]);
                    }
                }
            }
        }

        if ($publicacion_data_before_update->video_id != $data->video) {
            $publicacion_data["video_id"] = $data->video;
        }

        if ($publicacion_data_before_update->listing_type_id != $data->listing_type) {
            $publicacion_data["listing_type_id"] = $data->listing_type;
        }

        $warranty_type_different = false;
        $warranty_time_different = false;

        foreach ($publicacion_data_before_update->sale_terms as $sale_term) {
            if ($sale_term->id === "WARRANTY_TYPE") {
                if ($sale_term->value_name != $data->warranty->type->value) {
                    $warranty_type_different = true;
                }
            }

            if ($sale_term->id === "WARRANTY_TIME") {
                if ($sale_term->value_struct->number != $data->warranty->time->value || $sale_term->value_struct->unit != $data->warranty->time->unit) {
                    $warranty_time_different = true;
                }
            }
        }

        if ($warranty_time_different || $warranty_type_different) {
            $publicacion_data["sale_terms"] = array();

            if ($warranty_time_different) {
                $publicacion_data["sale_terms"][0] = array(
                    "id" => $data->warranty->time->id,
                    "value_name" => $data->warranty->time->value . " " . $data->warranty->time->unit
                );
            }

            if ($warranty_type_different) {
                $index = empty($publicacion_data["sale_terms"]) ? 0 : 1;

                $publicacion_data["sale_terms"][$index] = array(
                    "id" => $data->warranty->type->id,
                    "value_name" => $data->warranty->type->value
                );
            }
        }

        $response_data = \Httpful\Request::put(config("webservice.mercadolibre_enpoint") . "items/" . $marketplace->publicacion_id)
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->body(json_encode($publicacion_data))
            ->send();

        if ($response_data->code != 200) {
            $message = $response_data->body->message . "<br>";

            foreach ($response_data->body->cause as $cause) {
                $message .= $cause->message . "<br>";
            }
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = $message . "" . $log;
            $response->raw = $response_data->body;

            return $response;
        }

        if (property_exists($response_data->body, 'error')) {
            $message = $response_data->body->message . "<br><br>";

            foreach ($response_data->body->cause as $cause) {
                $message .= $cause->message . "<br>";
            }
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = $message . "" . $log;
            $response->raw = $response_data->body;

            return $response;
        }

        /* Se borras imagenes unas vez publicadas */
        foreach ($imagenes_a_borrar as $imagen) {
            unlink("img/mercadolibre_temp/" . $imagen);
        }

        $response->mensaje = "Publicación actualizada correctamente, favor de verificar la información en la página de Mercadolibre";

        return $response;
    }

    public static function actualizarPublicaciones($marketplace_id)
    {
        set_time_limit(0);

        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        extra_2,
                                        app_id,
                                        secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la API del marketplace con el ID " . $marketplace_id . "" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);
        $scroll_id = null;

        DB::table('marketplace_publicacion')->where(['id_marketplace_area' => $marketplace_id])->update([
            'status' => 'inactive'
        ]);

        $publicaciones_raw = array();

        $publicaciones = json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/items/search?search_type=scan&limit=100&access_token=" . $token));

        if (!empty($publicaciones)) {
            $scroll_id = $publicaciones->scroll_id;

            $publicaciones_raw = array_merge($publicaciones_raw, $publicaciones->results);

            while (!is_null($scroll_id)) {
                $publicaciones = json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/items/search?search_type=scan&limit=100&access_token=" . $token . "&scroll_id=" . $scroll_id));

                if (empty($publicaciones->results)) {
                    $scroll_id = null;

                    continue;
                } else {
                    $scroll_id = $publicaciones->scroll_id;
                }

                $publicaciones_raw = array_merge($publicaciones_raw, $publicaciones->results);
            }
        }

        foreach ($publicaciones_raw as $publicacion) {
            $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion . "?access_token=" . $token));

            if (!empty($publicacion_info)) {
                $existe_publicacion = DB::select("SELECT id, cantidad_disponible FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion_info->id . "'");

                if (!empty($existe_publicacion)) {
                    $publicacion_id = $existe_publicacion[0]->id;

                    $ofertas = DB::select("SELECT * FROM marketplace_publicacion_oferta WHERE id_publicacion = " . $publicacion_id . " AND ('" . date("Y-m-d H:i:s") . "' >= inicio AND '" . date("Y-m-d H:i:s") . "' <= final)");

                    DB::table('marketplace_publicacion')->where(['id' => $publicacion_id])->update([
                        'publicacion' => $publicacion_info->title,
                        'status' => $publicacion_info->status,
                        'url' => $publicacion_info->permalink,
                        'logistic_type' => $publicacion_info->shipping->logistic_type,
                        'tipo' => $publicacion_info->listing_type_id,
                        'tienda' => is_null($publicacion_info->official_store_id) ? "SIN TIENDA" : $publicacion_info->official_store_id
                    ]);

                    if (empty($ofertas)) {
                        DB::table('marketplace_publicacion')->where(['id' => $publicacion_id])->update([
                            'total' => $publicacion_info->price
                        ]);
                    }
                } else {
                    $publicacion_id = DB::table('marketplace_publicacion')->insertGetId([
                        'id_marketplace_area' => $marketplace->id,
                        'publicacion_id' => $publicacion_info->id,
                        'publicacion' => $publicacion_info->title,
                        'total' => $publicacion_info->price,
                        'status' => $publicacion_info->status,
                        'url' => $publicacion_info->permalink,
                        'logistic_type' => $publicacion_info->shipping->logistic_type,
                        'cantidad_disponible' => $publicacion_info->available_quantity,
                        'tipo' => $publicacion_info->listing_type_id,
                        'tienda' => is_null($publicacion_info->official_store_id) ? "SIN TIENDA" : $publicacion_info->official_store_id
                    ]);
                }

                DB::table('marketplace_publicacion_etiqueta')->where(['id_publicacion' => $publicacion_id])->delete();

                if (property_exists($publicacion_info, "variations")) {
                    foreach ($publicacion_info->variations as $variacion) {
                        $colores = "";

                        foreach ($variacion->attribute_combinations as $combinacion) {
                            $colores .= " " . $combinacion->value_name . " /";
                        }

                        $colores = trim(substr($colores, 0, -1));

                        DB::table('marketplace_publicacion_etiqueta')->insert([
                            'id_publicacion' => $publicacion_id,
                            'id_etiqueta' => $variacion->id,
                            'etiqueta' => "Color",
                            'valor' => $colores,
                            'cantidad' => (int)$variacion->available_quantity,
                        ]);
                    }
                }

                if (property_exists($publicacion_info, "sale_terms")) {
                    foreach ($publicacion_info->sale_terms as $term) {
                        if ($term->id == "MANUFACTURING_TIME") {
                            DB::table('marketplace_publicacion')->where(['id' => $publicacion_id])->update([
                                'tee' => (!is_null($term->value_struct) ? $term->value_struct->number : !empty($term->values)) ? $term->values[0]->struct->number : 0
                            ]);
                        }
                    }
                }
            }
        }

        $response->error = 0;

        return $response;
    }

    public static function buscarPublicacionCompetencia($publicacion)
    {
        $response = new \stdClass();

        $publicacion_data = DB::select("SELECT
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_publicacion_competencia.publicacion_id
                                        FROM marketplace_publicacion
                                        INNER JOIN marketplace_publicacion_competencia ON marketplace_publicacion.id = marketplace_publicacion_competencia.id_publicacion
                                        INNER JOIN marketplace_api ON marketplace_publicacion.id_marketplace_area = marketplace_api.id_marketplace_area
                                        WHERE marketplace_publicacion_competencia.id = " . $publicacion . "");

        if (empty($publicacion_data)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }

        $publicacion_data = $publicacion_data[0];

        $token = self::token($publicacion_data->app_id, $publicacion_data->secret);

        $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion_data->publicacion_id . "?access_token=" . $token));

        if (empty($existe_publicacion)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación " . $publicacion_data->publicacion_id . " en Mercadolibre" . $log;

            return $response;
        }

        $response->error = 0;
        $response->data = $publicacion_info;

        return $response;
    }

    public static function buscarPublicacion($publicacion_id, $marketplace_area)
    {
        $response = new \stdClass();

        $publicacion_data = DB::select("SELECT
                                            marketplace_api.app_id,
                                            marketplace_api.secret
                                        FROM marketplace_api
                                        WHERE id_marketplace_area = " . $marketplace_area . "");

        if (empty($publicacion_data)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }

        $publicacion_data = $publicacion_data[0];

        $token = self::token($publicacion_data->app_id, $publicacion_data->secret);

        $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion_id . "?access_token=" . $token));

        if (empty($publicacion_info)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación " . $publicacion_id . " en Mercadolibre" . $log;

            return $response;
        }

        $response->error = 0;
        $response->data = $publicacion_info;

        return $response;
    }

    public static function buscarPreguntas($marketplace_area)
    {
        $preguntas_data = array();
        $response = new \stdClass();
        $response->error = 1;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_area)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }

        $token = self::token($marketplace_data->app_id, $marketplace_data->secret);
        $seller = self::seller($marketplace_data->extra_2, $token);

        $scroll_id = "";

        while (!is_null($scroll_id)) {
            $preguntas = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "my/received_questions/search?seller_id=" . $seller->id . "&api_version=4&status=UNANSWERED&search_type=scan&scroll_id=" . $scroll_id . "&access_token=" . $token));

            if (empty($preguntas)) {
                $token = self::token($marketplace_data->app_id, $marketplace_data->secret);

                continue;
            }

            if (empty($preguntas->questions)) {
                $scroll_id = null;

                break;
            }

            foreach ($preguntas->questions as $question) {
                $question->previous_questions = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "questions/search?item=" . $question->item_id . "&from=" . $question->from->id . "&access_token=" . $token . ""));
            }

            $scroll_id = $preguntas->scroll_id;

            $preguntas_data = array_merge($preguntas_data, $preguntas->questions);
        }

        $response->error = 0;
        $response->data = $preguntas_data;

        return $response;
    }

    public static function responderPregunta($marketplace_area, $pregunta_id, $respuesta)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_area)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del marketplace." . $log;

            return $response;
        }

        $token = self::token($marketplace_data->app_id, $marketplace_data->secret);

        $data = array(
            "question_id" => $pregunta_id,
            "text" => $respuesta
        );

        $request_data = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "answers")
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->body(json_encode($data))
            ->send();

        $response_raw = $request_data->raw_body;
        $response_object = @json_decode($response_raw);

        if (empty($response_object)) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: empty response" . $log;
            $response->raw = $response_raw;

            return $response;
        }

        if (property_exists($response_object, "code")) {
            if ($response->code != 200) {
                $log = self::logVariableLocation();

                $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: error code != 200" . $log;
                $response->raw = $response_object;

                return $response;
            }
        }

        if (!property_exists($response_object, "answer")) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: answer object empty" . $log;
            $response->raw = $response_object;

            return $response;
        }

        if ($response_object->answer->status != 'ACTIVE') {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: answer status != 'ACTIVE'" . $log;
            $response->raw = $response_object;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function borrarPregunta($marketplace_area, $pregunta_id)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_area)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del marketplace." . $log;

            return $response;
        }

        $token = self::token($marketplace_data->app_id, $marketplace_data->secret);

        $request_data = \Httpful\Request::delete(config("webservice.mercadolibre_enpoint") . "questions/" . $pregunta_id)
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->send();

        $response_raw = $request_data->raw_body;
        $response_object = @json_decode($response_raw);

        if (empty($response_object)) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: empty response" . $log;

            return $response;
        }

        if ($response_object != 'Question deleted.') {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: " . $response_object . "" . $log;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function bloquearUsuarioParaPreguntar($marketplace_area, $user_id)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_area)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del marketplace." . $log;

            return $response;
        }

        $token = self::token($marketplace_data->app_id, $marketplace_data->secret);
        $seller = self::seller($marketplace_data->extra_2, $token);

        $data = array(
            "user_id" => $user_id,
        );

        $request_data = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "users/" . $seller->id . "/questions_blacklist")
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->body(json_encode($data))
            ->send();

        $response_raw = $request_data->raw_body;
        $response_object = @json_decode($response_raw);

        if (empty($response_object)) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: empty response" . $log;

            return $response;
        }

        if (property_exists($response_object, "code")) {
            if ($response->code != 200) {
                $log = self::logVariableLocation();

                $response->mensaje = "Ocurrió un error al mandar la solicitud a mercadolibre, error: error code != 200" . $log;

                return $response;
            }
        }

        $response->error = 0;

        return $response;
    }

    public static function desactivarPublicacion($publicacion_id, $marketplace_area)
    {
        $response = new \stdClass();
        $response->error = 0;
        $publicacion_body = array();

        $marketplace = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_area)
            ->first();

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información del marketplace." . $log;

            return $response;
        }


        $token = self::token($marketplace->app_id, $marketplace->secret);

        $publicacion_body["status"] = "paused";

        $response_data = \Httpful\Request::put(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion_id . "?access_token=" . $token)
            ->addHeader('Content-Type', 'application/json')
            ->body(json_encode($publicacion_body))
            ->send();

        if ($response_data->code != 200) {
            $message = $response_data->body->message . "<br>";

            foreach ($response_data->body->cause as $cause) {
                $message .= $cause->message . "<br>";
            }
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = $message . "" . $log;
            $response->raw = $response_data->body;

            return $response;
        }

        if (property_exists($response_data->body, 'error')) {
            $message = $response_data->body->message . "<br><br>";

            foreach ($response_data->body->cause as $cause) {
                $message .= $cause->message . "<br>";
            }
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = $message . "" . $log;
            $response->raw = $response_data->body;

            return $response;
        }

        return $response;
    }

    // public static function enviarMensaje($marketplace, $venta, $mensaje)
    public static function enviarMensaje($marketplace, $venta)
    {
        set_time_limit(0);

        $response = new \stdClass();

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        extra_2,
                                        app_id,
                                        secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la API del marketplace con el ID " . $marketplace . "" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];
        $token = self::token($marketplace->app_id, $marketplace->secret);

        $mensaje = "Para adjuntarte la factura de tu compra necesito los siguientes datos:\n\n";
        $mensaje .= "• Nombre y apellido\n";
        $mensaje .= "• RFC\n";
        $mensaje .= "• Domicilio\n";
        $mensaje .= "• Código postal";

        $data = array(
            "option_id" => "REQUEST_BILLING_INFO",
            "template_id" => "TEMPLATE___REQUEST_BILLING_INFO___1"
        );

        try {
            $response_message = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "messages/action_guide/packs/" . $venta . "/option?access_token=" . $token)
                ->addHeader('Authorization', $token)
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Cache-control', 'no-cache')
                ->body(json_encode($data))
                ->send();

            $response->error = 0;
            $response->data = $response_message->raw_body;

            return $response;
        } catch (Exception $e) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = $e->getMessage() . "" . $log;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function enviarFactura($documento)
    {
        set_time_limit(0);

        $response = new \stdClass();
        $response->error = 1;

        $marketplace = DB::select("SELECT
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM documento
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información de la API del marketplace del documento " . $documento . "" . $log;

            return $response;
        }

        $informacion_documento = DB::select("SELECT
                                                documento.no_venta,
                                                empresa.bd
                                            FROM documento 
                                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                            WHERE documento.id = " . $documento . "");

        if (empty($informacion_documento)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información del documento en el sistema" . $log;

            return $response;
        }

        $informacion_documento = $informacion_documento[0];
        $marketplace = $marketplace[0];

        $info_factura = @json_decode(file_get_contents(config('webservice.url') . $informacion_documento->bd . '/Factura/Estado/Folio/' . $documento));

        if (empty($info_factura)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información de la factura " . $documento . " en el ERP." . $log;

            return $response;
        }

        if (is_array($info_factura)) {
            $log = self::logVariableLocation();

            $response->mensaje = "Se encontró más de una factura con el mismo folio " . $documento . ", favor de verificar." . $log;

            return $response;
        }

        if ($info_factura->cancelado) {
            $log = self::logVariableLocation();

            $response->mensaje = "La factura " . $documento . " se encuentra cancelada por lo que no es posible enviar la factura." . $log;

            return $response;
        }

        if (!$info_factura->timbrado) {
            $log = self::logVariableLocation();

            $response->mensaje = "La factura " . $documento . " no se encuentra timbrada por lo que no se puede enviar la factura." . $log;

            return $response;
        }

        if (is_null($info_factura->path)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró el archivo de la factura " . $documento . ", favor de revisar." . $log;

            return $response;
        }

        if ($info_factura->rfc === "XAXX010101000") {
            $log = self::logVariableLocation();

            $response->mensaje = "No se envían facturas a publico general, o sea que es refacturación. " . $documento . "" . $log;

            DB::table("documento")->where(["id" => $documento])->update([
                "factura_enviada" => 1
            ]);

            return $response;
        }

        $pdf_factura = config('webservice.url') . $informacion_documento->bd . "/DescargarPDF/Serie/" . $info_factura->serie . "/Folio/" . $info_factura->folio;
        $token = self::token($marketplace->app_id, $marketplace->secret);
        $seller = self::seller($marketplace->extra_2, $token);

        $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $informacion_documento->no_venta . "&sort=date_desc&access_token=" . $token));

        if (empty($informacion_venta)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se pudo obtener información de la venta " . $documento . " en mercadolibre" . $log;

            return $response;
        }

        if (empty($informacion_venta->results)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se pudo obtener información de la venta " . $documento . " en mercadolibre" . $log;

            return $response;
        }

        $venta = $informacion_venta->results[0];

        $pack_id = explode(".", empty($venta->pack_id) ? $venta->id : sprintf('%lf', $venta->pack_id))[0];

        try {
            $pdf = tempnam('', 'pdf');
            rename($pdf, $pdf .= '.pdf');
            $file = fopen($pdf, 'r+');
            fwrite($file, file_get_contents($pdf_factura));

            $data = array(
                "fiscal_document" => $pdf
            );

            $request = \Httpful\Request::post(config("webservice.mercadolibre_enpoint") . "packs/" . $pack_id . "/fiscal_documents?access_token=" . $token);

            $request->sendsType(\Httpful\Mime::FORM);
            $request->addHeader('Content-Type', 'multipart/form-data');
            $request->attach($data);
            $response_data = $request->send();

            $response_data = @json_decode($response_data->raw_body);

            if (empty($response_data)) {
                $log = self::logVariableLocation();

                $response->mensaje = "Ocurrió un error al subir la factura " . $documento . " a Mercadolibre" . $log;

                return $response;
            }

            if (property_exists($response_data, "statusCode")) {
                $log = self::logVariableLocation();

                $response->mensaje = $response_data->message . "" . $log;

                return $response;
            }

            if (property_exists($response_data, "ids")) {
                DB::table("documento")->where(["id" => $documento])->update([
                    "factura_enviada" => 1
                ]);

                $response->error = 0;

                return $response;
            }
        } catch (Exception $e) {
            $log = self::logVariableLocation();

            $response->mensaje = $e->getMessage() . "" . $log;

            return $response;
        }
    }

    private static function postLabelary($path, $isFile)
    {
        $curl = curl_init();
        /* Cambiar a PDF cuando vuelva a funcionar el servicio de Labelary */
        curl_setopt($curl, CURLOPT_URL, "http://api.labelary.com/v1/printers/8dpmm/labels/4x8/");
        curl_setopt($curl, CURLOPT_POST, true);

        if ($isFile) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $path);
        } else {
            curl_setopt($curl, CURLOPT_POSTFIELDS, substr(file_get_contents($path), 0, 3) . "^CI28" . substr(file_get_contents($path), 3, strlen(file_get_contents($path))));
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Accept: application/pdf"));

        $pdf = tempnam('', 'zpl');
        rename($pdf, $pdf .= '.pdf');
        $file = fopen($pdf, 'r+');
        fwrite($file, curl_exec($curl));

        return $pdf;
    }

    public static function seller($pseudonimo, $token)
    {
        $url = config("webservice.mercadolibre_enpoint")."users/me";

        $options = [
            "http" => [
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($options);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return response()->json(["error" => "No se pudo obtener información del usuario"], 500);
        }

        return @json_decode($response, false);

    }

    public static function token($app_id, $secret_key)
    {
        $existe = DB::select("SELECT token FROM marketplace_api WHERE app_id = '" . $app_id . "' AND secret = '" . $secret_key . "' AND '" . date("Y-m-d H:i:s") . "' >= token_created_at AND '" . date("Y-m-d H:i:s") . "' <= token_expired_at AND token != 'N/A'");

        if (empty($existe)) {
            try {
                $decoded_secret_key = Crypt::decrypt($secret_key);
            } catch (DecryptException $e) {
                $decoded_secret_key = "";
            }

            $mp = new MP($app_id, $decoded_secret_key);
            $access_token = $mp->get_access_token();

            DB::table("marketplace_api")->where(["app_id" => $app_id, "secret" => $secret_key])->update([
                "token" => $access_token,
                "token_created_at" => date("Y-m-d H:i:s"),
                "token_expired_at" => date("Y-m-d H:i:s", strtotime("+6 hours"))
            ]);

            return $access_token;
        }

        return $existe[0]->token;
    }

    public static function checarEstado($documento, $tipo, $marketplace_id)
    {
        set_time_limit(0);
        // $tipo=1 Documento
        // $tipo=2 no_venta

        $response = new \stdClass();

        if ($tipo == 1) {
            $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret,
                                        documento.no_venta
                                    FROM documento
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    WHERE documento.id = " . $documento . "");

            if (empty($marketplace)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

                return $response;
            }

            $marketplace = $marketplace[0];
            $token = self::token($marketplace->app_id, $marketplace->secret);
            $seller = self::seller($marketplace->extra_2, $token);

            $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $marketplace->no_venta . "&sort=date_desc&access_token=" . $token));

            if (empty($informacion_venta)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

                return $response;
            }

            if (empty($informacion_venta->results)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

                return $response;
            }

            $informacion_venta = $informacion_venta->results[0];
            $shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $marketplace->no_venta . "/shipments?access_token=" . $token));

            if (empty($shipping)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información del envío de la venta " . $marketplace->no_venta . ", verificar que no esté cancelada" . $log;

                return $response;
            }
        } else if ($tipo == 2) {
            $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

            if (empty($marketplace)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

                return $response;
            }
            $marketplace = $marketplace[0];
            $token = self::token($marketplace->app_id, $marketplace->secret);
            $seller = self::seller($marketplace->extra_2, $token);

            $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $documento . "&sort=date_desc&access_token=" . $token));

            if (empty($informacion_venta)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $documento . " en Mecadolibre." . $log;

                return $response;
            }

            if (empty($informacion_venta->results)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $documento . " en Mecadolibre." . $log;

                return $response;
            }

            $informacion_venta = $informacion_venta->results[0];
            $shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $documento . "/shipments?access_token=" . $token));

            if (empty($shipping)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información del envío de la venta " . $documento . ", verificar que no esté cancelada" . $log;

                return $response;
            }
        }

        $response->error = 0;
        $response->info = $informacion_venta;
        $response->estado = $shipping->status;
        $response->subestado = $shipping->substatus;

        return $response;
    }

    public static function checarCancelados($documento, $tipo, $marketplace_id)
    {
        set_time_limit(0);
        // $tipo=1 Documento
        // $tipo=2 no_venta

        $response = new \stdClass();

        if ($tipo == 1) {
            $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret,
                                        documento.no_venta
                                    FROM documento
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    WHERE documento.id = " . $documento . "");

            if (empty($marketplace)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

                return $response;
            }

            $marketplace = $marketplace[0];
            $token = self::token($marketplace->app_id, $marketplace->secret);
            $seller = self::seller($marketplace->extra_2, $token);

            $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $marketplace->no_venta . "&sort=date_desc&access_token=" . $token));

            if (empty($informacion_venta)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

                return $response;
            }

            if (empty($informacion_venta->results)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $marketplace->no_venta . " en Mecadolibre." . $log;

                return $response;
            }

            $informacion_venta = $informacion_venta->results[0];
        } else if ($tipo == 2) {
            $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

            if (empty($marketplace)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador." . $log;

                return $response;
            }
            $marketplace = $marketplace[0];
            $token = self::token($marketplace->app_id, $marketplace->secret);
            $seller = self::seller($marketplace->extra_2, $token);

            $informacion_venta = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?seller=" . $seller->id . "&q=" . $documento . "&sort=date_desc&access_token=" . $token));

            if (empty($informacion_venta)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $documento . " en Mecadolibre." . $log;

                return $response;
            }

            if (empty($informacion_venta->results)) {
                $log = self::logVariableLocation();

                $response->error = 1;
                $response->mensaje = "No se encontró información de la venta " . $documento . " en Mecadolibre." . $log;

                return $response;
            }

            $informacion_venta = $informacion_venta->results[0];
        }

        if ($informacion_venta->status == 'cancelled') {
            $response->error = 0;
            $response->cancelada = true;
        } else {
            $response->error = 0;
            $response->cancelada = false;
        }

        return $response;
    }

    public static function getUserDataByNickname($nickname, $marketplace_id)
    {
        $response = new \stdClass();
        $response->error = 1;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_id)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }

        $token = self::token($marketplace_data->app_id, $marketplace_data->secret);

        $url = "https://api.mercadolibre.com/sites/MLM/search?nickname={$nickname}";

        $options = [
            "http" => [
                "header" => "Authorization: Bearer " . $token
            ]
        ];

        $context = stream_context_create($options);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return response()->json(["error" => "No se pudo obtener información del usuario"], 500);
        }

        return response()->json(json_decode($response, true));

    }

    public static function getMarketplaceData($marketplace_id)
    {
        $response = new \stdClass();
        $response->error = 0;

        $marketplace_data = DB::table("marketplace_api")
            ->where("id_marketplace_area", $marketplace_id)
            ->first();

        if (!$marketplace_data) {
            $log = self::logVariableLocation();

            $response->error = 1;
            $response->mensaje = "No se encontró información de la publicación." . $log;

            return $response;
        }
        $response->marketplace_data = $marketplace_data;

        return $response;
    }

    public static function logVariableLocation(): string
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'MS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'BRE'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }
}
