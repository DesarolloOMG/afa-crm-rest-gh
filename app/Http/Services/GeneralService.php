<?php

namespace App\Http\Services;

use Mailgun\Mailgun;
use DB;

class GeneralService
{
    public static function generarGuiaDocumento($documento, $usuario)
    {
        $response = new \stdClass();
        $response->error = 1;

        $informacion = DB::select("SELECT
                                        paqueteria.id,
                                        paqueteria.api,
                                        paqueteria.paqueteria
                                    FROM documento
                                    INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                    WHERE documento.id = " . $documento . "");
        # El documento no fue encontrado en el sistema
        if (empty($informacion)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información del documento, favor de contactar a un administrador." . $log;

            return $response;
        }

        $informacion = $informacion[0];

        # Información del cliente
        $informacion_cliente = DB::select("SELECT
                                        documento_entidad.*
                                    FROM documento
                                    INNER JOIN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                                    INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($informacion_cliente)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información sobre el cliente del documento, favor de contactar a un administrador." . $log;

            return $response;
        }

        $informacion_cliente = $informacion_cliente[0];

        # Información del usuario que está cerrando el pedido
        $usuario_data = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $usuario . " AND status = 1");

        if (empty($usuario_data)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información del usuario, favor de contactar a un administrador." . $log;

            return $response;
        }

        $usuario_data = $usuario_data[0];

        # Sí ya existen guías generadas para el documento, subimos la guía a dropbox y listo
        $contiene_guias = DB::select("SELECT binario, guia FROM paqueteria_guia WHERE id_documento = " . $documento . " ORDER BY created_at DESC");

        if (!empty($contiene_guias)) {
            try {
                $contiene_guias = $contiene_guias[0];
                $nombre_guia = "GUIA_" . $informacion->paqueteria . "_" . $documento . ".pdf";

                $response = \Httpful\Request::post(config('webservice.dropbox') . '2/files/upload')
                    ->addHeader('Authorization', "Bearer " . config("keys.dropbox") . "")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $nombre_guia . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body(base64_decode($contiene_guias->binario))
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento'  => $documento,
                    'id_usuario'    => 1,
                    'tipo'          => 2,
                    'id_impresora'  => 1,
                    'nombre'        => $nombre_guia,
                    'dropbox'       => $response->body->id
                ]);

                $response->error = 0;
                $response->guia = $contiene_guias->guia;

                return $response;
            } catch (Exception $e) {
                $log = self::logVariableLocation();

                $response->mensaje = "Ocurrió un error al subir la guía generada a dropbox, favor de contactar a un adminitrador, mensaje de error: " . $e->getMessage() . "" . $log;

                return $response;
            }
        }

        if (!$informacion->api) {
            $log = self::logVariableLocation();

            $response->mensaje = "La paquetería del documento no contiene una API para generar la guía, favor de contactar a un administrador." . $log;

            return $response;
        }

        $direccion = DB::select("SELECT * FROM documento_direccion WHERE id_documento = " . $documento . "");

        if (empty($direccion)) {
            $log = self::logVariableLocation();

            $response->mensaje = "El documento no contiene dirección para generar la guía, favor de contactar a un administrador." . $log;

            return $response;
        }

        $direccion = $direccion[0];

        if (empty($direccion->tipo_envio) || $direccion->tipo_envio == 'N/A') {
            $log = self::logVariableLocation();

            $response->mensaje = "El documento no tiene referido el tipo de envio (Estándar, Siguiente dia) favor de contactar al vendedor para actualizar la información." . $log;

            return $response;
        }

        $informacion_empresa = DB::table('documento')
            ->select('empresa.*')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->where('documento.id', $documento)
            ->first();

        $empresa = "OMG INTERNATIONAL SA DE CV";

        if ($informacion_empresa) {
            $empresa = $informacion_empresa->empresa;
        }

        if ($informacion->id < 100) {
            $cotizar_paqueteria = self::cotizarGuia($direccion, $informacion->paqueteria, $usuario_data, $informacion_cliente, $empresa);

            if ($cotizar_paqueteria->error) {
                $log = self::logVariableLocation();

                $response->mensaje = "Ocurrió un error al cotizar la paquetería " . $informacion->paqueteria . ", favor de contactar a un administrador, mensaje de error: " . $cotizar_paqueteria->mensaje . "" . $log;

                return $response;
            }
        } else {
            $cotizar_paqueteria = '';
        }

        $cuenta = '';

        $informacion_documento = DB::table("documento")
            ->select("documento.id_paqueteria", "marketplace_area.id_area", "documento.referencia", "documento.id_almacen_principal_empresa", "documento.id_marketplace_area", "documento.no_venta")
            ->join("marketplace_area", "documento.id_marketplace_area", "=", "marketplace_area.id")
            ->where("documento.id", $documento)
            ->first();

        if ($informacion_documento) {
            $paqueteria_area = DB::table("paqueteria_area")
                ->where("id_paqueteria", $informacion_documento->id_paqueteria)
                ->where("id_area", $informacion_documento->id_area)
                ->first();

            if ($paqueteria_area) {
                $cuenta = $paqueteria_area->cuenta;
            }
        }
        //AQUI ASIGNA LA CUENTA DE DHL SEGUN PAQUETERIA Y AREA
        if ($cuenta) {
            $cotizar_paqueteria->data['accountnumber'] = $cuenta;
        }
        //LO MANDA EN EL BODY:
        // {
        //     "cotizar_paqueteria": {
        //         "data": {
        //             "accountnumber": AQUI
        //         }
        //     }
        // }

        if ($informacion->id > 100) {
            $crear_guia = ShopifyService::crearGuia($documento, $direccion, $informacion, $usuario_data, $informacion_cliente, $informacion_empresa);
        } else {
            $crear_guia = \Httpful\Request::post(config("webservice.paqueterias") . 'api/' . $informacion->paqueteria . '/CrearGuia')
                ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
                ->body($cotizar_paqueteria->data, \Httpful\Mime::FORM)
                ->send();
        }

        $crear_guia_raw = $crear_guia->raw_body;
        $crear_guia = @json_decode($crear_guia->raw_body);

        if (empty($crear_guia)) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al generar la guía, error desconocido" . $log;
            $response->raw = $crear_guia_raw;

            return $response;
        }

        if (!property_exists($crear_guia, "code")) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al generar la guía, no se encontró el campo de 'code'" . $log;
            $response->raw = $crear_guia_raw;
            $informacion->id < 100 ? $response->data = $cotizar_paqueteria->data : $response->data = "Hubo un error al generar la guia";

            return $response;
        }

        if ($crear_guia->code != 200) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al generar la guía, mensaje de error: " . $crear_guia->mensaje . "" . $log;

            return $response;
        }

        $destino_telefono = empty($informacion_cliente->telefono) ? "1" : (is_numeric(trim($informacion_cliente->telefono)) ? ((float) $informacion_cliente->telefono < 1 ? "1" : $informacion_cliente->telefono) : "1");

        DB::table('paqueteria_guia')->insert([
            'id_documento' => $documento,
            'id_paqueteria' => $informacion->id,
            'id_usuario' => $usuario,
            'guia' => $crear_guia->guia,
            'binario' => $crear_guia->binario,
            'costo' => $informacion->id > 100 ? 0 : $cotizar_paqueteria->total,
            'seguro' => 0,
            'monto_seguro' => 0,
            'contenido' => $direccion->contenido,
            'numero_guias' => 1,
            'peso' => 1,
            'tipo_envio' => $direccion->tipo_envio,
            'tipo_paquete' => 'N/A',
            'largo' => $informacion->id > 100 ? 20 : 1,
            'alto' => $informacion->id > 100 ? 15 : 1,
            'ancho' => $informacion->id > 100 ? 15 : 1,
            'ori_empresa' => $empresa,
            'ori_contacto' => $usuario_data->nombre,
            'ori_celular' => "3336151770",
            'ori_telefono' => "3336151770",
            'ori_direccion_1' => "INDUSTRIA VIDRIERA #105",
            'ori_direccion_2' => "ENTRE INDUSTRIA TEXTIL",
            'ori_direccion_3' => "E INDUSTRIA MADERERA",
            'ori_referencia' => '',
            'ori_colonia' => "ZAPOPAN INDUSTRIAL NORTE",
            'ori_ciudad' => "ZAPOPAN",
            'ori_estado' => "JALISCO",
            'ori_cp' => "45130",
            'des_empresa' => $informacion_cliente->razon_social,
            'des_contacto' => $direccion->contacto,
            'des_celular' => $destino_telefono,
            'des_telefono' => $destino_telefono,
            'des_email' => $informacion_cliente->correo,
            'des_direccion_1' => $direccion->calle,
            'des_direccion_2' => $direccion->numero . " " . $direccion->numero_int,
            'des_direccion_3' => $direccion->colonia,
            'des_referencia' => $direccion->referencia,
            'des_colonia' => $direccion->colonia,
            'des_ciudad' => $direccion->ciudad,
            'des_estado' => $direccion->estado,
            'des_cp' => $direccion->codigo_postal
        ]);

        $existe_documento_guia = DB::select("SELECT id FROM documento_guia WHERE id_documento = " . $documento . " AND guia = '" . trim($crear_guia->guia) . "'");

        if (empty($existe_documento_guia)) {
            DB::table("documento_guia")->insert([
                "id_documento" => $documento,
                'guia' => $crear_guia->guia,
                'costo' => $informacion->id > 100 ? 0 : $cotizar_paqueteria->total
            ]);
        }

        $existe_manifiesto_guia = DB::select("SELECT id FROM manifiesto WHERE guia = '" . trim($crear_guia->guia) . "'");

        if (empty($existe_manifiesto_guia)) {
            $impresora_documento = DB::table("documento")
                ->select("empresa_almacen.id_impresora_manifiesto")
                ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
                ->where("documento.id", $documento)
                ->first();

            $shiping = DB::table("documento")->select("id_paqueteria")->where("id", $documento)->first();

            DB::table('manifiesto')->insert([
                'id_impresora' => $impresora_documento->id_impresora_manifiesto,
                'manifiesto' => date('dmY'),
                'guia' => $crear_guia->guia,
                'id_paqueteria' => $shiping->id_paqueteria,
                'id_marketplace_area' => $informacion_documento->id_marketplace_area == 64 ? $informacion_documento->id_marketplace_area : null,
                'notificado' => $informacion_documento->id_marketplace_area == 64 ? 0 : null
            ]);
        }

        if($informacion_documento->id_marketplace_area == 35 || $informacion_documento->id_marketplace_area == "35") {
            ShopifyService::actualizarPedido($informacion_documento->id_marketplace_area, $informacion_documento->no_venta, $crear_guia->guia);
        }

        try {
            $nombre_guia = "GUIA_" . $informacion->paqueteria . "_" . $documento . ".pdf";

            $response = \Httpful\Request::post(config('webservice.dropbox') . '2/files/upload')
                ->addHeader('Authorization', "Bearer " . config("keys.dropbox") . "")
                ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $nombre_guia . '" , "mode": "add", "autorename": true}')
                ->addHeader('Content-Type', 'application/octet-stream')
                ->body(base64_decode($crear_guia->binario))
                ->send();

            DB::table('documento_archivo')->insert([
                'id_documento'  => $documento,
                'id_usuario'    => 1,
                'tipo'          => 2,
                'id_impresora'  => 1,
                'nombre'        => $nombre_guia,
                'dropbox'       => $response->body->id
            ]);

            $response->error = 0;

            return $response;
        } catch (Exception $e) {
            $log = self::logVariableLocation();

            $response->mensaje = "Ocurrió un error al subir la guía generada a dropbox, favor de contactar a un adminitrador, mensaje de error: " . $e->getMessage() . "" . $log;

            return $response;
        }
    }

    public static function cotizarGuia($data, $paqueteria, $usuario, $cliente, $empresa)
    {
        $response = new \stdClass();
        $response->error = 1;

        $destino_telefono = empty($cliente->telefono) ? "1" : (is_numeric(trim($cliente->telefono)) ? ((float) $cliente->telefono < 1 ? "1" : $cliente->telefono) : "1");

        switch (strtolower($paqueteria)) {
            case 'fedex':
                $array = array(
                    'contenido' => substr(empty($data->contenido) || $data->contenido == 'N/A' ?: "PAQUETE", 0, 20),
                    'peso' => 1,
                    'alto' => 1,
                    'ancho' => 1,
                    'largo' => 1,
                    'tipo_envio' => $data->tipo_envio,
                    'asegurar' => 0,
                    'monto' => 0,
                    'referencia' => substr($data->referencia, 0, 25),
                    'origen_empresa' => "OMG INTERNATIONAL SA DE CV",
                    'origen_contacto' => $usuario->nombre,
                    'origen_telefono' => "3336151770",
                    'origen_celular' => "3336151770",
                    'origen_domicilio_1' => "LATERAL PERIFERICO #10042",
                    'origen_domicilio_2' => "EXITMEX",
                    'origen_domicilio_3' => "EXITMEX",
                    'origen_colonia' => "CHAPALITA INN",
                    'origen_ciudad' => "ZAPOPAN",
                    'origen_estado' => "JALISCO",
                    'origen_codigo_postal' => "45130",
                    'destino_empresa' => substr($cliente->razon_social, 0, 30),
                    'destino_contacto' => substr($data->contacto, 0, 30),
                    'destino_telefono' => $destino_telefono,
                    'destino_celular' => $destino_telefono,
                    'destino_domicilio_1' => substr($data->calle, 0, 30),
                    'destino_domicilio_2' => substr($data->numero . " " . $data->numero_int, 0, 30),
                    'destino_colonia' => substr($data->colonia, 0, 30),
                    'destino_ciudad' => substr($data->ciudad, 0, 30),
                    'destino_estado' => substr($data->estado, 0, 30),
                    'destino_codigo_postal' => $data->codigo_postal
                );

                $cotizar = array(
                    'tipo' => $data->tipo_envio,
                    'destino' => substr($data->ciudad, 0, 30),
                    'peso' => 1,
                    'cp_ini' => 45130,
                    'cp_end' => $data->codigo_postal,
                );

                break;
                //ya le cambié a que ponga la empresa que cotiza
            case 'dhl':
                $array = array(
                    'user_email' => $usuario->email,
                    'contenido' => substr(empty($data->contenido) || $data->contenido == 'N/A' ?: "PAQUETE", 0, 20),
                    'peso' => 1,
                    'height' => 1,
                    'width' => 1,
                    'depth' => 1,
                    'asegurar' => 0,
                    'monto_seguro' => 0,
                    'tipo_envio' => $data->tipo_envio,
                    'ori_empresa' => $empresa,
                    'ori_contacto' => $usuario->nombre,
                    'ori_telefono' => "3336151770",
                    'ori_celular' => "3336151770",
                    'ori_domi_1' => "LATERAL PERIFERICO #10042",
                    'ori_domi_2' => "CHAPALITA INN",
                    'ori_domi_3' => "EXITMEX",
                    'ori_ciudad' => "ZAPOPAN",
                    'ori_estado' => "JALISCO",
                    'ori_cp' => "45010",
                    'des_empresa' => substr($cliente->razon_social, 0, 30),
                    'des_contacto' => substr($data->contacto, 0, 30),
                    'des_email' => $cliente->correo,
                    'des_telefono' => $destino_telefono,
                    'des_celular' => $destino_telefono,
                    'des_domi_1' => substr($data->calle, 0, 30),
                    'des_domi_2' => substr($data->numero . " " . $data->numero_int, 0, 30),
                    'des_domi_3' => substr($data->colonia, 0, 30),
                    'des_colonia' => substr($data->colonia, 0, 30),
                    'des_ciudad' => substr($data->ciudad, 0, 30),
                    'des_estado' => substr($data->estado, 0, 30),
                    'des_cp' => $data->codigo_postal,
                    'referencia' => substr($data->referencia, 0, 25)
                );

                $cotizar = array(
                    'destino' => substr($data->ciudad, 0, 30),
                    'peso' => 1,
                    'tipo' => $data->tipo_envio,
                    'largo' => 1,
                    'ancho' => 1,
                    'alto' => 1,
                    'cp_ini' => "45130",
                    'cp_end' => $data->codigo_postal
                );

                break;

            case 'ups':

                $array = array(
                    'service_code' => $data->tipo_envio,
                    'service_name' => '',
                    'package_description' => substr(empty($data->contenido) || $data->contenido == 'N/A' ?: "PAQUETE", 0, 20),
                    'packaging_description' => '',
                    'peso' => 1,
                    'width' => 1,
                    'height' => 1,
                    'length' => 1,
                    'customercontext' => '',
                    'shipper_description' => '',
                    'shipper_name' => "WIMTECH DE MEXICO SA DE CV",
                    'shipper_attentionname' => $usuario->nombre,
                    'shipper_telefono' => "3336151770",
                    'shipper_extension' => '',
                    'shipper_address1' => "INDUSTRIA VIDRIERA #105",
                    'shipper_address2' => "ENTRE INDUSTRIA TEXTIL",
                    'shipper_address3' => "E INDUSTRIA MADERERA",
                    'shipper_city' => "ZAPOPAN",
                    'shipper_estado_code' => substr("JALISCO", 0, 3),
                    'shipper_codigo_postal' => "45130",
                    'shipto_name' => substr($cliente->razon_social, 0, 30),
                    'shipto_attentionName' => substr($data->contacto, 0, 30),
                    'shipto_email' => $cliente->correo,
                    'shipto_phone' => $destino_telefono,
                    'shipto_addressline1' => substr($data->calle, 0, 30),
                    'shipto_addressline2' => substr($data->numero . " " . $data->numero_int, 0, 30),
                    'shipto_addressline3' => substr($data->colonia, 0, 30),
                    'shipto_city' => substr($data->colonia, 0, 30),
                    'shipto_estado_code' => substr($data->estado, 0, 3),
                    'shipto_codigo_postal' => $data->codigo_postal
                );

                $cotizar = array(
                    'service_code' => $data->tipo_envio,
                    'service_description' => '',
                    'packagingtype_code' => '02',
                    'peso' => 1,
                    'largo' => 1,
                    'ancho' => 1,
                    'alto' => 1,
                    'shipper_postalcode' => "45130",
                    'shipfrom_name' => "WIMTECH DE MEXICO SA DE CV",
                    'shipfrom_addressline1' => "INDUSTRIA VIDRIERA #105",
                    'shipfrom_addressline2' => "ENTRE INDUSTRIA TEXTIL",
                    'shipfrom_addressline3' => "E INDUSTRIA MADERERA",
                    'shipfrom_postalcode' => "45130",
                    'shipto_name' => substr($cliente->razon_social, 0, 30),
                    'shipto_addressline1' => substr($data->calle, 0, 30),
                    'shipto_addressline2' => substr($data->numero . " " . $data->numero_int, 0, 30),
                    'shipto_addressline3' => substr($data->colonia, 0, 30),
                    'shipto_postalcode' => $data->codigo_postal,
                );

                break;

            case 'paquetexpress':
                $array = array(
                    "typeservice" => $data->tipo_envio,
                    "from" => array(
                        "company" => "WIMTECH DE MEXICO SA DE CV",
                        "contact" => $usuario->nombre,
                        "email" => $usuario->email,
                        "phone" => "3336151770",
                        "address1" => "INDUSTRIA VIDRIERA",
                        "number" => "105",
                        "neighborhood" => "ZAPOPAN INDUSTRIAL NORTE",
                        "city" => "ZAPOPAN",
                        "state" => "JALISCO",
                        "zip_code" => "45130"
                    ),
                    "to" => array(
                        "company" => substr($cliente->razon_social, 0, 30),
                        "contact" => substr($data->contacto, 0, 30),
                        "email" => $cliente->correo,
                        "phone" => $destino_telefono,
                        "address1" => substr($data->calle, 0, 30),
                        "number" => empty($data->numero) ? "SN" : $data->numero,
                        "neighborhood" => substr($data->colonia, 0, 30),
                        "city" => substr($data->ciudad, 0, 30),
                        "state" => substr($data->estado, 0, 30),
                        "zip_code" => $data->codigo_postal
                    ),
                    "reference" => substr($data->referencia, 0, 25),
                    "insurance" => 0,
                    "packets" => array(
                        "0" => array(
                            "weight" => 1,
                            "depth" => 1,
                            "width" => 1,
                            "height" => 1
                        )
                    ),
                    "content" => substr(empty($data->contenido) || $data->contenido == 'N/A' ?: "PAQUETE", 0, 20),
                    "ocurr" => "EAD",
                    "observation" => ""
                );

                $cotizar = array(
                    "typeservice" => $data->tipo_envio,
                    "from" => array(
                        "neighborhood" => "ZAPOPAN INDUSTRIAL NORTE",
                        "zip_code" => "45130"
                    ),
                    "to" => array(
                        "neighborhood" => substr($data->colonia, 0, 30),
                        "zip_code" => $data->codigo_postal
                    ),
                    "insurance" => 0,
                    "packets" => array(
                        "0" => array(
                            "weight" => 1,
                            "depth" => 1,
                            "width" => 1,
                            "height" => 1
                        )
                    )
                );

                break;

            case 'estafeta':
                $array = [
                    'contenido' => substr(empty($data->contenido) || $data->contenido == 'N/A' ?: "PAQUETE", 0, 20),
                    'numero_guias' => 1,
                    'peso' => 1,
                    'tipo_paquete' => 1,
                    'tipo_envio' => $data->tipo_envio,
                    "height" => 10,
                    "length" => 10,
                    "width" => 10,
                    "ensure" => 0,
                    'referencia' => "",
                    'informacion_adicional' => "",
                    'empresa' => "WIMTECH DE MEXICO SA DE CV",
                    'usuario' => "SISTEMA OMG",
                    'contacto' => "SISTEMA OMG",
                    'telefono' => "3336151770",
                    'celular' => "3336151770",
                    'origin_domicilio1' => "INDUSTRIA VIDRIERA #105",
                    'origin_domicilio2' => "FRACC. ZAPOPAN INDUSTRIAL NTE",
                    'colonia' => "FRACC. ZAPOPAN INDUSTRIAL NTE",
                    'ciudad' => "ZAPOPAN",
                    'estado' => "JALISCO",
                    'cp' => "45130",
                    "calle" => substr($data->calle, 0, 30),
                    "direccion_referencia" => "",
                    "externalnum" => $data->numero,
                    'destino_empresa' => substr($cliente->razon_social, 0, 30),
                    'destino_contacto' => substr($data->contacto, 0, 30),
                    'destino_telefono' => $destino_telefono,
                    'destino_cellphone' => $destino_telefono,
                    'destino_domicilio1' => substr($data->calle, 0, 30),
                    'destino_domicilio2' => substr($data->numero . " " . $data->numero_int, 0, 30),
                    'destino_colonia' => substr($data->colonia, 0, 30),
                    'destino_ciudad' => substr($data->ciudad, 0, 30),
                    'destino_estado' => substr($data->estado, 0, 30),
                    'destino_cp' => $data->codigo_postal,
                    "destino_calle" => substr($data->calle, 0, 30),
                    "destino_direccion_referencia" => substr($data->referencia, 0, 25),
                    "destino_externalnum" => substr($data->numero, 0, 30),
                    "destino_correo" => $usuario->email,
                    'from_cord_exists' => false,
                    'from_lng' => "not_found",
                    'from_lat' => "not_found",
                    'to_cord_exists' => false,
                    'to_lng' => "not_found",
                    'to_lat' => "not_found"
                ];

                $cotizar = [
                    'paquete' => array(
                        'peso' => 1,
                        'dimensiones' => array(
                            'largo' => 10,
                            'ancho' => 10,
                            'alto' => 10
                        )
                    ),
                    'servicio' => $data->tipo_envio,
                    'origen' => 45130, # codigo postal de Vidriera (domicilio registrado en todas las paqueterías)
                    'destino' => $data->codigo_postal,
                ];

                break;
        }

        try {
            $cotizar_res = \Httpful\Request::post(config("webservice.paqueterias") . 'api/' . $paqueteria . '/Cotizar')
                ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
                ->body($cotizar, \Httpful\Mime::FORM)
                ->send();

            $cotizar_raw = $cotizar_res->body;
            $cotizar_res = @json_decode($cotizar_res->raw_body);

            if (empty($cotizar_res)) {
                $response->error = 1;
                $response->mensaje = "No fue posible cotizar el envio, error desconocido";
                $response->raw = $cotizar_raw;

                return $response;
            }

//            if ($cotizar_res->error == 1) {
//                $response->error = 1;
//                $response->mensaje = "No fue posible cotizar el envio, error " . $cotizar_res->mensaje;
//
//                return $response;
//            }

            $response->error = 0;
            $response->total = (float) $cotizar_res->base + (float) $cotizar_res->extra;
            $response->data = $array;

            return $response;
        } catch (Exception $e) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No fue posible cotizar el envio, error " . $e->getMessage() . "" . $log;
            $response->raw = $cotizar_raw;

            return $response;
        }
    }

    public static function informacionProducto($codigo, $empresa)
    {
        $response = new \stdClass();
        $response->error = 0;

        $informacion = file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $empresa . '/' . rawurlencode($codigo));
        $informacion_parsed = @json_decode($informacion);

        if (empty($informacion_parsed)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->message = "No se pudo obtener información del producto." . $log;
            $response->raw = $informacion;

            return $response;
        }

        $response->data = is_array($informacion_parsed) ? $informacion_parsed[0] : $informacion_parsed;

        return $response;
    }

    public static function sendEmailToAdmins($recurso, $mensaje, $raw, $tipo = 0 /* 0 = Error, 1 = Advertencia */, $extra_emails = [])
    {
        $emails = "";
        $query = "";

        $view = view('email.notificacion_error_sistema')->with([
            "recurso" => $recurso,
            "mensaje" => $mensaje,
            "raw" => $raw,
            "anio" => date("Y")
        ]);

        if (!empty($extra_emails)) {
            $query = "OR usuario.id IN (" . implode(",", $extra_emails) . ")";
        }

        $admins = DB::select("SELECT
                                usuario.email
                            FROM usuario
                            INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                            INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                            INNER JOIN subnivel on subnivel_nivel.id_subnivel = subnivel.id
                            WHERE subnivel.subnivel = 'SISTEMA'
                            " . $query . "
                            GROUP BY usuario.email");

        foreach ($admins as $admin) {
            $emails .= $admin->email . ";";
        }

        $emails .= "sistemas@omg.com.mx";

        # $emails = substr($emails, 0, -1);

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->sendMessage($domain, array(
            'from' => 'CRM OMG International <crm@omg.com.mx>',
            'to' => $emails,
            'subject' => !$tipo ? 'Error en CRM' : '¡Advertencia!',
            'html' => $view
        ));
    }

    public static function randomString($length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'GS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'RAL'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
