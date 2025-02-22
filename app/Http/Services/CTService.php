<?php

namespace App\Http\Services;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use SimpleXMLElement;
use DOMDocument;
use Exception;
use DB;

class CTService
{
    public static function consultarProductos()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $data_proveedor = DB::table("modelo_proveedor")
            ->where("id", 5)
            ->first();

        if (empty($data_proveedor)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontró el proveedor solicitado en el sistema" . $log;

            return $response;
        }

        if ($data_proveedor->next_available_api_call_date > date("Y-m-d H:i:s")) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "El API todavía no está disponible para actualizar productos" . $log;

            return $response;
        }

        try {
            $productos_json = app('filesystem')->disk('ftp-ct')->get("catalogo_xml/productos.json");

            $productos = json_decode($productos_json);

            foreach ($productos as $producto) {
                $existe = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->clave)
                    ->where("id_modelo_proveedor", 5) # 5 es el ID del proveedor CT del norte
                    ->first();

                if (empty($existe)) {
                    $producto_id = DB::table("modelo_proveedor_producto")->insertGetId([
                        "id_modelo_proveedor" => 5,
                        "id_producto" => $producto->clave,
                        "id_marca" => $producto->idMarca,
                        "marca" => $producto->marca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->idCategoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->idSubCategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->clave,
                        "descripcion" => $producto->descripcion_corta,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo,
                        "codigo_barra" => $producto->ean,
                        "precioLista" => $producto->precio * $producto->tipoCambio,
                        "nuevo" => 1,
                        "fecha_nuevo" => date("Y-m-d H:i:s")
                    ]);
                } else {
                    DB::table("modelo_proveedor_producto")->where("id_producto", $producto->idProducto)->where("id_modelo_proveedor", 5)->update([
                        "id_marca" => $producto->idMarca,
                        "marca" => $producto->marca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->idCategoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->idSubCategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->clave,
                        "descripcion" => $producto->descripcion_corta,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo,
                        "codigo_barra" => $producto->ean,
                        "precioLista" => $producto->precio * $producto->tipoCambio,
                        "nuevo" => 1,
                        "fecha_nuevo" => date("Y-m-d H:i:s")
                    ]);

                    $producto_id = $existe->id;
                }

                foreach ($producto->existencia as $key => $existencia) {
                    $existe_almacen = DB::table("modelo_proveedor_almacen")
                        ->select("id")
                        ->where("id_modelo_proveedor", 5)
                        ->where("id_almacen", $key)
                        ->first();

                    if (!empty($existe_almacen)) {
                        $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                            ->where("id_modelo", $producto_id)
                            ->where("id_almacen", $existe_almacen->id)
                            ->first();

                        if (!empty($existe_registro_existencia)) {
                            DB::table("modelo_proveedor_producto_existencia")
                                ->where("id_modelo", $producto_id)
                                ->where("id_almacen", $existe_almacen->id)
                                ->update([
                                    "precio" => $producto->precio * $producto->tipoCambio,
                                    "existencia" => $existencia
                                ]);
                        } else {
                            DB::table("modelo_proveedor_producto_existencia")->insert([
                                "id_modelo" => $producto_id,
                                "id_almacen" => $existe_almacen->id,
                                "precio" => $producto->precio * $producto->tipoCambio,
                                "existencia" => $existencia
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->error = $e->getMessage() . "" . $log;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function consultarAlmacenes()
    {
        $response = new \stdClass();

        try {
            $reader = new Xlsx();

            // Tell the reader to only read the data. Ignore formatting etc.
            $reader->setReadDataOnly(true);

            // Read the spreadsheet file.
            $spreadsheet = $reader->load('archivos/listado_almacenes.xlsx');

            $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
            $almacenes = $sheet->toArray();

            array_shift($almacenes);

            foreach ($almacenes as $almacen) {
                $existe = DB::table("modelo_proveedor_almacen")
                    ->where("id_locacion", $almacen[0])
                    ->where("id_modelo_proveedor", 5)
                    ->where("status", 1)
                    ->first();

                if (empty($existe)) {
                    DB::table("modelo_proveedor_almacen")->insert([
                        "id_modelo_proveedor" => 5,
                        "id_almacen" => isset($almacen[2]) ? $almacen[2] : "N/A",
                        "id_locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "locacion" => isset($almacen[1]) ? $almacen[1] : "N/A",
                        "calle" => isset($almacen[3]) ? $almacen[3] : "N/A",
                        "numero" => isset($almacen[4]) ? $almacen[4] : "N/A",
                        "numero_int" => "N/A",
                        "colonia" => isset($almacen[6]) ? $almacen[6] : "N/A",
                        "ciudad" => isset($almacen[8]) ? $almacen[8] : "N/A",
                        "estado" => isset($almacen[9]) ? $almacen[9] : "N/A",
                        "codigo_postal" => isset($almacen[7]) ? $almacen[7] : "N/A",
                        "referencia" => isset($almacen[5]) ? $almacen[5] : "N/A",
                        "contacto" => isset($almacen[12]) ? $almacen[12] : "N/A",
                        "correo" => isset($almacen[13]) ? $almacen[13] : "N/A",
                        "telefono" => isset($almacen[10]) ? $almacen[10] : "N/A"
                    ]);
                } else {
                    DB::table("modelo_proveedor_almacen")->where("id", $existe->id)->update([
                        "id_almacen" => isset($almacen[2]) ? $almacen[2] : "N/A",
                        "id_locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "calle" => isset($almacen[3]) ? $almacen[3] : "N/A",
                        "numero" => isset($almacen[4]) ? $almacen[4] : "N/A",
                        "numero_int" => "N/A",
                        "colonia" => isset($almacen[6]) ? $almacen[6] : "N/A",
                        "ciudad" => isset($almacen[8]) ? $almacen[8] : "N/A",
                        "estado" => isset($almacen[9]) ? $almacen[9] : "N/A",
                        "codigo_postal" => isset($almacen[7]) ? $almacen[7] : "N/A",
                        "referencia" => isset($almacen[5]) ? $almacen[5] : "N/A",
                        "contacto" => isset($almacen[12]) ? $almacen[12] : "N/A",
                        "correo" => isset($almacen[13]) ? $almacen[13] : "N/A",
                        "telefono" => isset($almacen[10]) ? $almacen[10] : "N/A"
                    ]);
                }
            }
        } catch (Exception $e) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = $e->getMessage() . "" . $log;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function crearPedido($documento)
    {
        set_time_limit(0);
        setlocale(LC_ALL, "es_MX.utf8");

        $response = new \stdClass();
        $productos_b2b = array();
        $inventario_id = "";

        $informacion_entidad = DB::table("documento")
            ->join("documento_entidad", "documento_entidad.id", "=", "documento.id_entidad")
            ->where("documento.id", $documento)
            ->first();

        if (!$informacion_entidad) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontró información de la entidad del documento" . $log;

            return $response;
        }

        # Búscar el municipio/ciudad del documento
        $direccion_envio = DB::table("documento_direccion")
            ->where("id_documento", $documento)
            ->first();

        if (!$direccion_envio) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontró información de la dirección de envío del documento, favor de contactar a un administrador" . $log;

            return $response;
        }

        $almacenes_b2b = DB::table("modelo_proveedor_almacen")
            ->where("id_modelo_proveedor", 5)
            ->get()
            ->toArray();

        $almacen_b2b = null;

        # Verificamos si la ciudad del pedido, coincide con el nombre del almacén del proveedor
        foreach ($almacenes_b2b as $almacen) {
            similar_text(mb_strtoupper(iconv("utf-8", "ascii//TRANSLIT", trim($almacen->locacion))), mb_strtoupper(iconv("utf-8", "ascii//TRANSLIT", trim($direccion_envio->ciudad))), $porcentaje);

            if ($porcentaje >= 80) {
                $almacen_b2b = $almacen->id;

                break;
            }
        }

        # Si el nombre del almacén no coincide con la ciudad del pedido, verificamos si hay algún almacen en el estado del pedido
        foreach ($almacenes_b2b as $almacen) {
            similar_text(mb_strtoupper(iconv("utf-8", "ascii//TRANSLIT", trim($almacen->estado))), mb_strtoupper(iconv("utf-8", "ascii//TRANSLIT", trim($direccion_envio->estado))), $porcentaje);

            if ($porcentaje >= 80) {
                $almacen_b2b = $almacen->id;

                break;
            }
        }

        # Checamos que los productos de la venta estén relacionados con los de CT Internacional
        $productos = DB::table("movimiento")
            ->select("id_modelo", "cantidad")
            ->where("id_documento", $documento)
            ->get()
            ->toArray();

        foreach ($productos as $producto) {
            $producto_b2b = DB::table("modelo_proveedor_producto")
                ->select("id", "id_producto", "precioLista")
                ->where("id_modelo", $producto->id_modelo)
                ->where("id_modelo_proveedor", 5) # 4 es el ID del proveedor CT Internacional
                ->first();

            if (empty($producto_b2b)) {
                $response->error = 1;
                $response->mensaje = "No se encontró relacionado un codigo de la venta con los productos de CT Internacional, favor de verificar e intentar de nuevo.";

                return $response;
            }

            $inventario = DB::table("modelo_proveedor_producto_existencia")
                ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                ->where("modelo_proveedor_producto_existencia.id_modelo", $producto_b2b->id)
                ->where("modelo_proveedor_producto_existencia.existencia", ">=", $producto->cantidad)
                ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
                ->when($almacen_b2b, function ($query, $almacen_b2b) {
                    return $query->where("modelo_proveedor_almacen.id", $almacen_b2b);
                })
                ->first();

            if (empty($inventario)) {
                $inventario = DB::table("modelo_proveedor_producto_existencia")
                    ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                    ->where("modelo_proveedor_producto_existencia.id_modelo", $producto_b2b->id)
                    ->where("modelo_proveedor_producto_existencia.existencia", ">=", $producto->cantidad)
                    ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
                    ->orderByRaw("CASE WHEN modelo_proveedor_almacen.id_locacion LIKE '%34A%'
                                        THEN 1 WHEN modelo_proveedor_almacen.id_locacion LIKE '%35A%'
                                        THEN 2 WHEN modelo_proveedor_almacen.id_locacion LIKE '%47A%'
                                        THEN 3 WHEN modelo_proveedor_almacen.id_locacion LIKE '%13A%'
                                        THEN 4 WHEN modelo_proveedor_almacen.id_locacion LIKE '%24A%'
                                        THEN 5 ELSE 6 END")
                    ->first();
            }

            if (empty($inventario)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No hay inventario disponible en ningun de los almaceenes de uno de los productos en CT Internacional, favor de verificar e intentar de nuevo." . $log;

                return $response;
            }

            $producto->id_b2b = $producto_b2b->id;
            $producto->producto_b2b = $producto_b2b->id_producto;
            $producto->precio = $producto_b2b->precioLista;

            array_push($productos_b2b, $producto_b2b->id);
        }

        # Verificar que los 3 productos tengan inventario en el mismo almacén
        $inventario_en_almacenes = DB::table("modelo_proveedor_producto_existencia")
            ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
            ->where("modelo_proveedor_almacen.id", $almacen_b2b)
            ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
            ->whereIn("modelo_proveedor_producto_existencia.id_modelo", $productos_b2b)
            ->get()
            ->toArray();

        if (count($inventario_en_almacenes) == count($productos_b2b)) {
            $almacen_data = DB::table("modelo_proveedor_almacen")->where("id", $almacen_b2b)->first();

            $inventario_id = $almacen_data->id_locacion;
        } else {
            # Checamos que tenga existencias en los almacenes principales
            foreach (["34A", "35A", "47A", "13A", "24A"] as $almacen) {
                $inventario_en_almacenes = DB::table("modelo_proveedor_producto_existencia")
                    ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                    ->where("modelo_proveedor_almacen.id_locacion", $almacen)
                    ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
                    ->whereIn("modelo_proveedor_producto_existencia.id_modelo", $productos_b2b)
                    ->get()
                    ->toArray();

                if (count($inventario_en_almacenes) == count($productos_b2b)) {
                    $inventario_id = $almacen;

                    break;
                }
            }

            if (empty($inventario_id)) {
                $almacenes = DB::table("modelo_proveedor_producto_existencia")
                    ->select("modelo_proveedor_almacen.id_locacion")
                    ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                    ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
                    ->whereIn("modelo_proveedor_producto_existencia.id_modelo", $productos_b2b)
                    ->groupBy('modelo_proveedor_almacen.id_locacion')
                    ->get()
                    ->toArray();

                foreach ($almacenes as $almacen) {
                    $inventario_en_almacenes = DB::table("modelo_proveedor_producto_existencia")
                        ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                        ->where("modelo_proveedor_almacen.id_locacion", $almacen->id_locacion)
                        ->where("modelo_proveedor_almacen.id_modelo_proveedor", 5)
                        ->whereIn("modelo_proveedor_producto_existencia.id_modelo", $productos_b2b)
                        ->get()
                        ->toArray();

                    if (count($inventario_en_almacenes) == count($productos_b2b)) {
                        $inventario_id = $almacen->id_locacion;

                        break;
                    }
                }
            }
        }

        if (empty($inventario_id)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Los productos no cuentan con inventario en el mismo almacén" . $log;

            return $response;
        }

        $observacion_documento = DB::table("documento")->select("observacion")->where("id", $documento)->first();

        $productos_data = array();

        $telefono_envio = empty($informacion_entidad->telefono) || $informacion_entidad->telefono == 'N/A' ? $informacion_entidad->telefono : "3336151770";

        $data = array(
            "idPedido" => $documento,
            "almacen" => $inventario_id,
            "tipoPago" => "99",
            "envio" => array(
                "0" => array(
                    "nombre" => $direccion_envio->contacto,
                    "direccion" => $direccion_envio->calle,
                    "entreCalles" => $direccion_envio->referencia,
                    "noExterior" => $direccion_envio->numero,
                    "colonia" => $direccion_envio->colonia,
                    "estado" => $direccion_envio->estado,
                    "ciudad" => $direccion_envio->ciudad,
                    "codigoPostal" => $direccion_envio->codigo_postal,
                    "telefono" => $telefono_envio
                )
            ),
            "producto" => array()
        );

        foreach ($productos as $producto) {
            array_push($data["producto"], array(
                "clave" => $producto->producto_b2b,
                "cantidad" => $producto->cantidad,
                "precio" => $producto->precio,
                "moneda" => "MXN"
            ));
        }

        $token = self::crearToken();

        if ($token->error) {
            return $token;
        }

        try {
            $request_data = \Httpful\Request::post(config('webservice.ct_internacional') . "pedido")
                ->addHeader('x-auth', $token->token)
                ->addHeader('Content-Type', 'application/json')
                ->body($data, \Httpful\Mime::FORM)
                ->send();

            $raw_request = $request_data->raw_body;
            $request = @json_decode($raw_request);

            if (empty($request)) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B." . $log;
                $response->raw = $raw_request;
                $response->data = $request_data;

                return $response;
            }

            if (is_array($request)) {
                $request = $request[0];
            }

            if (property_exists($request, "status")) {
                if ($request->status != 200) {
                    $log = self::logVariableLocation();
                    $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B." . $log;
                    $response->raw = $raw_request;

                    return $response;
                }
            }

            if (property_exists($request, "errorCode")) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B, mensaje de error: " . $request->errorMessage . "." . $log;
                $response->raw = $raw_request;

                return $response;
            }

            DB::table("documento")->where("id", $documento)->update([
                "no_venta_btob" => $request->respuestaCT->pedidoWeb
            ]);

            foreach ($productos as $producto) {
                $producto_b2b = DB::table("modelo_proveedor_producto_existencia")
                    ->select("existencia")
                    ->where("id_modelo", $producto->id_b2b)
                    ->first();

                $almacen_data = DB::table("modelo_proveedor_almacen")
                    ->where("id_modelo_proveedor", 5)
                    ->where("id_locacion", $inventario_id)
                    ->first();
            }

            $confirmar_pedido = self::confirmarPedido($request->respuestaCT->pedidoWeb);

            if ($confirmar_pedido->error) {
                return $confirmar_pedido;
            }
        } catch (\SoapFault $exception) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = $exception->faultstring . "" . $log;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function confirmarPedido($pedido)
    {
        $response = new \stdClass();
        $response->error = 1;

        $data = array(
            "folio" => $pedido
        );

        $token = self::crearToken();

        if ($token->error) {
            return $token;
        }

        $request_data = \Httpful\Request::post(config('webservice.ct_internacional') . "pedido/confirmar")
            ->addHeader('x-auth', $token->token)
            ->addHeader('Content-Type', 'application/json')
            ->body($data, \Httpful\Mime::FORM)
            ->send();

        $raw_request = $request_data->raw_body;
        $request = @json_decode($raw_request);

        if (empty($request)) {
            $log = self::logVariableLocation();
            $response->mensaje = "Ocurrió un error al confirmar el pedido con el proveedor" . $log;
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        if (is_array($request)) {
            $request = $request[0];
        }

        if (property_exists($request, "status")) {
            if ($request->status != 200) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al confirmar el pedido con el proveedor." . $log;
                $response->raw = $raw_request;

                return $response;
            }
        }

        if (property_exists($request, "errorCode")) {
            $log = self::logVariableLocation();
            $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B, mensaje de error: " . $request->errorMessage . "." . $log;
            $response->raw = $raw_request;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function adjuntarGuiaPedidosFacturados()
    {
        $errores = array();
        $statuses = array();

        $documentos = DB::table("documento")
            ->select("documento.id", "documento.no_venta_btob", "paqueteria.paqueteria")
            ->join("paqueteria", "documento.id_paqueteria", "=", "paqueteria.id")
            ->where("documento.status", 1)
            ->where("documento.id_modelo_proveedor", 5)
            ->where("documento.status_proveedor_btob", 0)
            ->get()
            ->toArray();

        foreach ($documentos as $documento) {
            $pedido_status = self::verificarStatusPedido($documento->no_venta_btob);

            array_push($statuses, $pedido_status);

            if ($pedido_status->error) {
                return $pedido_status;
            }

            if ($pedido_status->status == "Facturado") {
                $archivos = array();

                # Checamos los archivos del pedido
                $archivos_embarque = DB::table("documento_archivo")
                    ->select("dropbox")
                    ->where("id_documento", $documento->id)
                    ->where("tipo", 2)
                    ->get()
                    ->toArray();

                if (empty($archivos_embarque)) {
                    $log = self::logVariableLocation();
                    $response->error = 1;
                    $response->mensaje = "El documento no contiene archivos de embarque, por lo cual se cancela" . $log;

                    return $response;
                }

                foreach ($archivos_embarque as $archivo) {
                    $file_data = new \stdClass();
                    $file_data->path = $archivo->dropbox;

                    $response = \Httpful\Request::post(config("webservice.dropbox_api") . 'files/get_temporary_link')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Content-Type', 'application/json')
                        ->body(json_encode($file_data))
                        ->send();

                    $response = @json_decode($response->raw_body);

                    array_push($archivos, base64_encode(file_get_contents($response->link)));
                }

                if (empty($archivos_embarque)) {
                    # Enviar correo a administradores por que el pedido no tiene guias adjuntas
                    array_push($errores, "El documento " . $documento->id . " no contiene archivos de embarque adjuntos, favor de revisar y adjuntar archivos");

                    continue;
                }

                $cargar_guia = self::cargarGuiaPedido($documento->no_venta_btob, $archivos, $documento->paqueteria);

                if ($cargar_guia->error) {
                    array_push($errores, $cargar_guia->mensaje);

                    continue;
                }

                DB::table("documento")->where("id", $documento->id)->update([
                    "status_proveedor_btob" => 1
                ]);
            }
        }

        if (!empty($errores)) {
            GeneralService::sendEmailToAdmins("Errores al subir archivos CT Internacional", "No fue posible adjuntar las guías de algunar pedidos para el proveedor B2B CT Internacional", implode(", ", $errores), 1, []);
        }

        return $statuses;
    }

    public static function verificarStatusPedido($pedido)
    {
        $response = new \stdClass();
        $response->error = 1;

        $token = self::crearToken();

        if ($token->error) {
            return $token;
        }

        $request_data = \Httpful\Request::get(config('webservice.ct_internacional') . "pedido/estatus/" . $pedido)
            ->addHeader('x-auth', $token->token)
            ->send();

        $raw_request = $request_data->raw_body;
        $request = @json_decode($raw_request);

        if (empty($request)) {
            $log = self::logVariableLocation();
            $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B." . $log;
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        if (property_exists($request, "errorCode")) {
            $log = self::logVariableLocation();
            $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B, mensaje de error: " . $request->errorMessage . "." . $log;
            $response->raw = $raw_request;

            return $response;
        }

        $response->error = 0;
        $response->status = $request->status;

        return $response;
    }

    public static function cargarGuiaPedido($pedido, $archivos, $paqueteria)
    {
        $response = new \stdClass();
        $response->error = 1;

        $data = array(
            "folio" => $pedido,
            "guias" => array()
        );

        foreach ($archivos as $archivo) {
            array_push($data["guias"], array(
                "guia" => uniqid(),
                "paqueteria" => $paqueteria,
                "archivo" => $archivo
            ));
        }

        $token = self::crearToken();

        if ($token->error) {
            return $token;
        }

        try {
            $request_data = \Httpful\Request::post(config('webservice.ct_internacional') . "pedido/guias")
                ->addHeader('x-auth', $token->token)
                ->addHeader('Content-Type', 'application/json')
                ->body($data, \Httpful\Mime::FORM)
                ->send();

            $raw_request = $request_data->raw_body;
            $request = @json_decode($raw_request);

            if (empty($request)) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B." . $log;
                $response->raw = $raw_request;
                $response->data = $request_data;

                return $response;
            }

            if (property_exists($request, "status")) {
                if ($request->status != 200) {
                    $log = self::logVariableLocation();
                    $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B." . $log;
                    $response->raw = $raw_request;

                    return $response;
                }
            }

            if (property_exists($request, "errorCode")) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al crear la venta con el proveedor B2B, mensaje de error: " . $request->errorMessage . "." . $log;
                $response->raw = $raw_request;

                return $response;
            }
        } catch (\SoapFault $exception) {
            $log = self::logVariableLocation();
            $response->mensaje = $exception->faultstring . "" . $log;
            $response->data = $soap_data;

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function crearToken()
    {
        $response = new \stdClass();
        $response->error = 1;

        $token_data = DB::table("modelo_proveedor")
            ->where("id", 5)
            ->first();

        if (!$token_data) {
            $log = self::logVariableLocation();
            $response->mensaje = "Error al consultar la información del proveedor requerido" . $log;

            return $response;
        }

        if (empty($token_data->api_data)) {
            $log = self::logVariableLocation();
            $response->mensaje = "Error al obtener información del token data del proveedor seleccionado" . $log;

            return $response;
        }

        $token_data = @json_decode($token_data->api_data);

        if (empty($token_data)) {
            $log = self::logVariableLocation();
            $response->mensaje = "Error al decodificar el token data para el proveedor seleccionado" . $log;

            return $response;
        }

        $request_data = \Httpful\Request::post(config('webservice.ct_internacional') . "cliente/token")
            ->addHeader('Content-Type', 'application/json')
            ->body($token_data, \Httpful\Mime::FORM)
            ->send();

        $raw_request = $request_data->raw_body;
        $request = @json_decode($raw_request);

        if (empty($request)) {
            $log = self::logVariableLocation();
            $response->mensaje = "Ocurrió un error al buscar la venta en la plataforma." . $log;
            $response->raw = $raw_request;
            $response->data = $request_data;

            return $response;
        }

        if (property_exists($request, "status")) {
            if ($request->status != 200) {
                $log = self::logVariableLocation();
                $response->mensaje = "Ocurrió un error al generar el token del proveedor seleccionado" . $log;
                $response->raw = $raw_request;

                return $response;
            }
        }

        $response->error = 0;
        $response->token = $request->token;

        return $response;
    }
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'CS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'CTS'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }

    public static function newConsultarProductos()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $data_proveedor = DB::table("modelo_proveedor")
            ->where("id", 5)
            ->first();

        if (empty($data_proveedor)) {
            $response->error = 1;
            $response->mensaje = "No se encontró el proveedor solicitado en el sistema" . ' ' . self::logVariableLocation();

            return $response;
        }

        if ($data_proveedor->next_available_api_call_date > date("Y-m-d H:i:s")) {
            $response->error = 1;
            $response->mensaje = "El API todavía no está disponible para actualizar productos" . ' ' . self::logVariableLocation();

            return $response;
        }

        try {
            $productos_json = app('filesystem')->disk('ftp-ct')->get("catalogo_xml/productos.json");

            $productos = json_decode($productos_json);

            foreach ($productos as $producto) {
                $existe = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->clave)
                    ->where("id_modelo_proveedor", 5) # 5 es el ID del proveedor CT del norte
                    ->first();

                if (empty($existe)) {
                    $producto_id = DB::table("modelo_proveedor_producto")->insertGetId([
                        "id_modelo_proveedor" => 5,
                        "id_producto" => $producto->clave,
                        "id_marca" => $producto->idMarca,
                        "marca" => $producto->marca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->idCategoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->idSubCategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->clave,
                        "descripcion" => $producto->descripcion_corta,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo,
                        "codigo_barra" => $producto->ean,
                        "precioLista" => $producto->precio * $producto->tipoCambio,
                        "nuevo" => 1,
                        "fecha_nuevo" => date("Y-m-d H:i:s")
                    ]);
                } else {
                    DB::table("modelo_proveedor_producto")->where("id_producto", $producto->idProducto)->where("id_modelo_proveedor", 5)->update([
                        "id_marca" => $producto->idMarca,
                        "marca" => $producto->marca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->idCategoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->idSubCategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->clave,
                        "descripcion" => $producto->descripcion_corta,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo,
                        "codigo_barra" => $producto->ean,
                        "precioLista" => $producto->precio * $producto->tipoCambio,
                        "fecha_nuevo" => date("Y-m-d H:i:s")
                    ]);

                    $producto_id = $existe->id;
                }

                foreach ($producto->existencia as $key => $existencia) {
                    $existe_almacen = DB::table("modelo_proveedor_almacen")
                        ->select("id")
                        ->where("id_modelo_proveedor", 5)
                        ->where("id_almacen", $key)
                        ->first();

                    if (!empty($existe_almacen)) {
                        $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                            ->where("id_modelo", $producto_id)
                            ->where("id_almacen", $existe_almacen->id)
                            ->first();

                        if (!empty($existe_registro_existencia)) {
                            DB::table("modelo_proveedor_producto_existencia")
                                ->where("id_modelo", $producto_id)
                                ->where("id_almacen", $existe_almacen->id)
                                ->update([
                                    "precio" => $producto->precio * $producto->tipoCambio,
                                    "existencia" => $existencia
                                ]);
                        } else {
                            DB::table("modelo_proveedor_producto_existencia")->insert([
                                "id_modelo" => $producto_id,
                                "id_almacen" => $existe_almacen->id,
                                "precio" => $producto->precio * $producto->tipoCambio,
                                "existencia" => $existencia
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $response->error = 1;
            $response->error = $e->getMessage() . "" . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;

        return $response;
    }

    public static function newConsultarAlmacenes()
    {
        $response = new \stdClass();

        try {
            $reader = new Xlsx();

            // Tell the reader to only read the data. Ignore formatting etc.
            $reader->setReadDataOnly(true);

            // Read the spreadsheet file.
            $spreadsheet = $reader->load('archivos/listado_almacenes.xlsx');

            $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
            $almacenes = $sheet->toArray();

            array_shift($almacenes);

            foreach ($almacenes as $almacen) {
                $existe = DB::table("modelo_proveedor_almacen")
                    ->where("id_locacion", $almacen[0])
                    ->where("id_modelo_proveedor", 5)
                    ->where("status", 1)
                    ->first();

                if (empty($existe)) {
                    DB::table("modelo_proveedor_almacen")->insert([
                        "id_modelo_proveedor" => 5,
                        "id_almacen" => isset($almacen[2]) ? $almacen[2] : "N/A",
                        "id_locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "locacion" => isset($almacen[1]) ? $almacen[1] : "N/A",
                        "calle" => isset($almacen[3]) ? $almacen[3] : "N/A",
                        "numero" => isset($almacen[4]) ? $almacen[4] : "N/A",
                        "numero_int" => "N/A",
                        "colonia" => isset($almacen[6]) ? $almacen[6] : "N/A",
                        "ciudad" => isset($almacen[8]) ? $almacen[8] : "N/A",
                        "estado" => isset($almacen[9]) ? $almacen[9] : "N/A",
                        "codigo_postal" => isset($almacen[7]) ? $almacen[7] : "N/A",
                        "referencia" => isset($almacen[5]) ? $almacen[5] : "N/A",
                        "contacto" => isset($almacen[12]) ? $almacen[12] : "N/A",
                        "correo" => isset($almacen[13]) ? $almacen[13] : "N/A",
                        "telefono" => isset($almacen[10]) ? $almacen[10] : "N/A"
                    ]);
                } else {
                    DB::table("modelo_proveedor_almacen")->where("id", $existe->id)->update([
                        "id_almacen" => isset($almacen[2]) ? $almacen[2] : "N/A",
                        "id_locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "locacion" => isset($almacen[0]) ? $almacen[0] : "N/A",
                        "calle" => isset($almacen[3]) ? $almacen[3] : "N/A",
                        "numero" => isset($almacen[4]) ? $almacen[4] : "N/A",
                        "numero_int" => "N/A",
                        "colonia" => isset($almacen[6]) ? $almacen[6] : "N/A",
                        "ciudad" => isset($almacen[8]) ? $almacen[8] : "N/A",
                        "estado" => isset($almacen[9]) ? $almacen[9] : "N/A",
                        "codigo_postal" => isset($almacen[7]) ? $almacen[7] : "N/A",
                        "referencia" => isset($almacen[5]) ? $almacen[5] : "N/A",
                        "contacto" => isset($almacen[12]) ? $almacen[12] : "N/A",
                        "correo" => isset($almacen[13]) ? $almacen[13] : "N/A",
                        "telefono" => isset($almacen[10]) ? $almacen[10] : "N/A"
                    ]);
                }
            }
        } catch (Exception $e) {

            $response->error = 1;
            $response->mensaje = $e->getMessage() . " " . self::logVariableLocation();

            return $response;
        }

        $response->error = 0;

        return $response;
    }
}
