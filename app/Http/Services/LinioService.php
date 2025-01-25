<?php

namespace App\Http\Services;

use App\Http\Services\DocumentoService;
use Illuminate\Support\Facades\Crypt;
use Mailgun\Mailgun;
use SimpleXMLElement;
use DOMDocument;
use Exception;
use DateTime;

use DB;

class LinioService
{
    public static function venta($venta, $credenciales)
    {
        $response = new \stdClass();
        $orden_id = 0;

        // The current time. Needed to create the Timestamp parameter below.
        $now = new DateTime();

        // The parameters for our GET request. These will get signed.
        $parameters = array(
            'Action' => 'GetOrders',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'CreatedBefore' => $now->format(DateTime::ISO8601),
            'Offset' => 0,
            'Limit' => 1000,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
            'SortBy' => 'created_at',
            'SortDirection' => 'DESC',
            'Status' => 'pending'
        );

        $raw_ventas = self::request_data($parameters, $credenciales->secret);
        $ventas = @json_decode($raw_ventas);

        if (empty($ventas)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
            $response->raw = $raw_ventas;

            return $response;
        }

        if (property_exists($ventas, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        if (is_array($ventas->SuccessResponse->Body->Orders->Order)) {
            foreach ($ventas->SuccessResponse->Body->Orders->Order as $orden) {
                if ($orden->OrderNumber == $venta) {
                    $orden_id = $orden->OrderId;
                }
            }
        } else {
            if ($ventas->SuccessResponse->Body->Orders->Order->OrderNumber == $venta) {
                $orden_id = $ventas->SuccessResponse->Body->Orders->Order->OrderId;
            }
        }

        if ($orden_id == 0) {
            $parameters = array(
                'Action' => 'GetOrders',
                'UserID' => $credenciales->app_id,
                'Version' => '1.0',
                'CreatedBefore' => $now->format(DateTime::ISO8601),
                'Offset' => 0,
                'Limit' => 1000,
                'Format' => 'JSON',
                'Timestamp' => $now->format(DateTime::ISO8601),
                'SortBy' => 'created_at',
                'SortDirection' => 'DESC',
                'Status' => 'ready_to_ship'
            );

            $raw_ventas = self::request_data($parameters, $credenciales->secret);
            $ventas = @json_decode($raw_ventas);

            if (empty($ventas)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
                $response->raw = $raw_ventas;

                return $response;
            }

            if (property_exists($ventas, 'ErrorResponse')) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas->ErrorResponse->Head->ErrorMessage . "" . $log;

                return $response;
            }

            if (is_array($ventas->SuccessResponse->Body->Orders->Order)) {
                foreach ($ventas->SuccessResponse->Body->Orders->Order as $orden) {
                    if ($orden->OrderNumber == $venta) {
                        $orden_id = $orden->OrderId;
                    }
                }
            } else {
                if ($ventas->SuccessResponse->Body->Orders->Order->OrderNumber == $venta) {
                    $orden_id = $ventas->SuccessResponse->Body->Orders->Order->OrderId;
                }
            }
        }

        if ($orden_id == 0) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "El número de venta no fue encontrado en las ventas recientes, favor de verificar e intentar de nuevo." . $log;

            return $response;
        }

        $parameters = array(
            'Action' => 'GetOrder',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $orden_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $raw_venta = self::request_data($parameters, $credenciales->secret);
        $venta = @json_decode($raw_venta);

        if (empty($venta)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de la venta, favor de contactar al administrador." . $log;
            $response->raw = $raw_venta;

            return $response;
        }

        $venta = $venta->SuccessResponse->Body->Orders->Order;

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $orden_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $productos_raw = self::request_data($parameters, $credenciales->secret);
        $productos = json_decode($productos_raw);

        if (empty($productos_raw)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de los productos de la venta, favor de contactar a un administrador." . $log;
            $response->raw = $productos_raw;

            return $response;
        }

        $venta->productos  = $productos->SuccessResponse->Body->OrderItems->OrderItem;

        $response->error = 0;
        $response->data = $venta;

        return $response;
    }

    public static function documento($venta, $credenciales)
    {
        $archivos = array();
        $response = new \stdClass();
        $orden_id = 0;

        // The current time. Needed to create the Timestamp parameter below.
        $now = new DateTime();

        // The parameters for our GET request. These will get signed.
        $parameters = array(
            'Action' => 'GetOrders',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'CreatedBefore' => $now->format(DateTime::ISO8601),
            'Offset' => 0,
            'Limit' => 1000,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
            'SortBy' => 'created_at',
            'SortDirection' => 'ASC',
            'Status' => 'ready_to_ship'
        );

        $data = @json_decode(self::request_data($parameters, $credenciales->secret));

        if (empty($data)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar las ordenes de Linio, error desconocido" . $log;

            return $response;
        }

        if (property_exists($data, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar las ordenes de Linio, mensaje de error -> " . $data->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        if (is_array($data->SuccessResponse->Body->Orders->Order)) {
            foreach ($data->SuccessResponse->Body->Orders->Order as $orden) {
                if ($orden->OrderNumber == $venta) {
                    $orden_id = $orden->OrderId;
                }
            }
        } else {
            if ($data->SuccessResponse->Body->Orders->Order->OrderNumber == $venta) {
                $orden_id = $data->SuccessResponse->Body->Orders->Order->OrderId;
            }
        }

        if ($orden_id == 0) {
            $parameters = array(
                'Action' => 'GetOrders',
                'UserID' => $credenciales->app_id,
                'Version' => '1.0',
                'CreatedBefore' => $now->format(DateTime::ISO8601),
                'Offset' => 0,
                'Limit' => 1000,
                'Format' => 'JSON',
                'Timestamp' => $now->format(DateTime::ISO8601),
                'SortBy' => 'created_at',
                'SortDirection' => 'ASC',
                'Status' => 'pending'
            );

            $data = @json_decode(self::request_data($parameters, $credenciales->secret));

            if (empty($data)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al buscar las ordenes de Linio, error desconocido" . $log;

                return $response;
            }

            if (property_exists($data, 'ErrorResponse')) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al buscar las ordenes de Linio, mensaje de error -> " . $data->ErrorResponse->Head->ErrorMessage . "" . $log;

                return $response;
            }

            if (is_array($data->SuccessResponse->Body->Orders->Order)) {
                foreach ($data->SuccessResponse->Body->Orders->Order as $orden) {
                    if ($orden->OrderNumber == $venta) {
                        $orden_id = $orden->OrderId;
                    }
                }
            } else {
                if ($data->SuccessResponse->Body->Orders->Order->OrderNumber == $venta) {
                    $orden_id = $data->SuccessResponse->Body->Orders->Order->OrderId;
                }
            }

            if ($orden_id == 0) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "El número de venta no fue encontrado." . $log;
                $response->raw = is_array($data->SuccessResponse->Body->Orders->Order) ? $data->SuccessResponse->Body->Orders->Order : 0;

                return $response;
            }
        }

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $orden_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $data = @json_decode(self::request_data($parameters, $credenciales->secret));

        if (empty($data)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al buscar los productos de la venta, error desconocido" . $log;

            return $response;
        }

        if (property_exists($data, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrio un error al buscar los productos de la venta, mensaje de error -> " . $data->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        $productos = $data->SuccessResponse->Body->OrderItems->OrderItem;
        $productos_id = array();

        if (is_array($productos)) {
            foreach ($productos as $producto) {
                if ($producto->ShippingType == "Dropshipping") {
                    if ($producto->Status == "pending") {
                        $paqueteria = DB::table("documento")
                            ->select("paqueteria.paqueteria", "documento.id_marketplace_area")
                            ->join("paqueteria", "documento.id_paqueteria", "=", "paqueteria.id")
                            ->where("documento.no_venta", $venta)
                            ->first();

                        if (!$paqueteria) {
                            $log = self::logVariableLocation();
                            $response->error = 1;
                            $response->mensaje = "No se encontró información de la paquetería de la venta " . $venta . "" . $log;

                            return $response;
                        }

                        $cambiar_estatus = self::cambiarEstadoVenta($orden_id, $paqueteria->id_marketplace_area, $paqueteria->paqueteria);

                        if ($cambiar_estatus->error) {
                            return $cambiar_estatus;
                        }
                    }

                    array_push($productos_id, $producto->OrderItemId);
                }
            }
        } else {
            if ($productos->ShippingType != "Dropshipping") {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "La venta no contiene productos con Dropshipping" . $log;

                return $response;
            }

            if ($productos->Status == "pending") {
                $paqueteria = DB::table("documento")
                    ->select("paqueteria.paqueteria", "documento.id_marketplace_area")
                    ->join("paqueteria", "documento.id_paqueteria", "=", "paqueteria.id")
                    ->where("documento.no_venta", $venta)
                    ->first();

                if (!$paqueteria) {
                    $log = self::logVariableLocation();
                    $response->error = 1;
                    $response->mensaje = "No se encontró información de la paquetería de la venta " . $venta . "" . $log;

                    return $response;
                }

                $cambiar_estatus = self::cambiarEstadoVenta($orden_id, $paqueteria->id_marketplace_area, $paqueteria->paqueteria);

                if ($cambiar_estatus->error) {
                    return $cambiar_estatus;
                }
            }

            array_push($productos_id, $productos->OrderItemId);
        }

        // The parameters for our GET request. These will get signed.
        $parameters = array(
            'Action' => 'GetDocument',
            'UserID' => $credenciales->app_id,
            'DocumentType' => 'shippingParcel',
            'OrderItemIds' => $productos_id,
            'Format' => 'JSON',
            'Version' => '1.0',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $data = json_decode(self::request_data($parameters, $credenciales->secret));

        if (empty($data)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrio un error al buscar el documento de linio, mensaje desconocido" . $log;

            return $response;
        }

        if (property_exists($data, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrio un error al buscar el documento de linio, mensaje de error -> " . $data->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        if (is_array($data->SuccessResponse->Body->Documents)) {
            foreach ($data->SuccessResponse->Body->Documents as $document) {
                array_push($archivos, $document->File);
            }
        } else {
            array_push($archivos, $data->SuccessResponse->Body->Documents->Document->File);
        }

        $response->error = 0;
        $response->file = $archivos;

        return $response;
    }

    public static function importarVentas($marketplace_id, $tipo_importacion, $usuario, $fecha_inicial = "", $fecha_final = "")
    {
        $response = new \stdClass();
        $archivos = array();
        $ventas = array();

        $credenciales = DB::table("marketplace_area")
            ->select(
                "marketplace_area.id",
                "marketplace_api.app_id",
                "marketplace_api.secret",
                "marketplace_api.extra_2",
                "marketplace.marketplace"
            )
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $marketplace_id)
            ->first();

        if (!$credenciales) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "No se encontró información de las credenciales del marketplace seleccionado, favor de contactar a un administrador." . $log;

            return $response;
        }

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $tipo_busqueda = $tipo_importacion == "dropoff" ? "pending" : "delivered";

        $date_created_before = new DateTime();

        if ($tipo_importacion !== "dropoff") {
            $date_created_after = new DateTime(date("Y-m-01 00:00:00"));
            $date_created_before = new DateTime(date("Y-m-t 23:59:59"));
        }

        if (!empty($fecha_inicial) && !empty($fecha_final)) {
            $date_created_after = new DateTime($fecha_inicial . " 00:00:00");
            $date_created_before = new DateTime($fecha_final . " 23:59:59");
        }

        $offset = 0;

        $while_ventas_pending = true;

        $now = new DateTime();

        while ($while_ventas_pending) {
            $parameters = array(
                'Action' => 'GetOrders',
                'UserID' => $credenciales->app_id,
                'Version' => '1.0',
                'CreatedBefore' => $date_created_before->format(DateTime::ISO8601),
                'Offset' => $offset,
                'Limit' => 1000,
                'Format' => 'JSON',
                'Timestamp' => $now->format(DateTime::ISO8601),
                'SortBy' => 'created_at',
                'SortDirection' => 'DESC',
                'Status' => $tipo_busqueda
            );

            if ($tipo_importacion !== "dropoff") {
                $parameters["CreatedAfter"] = $date_created_after->format(DateTime::ISO8601);
            }

            $raw_ventas_pending = self::request_data($parameters, $credenciales->secret);
            $ventas_pending = @json_decode($raw_ventas_pending);

            if (empty($ventas_pending)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
                $response->raw = $raw_ventas_pending;

                return $response;
            }

            if (property_exists($ventas_pending, 'ErrorResponse')) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas_pending->ErrorResponse->Head->ErrorMessage . "" . $log;

                return $response;
            }

            if (!property_exists($ventas_pending->SuccessResponse->Body->Orders, "Order")) {
                $while_ventas_pending = null;

                break;
            }

            if (is_array($ventas_pending->SuccessResponse->Body->Orders->Order)) {
                $ventas_new = $ventas_pending->SuccessResponse->Body->Orders->Order;
            } else {
                $ventas_new = [$ventas_pending->SuccessResponse->Body->Orders->Order];
            }

            # Si ya no hay ventas en el resultado, se rompe el ciclo
            if (empty($ventas_new)) {
                $while_ventas_pending = null;

                break;
            }

            $ventas = array_merge($ventas, $ventas_new);
            $offset += 1000;
        }

        if ($tipo_importacion !== "dropoff") {
            $while_ventas_shipped = true;

            $offset = 0;

            while ($while_ventas_shipped) {
                $parameters = array(
                    'Action' => 'GetOrders',
                    'UserID' => $credenciales->app_id,
                    'Version' => '1.0',
                    'CreatedAfter' => $date_created_after->format(DateTime::ISO8601),
                    'CreatedBefore' => $date_created_before->format(DateTime::ISO8601),
                    'Offset' => $offset,
                    'Limit' => 1000,
                    'Format' => 'JSON',
                    'Timestamp' => $now->format(DateTime::ISO8601),
                    'SortBy' => 'created_at',
                    'SortDirection' => 'DESC',
                    'Status' => 'shipped'
                );

                $raw_ventas_shipped = self::request_data($parameters, $credenciales->secret);
                $ventas_shipped = @json_decode($raw_ventas_shipped);

                if (empty($ventas_shipped)) {
                    $log = self::logVariableLocation();
                    $response->error = 1;
                    $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
                    $response->raw = $raw_ventas_shipped;

                    return $response;
                }

                if (property_exists($ventas_shipped, 'ErrorResponse')) {
                    $log = self::logVariableLocation();
                    $response->error = 1;
                    $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas_shipped->ErrorResponse->Head->ErrorMessage . "" . $log;

                    return $response;
                }

                if (!property_exists($ventas_shipped->SuccessResponse->Body->Orders, "Order")) {
                    $while_ventas_shipped = null;

                    break;
                }

                if (is_array($ventas_shipped->SuccessResponse->Body->Orders->Order)) {
                    $ventas_new = $ventas_shipped->SuccessResponse->Body->Orders->Order;
                } else {
                    $ventas_new = [$ventas_shipped->SuccessResponse->Body->Orders->Order];
                }

                # Si ya no hay ventas en el resultado, se rompe el ciclo
                if (empty($ventas_new)) {
                    $while_ventas_shipped = null;

                    break;
                }

                $ventas = array_merge($ventas, $ventas_new);
                $offset += 1000;
            }
        }

        foreach ($ventas as $venta) {
            $venta->Error = 0;

            $precio_pagado = 0;
            $cupones = 0;
            $envio_pagado = 0;
            $total_pagado = 0;

            $venta_a_buscar = $tipo_importacion !== "dropoff" ? $venta->OrderNumber . "F" : $venta->OrderNumber;

            $existe_venta = DB::table("documento")
                ->where("no_venta", $venta_a_buscar)
                ->where("id_marketplace_area", $marketplace_id)
                ->where("status", 1)
                ->first();

            if ($existe_venta) {
                $log = self::logVariableLocation();
                $venta->Error = 1;
                $venta->ErrorMessage = "La venta " . $venta->OrderNumber . " ya está registrada en el sistema." . $log;

                continue;
            }

            $parameters = array(
                'Action' => 'GetOrderItems',
                'UserID' => $credenciales->app_id,
                'Version' => '1.0',
                'OrderId' => $venta->OrderId,
                'Format' => 'JSON',
                'Timestamp' => $now->format(DateTime::ISO8601),
            );

            $productos_raw = self::request_data($parameters, $credenciales->secret);
            $productos = json_decode($productos_raw);

            if (empty($productos_raw)) {
                $log = self::logVariableLocation();
                $venta->Error = 1;
                $venta->ErrorMessage = "No fue posible obtener información de los productos de la venta, favor de contactar a un administrador." . $log;

                continue;
            }

            $productos = is_array($productos->SuccessResponse->Body->OrderItems->OrderItem) ? $productos->SuccessResponse->Body->OrderItems->OrderItem : [$productos->SuccessResponse->Body->OrderItems->OrderItem];

            $venta->Productos = array();
            $venta->ProductosPublicacion = array();
            $venta->Fase = 3;

            foreach ($productos as $producto) {
                if ($tipo_importacion !== "dropoff" && $producto->ShippingType !== "Own Warehouse") {
                    continue;
                }

                foreach ($venta->Productos as $venta_producto) {
                    if ($producto->ShopSku === $venta_producto->ShopSku && $producto->PaidPrice === $venta_producto->PaidPrice && $producto->ShippingType === $venta_producto->ShippingType) {
                        $venta_producto->Cantidad++;

                        continue 2;
                    }
                }

                $producto->Cantidad = 1;


                array_push($venta->Productos, $producto);
            }

            if (empty($venta->Productos)) {
                $log = self::logVariableLocation();
                $venta->Fase = 1;
                $venta->Error = 1;
                $venta->ErrorMessage = "La venta " . $venta->OrderNumber . " no tiene productos para la importacion " . $tipo_importacion . "" . $log;
                $venta->Seguimiento = "La venta " . $venta->OrderNumber . " no tiene productos para la importacion " . $tipo_importacion . "";

                continue;
            }

            $producto_dropoff = 0;
            $producto_fulfillment = 0;

            foreach ($venta->Productos as $producto) {
                if ($producto->ShippingType === "Dropshipping") {
                    $producto_dropoff = 1;
                }

                if ($producto->ShippingType === "Own Warehouse") {
                    $producto_fulfillment = 1;
                }

                $existe_publicacion = DB::table("marketplace_publicacion")
                    ->select(
                        "marketplace_publicacion.id",
                        "marketplace_publicacion.id_almacen_empresa",
                        "marketplace_publicacion.id_almacen_empresa_fulfillment",
                        "marketplace_publicacion.id_proveedor",
                        "empresa.bd",
                        "empresa_almacen.id_erp AS id_almacen"
                    )
                    ->join("empresa_almacen", "marketplace_publicacion.id_almacen_empresa", "=", "empresa_almacen.id")
                    ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
                    ->where("publicacion_id", $producto->ShopSku)
                    ->first();

                if (!$existe_publicacion) {
                    $log = self::logVariableLocation();
                    $venta->Fase = 1;
                    $venta->Error = 1;
                    $venta->ErrorMessage = "No se encontró la publicación de la venta " . $venta->OrderNumber . " registrada en el sistema, por lo tanto, no hay relación de productos " . $producto->ShopSku . "" . $log;
                    $venta->Seguimiento = "No se encontró la publicación de la venta " . $venta->OrderNumber . " registrada en el sistema, por lo tanto, no hay relación de productos " . $producto->ShopSku . "";

                    continue 2;
                }

                $productos_publicacion = DB::table("marketplace_publicacion_producto")
                    ->where("id_publicacion", $existe_publicacion->id)
                    ->get()
                    ->toArray();

                if (empty($productos_publicacion)) {
                    $log = self::logVariableLocation();
                    $venta->Fase = 1;
                    $venta->Error = 1;
                    $venta->ErrorMessage = "No hay relación entre productos y la publicación " . $producto->ShopSku . " en la venta " . $venta->OrderNumber . "" . $log;
                    $venta->Seguimiento = "No hay relación entre productos y la publicación " . $producto->ShopSku . " en la venta " . $venta->OrderNumber . "";

                    continue 2;
                }

                $porcentaje_total = 0;

                foreach ($productos_publicacion as $producto_publicacion) {
                    $porcentaje_total += $producto_publicacion->porcentaje;
                }

                if ($porcentaje_total != 100) {
                    $log = self::logVariableLocation();
                    $venta->Fase = 1;
                    $venta->Error = 1;
                    $venta->ErrorMessage = "Los productos de la publicación " . $producto->ShopSku . " no suman un porcentaje total de 100%" . $log;
                    $venta->Seguimiento = "Los productos de la publicación " . $producto->ShopSku . " no suman un porcentaje total de 100%";

                    continue 2;
                }

                $producto->Almacen = $producto->ShippingType === "Dropshipping" ? $existe_publicacion->id_almacen_empresa : $existe_publicacion->id_almacen_empresa_fulfillment;
                $producto->AlmacenDropoff = $existe_publicacion->id_almacen_empresa;
                $producto->AlmacenFulfillment = $existe_publicacion->id_almacen_empresa_fulfillment;
                $producto->Proveedor = $existe_publicacion->id_proveedor;

                foreach ($productos_publicacion as $producto_publicacion) {
                    $producto_publicacion->precio = round(($producto_publicacion->porcentaje * $producto->PaidPrice / 100) / $producto_publicacion->cantidad, 6);
                    $producto_publicacion->cantidad = $producto_publicacion->cantidad * $producto->Cantidad;
                    $producto_publicacion->shipping_type = $producto->ShippingType;
                    $producto_publicacion->shipment_provider = $producto->ShipmentProvider;
                    $producto_publicacion->proveedor = $producto->Proveedor;
                    $producto_publicacion->almacen = $producto->Almacen;
                    $producto_publicacion->almacendropoff = $producto->AlmacenDropoff;
                    $producto_publicacion->almacenfulfillment = $producto->AlmacenFulfillment;

                    if ($existe_publicacion->id_proveedor == 0) {
                        $producto_sku = DB::table("modelo")
                            ->select("sku")
                            ->where("id", $producto_publicacion->id_modelo)
                            ->first();

                        $existencia = DocumentoService::existenciaProducto($producto_sku->sku, $producto->Almacen);

                        if ($existencia->error) {
                            $log = self::logVariableLocation();
                            $venta->Fase = 1;
                            $venta->Error = 1;
                            $venta->ErrorMessage = "Ocurrió un error al buscar la existencia del producto " . $producto_sku->sku . " en la venta " . $venta->OrderNumber . ", mensaje de error: " . $existencia->mensaje . "" . $log;
                            $venta->Seguimiento = "Ocurrió un error al buscar la existencia del producto " . $producto_sku->sku . " en la venta " . $venta->OrderNumber . ", mensaje de error: " . $existencia->mensaje . "";

                            continue 3;
                        }

                        if ((int) $existencia->existencia < (int) $producto->Cantidad) {
                            $log = self::logVariableLocation();
                            $venta->Fase = 1;
                            $venta->Error = 1;
                            $venta->ErrorMessage = "No hay suficiente existencia para procesar la venta " . $venta->OrderNumber . " en el almacén " . $producto->Almacen . " del producto " . $producto_sku->sku . "" . $log;
                            $venta->Seguimiento = "No hay suficiente existencia para procesar la venta " . $venta->OrderNumber . " en el almacén " . $producto->Almacen . " del producto " . $producto_sku->sku . "";

                            continue 3;
                        }
                    } else {
                        if ($producto->ShippingType !== "Dropshipping") {
                            $log = self::logVariableLocation();
                            $venta->Fase = 1;
                            $venta->Error = 1;
                            $venta->ErrorMessage = "El producto está marcado como fulfillment y la publicación en el sistema está marcada como proveedor externo, eso es incorrecto." . $log;
                            $venta->Seguimiento = "El producto está marcado como fulfillment y la publicación en el sistema está marcada como proveedor externo, eso es incorrecto";

                            continue 3;
                        }

                        $existe_en_proveedor = DB::table("modelo_proveedor_producto")
                            ->where("id_modelo", $producto_publicacion->id_modelo)
                            ->where("id_modelo_proveedor", $existe_publicacion->id_proveedor)
                            ->first();

                        if (!$existe_en_proveedor) {
                            $log = self::logVariableLocation();
                            $venta->Fase = 1;
                            $venta->Error = 1;
                            $venta->ErrorMessage = "No existe relación entre productos de la publicación y codigos de proveedor en la venta " . $venta->OrderNumber . ", favor de crear la relación para poder continuar con el proceso." . $log;
                            $venta->Seguimiento = "No existe relación entre productos de la publicación y codigos de proveedor en la venta " . $venta->OrderNumber . ", favor de crear la relación para poder continuar con el proceso.";

                            continue 3;
                        }
                    }
                }

                $venta->ProductosPublicacion = array_merge($venta->ProductosPublicacion, $productos_publicacion);
            }

            $venta->Doble = $producto_dropoff && $producto_fulfillment ? 1 : 0;

            foreach ($venta->Productos as $key) {
                $precio_pagado = $precio_pagado + $key->PaidPrice;
                $cupones = $cupones + $key->VoucherAmount;
                $envio_pagado = $envio_pagado + $key->ShippingAmount;
            }

            $total_pagado = $precio_pagado + $cupones + $envio_pagado;
            $venta->SumaProductos = $precio_pagado + $cupones;
            $venta->SumaCupones = $cupones;
            $venta->SumaEnvio = $envio_pagado;
            $venta->SumaTotal = $total_pagado;

            $venta->importar_venta_data = self::importarVenta($venta, $marketplace_id, $usuario);
        }

        $errores = "";

        foreach ($ventas as $venta) {
            if ($venta->Error) {
                $log = self::logVariableLocation();
                $errores .= $venta->ErrorMessage . "<br>" . $log;
            }
        }

        self::enviarEmailErroresImportacion($marketplace_id, $errores, "de importación de ventas " . $tipo_importacion . " de LINIO " . date("Y-m-d H:i:s") . "");

        return $ventas;
    }

    private static function importarVenta($venta_data, $marketplace_id, $usuario)
    {
        $venta_data->productos_documento = array();
        $venta_data->paqueteria_id = 1;
        $venta_data->fase = $venta_data->Fase;
        $venta_data->fulfillment = false;
        $venta_data->usuario = $usuario;
        $venta_data->marketplace_id = $marketplace_id;

        $paqueterias = DB::table("paqueteria")
            ->select("id", "paqueteria")
            ->where("status", 1)
            ->get();

        if (!$venta_data->Doble) {
            $producto_fulfillment = false;

            foreach ($paqueterias as $paqueteria) {
                if (strtolower($paqueteria->paqueteria) === strtolower(explode(" ", $venta_data->Productos[0]->ShipmentProvider)[0])) {
                    $venta_data->paqueteria_id = $paqueteria->id;
                }
            }

            foreach ($venta_data->ProductosPublicacion as $producto) {
                $existe_en_arreglo = false;

                if ($producto->shipping_type !== "Dropshipping") {
                    $producto_fulfillment = true;
                }

                foreach ($venta_data->productos_documento as $producto_documento) {
                    if ($producto_documento->id_modelo == $producto->id_modelo) {
                        $existe_en_arreglo = true;

                        $producto_documento->cantidad += $producto->cantidad;

                        break;
                    }
                }

                if (!$existe_en_arreglo) array_push($venta_data->productos_documento, $producto);
            }

            $venta_data->Proveedor = $venta_data->productos_documento[0]->proveedor;
            $venta_data->Almacen = $venta_data->productos_documento[0]->almacen;

            if (($venta_data->Proveedor != 0 && $venta_data->fase != 1) || ($producto_fulfillment && $venta_data->fase != 1)) {
                $venta_data->fulfillment = true;
                $venta_data->fase = 6;
            }

            $venta_data->OrderNumber = $venta_data->fulfillment ? $venta_data->OrderNumber . "F" : $venta_data->OrderNumber;

            $existe_venta = DB::table("documento")
                ->where("no_venta", $venta_data->OrderNumber)
                ->where("id_marketplace_area", $marketplace_id)
                ->where("status", 1)
                ->first();

            if ($existe_venta) {
                return "La venta " . $venta_data->OrderNumber . " ya éxiste registrada en el pedido " . $existe_venta->id;
            }

            return self::importarVentaIndividual($venta_data);
        } else {
            /* Se importa primero la venta dropshipping */
            $productos = array();

            foreach ($venta_data->ProductosPublicacion as $producto) {
                if ($producto->shipping_type === "Dropshipping") {
                    array_push($productos, $producto);
                }
            }

            foreach ($paqueterias as $paqueteria) {
                if (strtolower($paqueteria->paqueteria) === strtolower(explode(" ", $productos[0]->shipment_provider)[0])) {
                    $venta_data->paqueteria_id = $paqueteria->id;
                }
            }

            foreach ($productos as $producto) {
                $existe_en_arreglo = false;

                foreach ($venta_data->productos_documento as $producto_documento) {
                    if ($producto_documento->id_modelo == $producto->id_modelo) {
                        $existe_en_arreglo = true;

                        $producto_documento->cantidad += $producto->cantidad;

                        break;
                    }
                }

                if (!$existe_en_arreglo) array_push($venta_data->productos_documento, $producto);
            }

            $venta_data->Proveedor = $venta_data->productos_documento[0]->proveedor;
            $venta_data->Almacen = $venta_data->productos_documento[0]->almacendropoff;

            if ($venta_data->Proveedor != 0 && $venta_data->fase != 1) {
                $venta_data->fulfillment = 1;
                $venta_data->fase = 6;
            }

            $existe_venta = DB::table("documento")
                ->where("no_venta", $venta_data->OrderNumber)
                ->where("id_marketplace_area", $marketplace_id)
                ->where("status", 1)
                ->first();

            if (!$existe_venta) {
                self::importarVentaIndividual($venta_data);
            }

            $venta_data->productos_documento = array();
            $productos = array();

            $venta_data->fulfillment = true;

            if ($venta_data->fase != 1) {
                $venta_data->fase = 6;
            }

            foreach ($venta_data->ProductosPublicacion as $producto) {
                if ($producto->shipping_type === "Own Warehouse") {
                    array_push($productos, $producto);
                }
            }

            $venta_data->paqueteria_id = 9;

            foreach ($productos as $producto) {
                $existe_en_arreglo = false;

                foreach ($venta_data->productos_documento as $producto_documento) {
                    if ($producto_documento->id_modelo == $producto->id_modelo) {
                        $existe_en_arreglo = true;

                        $producto_documento->cantidad += $producto->cantidad;

                        break;
                    }
                }

                if (!$existe_en_arreglo) array_push($venta_data->productos_documento, $producto);
            }

            $venta_data->Almacen = $venta_data->productos_documento[0]->almacenfulfillment;
            $venta_data->OrderNumber = $venta_data->OrderNumber . "F";

            $existe_venta = DB::table("documento")
                ->where("no_venta", $venta_data->OrderNumber)
                ->where("id_marketplace_area", $marketplace_id)
                ->where("status", 1)
                ->first();

            if (!$existe_venta) {
                self::importarVentaIndividual($venta_data);
            }
        }
    }

    public static function importarVentaIndividual($venta_data)
    {
        $total_pago = 0;

        $entidad = DB::table('documento_entidad')->insertGetId([
            'razon_social' => $venta_data->CustomerFirstName . " " . $venta_data->CustomerLastName,
            'rfc' => mb_strtoupper('XAXX010101000', 'UTF-8'),
            'telefono' => "0",
            'telefono_alt' => "0",
            'correo' => "0"
        ]);

        $documento = DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_almacen_principal_empresa' => $venta_data->Almacen,
            'id_marketplace_area' => $venta_data->marketplace_id,
            'id_usuario' => $venta_data->usuario,
            'id_paqueteria' => $venta_data->paqueteria_id,
            'id_fase' => $venta_data->fase,
            'id_modelo_proveedor' => $venta_data->Proveedor,
            'no_venta' => $venta_data->OrderNumber,
            'referencia' => "N/A",
            'observacion' => $venta_data->NationalRegistrationNumber,
            'info_extra' => "N/A",
            'fulfillment' => $venta_data->fulfillment,
            'comentario' => $venta_data->OrderId,
            'mkt_publicacion' => $venta_data->Productos[0]->ShopSku,
            'mkt_total' => $venta_data->SumaProductos,
            'mkt_fee' => 0,
            'mkt_coupon' =>
            $venta_data->SumaCupones,
            'mkt_shipping_total' =>
            $venta_data->SumaEnvio,
            'mkt_created_at' => $venta_data->CreatedAt,
            'mkt_user_total' => $venta_data->SumaTotal,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $venta_data->usuario,
            'seguimiento' => "<h2>PEDIDO IMPORTADO AUTOMATICAMENTE</h2>"
        ]);

