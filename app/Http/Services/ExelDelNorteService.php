<?php

namespace App\Http\Services;

use SimpleXMLElement;
use DOMDocument;
use SoapClient;
use Exception;
use DB;

class ExelDelNorteService
{
    public static function consultarProductos()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $data_proveedor = DB::table("modelo_proveedor")
            ->where("id", 4)
            ->first();

        if (empty($data_proveedor)) {
            $response->error = 1;
            $response->mensaje = "No se encontró el proveedor solicitado en el sistema" . self::logVariableLocation();

            return $response;
        }

        if ($data_proveedor->next_available_api_call_date > date("Y-m-d H:i:s")) {
            $response->error = 1;
            $response->mensaje = "El API todavía no está disponible para actualizar productos" . self::logVariableLocation();

            return $response;
        }

        $soap_data = array(
            "Usuario" => config('keys.exel_user'),
            "Password" => config('keys.exel_password')
        );

        try {
            $client = new SoapClient(config('webservice.exel_producto') . "?wsdl", array('trace' => 1, 'exceptions' => true));

            $soap_response = $client->Obtener_Productos_Listado($soap_data);

            $productos = (array) json_decode($soap_response->Obtener_Productos_ListadoResult);

            DB::table("modelo_proveedor")
                ->where("id", 4)
                ->update([
                    "last_api_call_date" => date("Y-m-d H:i:s"),
                    "next_available_api_call_date" => date("Y-m-d H:i:s", strtotime(" +23 hours"))
                ]);

            foreach ($productos as $producto) {
                $existe = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->id_producto)
                    ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                    ->first();

                if (empty($existe)) {
                    DB::table("modelo_proveedor_producto")->insert([
                        "id_modelo_proveedor" => 4,
                        "id_producto" => $producto->id_producto,
                        "id_marca" => $producto->id_marca,
                        "marca" => $producto->marca,
                        "id_familia" => $producto->id_familia,
                        "familia" => $producto->familia,
                        "id_categoria" => $producto->id_categoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->id_subcategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->codigo_proveedor,
                        "descripcion" => $producto->descripcion,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo_sentai,
                        "codigo_barra" => $producto->codigo_barra,
                        "precioLista" => $producto->precioLista,
                        "nuevo" => $producto->nuevo,
                        "fecha_nuevo" => $producto->fecha_nuevo
                    ]);
                } else {
                    DB::table("modelo_proveedor_producto")->where("id_producto", $producto->id_producto)->where("id_modelo_proveedor", 4)->update([
                        "id_marca" => $producto->id_marca,
                        "marca" => $producto->marca,
                        "id_familia" => $producto->id_familia,
                        "familia" => $producto->familia,
                        "id_categoria" => $producto->id_categoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->id_subcategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->codigo_proveedor,
                        "descripcion" => $producto->descripcion,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo_sentai,
                        "codigo_barra" => $producto->codigo_barra,
                        "precioLista" => $producto->precioLista,
                        "nuevo" => $producto->nuevo,
                        "fecha_nuevo" => $producto->fecha_nuevo
                    ]);
                }
            }

            $response->error = 0;

