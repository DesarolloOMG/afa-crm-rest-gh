<?php

namespace App\Http\Controllers;

use App\Http\Services\MercadolibreService;
use App\Http\Services\GeneralService;
use Illuminate\Http\Request;
use App\Events\PusherEvent;
use Mailgun\Mailgun;
use Exception;
use DateTime;
use DB;
use MP;

class NotificacionesController extends Controller{
    public function notificacion($marketplace_id){0+-
        
        header("HTTP/1.1 200 OK");

        if (empty($marketplace_id)) {
            file_put_contents("logs/mercadolibre.log", date("d/m/Y H:i:s") . " Error: No se encontro el marketplace, token vacío." . PHP_EOL, FILE_APPEND);

            throw new Exception("No se encontró el marketplace, token vacío.", 1);
        }

        $notificacion = file_get_contents("php://input");

        $marketplace = DB::table("marketplace_api")
                        ->where("id_marketplace_area", $marketplace_id)
                        ->first();

        if ($marketplace) {
            $notificacion_data = @json_decode($notificacion);

            if (empty($notificacion_data)) {
                file_put_contents("logs/mercadolibre.log", date("d/m/Y H:i:s") . " Error: Error al obtener informacion del JSON de la notificacion." . PHP_EOL, FILE_APPEND);

                throw new Exception("Error al obtener información del JSON de la notificación.", 1);
            }

            DB::table("notificacion_mercadolibre")->insert([
                "data" => json_encode($notificacion_data)
            ]);

            $token = MercadolibreService::token($marketplace->app_id, $marketplace->secret);
            $seller = MercadolibreService::seller($marketplace->extra_2);
            
            $seller_id  = $seller->seller->id;

            if ($notificacion_data->topic === 'items') {
                $array = explode("/", $notificacion_data->resource);
                $publicacion = $array[count($array) - 1];

                $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $publicacion . "?access_token=" . $token));

                if (empty($publicacion_info)) {
                    file_put_contents("logs/mercadolibre.log", date("d/m/Y H:i:s") . " Error: No se encontro información de la publicacion " . $publicacion . "." . PHP_EOL, FILE_APPEND);

                    throw new Exception("No se encontró información de la publicacion " . $publicacion, 1);
                }

                $existe_publicacion_crm = DB::table("marketplace_publicacion")
                                            ->where("publicacion_id", $publicacion)
                                            ->first();

                if ($existe_publicacion_crm) {
                    if ($existe_publicacion_crm->id_almacen_empresa != 0 || $existe_publicacion_crm->id_almacen_empresa_fulfillment != 0) {
                        $almacen_empresa = $existe_publicacion_crm->id_almacen_empresa == 0 ? $existe_publicacion_crm->id_almacen_empresa_fulfillment : $existe_publicacion_crm->id_almacen_empresa;

                        $empresa = DB::table("empresa_almacen")
                                        ->select("empresa.bd")
                                        ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
                                        ->where("empresa_almacen.id", $almacen_empresa)
                                        ->first();

                        if ($empresa) {
                            if (count($publicacion_info->variations) > 0) {
                                foreach ($publicacion_info->variations as $variacion) {
                                    $variacion_nombre = "";

                                    foreach ($variacion->attribute_combinations as $combinacion) {
                                        $variacion_nombre .= $combinacion->value_name . ", ";
                                    }

                                    $variacion_nombre = substr($variacion_nombre, 0, -1);

                                    $publicacion_productos = DB::table("marketplace_publicacion_producto")
                                                                ->select("modelo.sku", "marketplace_publicacion_producto.cantidad")
                                                                ->join("modelo", "marketplace_publicacion_producto.id_modelo", "=", "modelo.id")
                                                                ->where("marketplace_publicacion_producto.id_publicacion", $existe_publicacion_crm->id)
                                                                ->where("marketplace_publicacion_producto.etiqueta", $variacion->id)
                                                                ->get();

                                    if ($publicacion_productos) {
                                        $costo_total_productos = 0;

                                        foreach ($publicacion_productos as $producto) {
                                            $informacion_producto = GeneralService::informacionProducto($producto->sku, $empresa->bd);

                                            if ($informacion_producto->error) {
                                                return (array) $informacion_producto;
                                            }

                                            $costo_total_productos += (float) $informacion_producto->data->ultimo_costo * $producto->cantidad;
                                        }

                                        $precio_con_descuento = ((10 * (float) $costo_total_productos) / 100);

                                        if ($variacion->price < $precio_con_descuento) {
                                            GeneralService::sendEmailToAdmins("Notificaciones Mercadolibre", "La publicación " . $publicacion_info->permalink . " en su variacion " . $variacion_nombre . " tiene una perdida mayor al 10% comparado con el costo de sus productos relacionados, favor de verificar URGENTE", "La publicación fue pausada", 1, [83, 35]/* Extra emails (User ID) */);
                                            MercadolibreService::desactivarPublicacion($publicacion, $marketplace_id);

                                            break;
                                        }
                                    }
                                }
                            }
                            else {
                                $publicacion_productos = DB::table("marketplace_publicacion_producto")
                                                            ->select("modelo.sku", "marketplace_publicacion_producto.cantidad")
                                                            ->join("modelo", "marketplace_publicacion_producto.id_modelo", "=", "modelo.id")
                                                            ->where("marketplace_publicacion_producto.id_publicacion", $existe_publicacion_crm->id)
                                                            ->get();

                                if ($publicacion_productos) {
                                    $costo_total_productos = 0;

                                    foreach ($publicacion_productos as $producto) {
                                        $informacion_producto = GeneralService::informacionProducto($producto->sku, $empresa->bd);

                                        if ($informacion_producto->error) {
                                            return (array) $informacion_producto;
                                        }

                                        $costo_total_productos += (float) $informacion_producto->data->ultimo_costo * $producto->cantidad;
                                    }

                                    $precio_con_descuento = ((10 * (float) $costo_total_productos) / 100);

                                    if ($publicacion_info->price < $precio_con_descuento) {
                                        if ($publicacion_info->status !== "paused") {
                                            GeneralService::sendEmailToAdmins("Notificaciones Mercadolibre", "La publicación " . $publicacion_info->permalink . " tiene una perdida mayor al 10% comparado con el costo de sus productos relacionados, favor de verificar URGENTE", "La publicación fue pausada", 1, [83, 35]/* Extra emails (User ID) */);
                                            MercadolibreService::desactivarPublicacion($publicacion, $marketplace_id);
                                        }                        
                                    }
                                }   
                            }
                        }
                    }
                }

                if (property_exists($publicacion_info, "original_price")) {
                    if (!is_null($publicacion_info->original_price)) {
                        $porcentaje = (float) $publicacion_info->price * 100 / (float) $publicacion_info->original_price;
                        $porcentaje = 100 - $porcentaje;

                        if ($porcentaje >= 30) {
                            if ($publicacion_info->status !== "paused") {
                                MercadolibreService::desactivarPublicacion($publicacion, $marketplace_id);
                                GeneralService::sendEmailToAdmins("Notificaciones Mercadolibre", "La publicación " . $publicacion_info->permalink . " tiene un descuento mayor al 30%, favor de verificar URGENTE", "La publicación fue pausada", 1, [83, 35]/* Extra emails (User ID) */);
                            }
                        }
                    }
                }
            }
        }
        else {
            file_put_contents("logs/mercadolibre.log", date("d/m/Y H:i:s") . " Error: No se encontraron las credenciales de la api para el marketplace " . $marketplace_id . "." . PHP_EOL, FILE_APPEND);

            throw new Exception("No se encontraron las credenciales de la api para el marketplace " . $marketplace_id . "", 1);
        }
    }

    public function notificacion_linio($nombre){
        header("HTTP/1.1 200 OK");
		
        $notif                  = file_get_contents("php://input");
        $now                    = new DateTime();
        $marketplace_user_total = 0;
        $marketplace_total      = 0;
        $marketplace_fee        = 0;
        $marketplace_coupon     = 0;
        $marketplace_ship       = 0;
        $id_paqueteria          = 1;
        $fulfillment            = 0;
        $importar               = 0;
        $seguimiento            = '';
        $referencia             = '';
        $total_productos        = 0;

        if (!file_exists("logs")) {
            mkdir("logs", 0777, true);
        }

        $info   = DB::select("SELECT app_id, secret, id_marketplace_area FROM marketplace_api WHERE extra_1 = '" . str_replace("%20", " ", $nombre) . "'");

        if (empty($info)) {
            file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " No se encontró ninguna cuenta con el nombre " . $nombre . "" . PHP_EOL, FILE_APPEND);

            throw new Exception("No se encontró ninguna cuenta con el nombre " . $nombre . " registrada en el sistema.", 1);
        }

        $info   = $info[0];
        $noti   = json_decode($notif);

        $parameters = array(
            'Action'        => 'GetOrder',
            'UserID'        => $info->app_id,
            'Version'       => '1.0',
            'OrderId'       => $noti->payload->OrderId,
            'Format'        => 'JSON',
            'Timestamp'     => $now->format(DateTime::ISO8601)
        );

        $response   = json_decode($this->request_data($parameters, $info->secret));

        $data       =   $response->SuccessResponse->Body->Orders->Order;

        $parameters = array(
            'Action'        => 'GetOrderItems',
            'UserID'        => $info->app_id,
            'Version'       => '1.0',
            'OrderId'       => $noti->payload->OrderId,
            'Format'        => 'JSON',
            'Timestamp'     => $now->format(DateTime::ISO8601)
        );

        $response = json_decode($this->request_data($parameters, $info->secret));

        $productos                  = $response->SuccessResponse->Body->OrderItems->OrderItem;
        $productos_publicacion_a    = array();

        $existe_orden = DB::select("SELECT id FROM documento WHERE no_venta = '" . $data->OrderNumber . "'");

        $usuarios_marketplace = DB::select("SELECT id_usuario FROM usuario_marketplace_area WHERE id_marketplace_area = " . $info->id_marketplace_area . "");

        if (empty($existe_orden)) {
            if (is_array($productos)) {
                foreach ($productos as $producto) {
                    $existe_en_array = 0;

                    foreach ($productos_publicacion_a as $producto_publicacion) {
                        if ($producto_publicacion->producto == $producto->ShopSku) {
                            $producto_publicacion->cantidad += 1;
                            $producto_publicacion->cupon    += (property_exists($producto, "VoucherAmount")) ? (float) $producto->VoucherAmount : 0;

                            $existe_en_array = 1;
                        }
                    }

                    if (!$existe_en_array) {
                        $producto_object = new \stdClass();

                        $producto_object->producto  = $producto->ShopSku;
                        $producto_object->cantidad  = 1;
                        $producto_object->precio    = $producto->ItemPrice;
                        $producto_object->cupon     = 0;

                        array_push($productos_publicacion_a, $producto_object);
                    }
                }

                foreach ($productos_publicacion_a as $producto_publicacion) {
                    $existe_publicacion = DB::select("SELECT id, id_almacen FROM marketplace_publicacion WHERE publicacion_id = '" . $producto_publicacion->producto . "'");

                    if (empty($existe_publicacion)) {
                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No se encontró la publicación, por lo tanto, no hay relación de productos " . $producto_publicacion->producto . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                        throw new Exception("No se encontró la publicación, por lo tanto, no hay relación de productos " . $producto_publicacion->producto . ", creación cancelada.", 1);
                    }
                    else {
                        $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                        if (empty($productos_publicacion)) {
                            file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No hay relación entre productos y la publicación " . $producto_publicacion->producto . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                            throw new Exception("No hay relación entre productos y la publicación " . $producto_publicacion->producto . ", creación cancelada.", 1);
                        }
                        else {
                            foreach ($productos_publicacion as $producto) {
                                $producto_codigo    = DB::select("SELECT sku, serie, id_tipo FROM modelo WHERE id = " . $producto->id_modelo . "")[0];

                                $response = \Httpful\Request::get('http://201.7.208.53:11903/api/adminpro/producto/Consulta/Productos/SKU/7/' . rawurlencode(trim($producto_codigo->sku)) . '')->send();

                                $productos_info = $response->body;

                                if (empty($productos_info)) {
                                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: Producto no encontrado, codigo del producto " . $producto_codigo->sku . ", publicacion: " . $producto_publicacion->producto . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                    throw new Exception("Error: Producto no encontrado, codigo del producto " . $producto_codigo->sku . ", publicacion: " . $producto_publicacion->producto . ", creación cancelada.", 1);
                                }

                                if ($producto_codigo->id_tipo == 1) {
                                    $existencia_real = 0;

                                    foreach ($productos_info[0]->existencias->almacenes as $almacen) {
                                        if ($existe_publicacion[0]->id_almacen == $almacen->almacenid) {
                                            $pendientes_surtir = DB::select("SELECT
                                                                            IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                                        FROM documento
                                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                        WHERE modelo.sku = '" . $producto_codigo->sku . "'
                                                                        AND documento.id_almacen = " . $existe_publicacion[0]->id_almacen . "
                                                                        AND documento.id_tipo = 2
                                                                        AND documento.id_fase < 6")[0]->cantidad;

                                            $existencia_real = (int) $almacen->fisico - (int) $pendientes_surtir;
                                        }
                                    }
                                    
                                    if ($existencia_real < ((int) $producto->cantidad * (int) $producto_publicacion->cantidad)) {
                                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No hay suficiente existencia para procesar la venta, codigo del producto " . $producto_codigo->sku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                        throw new Exception("No hay suficiente existencia para procesar la venta, codigo del producto " . $producto_codigo->sku . ", creación cancelada.", 1);
                                    }
                                }

                                $total_productos    += (float) $producto->precio * 1.16 * ((int) $producto->cantidad * (int) $producto_publicacion->cantidad);
                                $marketplace_coupon += (float) $producto_publicacion->cupon;
                                $marketplace_total  += (float) $producto_publicacion->cantidad * (float) $producto_publicacion->precio;
                            }
                        }
                    }
                }
            }
            else {
                $producto = $productos;

                $existe_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $producto->ShopSku . "'");

                if ($producto->ShippingType == 'Own Warehouse') {
                    $fulfillment    = 1;
                    $id_paqueteria  = 9;
                }

                $marketplace_total  = $producto->ItemPrice;

                if (empty($existe_publicacion)) {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No se encontró la publicación, por lo tanto, no hay relación de productos " . $producto->ShopSku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                    throw new Exception("No se encontró la publicación, por lo tanto, no hay relación de productos " . $producto->ShopSku . ", creación cancelada.", 1);
                }
                else {
                    $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                    if (empty($productos_publicacion)) {
                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No hay relación entre productos y la publicación " . $producto->ShopSku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                        throw new Exception("No hay relación entre productos y la publicación " . $producto->ShopSku . ", creación cancelada.", 1);
                    }
                    else {
                        foreach ($productos_publicacion as $producto) {
                            $producto_codigo    = DB::select("SELECT sku, serie, id_tipo FROM modelo WHERE id = " . $producto->id_modelo . "")[0];

                            $response = \Httpful\Request::get('http://201.7.208.53:11903/api/adminpro/producto/Consulta/Productos/SKU/7/' . rawurlencode(trim($producto_codigo->sku)) . '')->send();

                            $productos_info = $response->body;

                            if (empty($productos_info)) {
                                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: Producto no encontrado, codigo del producto " . $producto_codigo->sku . ", publicacion: " . $producto->ShopSku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                throw new Exception("Error: Producto no encontrado, codigo del producto " . $producto_codigo->sku . ", publicacion: " .$producto->ShopSku . ", creación cancelada.", 1);
                            }

                            if ($producto_codigo->id_tipo == 1) {
                                $existencia_real = 0;

                                foreach ($productos_info[0]->existencias->almacenes as $almacen) {
                                    if ($existe_publicacion[0]->id_almacen == $almacen->almacenid) {
                                        $pendientes_surtir = DB::select("SELECT
                                                                        IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                                    FROM documento
                                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                    WHERE modelo.sku = '" . $producto_codigo->sku . "'
                                                                    AND documento.id_almacen = " . $existe_publicacion[0]->id_almacen . "
                                                                    AND documento.id_tipo = 2
                                                                    AND documento.id_fase < 6")[0]->cantidad;

                                        $existencia_real = (int) $almacen->fisico - (int) $pendientes_surtir;
                                    }
                                }
                                
                                if ($existencia_real < ((int) $producto->cantidad * (int) $producto_publicacion->cantidad)) {
                                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No hay suficiente existencia para procesar la venta, codigo del producto " . $producto_codigo->sku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                    throw new Exception("No hay suficiente existencia para procesar la venta, codigo del producto " . $producto_codigo->sku . ", creación cancelada.", 1);
                                }
                            }

                            if ($fulfillment) {
                                if ($producto_codigo->serie) {
                                    $series_disponible = DB::select("SELECT
                                                                        COUNT(*) AS cantidad
                                                                    FROM movimiento
                                                                    INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                                    WHERE movimiento.id_modelo = " . $producto->id_modelo . "
                                                                    AND producto.id_almacen = " . $existe_publicacion[0]->id_almacen . "
                                                                    AND producto.status = 1")[0]->cantidad;

                                    if ($producto->cantidad > $series_disponible) {
                                        file_put_contents("logs/mercadolibre.log", date("d/m/Y H:i:s") . " Error: No hay suficientes series para surtir la venta, codigo del producto " . $producto_codigo->sku . ", publicacion " . $producto->ShopSku . ", venta " . $data->OrderNumber . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                        throw new Exception("No hay suficientes series para surtir la venta, codigo del producto " . $producto_codigo->sku . ", publicacion " . $producto->ShopSku . ", venta " . $data->OrderNumber . ", creación cancelada.", 1);
                                    }
                                }
                            }

                            $total_productos    += (float) $producto->precio * 1.16 * ((int) $producto->cantidad);
                            $marketplace_coupon += (property_exists($producto, "VoucherAmount")) ? (float) $producto->VoucherAmount : 0;
                        }
                    }
                }
            }

            if (!$fulfillment) {
                $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

                foreach ($paqueterias as $paqueteria) {
                    if (is_array($productos)) {
                        if ($paqueteria->paqueteria == explode(" ", $productos[0]->ShipmentProvider)[0]) {
                            $id_paqueteria = $paqueteria->id;
                        }
                    }
                    else {
                        if ($paqueteria->paqueteria == explode(" ", $productos->ShipmentProvider)[0]) {
                            $id_paqueteria = $paqueteria->id;
                        }
                    }
                }
            }

            $existe_entidad = DB::select("SELECT id FROM documento_entidad WHERE razon_social = '" . $data->CustomerFirstName . " " . $data->CustomerLastName . "' AND rfc = 'XAXX010101000'");

            if (empty($existe_entidad)) {
                $entidad = DB::table('documento_entidad')->insertGetId([
                    'razon_social'  => mb_strtoupper($data->CustomerFirstName . " " . $data->CustomerLastName, 'UTF-8'),
                    'rfc'           => mb_strtoupper('XAXX010101000', 'UTF-8'),
                    'telefono'      => mb_strtoupper($data->AddressShipping->Phone, 'UTF-8'),
                    'telefono_alt'  => mb_strtoupper($data->AddressShipping->Phone2, 'UTF-8'),
                    'correo'        => mb_strtoupper($data->AddressShipping->CustomerEmail, 'UTF-8')
                ]);
            }
            else {
                $entidad = $existe_entidad[0]->id;
            }

            $documento = DB::table('documento')->insertGetId([
                'documento_extra'       => '',
                'id_periodo'            => 1,
                'id_cfdi'               => 3,
                'id_marketplace_area'   => $info->id_marketplace_area,
                'id_usuario'            => 1,
                'id_moneda'             => 3,
                'id_entidad'            => $entidad,
                'id_paqueteria'         => $id_paqueteria,
                'id_fase'               => ($fulfillment) ? 5 : 2,
                'no_venta'              => $data->OrderNumber,
                'tipo_cambio'           => 1,
                'referencia'            => $data->OrderNumber,
                'observacion'           => $data->OrderNumber,
                'info_extra'            => '',
                'fulfillment'           => $fulfillment,
                'mkt_total'             => $data->Price,
                'mkt_user_total'        => $marketplace_user_total,
                'mkt_fee'               => $marketplace_fee,
                'mkt_coupon'            => $marketplace_coupon,
                'mkt_shipping_total'    => $marketplace_ship,
                'mkt_created_at'        => $data->CreatedAt,
                'started_at'            => date('Y-m-d H:i:s')
            ]);
            
            DB::table('seguimiento')->insert([
                'id_documento'  => $documento,
                'id_usuario'    => 1,
                'seguimiento'   => $seguimiento
            ]);

            $direccion = \Httpful\Request::get('http://201.7.208.53:11903/api/adminpro/Consultas/CP/' . $data->AddressShipping->PostCode)->send();

            $direccion = json_decode($direccion->raw_body);

            if ($direccion->code == 200) {
                $estado             = $direccion->estado[0]->estado;
                $ciudad             = $direccion->municipio[0]->municipio;
                $colonia            = "";
                $id_direccion_pro   = "";

                foreach ($direccion->colonia as $colonia_text) {
                    if (strtolower($colonia_text->colonia) == strtolower(explode(",", $data->AddressShipping->Address2)[0])) {
                        $colonia            = $colonia_text->colonia;
                        $id_direccion_pro   = $colonia_text->codigo;
                    }
                }
            }
            else {
                $estado             = explode(",", $data->AddressShipping->City)[1];
                $ciudad             = explode(",", $data->AddressShipping->City)[2];
                $colonia            = explode(",", $data->AddressShipping->Address2)[0];
                $id_direccion_pro   = "";
            }

            DB::table('documento_direccion')->insert([
                'id_documento'      => $documento,
                'id_direccion_pro'  => '',
                'contacto'          => mb_strtoupper($data->AddressShipping->FirstName . " " . $data->AddressShipping->LastName, 'UTF-8'),
                'calle'             => mb_strtoupper($data->AddressShipping->Address1, 'UTF-8'),
                'numero'            => mb_strtoupper('', 'UTF-8'),
                'numero_int'        => mb_strtoupper('', 'UTF-8'),
                'colonia'           => $colonia,
                'ciudad'            => $ciudad,
                'estado'            => $estado,
                'codigo_postal'     => mb_strtoupper($data->AddressShipping->PostCode, 'UTF-8'),
                'referencia'        => mb_strtoupper($data->AddressShipping->Address3, 'UTF-8'),
            ]);

            if (is_array($productos)) {
                foreach ($productos_publicacion_a as $producto_publicacion) {
                    $id_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $producto_publicacion->producto . "'")[0]->id;

                    $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $id_publicacion . "");

                    foreach ($productos_publicacion as $producto) {
                        $producto_data = DB::select("SELECT sku, descripcion FROM modelo WHERE id = " . $producto->id_modelo . "")[0];

                        DB::table('movimiento')->insert([
                            'id_documento'  => $documento,
                            'id_modelo'     => $producto->id_modelo,
                            'cantidad'      => $producto->cantidad * $producto_publicacion->cantidad,
                            'precio'        => $producto->precio,
                            'garantia'      => $producto->garantia,
                            'modificacion'  => '',
                            'regalo'        => $producto->regalo
                        ]);
                    }   
                }
            }
            else {
                $productos_cambiados = array();

                $id_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $productos->ShopSku . "'")[0]->id;

                $productos_publicacion = DB::select("SELECT * FROM marketplace_publicacion_producto WHERE id_publicacion = " . $id_publicacion . "");

                foreach ($productos_publicacion as $producto) {
                    $producto_data = DB::select("SELECT sku, descripcion, serie FROM modelo WHERE id = " . $producto->id_modelo . "")[0];

                    DB::table('movimiento')->insert([
                        'id_documento'  => $documento,
                        'id_modelo'     => $producto->id_modelo,
                        'cantidad'      => $producto->cantidad,
                        'precio'        => $producto->precio,
                        'garantia'      => $producto->garantia,
                        'modificacion'  => '',
                        'regalo'        => $producto->regalo
                    ]);

                    if ($fulfillment) {
                        if ($producto_data->serie) {
                            for ($i=0; $i < (int) $producto->cantidad; $i++) {
                                $serie_disponible = DB::select("SELECT
                                                                    producto.id
                                                                FROM movimiento
                                                                INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                                INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                                WHERE movimiento.id_modelo = " . $producto->id_modelo . "
                                                                AND producto.id_almacen = " . $existe_publicacion[0]->id_almacen . "
                                                                AND producto.status = 1
                                                                LIMIT 1");

                                if (empty($serie_disponible)) {
                                    foreach ($productos_cambiados as $serie) {
                                        DB::table('producto')->where(['id' => $serie])->update([
                                            'status'    => 1
                                        ]);
                                    }

                                    DB::table('documento')->where(['id' => $documento])->delete();

                                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No se encontraron series disponibles para el producto " . $producto_codigo->sku . ", publicacion " . $productos->ShopSku . ", venta " . $data->OrderNumber . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                                    throw new Exception("No se encontraron series disponibles para el producto " . $producto_codigo->sku . ", publicacion " . $productos->ShopSku . ", venta " . $data->OrderNumber . ", creación cancelada.", 1);
                                }

                                $serie_disponible = $serie_disponible[0]->id;

                                DB::table('producto')->where(['id' => $serie_disponible])->update([
                                    'status'    => 0
                                ]);

                                DB::table('movimiento_producto')->insert([
                                    'id_movimiento' => $movimiento,
                                    'id_producto'   => $serie_disponible
                                ]);

                                array_push($productos_cambiados, $serie_disponible);
                            }
                        }
                    }
                }
            }

//            if (!$fulfillment) {
//                $html = view('email.notificacion_pedido')->with(['cliente' => ($data->CustomerFirstName . " " . $data->CustomerLastName), 'pedido' => $documento, 'anio' => date('Y')]);
//
//                $mg     = Mailgun::create('key-ff8657eb0bb864245bfff77c95c21bef');
//                $domain = config("mailgun.email_from");
//                $mg->messages()->send($domain, array('from'  => 'Laptop México <generico@omg.com.mx>',
//                                        'to'      => 'desarrollo1@omg.com.mx',
//                                        'subject' => '¡Pedido ' . $documento . ' procesado!',
//                                        'html'    => $html->render()));
//            }

//            if (!empty($usuarios_marketplace)) {
//                $usuarios = array();
//
//                $notificacion['titulo']     = "Nueva venta";
//                $notificacion['message']    = ($fulfillment) ? "Se ha importado correctamente la venta: " . $data->OrderNumber . " con el número de pedido: " . $documento . " al ser una venta de fulfillment, fue surtida automaticamente, favor de verificar que esté creada correctamente de lo contrario cancelar e importar manualmente." : "Se ha importado correctamente la venta: " . $data->OrderNumber . " con el número de pedido: " . $documento . ", la podrás visualizar en el menú 'Ventas > Pedidos de venta > Pendientes'";
//                $notificacion['tipo']       = "success"; // success, warning, danger
//
//                $notificacion_id = DB::table('notificacion')->insertGetId([
//                    'data'  => json_encode($notificacion)
//                ]);
//
//                $notificacion['id']         = $notificacion_id;
//
//                foreach ($usuarios_marketplace as $usuario) {
//                    DB::table('notificacion_usuario')->insert([
//                        'id_usuario'        => $usuario->id_usuario,
//                        'id_notificacion'   => $notificacion_id
//                    ]);
//
//                    array_push($usuarios, $usuario->id_usuario);
//                }
//
//                if (!empty($usuarios)) {
//                    $notificacion['usuario']    = $usuarios;
//
//                    event(new PusherEvent(json_encode($notificacion)));
//                }
//            }

            echo "Venta importada correctamente!";
        }
        else {
            echo "Venta actualizada correctamente";
        }
    }

    private function request_data($parameters, $secret){
        // Sort parameters by name.
        ksort($parameters);

        // URL encode the parameters.
        $encoded = array();
        foreach ($parameters as $name => $value) {
            $encoded[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        // Concatenate the sorted and URL encoded parameters into a string.
        $concatenated = implode('&', $encoded);

        // Compute signature and add it to the parameters.
        $parameters['Signature'] = rawurlencode(hash_hmac('sha256', $concatenated, $secret, false));

        // Replace with the URL of your API host.
        $url = "https://sellercenter-api.linio.com.mx/?" . $concatenated . '&Signature=' . $parameters['Signature'];

        // Build Query String
        $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $response = \Httpful\Request::post($url . "?" . $queryString)->send();

        return $response;
    }
    
    private function token($app_id, $secret_key){
        $mp = new MP($app_id, $secret_key);
        $access_token = $mp->get_access_token();

        return $access_token;
    }
}