        if (property_exists($venta_data, 'seguimiento')) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $venta_data->usuario,
                'seguimiento' => $venta_data->Seguimiento
            ]);
        }

        DB::table('documento_entidad_re')->insert([
            'id_entidad' => $entidad,
            'id_documento' => $documento
        ]);

        DB::table('documento_direccion')->insert([
            'id_documento' => $documento,
            'id_direccion_pro' => 0,
            'contacto' => $venta_data->CustomerFirstName . " " . $venta_data->CustomerLastName,
            'calle' => $venta_data->AddressShipping->Address1,
            'numero' => "N/A",
            'numero_int' => "N/A",
            'colonia' => "N/A",
            'ciudad' => "N/A",
            'estado' => "N/A",
            'codigo_postal' => $venta_data->AddressShipping->PostCode,
            'referencia' => $venta_data->DeliveryInfo
        ]);

        foreach ($venta_data->productos_documento as $producto) {
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
            'id_usuario' => $venta_data->usuario,
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
            'entidad_destino' => "",
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

        $paqueteria = DB::table("paqueteria")
            ->select("paqueteria")
            ->where("id", $venta_data->paqueteria_id)
            ->first();

        $cambiarEstadoVenta = "";

        if (!$venta_data->fulfillment) {
            $cambiarEstadoVenta = self::cambiarEstadoVenta($venta_data->OrderId, $venta_data->marketplace_id, $paqueteria->paqueteria);
        }

        if (property_exists($venta_data, 'Proveedor')) {
            if ($venta_data->Proveedor != 0) {
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
                                                    WHERE marketplace_area.id = " . $marketplace_id . "")[0];

                    $guia = self::documento($venta->id, $marketplace_data);

                    if ($guia->error) {
                        $venta_data->Error = 1;

                        DB::table('documento')->where(['id' => $documento])->update([
                            'id_fase' => 1
                        ]);

                        DB::table('seguimiento')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'seguimiento' => "<p>No fue posible descargar las guías de embarque para solicitar la orden de compra al proveedor B2B, mensaje de error: " . $guia->mensaje . "</p>"
                        ]);
                    } else {
                        $nombre = "etiqueta_" . trim($venta->id) . ".pdf";

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

                        switch ($venta_data->Proveedor) {
                            case '4':
                                $crear_pedido_btob = ExelDelNorteService::crearPedido($documento);
                                break;

                            case '5':
                                $crear_pedido_btob = CTService::crearPedido($documento);
                                break;

                            default:
                                $crear_pedido_btob = new \stdClass();

                                $crear_pedido_btob->error = 1;
                                $crear_pedido_btob->mensaje = "El proveedor no ha sido configurado";

                                break;
                        }

                        if ($crear_pedido_btob->error) {
                            $venta_data->Error = 1;

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
                            ->where("tipo", 2)
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

        if ($venta_data->fulfillment) {
            $fullComercial = DocumentoService::crearFactura($documento, 0, 0);
            //Aqui ta
            if ($fullComercial->error) {
                return response()->json([
                    'code'  => 500,
                    'message'   => $fullComercial->mensaje
                ]);
            }
        }

        if ($venta_data->Error) {
            DB::table("documento")->where(["id" => $documento])->update([
                "id_fase" => 1
            ]);
        }

        return $cambiarEstadoVenta;
    }

    public static function validarVenta($documento)
    {
        $response = new \stdClass();
        $response->error = 0;

        $productos = array();
        $fase = 3;
        $paqueteria_id = 1;

        $informacion_documento = DB::table("documento")
            ->where("id", $documento)
            ->first();

        if (!$orden_id) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontró el número de orden del documento." . $log;

            return $response;
        }

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

        $paqueterias = DB::table("paqueteria")
            ->select("id", "paqueteria")
            ->where("status", 1)
            ->get();

        $marketplace = $marketplace[0];

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }


        $response = new \stdClass();

        // The current time. Needed to create the Timestamp parameter below.
        $now = new DateTime();

        $parameters = array(
            'Action' => 'GetOrder',
            'UserID' => $marketplace->app_id,
            'Version' => '1.0',
            'OrderId' => $informacion_documento->comentario,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $raw_venta = self::request_data($parameters, $marketplace->secret);
        $venta = @json_decode($raw_venta);

        if (empty($venta)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de la venta, favor de contactar al administrador." . $log;
            $response->raw = $raw_venta;

            return $response;
        }

        $venta = $venta->SuccessResponse->Body->Orders->Order;

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $marketplace->app_id,
            'Version' => '1.0',
            'OrderId' => $informacion_documento->comentario,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $productos_raw = self::request_data($parameters, $credenciales->secret);
        $productos_venta = json_decode($productos_raw);

        if (empty($productos_raw)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de los productos de la venta, favor de contactar a un administrador." . $log;

            return $response;
        }

        $venta->validar_envio = false;

        $productos_venta = is_array($productos->SuccessResponse->Body->OrderItems->OrderItem) ? $productos->SuccessResponse->Body->OrderItems->OrderItem : [$productos->SuccessResponse->Body->OrderItems->OrderItem];

        foreach ($productos_venta as $producto) {
            if ($informacion_documento->fulfillment && $producto->ShippingType == "Own Warehouse") {
                array_push($productos, $producto);
            }

            if (!$informacion_documento->fulfillment && $producto->ShippingType == "Dropshipping") {
                array_push($productos, $producto);
            }

            if ($producto->Status == "pending") {
                $venta->validar_envio = true;
            }
        }

        if ($informacion_documento->fulfillment) {
            $paqueteria_id = 9;
            $fase = 6;
        } else {
            foreach ($paqueterias as $paqueteria) {
                if (strtolower($paqu6teria->paqueteria) === strtolower(explode(" ", $productos[0]->ShipmentProvider)[0])) {
                    $venta_data->paqueteria_id = $paqueteria->id;
                }
            }
        }

        $venta->productos = array();

        foreach ($productos as $producto) {
            foreach ($venta->Productos as $venta_producto) {
                if ($producto->ShopSku === $venta_producto->ShopSku && $producto->PaidPrice === $venta_producto->PaidPrice) {
                    $venta_producto->Cantidad++;

                    continue 2;
                }
            }

            $producto->Cantidad = 1;

            array_push($venta->productos, $producto);
        }

        $venta->productos_publicacion = array();

        foreach ($venta->productos as $producto) {
            $existe_publicacion = DB::table("marketplace_publicacion")
                ->select(
                    "marketplace_publicacion.id",
                    "marketplace_publicacion.id_almacen_empresa",
                    "marketplace_publicacion.id_almacen_empresa_fulfillment",
                    "marketplace_publicacion.id_proveedor",
                    "empresa.bd",
                    "empresa_almacen.id_erp AS id_almacen"
                )
                ->join("empresa_almacen", "marketplace_publicacion.id_almacen_empresa", "=", "empresa_almacen.id")
                ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
                ->where("publicacion_id", $producto->ShopSku)
                ->first();

            if (!$existe_publicacion) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No se encontró la publicación de la venta " . $venta->OrderNumber . " registrada en el sistema, por lo tanto, no hay relación de productos " . $producto->ShopSku . "" . $log;

                return $response;
            }

            $productos_publicacion = DB::table("marketplace_publicacion_producto")
                ->where("id_publicacion", $existe_publicacion->id)
                ->get()
                ->toArray();

            if (empty($productos_publicacion)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No hay relación entre productos y la publicación " . $producto->ShopSku . " en la venta " . $venta->OrderNumber . "" . $log;

                return $response;
            }

            $porcentaje_total = 0;

            foreach ($productos_publicacion as $producto_publicacion) {
                $porcentaje_total += $producto_publicacion->porcentaje;
            }

            if ($porcentaje_total != 100) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Los productos de la publicación " . $producto->ShopSku . " no suman un porcentaje total de 100%" . $log;

                return $response;
            }

            foreach ($productos_publicacion as $producto_publicacion) {
                $producto_publicacion->precio = round(($producto_publicacion->porcentaje * $producto->PaidPrice / 100) / $producto->Cantidad, 6);
                $producto_publicacion->cantidad = $producto_publicacion->cantidad * $producto->Cantidad;
                $producto_publicacion->almacendropoff = $existe_publicacion->id_almacen_empresa;
                $producto_publicacion->almacenfulfillment = $existe_publicacion->id_almacen_empresa_fulfillment;

                if ($existe_publicacion->id_proveedor == 0) {
                    $producto_sku = DB::table("modelo")
                        ->select("sku")
                        ->where("id", $producto_publicacion->id_modelo)
                        ->first();

                    $existencia = DocumentoService::existenciaProducto($producto_sku->sku, $producto->Almacen);

                    if ($existencia->error) {
                        $log = self::logVariableLocation();
                        $response->error = 1;
                        $response->mensaje = "Ocurrió un error al buscar la existencia del producto " . $producto_sku->sku . " en la venta " . $venta->OrderNumber . ", mensaje de error: " . $existencia->mensaje . "" . $log;

                        return $response;
                    }

                    if ((int) $existencia->existencia < (int) $producto->Cantidad) {
                        $log = self::logVariableLocation();
                        $response->error = 1;
                        $response->mensaje = "No hay suficiente existencia para procesar la venta " . $venta->OrderNumber . " en el almacén " . $venta->Almacen . " del producto " . $producto_sku->sku . "" . $log;

                        return $response;
                    }
                } else {
                    $existe_en_proveedor = DB::table("modelo_proveedor_producto")
                        ->where("id_modelo", $producto_publicacion->id_modelo)
                        ->where("id_modelo_proveedor", $existe_publicacion->id_proveedor)
                        ->first();

                    if (!$existe_en_proveedor) {
                        $log = self::logVariableLocation();
                        $response->error = 1;
                        $response->mensaje = "No existe relación entre productos de la publicación y codigos de proveedor en la venta " . $venta->OrderNumber . ", favor de crear la relación para poder continuar con el proceso." . $log;

                        return $response;
                    }
                }
            }

            $venta->productos_publicacion = array_merge($venta->productos_publicacion, $productos_publicacion);
        }

        if ($venta->error = 0) {
            $venta->almacen = $informacion_documento->fulfillment ? $venta->productos_publicacion[0]->almacenfulfillment : $venta->productos_publicacion[0]->almacendropoff;
        }

        $response->venta = $venta;

        return $response;
    }

    public static function validarVentasCanceladas($marketplace_id)
    {
        $response = new \stdClass();

        $credenciales = DB::table("marketplace_area")
            ->select(
                "marketplace_area.id",
                "marketplace_api.app_id",
                "marketplace_api.secret",
                "marketplace_api.extra_2",
                "marketplace.marketplace"
            )
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $marketplace_id)
            ->first();

        if (!$credenciales) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "No se encontró información de las credenciales del marketplace seleccionado, favor de contactar a un administrador." . $log;

            return $response;
        }

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $created_after = new DateTime(date("Y-m-01 00:00:00"));
        $created_before = new DateTime(date("Y-m-t 00:00:00"));
        $now = new DateTime();

        $parameters = array(
            'Action' => 'GetOrders',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'CreatedAfter' => $created_after->format(DateTime::ISO8601),
            'CreatedBefore' => $created_before->format(DateTime::ISO8601),
            'Offset' => 0,
            'Limit' => 1000,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
            'SortBy' => 'created_at',
            'SortDirection' => 'DESC',
            'Status' => 'canceled'
        );

        $raw_ventas_cancelled = self::request_data($parameters, $credenciales->secret);
        $ventas_cancelled = @json_decode($raw_ventas_cancelled);

        if (empty($ventas_cancelled)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
            $response->raw = $raw_ventas_cancelled;

            return $response;
        }

        if (property_exists($ventas_cancelled, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas_cancelled->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        if (is_array($ventas_cancelled->SuccessResponse->Body->Orders->Order)) {
            $ventas = $ventas_cancelled->SuccessResponse->Body->Orders->Order;
        } else {
            $ventas = [$ventas_cancelled->SuccessResponse->Body->Orders->Order];
        }

        $mensaje = "";

        foreach ($ventas as $venta) {
            $ventas_crm = DB::table("documento")
                ->select("id", "status")
                ->where("no_venta", $venta->OrderNumber)
                ->where("id_marketplace_area", $marketplace_id)
                ->get();

            foreach ($ventas_crm as $venta_crm) {
                if ($venta_crm->status) {
                    $mensaje .= "La venta " . $venta->OrderNumber . " se encuentra cancelada en Linio pero en CRM no, pedido: " . $venta_crm->id . "<br>";
                }
            }
        }

        if (!empty($mensaje)) {
            self::enviarEmailErroresImportacion($marketplace_id, $mensaje, "de validación de ventas canceladas LINIO vs CRM " . date("Y-m-d") . "");
        }
    }

    public static function informacionVenta($venta, $marketplace_id, $status = "pending")
    {
        $response = new \stdClass();

        $credenciales = DB::table("marketplace_area")
            ->select(
                "marketplace_area.id",
                "marketplace_api.app_id",
                "marketplace_api.secret",
                "marketplace_api.extra_2",
                "marketplace.marketplace"
            )
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $marketplace_id)
            ->first();

        if (!$credenciales) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "No se encontró información de las credenciales del marketplace seleccionado, favor de contactar a un administrador." . $log;

            return $response;
        }

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $now = new DateTime();
        $offset = 0;
        $while_ventas = true;

        $parameters = array(
            'Action' => 'GetOrder',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $venta,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $raw_venta_data = self::request_data($parameters, $credenciales->secret);
        $venta_data = @json_decode($raw_venta_data);

        if (!empty($venta_data)) {
            $productos = self::productosPorVenta($venta, $credenciales);

            if ($productos->error) {
                return $productos;
            }

            $venta_data->productos = is_array($productos->data) ? $productos->data : [$productos->data];

            return $venta_data;
        }

        while ($while_ventas) {
            $parameters = array(
                'Action' => 'GetOrders',
                'UserID' => $credenciales->app_id,
                'Version' => '1.0',
                'CreatedBefore' => $now->format(DateTime::ISO8601),
                'Offset' => $offset,
                'Limit' => 1000,
                'Format' => 'JSON',
                'Timestamp' => $now->format(DateTime::ISO8601),
                'SortBy' => 'created_at',
                'SortDirection' => 'DESC',
                'Status' => $status
            );

            $raw_ventas_pending = self::request_data($parameters, $credenciales->secret);
            $ventas_pending = @json_decode($raw_ventas_pending);

            if (empty($ventas_pending)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No fue posible buscar las ultimas ventas en el sistema de Linio, favor de contactar a un administrador." . $log;
                $response->raw = $raw_ventas_pending;

                return $response;
            }

            if (property_exists($ventas_pending, 'ErrorResponse')) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al obtener la información de las ultimas ventas en el sistema de Linio, mensaje de error: " . $ventas_pending->ErrorResponse->Head->ErrorMessage . "" . $log;

                return $response;
            }

            if (!property_exists($ventas_pending->SuccessResponse->Body->Orders, "Order")) {
                $while_ventas_pending = null;

                break;
            }

            if (is_array($ventas_pending->SuccessResponse->Body->Orders->Order)) {
                $ventas = $ventas_pending->SuccessResponse->Body->Orders->Order;
            } else {
                $ventas = [$ventas_pending->SuccessResponse->Body->Orders->Order];
            }

            # Si ya no hay ventas en el resultado, se rompe el ciclo
            if (empty($ventas)) {
                $while_ventas_pending = null;

                break;
            }

            foreach ($ventas as $ventaa) {
                if ($ventaa->OrderNumber == $venta) {
                    $productos = self::productosPorVenta($ventaa->OrderId, $credenciales);

                    if ($productos->error) {
                        return $productos;
                    }

                    $ventaa->productos = is_array($productos->data) ? $productos->data : [$productos->data];

                    return $ventaa;
                }
            }

            $offset += 1000;
        }

        return 0;
    }

    public static function productosPorVenta($venta_id, $credenciales)
    {
        $response = new \stdClass();

        $now = new DateTime();

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $venta_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $productos_raw = self::request_data($parameters, $credenciales->secret);
        $productos = json_decode($productos_raw);

        if (empty($productos_raw)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "No fue posible obtener información de los productos de la venta, favor de contactar a un administrador." . $log;

            return $response;
        }

        $response->error = 0;
        $response->data = $productos;

        return $response;
    }

    public static function cambiarEstadoVenta($orden_id, $marketplace_id, $paqueteria)
    {
        $response = new \stdClass();

        $marketplace = DB::select("SELECT app_id, secret FROM marketplace_api WHERE id_marketplace_area = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace." . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        // The current time. Needed to create the Timestamp parameter below.
        $now = new DateTime();

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $marketplace->app_id,
            'Version' => '1.0',
            'OrderId' => $orden_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $productos_data = @json_decode(self::request_data($parameters, $marketplace->secret));

        if (empty($productos_data)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible obtener los productos de la venta." . $log;

            return $response;
        }

        if (property_exists($productos_data, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrio un error al buscar los productos de la venta, mensaje de error -> " . $productos_data->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        $productos = $productos_data->SuccessResponse->Body->OrderItems->OrderItem;
        $productos_id = array();

        if (is_array($productos)) {
            foreach ($productos as $producto) {
                if ($producto->ShippingType == "Dropshipping" && $producto->Status == "pending") {
                    array_push($productos_id, $producto->OrderItemId);
                }
            }
        } else {
            array_push($productos_id, $productos->OrderItemId);
        }

        $parameters = array(
            'Action' => 'SetStatusToReadyToShip',
            'UserID' => $marketplace->app_id,
            'Version' => '1.0',
            'DeliveryType' => 'dropship',
            'ShippingProvider' => $paqueteria,
            'TrackingNumber' => '',
            'OrderItemIds' => $productos_id,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $response = json_decode(self::request_data($parameters, $marketplace->secret));

        if (property_exists($response, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al cambiar de estado la venta en los sistemas de Linio, mensaje de error -> " . $response->ErrorResponse->Head->ErrorMessage . "" . $log;
            $response->data = $parameters;
            $response->s = $orden_id;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function actualizarPublicaciones($marketplace)
    {
        set_time_limit(0);

        $response = new \stdClass();
        $now = new DateTime();

        $marketplace = DB::table("marketplace_area")
            ->select("marketplace_area.id", "marketplace_api.app_id", "marketplace_api.secret")
            ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $marketplace)
            ->first();

        if (!$marketplace) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontró información de la API del marketplace con el ID " . $marketplace . "" . $log;

            return $response;
        }

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        $parameters = array(
            'Action' => 'GetProducts',
            'UserID' => $marketplace->app_id,
            'Version' => '1.0',
            'Filter' => 'all',
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ISO8601),
        );

        $response_publicaciones = @json_decode(self::request_data($parameters, $marketplace->secret));

        if (empty($response_publicaciones)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "Ocurrió un error al obtener información de las publicaciones" . $log;

            return $response;
        }

        if (property_exists($response_publicaciones, 'ErrorResponse')) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Ocurrió un error al actualizar las publicaciones de linio, mensaje de error: " . $response_publicaciones->ErrorResponse->Head->ErrorMessage . "" . $log;

            return $response;
        }

        $publicaciones = $response_publicaciones->SuccessResponse->Body->Products->Product;

        foreach ($publicaciones as $publicacion) {
            $existe_publicacion = DB::table("marketplace_publicacion")
                ->select("id")
                ->where("publicacion_id", $publicacion->ShopSku)
                ->first();

            if ($existe_publicacion) {
                DB::table('marketplace_publicacion')->where('id', $existe_publicacion->id)->update([
                    'publicacion' => $publicacion->Name,
                    'publicacion_sku' => $publicacion->SellerSku,
                    'status' => $publicacion->Status,
                    'total' => $publicacion->SalePrice,
                ]);
            } else {
                DB::table('marketplace_publicacion')->insert([
                    'id_marketplace_area' => $marketplace->id,
                    'publicacion_id' => $publicacion->ShopSku,
                    'publicacion_sku' => $publicacion->SellerSku,
                    'publicacion' => $publicacion->Name,
                    'total' => $publicacion->SalePrice,
                    'status' => $publicacion->Status,
                ]);
            }
        }

        $response->error = 0;

        return $response;
    }

    public static function enviarEmailErroresImportacion($marketplace_id, $errores, $titulo_email)
    {
        $emails = "";

        $view = view('email.notificacion_error_importacion_linio')->with([
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

    private static function request_data($parameters, $secret)
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

        // Compute signature and add it to the parameters.
        $parameters['Signature'] = rawurlencode(hash_hmac('sha256', $concatenated, $secret, false));

        // Replace with the URL of your API host.
        $url = "https://sellercenter-api.linio.com.mx/?" . $concatenated . '&Signature=' . $parameters['Signature'];

        // Build Query String
        $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $response = \Httpful\Request::post($url)->send();

        return $response;
    }
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'LS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'NIO'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