            return $response;
        } catch (\SoapFault $exception) {

            $response->error = 1;
            $response->mensaje = $exception->faultstring . " " . self::logVariableLocation();

            return $response;
        }
    }

    public static function consultaPreciosYExistencias()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $productos = DB::table("modelo_proveedor_producto")
            ->select("id_producto")
            ->where("actualizar", 1)
            ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
            ->get()
            ->pluck("id_producto")
            ->toArray();

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No hay productos para actualizar" . self::logVariableLocation();

            return $response;
        }

        $soap_data = array(
            "Usuario" => config('keys.exel_user'),
            "Password" => config('keys.exel_password'),
            "Codigos" => $productos
        );

        try {
            $client = new SoapClient(config('webservice.exel_producto') . "?wsdl", array('trace' => 1, 'exceptions' => true));

            $soap_response = $client->Obtener_Productos_PrecioYExistencia($soap_data);

            $productos = (array) json_decode($soap_response->Obtener_Productos_PrecioYExistenciaResult);

            foreach ($productos as $producto) {
                $existe_almacen = DB::table("modelo_proveedor_almacen")
                    ->where("id_modelo_proveedor", 4)
                    ->where("id_locacion", $producto->id_localidad)
                    ->first();

                if (empty($existe_almacen)) {
                    $almacen_id = DB::table("modelo_proveedor_almacen")->insertGetId([
                        "id_modelo_proveedor" => 4,
                        "id_locacion" => $producto->id_localidad,
                        "locacion" => $producto->localidad,
                        "estado" => $producto->localidad
                    ]);
                } else {
                    $almacen_id = $existe_almacen->id;
                }

                $informacion = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->id_producto)
                    ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                    ->first();

                if (!empty($informacion)) {
                    $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                        ->where("id_modelo", $informacion->id)
                        ->where("id_almacen", $almacen_id)
                        ->first();

                    if (!empty($existe_registro_existencia)) {
                        DB::table("modelo_proveedor_producto_existencia")
                            ->where("id_modelo", $informacion->id)
                            ->where("id_almacen", $almacen_id)
                            ->update([
                                "precio" => (float) $producto->precio,
                                "existencia" => (int) $producto->existencia
                            ]);
                    } else {
                        DB::table("modelo_proveedor_producto_existencia")->insert([
                            "id_modelo" => $informacion->id,
                            "id_almacen" => $almacen_id,
                            "precio" => (float) $producto->precio,
                            "existencia" => (int) $producto->existencia
                        ]);
                    }
                }
            }

            $response->error = 0;
            $response->mensaje = 'Actualización correcta';

            return $response;
        } catch (\SoapFault $exception) {
            $response->error = 1;
            $response->mensaje = $exception->faultstring . " " . self::logVariableLocation();

            return $response;
        }
    }

    public static function crearPedido($documento)
    {
        set_time_limit(0);

        $response = new \stdClass();
        $productos_exel = array();
        $inventario_id = "";
        $archivos = array();

        # Checamos los archivos del pedido
        $archivos_embarque = DB::table("documento_archivo")
            ->select("dropbox")
            ->where("id_documento", $documento)
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

        # Checamos que los productos de la venta estén relacionados con los de exel del norte
        $productos = DB::table("movimiento")
            ->select("id_modelo", "cantidad")
            ->where("id_documento", $documento)
            ->get()
            ->toArray();

        foreach ($productos as $producto) {
            $producto_exel = DB::table("modelo_proveedor_producto")
                ->select("id", "id_producto")
                ->where("id_modelo", $producto->id_modelo)
                ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                ->where("actualizar", 1)
                ->first();

            if (empty($producto_exel)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No se encontró relacionado un codigo de la venta con los productos de exel, favor de verificar e intentar de nuevo." . $log;

                return $response;
            }

            $inventario = DB::table("modelo_proveedor_producto_existencia")
                ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                ->where("modelo_proveedor_producto_existencia.id_modelo", $producto_exel->id)
                ->where("modelo_proveedor_producto_existencia.existencia", ">=", $producto->cantidad)
                ->where("modelo_proveedor_almacen.id_modelo_proveedor", 4)
                ->orderByRaw("CASE WHEN modelo_proveedor_almacen.id_locacion LIKE '%MX%' THEN 1 WHEN modelo_proveedor_almacen.id_locacion LIKE '%GD%' THEN 2 ELSE 3 END")
                ->first();

            if (empty($inventario)) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "No hay inventario disponible en ningun de los almaceenes de uno de los productos en exel del norte, favor de verificar e intentar de nuevo." . $log;

                return $response;
            }

            $producto->id_exel = $producto_exel->id;
            $producto->producto_exel = $producto_exel->id_producto;

            array_push($productos_exel, $producto_exel->id);
        }

        # Verificar que los 3 productos tengan inventario en el mismo almacén (Actualmente solo se buscará en GDL)
        foreach (["GD"] as $almacen) {
            foreach ($productos as $producto) {
                $existencia = DB::table("modelo_proveedor_producto_existencia")
                    ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                    ->where("modelo_proveedor_almacen.id_locacion", $almacen)
                    ->where("modelo_proveedor_almacen.id_modelo_proveedor", 4)
                    ->where("modelo_proveedor_producto_existencia.id_modelo", $producto->id_exel)
                    ->first();

                if (!$existencia) continue 2;

                if ($producto->cantidad > $existencia->existencia) continue 2;
            }

            $inventario_id = $almacen;

            break;
        }

        if (empty($inventario_id)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "Los productos no cuentan con inventario en el mismo almacén" . $log;

            return $response;
        }

        $observacion_documento = DB::table("documento")->select("observacion")->where("id", $documento)->first();

        $soap_data = array(
            "Usuario" => config('keys.exel_user'),
            "Password" => config('keys.exel_password'),
            "Orden" => array(
                "Id_Contrato" => "0",
                "Id_Licitacion" => "0",
                "folio" => $documento,
                "Notas" => $observacion_documento->observacion,
                "Id_Cliente" => config("keys.exel_cliente"), # Número de cliente de OMG
                "Lineas" => array(),
                "Termino" => array(
                    "id_cliente" => config("keys.exel_cliente"),
                    "id_termino_pago" => "",
                    "termino_pago" => ""
                ),
                "Informacion" => array(
                    "clsInformacion_Orden_Venta" => array(
                        "Id_Localidad" => $inventario_id,
                        "Id_Direccion_Embarque" => "0",
                        "Id_Transportista" => "GPCL",
                        "Numero_Orden_Cliente" => $documento
                    )
                ),
                "opg" => "",
                "deal" => "",
                "c_UsoCFDI" => "G01"
            )
        );

        foreach ($productos as $producto) {
            array_push($soap_data["Orden"]["Lineas"], array(
                "id_plan" => "0",
                "precio" => "0",
                "Id_Localidad" => $inventario_id,
                "Id_Producto" => $producto->producto_exel,
                "Cantidad" => $producto->cantidad,
                "Cantidad_Solicitada" => $producto->cantidad,
                "BackOrder" => false,
                "id_promocion" => "0",
                "descuento" => "0"
            ));
        }

        try {
            $client = new SoapClient(config('webservice.exel_venta') . "?wsdl", array('trace' => 1, 'exceptions' => true));

            $soap_response = $client->Colocar_Orden_Venta($soap_data);

            if ($soap_response->Colocar_Orden_VentaResult->Acuse->codigo != 0) {
                $log = self::logVariableLocation();
                $response->error = 1;
                $response->mensaje = "Ocurrió un error al crear la orden de venta en Exel del norte, mensaje de error: " . $soap_response->Acuse->mensaje . "" . $log;

                return $response;
            }

            DB::table("documento")->where("id", $documento)->update([
                "no_venta_btob" => $soap_response->Colocar_Orden_VentaResult->Numero_Orden
            ]);

            foreach ($productos as $producto) {
                $producto_exel = DB::table("modelo_proveedor_producto_existencia")
                    ->select("existencia")
                    ->where("id_modelo", $producto->id_exel)
                    ->first();
            }

            foreach ($archivos as $archivo) {
                $zpl = false;

                $zpl = strpos($archivo->nombre, 'zpl') !== false ? true : false;

                $cargar_guia = self::cargarGuiaPedido($soap_response->Colocar_Orden_VentaResult->Numero_Orden, $inventario_id, $archivo, $zpl);

                if ($cargar_guia->error) {
                    return $cargar_guia;
                }
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

    public static function cargarGuiaPedido($pedido, $localidad, $base64, $zpl = false)
    {
        $response = new \stdClass();

        $soap_data = array(
            "Usuario" => config('keys.exel_user'),
            "Password" => config('keys.exel_password'),
            "claveOrdenDeVenta" => $pedido,
            "idLocalidad" => $localidad,
            "Guia" => uniqid(),
            "buffer" => base64_decode($base64)
        );

        try {
            $client = new SoapClient(config('webservice.exel_producto') . "?wsdl", array('trace' => 1, 'exceptions' => true, 'encoding' => ' UTF-8'));

            if (!$zpl) {
                $soap_response = $client->cargarGuiaEnPDF($soap_data);
            } else {
                $soap_response = $client->cargarGuiaEnZPL2($soap_data);
            }

            if ($soap_response->cargarGuiaEnPDFResult == "REGISTRO INSERTADO") {
                $response->error = 0;
                $response->data = $soap_data;

                return $response;
            }
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible insertar la guia en el pedido de exel" . $log;
            $response->raw = $soap_response->cargarGuiaEnPDFResult;
            $response->data = $soap_data;

            return $response;
        } catch (\SoapFault $exception) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = $exception->faultstring . "" . $log;
            $response->data = $soap_data;

            return $response;
        }
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'ES'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'RTE'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }

    public static function newConsultarProductos()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $data_proveedor = DB::table("modelo_proveedor")
            ->where("id", 4)
            ->first();

        if (empty($data_proveedor)) {
            $response->error = 1;
            $response->mensaje = "No se encontró el proveedor solicitado en el sistema" . self::logVariableLocation();

            return $response;
        }

        if ($data_proveedor->next_available_api_call_date > date("Y-m-d H:i:s")) {
            $response->error = 1;
            $response->mensaje = "El API todavía no está disponible para actualizar productos" . self::logVariableLocation();

            return $response;
        }

        $soap_data = array(
            "Usuario" => config('keys.exel_user'),
            "Password" => config('keys.exel_password')
        );

        try {
            $client = new SoapClient(config('webservice.exel_producto') . "?wsdl", array('trace' => 1, 'exceptions' => true));

            $soap_response = $client->Obtener_Productos_Listado($soap_data);

            $productos = (array) json_decode($soap_response->Obtener_Productos_ListadoResult);

            DB::table("modelo_proveedor")
                ->where("id", 4)
                ->update([
                    "last_api_call_date" => date("Y-m-d H:i:s"),
                    "next_available_api_call_date" => date("Y-m-d H:i:s", strtotime(" +23 hours"))
                ]);

            foreach ($productos as $producto) {
                $existe = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->id_producto)
                    ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                    ->first();

                if (empty($existe)) {
                    DB::table("modelo_proveedor_producto")->insert([
                        "id_modelo_proveedor" => 4,
                        "id_producto" => $producto->id_producto,
                        "id_marca" => $producto->id_marca,
                        "marca" => $producto->marca,
                        "id_familia" => $producto->id_familia,
                        "familia" => $producto->familia,
                        "id_categoria" => $producto->id_categoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->id_subcategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->codigo_proveedor,
                        "descripcion" => $producto->descripcion,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo_sentai,
                        "codigo_barra" => $producto->codigo_barra,
                        "precioLista" => $producto->precioLista,
                        "nuevo" => 1,
                        "fecha_nuevo" => $producto->fecha_nuevo
                    ]);
                } else {
                    DB::table("modelo_proveedor_producto")->where("id_producto", $producto->id_producto)->where("id_modelo_proveedor", 4)->update([
                        "id_marca" => $producto->id_marca,
                        "marca" => $producto->marca,
                        "id_familia" => $producto->id_familia,
                        "familia" => $producto->familia,
                        "id_categoria" => $producto->id_categoria,
                        "categoria" => $producto->categoria,
                        "id_subcategoria" => $producto->id_subcategoria,
                        "subcategoria" => $producto->subcategoria,
                        "codigo_proveedor" => $producto->codigo_proveedor,
                        "descripcion" => $producto->descripcion,
                        "activo" => $producto->activo,
                        "activo_sentai" => $producto->activo_sentai,
                        "codigo_barra" => $producto->codigo_barra,
                        "precioLista" => $producto->precioLista,
                        "fecha_nuevo" => $producto->fecha_nuevo
                    ]);
                }
            }

            $response->error = 0;
            $response->mensaje = 'Importación correcta';

            return $response;
        } catch (\SoapFault $exception) {

            $response->error = 1;
            $response->mensaje = $exception->faultstring . " " . self::logVariableLocation();

            return $response;
        }
    }

    public static function newConsultaPreciosYExistencias()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $productos = DB::table("modelo_proveedor_producto")
            ->select("id_producto")
            ->where("actualizar", 1)
            ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
            ->get()
            ->pluck("id_producto")
            ->toArray();

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No hay productos para actualizar" . self::logVariableLocation();

            return $response;
        }
        $chunks = array_chunk($productos, 100);

        foreach ($chunks as $chunk) {


            $soap_data = array(
                "Usuario" => config('keys.exel_user'),
                "Password" => config('keys.exel_password'),
                "Codigos" => $chunk
            );

            try {
                $client = new SoapClient(config('webservice.exel_producto') . "?wsdl", array('trace' => 1, 'exceptions' => true));

                $soap_response = $client->Obtener_Productos_PrecioYExistencia($soap_data);

                $productos = (array) json_decode($soap_response->Obtener_Productos_PrecioYExistenciaResult);

                foreach ($productos as $producto) {
                    $existe_almacen = DB::table("modelo_proveedor_almacen")
                        ->where("id_modelo_proveedor", 4)
                        ->where("id_locacion", $producto->id_localidad)
                        ->first();

                    if (empty($existe_almacen)) {
                        $almacen_id = DB::table("modelo_proveedor_almacen")->insertGetId([
                            "id_modelo_proveedor" => 4,
                            "id_locacion" => $producto->id_localidad,
                            "locacion" => $producto->localidad,
                            "estado" => $producto->localidad
                        ]);
                    } else {
                        $almacen_id = $existe_almacen->id;
                    }

                    $informacion = DB::table("modelo_proveedor_producto")
                        ->select("id")
                        ->where("id_producto", $producto->id_producto)
                        ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                        ->first();

                    if (!empty($informacion)) {
                        $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                            ->where("id_modelo", $informacion->id)
                            ->where("id_almacen", $almacen_id)
                            ->first();

                        if (!empty($existe_registro_existencia)) {
                            DB::table("modelo_proveedor_producto_existencia")
                                ->where("id_modelo", $informacion->id)
                                ->where("id_almacen", $almacen_id)
                                ->update([
                                    "precio" => (float) $producto->precio,
                                    "existencia" => (int) $producto->existencia
                                ]);
                        } else {
                            DB::table("modelo_proveedor_producto_existencia")->insert([
                                "id_modelo" => $informacion->id,
                                "id_almacen" => $almacen_id,
                                "precio" => (float) $producto->precio,
                                "existencia" => (int) $producto->existencia
                            ]);
                        }
                    }
                    DB::table("modelo_proveedor_producto")
                        ->where("id_producto", $producto->id_producto)
                        ->where("id_modelo_proveedor", 4) # 4 es el ID del proveedor Exel del norte
                        ->update([
                            'actualizar' => 0,
                        ]);
                }
            } catch (\SoapFault $exception) {
                $response->error = 1;
                $response->mensaje = $exception->faultstring . " " . self::logVariableLocation();

                return $response;
            }
        }
        $response->error = 0;
        $response->mensaje = 'Actualización correcta';
        $response->data = $productos;

        return $response;
    }
}
