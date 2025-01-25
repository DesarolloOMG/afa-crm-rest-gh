<?php

namespace App\Http\Services;

use DOMDocument;
use DB;

class ArrobaService
{
    public static function consultaPreciosYExistencias()
    {
        set_time_limit(0);

        $response = new \stdClass();

        $data_proveedor = DB::table("modelo_proveedor")
            ->where("id", 3)
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

        try {
            $dom = new DOMDocument('1.0', 'utf-8');

            $data = $dom->createElement("data");

            $usuario = $dom->createElement("Usuario", config("keys.arroba_user"));
            $contrasena = $dom->createElement("Contrasena", config("keys.arroba_password"));
            $descripcion = $dom->createElement("Descripcion", "");
            $clave = $dom->createElement("ClaveArticulo", "");

            $data->appendChild($usuario);
            $data->appendChild($contrasena);
            $data->appendChild($descripcion);
            $data->appendChild($clave);

            $dom->appendChild($data);

            $xml = $dom->saveXML();

            $productos_request = \Httpful\Request::post(config("webservice.arroba") . "ExistenciasAlmacen")
                ->addHeader("Content-Type", "text/xml")
                ->body($xml)
                ->send();

            $productos_request = $productos_request->body;

            DB::table("modelo_proveedor")
                ->where("id", 3)
                ->update([
                    "last_api_call_date" => date("Y-m-d H:i:s"),
                    "next_available_api_call_date" => date("Y-m-d H:i:s", strtotime(" +23 hours"))
                ]);

            foreach ($productos_request->data as $producto) {
                $existe = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_producto", $producto->SKU)
                    ->where("id_modelo_proveedor", 3) # 3 es el ID del proveedor ARROBA
                    ->first();

                if (empty($existe)) {
                    $producto_id = DB::table("modelo_proveedor_producto")->insertGetId([
                        "id_modelo_proveedor" => 3,
                        "id_producto" => $producto->SKU,
                        "id_marca" => $producto->Marca,
                        "marca" => $producto->DescripcionMarca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->DepartamentoID,
                        "categoria" => $producto->Departamento,
                        "id_subcategoria" => $producto->GrupoID,
                        "subcategoria" => $producto->Grupo,
                        "codigo_proveedor" => $producto->SKU,
                        "descripcion" => $producto->Descripcion,
                        "activo" => 1,
                        "activo_sentai" => 1,
                        "codigo_barra" => $producto->SKU,
                        "precioLista" => $producto->Precio_Pesos,
                        "nuevo" => 1,
                        "fecha_nuevo" => date("Y-m-d H:i:s")
                    ]);
                } else {
                    DB::table("modelo_proveedor_producto")->where("id_producto", $producto->SKU)->where("id_modelo_proveedor", 3)->update([
                        "id_marca" => $producto->Marca,
                        "marca" => $producto->DescripcionMarca,
                        "id_familia" => "N/A",
                        "familia" => "N/A",
                        "id_categoria" => $producto->DepartamentoID,
                        "categoria" => $producto->Departamento,
                        "id_subcategoria" => $producto->GrupoID,
                        "subcategoria" => $producto->Grupo,
                        "codigo_proveedor" => $producto->SKU,
                        "descripcion" => $producto->Descripcion,
                        "activo" => 1,
                        "activo_sentai" => 1,
                        "codigo_barra" => $producto->SKU,
                        "precioLista" => $producto->Precio_Pesos,
                        "nuevo" => 1,
                    ]);

                    $producto_id = $existe->id;
                }

                /* Existencia almacen CEDIS */
                $existe_almacen_cedis = DB::table("modelo_proveedor_almacen")
                    ->select("id")
                    ->where("id_modelo_proveedor", 3)
                    ->where("id_almacen", 'CEDIS')
                    ->first();

                if (empty($existe_almacen_cedis)) {
                    $almacen_id = DB::table("modelo_proveedor_almacen")->insert([
                        "id_modelo_proveedor" => 3,
                        "id_almacen" => 'CEDIS',
                        "id_locacion" => 'CEDIS',
                        "locacion" => 'CEDIS',
                        "calle" => "N/A",
                        "numero" => "N/A",
                        "numero_int" => "N/A",
                        "colonia" => "N/A",
                        "ciudad" => "CEDIS",
                        "estado" => "N/A",
                        "codigo_postal" => "N/A",
                        "referencia" => "N/A",
                        "contacto" => "N/A",
                        "correo" => "N/A",
                        "telefono" => "N/A"
                    ]);
                } else {
                    $almacen_id = $existe_almacen_cedis->id;
                }

                $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                    ->where("id_modelo", $producto_id)
                    ->where("id_almacen", $almacen_id)
                    ->first();

                if (!empty($existe_registro_existencia)) {
                    DB::table("modelo_proveedor_producto_existencia")
                        ->where("id_modelo", $producto_id)
                        ->where("id_almacen", $almacen_id)
                        ->update([
                            "precio" => $producto->Precio_Pesos,
                            "existencia" => $producto->CEDIS
                        ]);
                } else {
                    DB::table("modelo_proveedor_producto_existencia")->insert([
                        "id_modelo" => $producto_id,
                        "id_almacen" => $almacen_id,
                        "precio" => $producto->Precio_Pesos,
                        "existencia" => $producto->CEDIS
                    ]);
                }

                /* EXISTENCIA ALMACEN GDL */
                $existe_almacen_gdl = DB::table("modelo_proveedor_almacen")
                    ->select("id")
                    ->where("id_modelo_proveedor", 3)
                    ->where("id_almacen", 'GDL')
                    ->first();

                if (empty($existe_almacen_gdl)) {
                    $almacen_id = DB::table("modelo_proveedor_almacen")->insertGetId([
                        "id_modelo_proveedor" => 3,
                        "id_almacen" => 'GDL',
                        "id_locacion" => 'GDL',
                        "locacion" => 'GDL',
                        "calle" => "N/A",
                        "numero" => "N/A",
                        "numero_int" => "N/A",
                        "colonia" => "N/A",
                        "ciudad" => "GDL",
                        "estado" => "N/A",
                        "codigo_postal" => "N/A",
                        "referencia" => "N/A",
                        "contacto" => "N/A",
                        "correo" => "N/A",
                        "telefono" => "N/A"
                    ]);
                } else {
                    $almacen_id = $existe_almacen_gdl->id;
                }

                $existe_registro_existencia = DB::table("modelo_proveedor_producto_existencia")
                    ->where("id_modelo", $producto_id)
                    ->where("id_almacen", $almacen_id)
                    ->first();

                if (!empty($existe_registro_existencia)) {
                    DB::table("modelo_proveedor_producto_existencia")
                        ->where("id_modelo", $producto_id)
                        ->where("id_almacen", $almacen_id)
                        ->update([
                            "precio" => $producto->Precio_Pesos,
                            "existencia" => $producto->GDL
                        ]);
                } else {
                    DB::table("modelo_proveedor_producto_existencia")->insert([
                        "id_modelo" => $producto_id,
                        "id_almacen" => $almacen_id,
                        "precio" => $producto->Precio_Pesos,
                        "existencia" => $producto->GDL
                    ]);
                }
            }

            $response->error = 0;

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = $exception->faultstring . "" . self::logVariableLocation();

            return $response;
        }


        return $productos_request;
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'AS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'OBA'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
