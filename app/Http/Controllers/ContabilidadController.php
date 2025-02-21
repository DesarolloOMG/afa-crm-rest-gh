<?php

namespace App\Http\Controllers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use Mailgun\Mailgun;
use DOMDocument;
use Exception;
use DateTime;
use DB;
use MP;

class ContabilidadController extends Controller
{
    /* Contabilidad > Pagos */
    public function contabilidad_pagos_data(Request $request)
    {
        $metodos = DB::select("SELECT * FROM metodo_pago");
        $monedas = DB::select("SELECT * FROM moneda");
        $auth = json_decode($request->auth);

        $ventas = $this->ventas_raw_data("AND usuario_empresa.id_usuario = " . $auth->id . " AND documento.pagado = 0 AND marketplace_area.publico = 0 AND documento_periodo.id = 1 AND documento.id_fase BETWEEN 2 AND 5");

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas,
            'metodos'   => $metodos,
            'monedas'   => $monedas
        ]);
    }

    public function contabilidad_pagos_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!$data->terminar) {
            DB::table('seguimiento')->insert([
                'id_documento'  => $data->documento,
                'id_usuario'    => $auth->id,
                'seguimiento'   => $data->seguimiento
            ]);

            return response()->json([
                'code'  => 200,
                'message'   => "Seguimiento guardado correctamente."
            ]);
        }

        $existe_pago = DB::select("SELECT
                                    documento_pago.* 
                                FROM documento_pago_re 
                                INNER JOIN documento_pago ON documento_pago_re.id_pago = documento_pago.id
                                WHERE documento_pago_re.id_documento = " . $data->documento . "");

        $rfc_cliente = DB::select("SELECT
                                        documento_entidad.rfc
                                    FROM documento
                                    INNER JOIN documento_entidad ON documento_entidad.id = documento.id_entidad
                                    WHERE documento.id = " . $data->documento . "")[0]->rfc;
        # se cambia el id clasificacion a 0 ya que no existe otro
        if (empty($existe_pago)) {
            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario'                => $auth->id,
                'id_metodopago'             => $data->metodo_pago,
                'id_clasificacion'          => 0,
                'destino_importe'           => $data->importe,
                'entidad_destino'           => $data->entidad_destino,
                'destino_entidad'           => $data->destino,
                'entidad_origen'            => 1, #cliente,
                'origen_entidad'            => $rfc_cliente,
                'referencia'                => $data->referencia,
                'clave_rastreo'             => $data->clave_rastreo,
                'autorizacion'              => $data->numero_aut,
                'origen_fecha_operacion'   => date('Y-m-d'),
                'origen_fecha_afectacion'  => $data->fecha_cobro,
                'destino_fecha_operacion'   => date('Y-m-d'),
                'destino_fecha_afectacion'  => $data->fecha_cobro,
                'cuenta_cliente'            => $data->cuenta_cliente
            ]);

            DB::table('documento_pago_re')->insert([
                'id_pago'   => $pago,
                'id_documento'  => $data->documento
            ]);
        } else {
            DB::table('documento_pago')->where(['id' => $existe_pago[0]->id])->update([
                'id_metodopago'             => $data->metodo_pago,
                'destino_importe'           => $data->importe,
                'entidad_origen'            => 1, #cliente,
                'origen_entidad'            => $rfc_cliente,
                'entidad_destino'           => $data->entidad_destino,
                'destino_entidad'           => $data->destino,
                'referencia'                => $data->referencia,
                'clave_rastreo'             => $data->clave_rastreo,
                'autorizacion'              => $data->numero_aut,
                'origen_fecha_operacion'    => date('Y-m-d'),
                'origen_fecha_afectacion'   => $data->fecha_cobro,
                'destino_fecha_operacion'   => date('Y-m-d'),
                'destino_fecha_afectacion'  => $data->fecha_cobro,
                'cuenta_cliente'            => $data->cuenta_cliente
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        $data_documento = DB::table('documento')->where('id', $data->documento)->first();

        if($data_documento->anticipada) {
            DB::table('documento')->where(['id' => $data->documento])->update([
                'pagado' => 1,
                'id_fase' => 6
            ]);
        } else {
            DB::table('documento')->where(['id' => $data->documento])->update([
                'pagado' => 1
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Documento guardado correctamente."
        ]);
    }

    /* Contabilidad > Linio */
    public function contabilidad_linio_guardar(Request $request)
    {
        set_time_limit(0);

        $facturas = array();
        $data = json_decode($request->input('data'));

        file_put_contents("logs/linio.log", "");

        if (empty($data->xmls)) {
            return response()->json([
                'message' => "No se encontró ninguna factura para importar."
            ], 404);
        }

        if (!$data->empresa) {
            return response()->json([
                "message" => "No se encontró una empresa seleccionada para generar la importación"
            ], 404);
        }

        $informacion_empresa = DB::table("empresa")->find($data->empresa);

        if (!$informacion_empresa) {
            return response()->json([
                "message" => "No se encontró información de la empresa seleccionada"
            ], 404);
        }

        foreach ($data->xmls as $xml) {
            $xml_data = simplexml_load_string($xml->path, 'SimpleXMLElement', LIBXML_NOWARNING);

            if (empty($xml_data)) {
                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: XML Invalido." . PHP_EOL, FILE_APPEND);

                continue;
            }

            $documento = new DOMDocument;

            $documento->loadXML($xml_data->asXML());

            $comprobante = $documento->getElementsByTagName('Comprobante')->item('0');
            $emisor = $documento->getElementsByTagName('Emisor')->item('0');
            $receptor = $documento->getElementsByTagName('Receptor')->item('0');
            $uuid = $documento->getElementsByTagName('TimbreFiscalDigital')->item('0');

            if ($emisor->getAttribute('Rfc') === $informacion_empresa->rfc) {
                $conceptos = $documento->getElementsByTagName('Concepto');

                if (empty($conceptos)) {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: El XML con el UUID " . $uuid->getAttribute('UUID') . " no contiene conceptos." . PHP_EOL, FILE_APPEND);

                    continue;
                }

                foreach ($xml->ventas as $venta) {
                    $existe_venta_crm = DB::select("SELECT
                                                    documento.id,
                                                    documento.id_almacen_principal_empresa,
                                                    documento.factura_serie,
                                                    documento.factura_folio,
                                                    documento.created_at
                                                FROM documento
                                                WHERE (no_venta = '" . TRIM($venta) . "' OR no_venta = '" . TRIM($venta) . "F')
                                                AND status = 1");

                    if (empty($existe_venta_crm)) {
                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: La venta " . $venta . " relacionada al UUID " . $uuid->getAttribute('UUID') . " no existe registrada en el sistema como activa." . PHP_EOL, FILE_APPEND);

                        continue 2;
                    }
                }

                $factura_data = new \stdClass();
                $factura_data->serie = mb_strtoupper($comprobante->getAttribute('Serie'), 'UTF-8');
                $factura_data->folio = mb_strtoupper($comprobante->getAttribute('Folio'), 'UTF-8');
                $factura_data->uuid = $uuid->getAttribute('UUID');
                $factura_data->moneda = $comprobante->getAttribute('Moneda');
                $factura_data->tc = $comprobante->getAttribute('TipoCambio');
                $factura_data->forma_pago = $comprobante->getAttribute('FormaPago');
                $factura_data->metodo_pago = $comprobante->getAttribute('MetodoPago');
                $factura_data->fecha = explode("T", $comprobante->getAttribute('Fecha'))[0];
                $factura_data->anio = explode("-", $comprobante->getAttribute('Fecha'))[0];
                $factura_data->ventas = $xml->ventas;

                $cliente_data = new \stdClass();
                $cliente_data->rfc = mb_strtoupper($receptor->getAttribute('Rfc'), 'UTF-8');
                $cliente_data->nombre = mb_strtoupper($receptor->getAttribute('Nombre'), 'UTF-8');
                $cliente_data->uso_cfdi = $receptor->getAttribute('UsoCFDI');

                $factura_data->cliente = $cliente_data;
                $factura_data->envio = 0;
                $factura_data->descuento = (float) $comprobante->getAttribute('Descuento');

                $conceptos = $documento->getElementsByTagName('Concepto');

                foreach ($conceptos as $concepto) {
                    if (strpos($concepto->getAttribute('Descripcion'), 'Envio') !== false) {
                        $factura_data->envio += (float) $concepto->getAttribute('Importe');
                    }
                }

                array_push($facturas, $factura_data);
            } else {
                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: El emisor de la factura no corresponde a la empresa seleccionada, RFC emisor " . $emisor->getAttribute('Rfc') . "." . PHP_EOL, FILE_APPEND);
            }
        }

        foreach ($facturas as $factura) {
            $productos = [];
            $total_documento = 0;
            $bd = "7";

            if (empty($factura->ventas)) {
                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No se encontraron ventas en el CRM para la factura de " . $factura->serie . " " . $factura->folio . " con el UUID " . $factura->uuid . "" . PHP_EOL, FILE_APPEND);

                continue;
            }

            foreach ($factura->ventas as $venta) {
                $existe_venta = DB::select("SELECT
                                                documento.id,
                                                documento.factura_folio,
                                                documento.factura_serie,
                                                empresa.bd,
                                                empresa_almacen.id_erp AS almacen
                                            FROM documento 
                                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                            WHERE (no_venta = '" . TRIM($venta) . "' OR no_venta = '" . TRIM($venta) . "F')");

                if (empty($existe_venta)) {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: Venta " . $venta . " no encontrada en CRM, creación de la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . PHP_EOL, FILE_APPEND);

                    continue 2;
                }

                if ($existe_venta[0]->factura_folio != 'N/A') {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: Venta " . $venta . " en la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . " ya ha sido importada anteriormente con la factura " . $existe_venta[0]->factura_serie . " " . $existe_venta[0]->factura_folio . PHP_EOL, FILE_APPEND);

                    continue 2;
                }

                $productos_venta = DB::select("SELECT
                                                '' AS id,
                                                movimiento.id AS id_movimiento,
                                                modelo.sku,
                                                modelo.serie,
                                                movimiento.cantidad,
                                                movimiento.precio AS precio_unitario,
                                                0 AS descuento,
                                                movimiento.comentario AS comentarios,
                                                movimiento.addenda AS addenda_numero_entrada_almacen
                                            FROM movimiento
                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                            WHERE movimiento.id_documento = " . $existe_venta[0]->id . "");

                foreach ($productos_venta as $producto) {
                    $total_documento += $producto->cantidad * $producto->precio_unitario * 1.16;
                }

                $productos = array_merge($productos, $productos_venta);
                $bd = $existe_venta[0]->bd;
                $almacen = $existe_venta[0]->almacen;
            }

            if ($factura->envio > 0) {
                $producto_envio = new \stdClass();

                $producto_envio->id = "";
                $producto_envio->sku = "ZZGZ0001";
                $producto_envio->serie = 0;
                $producto_envio->cantidad = 1;
                $producto_envio->precio_unitario = $factura->envio;
                $producto_envio->descuento = 0;
                $producto_envio->comentarios = "";
                $producto_envio->addenda_numero_entrada_almacen = "";

                $total_documento += $factura->envio * 1.16;

                array_push($productos, $producto_envio);
            }

            # Se busca al cliente por RFC para verificar que exista
            $cliente_data = @json_decode(file_get_contents(config('webservice.url') . "Consultas/Clientes/" . $bd . "/RFC/" . $factura->cliente->rfc));

            if (empty($cliente_data)) {
                // $entidad_data = new \stdClass();
                // $entidad_data->rfc = $factura->cliente->rfc;
                // $entidad_data->razon_social = $factura->cliente->nombre;
                // $entidad_data->codigo_postal_fiscal = 'N/A';
                // $entidad_data->regimen_id = '601';

                // $crear_cliente = DocumentoService::crearEntidad($entidad_data, $bd);

                // if ($crear_cliente->error) {
                //     file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear al cliente de la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . ", error: " . $crear_cliente->mensaje . "" . PHP_EOL, FILE_APPEND);

                //     continue;
                // }
            }

            try {
                $array_pro = array(
                    "bd" => $bd,
                    "password" => config('webservice.token'),
                    "prefijo" => $factura->serie,
                    "folio" => $factura->folio,
                    "uuid" => $factura->uuid,
                    "fecha" => $factura->fecha,
                    "cliente" => $factura->cliente->rfc,
                    "titulo" => "Factura generada por linio",
                    "almacen" => $almacen, # FBL
                    "fecha_entrega_doc" => "",
                    "divisa" => ($factura->moneda == 'MXN') ? 3 : 2,
                    "tipo_cambio" => $factura->tc,
                    "condicion_pago" => 1,
                    "descuento_global" => $factura->descuento,
                    "productos" => json_encode($productos),
                    "metodo_pago" => $factura->metodo_pago,
                    "forma_pago" => $factura->forma_pago,
                    "uso_cfdi" => $factura->cliente->uso_cfdi,
                    "comentarios" => implode(" ", $factura->ventas),
                    'addenda' => 0,
                    'addenda_orden_compra' => "",
                    'addenda_solicitud_pago' => "",
                    'addenda_tipo_documento ' => "",
                    'addenda_factura_asociada' => ""
                );

                $crear_documento = \Httpful\Request::post(config('webservice.url') . "facturas/cliente/insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
                    ->body($array_pro, \Httpful\Mime::FORM)
                    ->send();

                $crear_documento_raw = $crear_documento->raw_body;
                $crear_documento = @json_decode($crear_documento);

                if (empty($crear_documento)) {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . " en el ERP, mensaje de error: " . $crear_documento_raw . "." . PHP_EOL, FILE_APPEND);

                    continue;
                }

                if ($crear_documento->error == 1) {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . " en el ERP, mensaje de error: " . $crear_documento->mensaje . "." . PHP_EOL, FILE_APPEND);


                    continue;
                }

                foreach ($factura->ventas as $venta) {
                    $existe_venta = DB::select("SELECT id FROM documento WHERE (no_venta = '" . TRIM($venta) . "' OR no_venta = '" . TRIM($venta) . "F') AND id_fase != 6");

                    if (!empty($existe_venta)) {
                        DB::table('documento')->where(['id' => $existe_venta[0]->id])->update([
                            'factura_serie' => $factura->serie,
                            'factura_folio' => $factura->folio,
                            'documento_extra' => $crear_documento->id,
                            'uuid' => $factura->uuid,
                            'id_fase' => 6
                        ]);
                    }
                }
            } catch (Exception $e) {
                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la factura " . $factura->serie . " " . $factura->folio . " con UUID " . $factura->uuid . " en el ERP, mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                continue;
            }
            // INGRESO LINIO
            // $factura_id = $crear_documento->id;

            // try {
            //     # Ingreso por el total de la venta
            //     $ingreso = array(
            //         "bd" => $bd,
            //         "password" => config('webservice.token'),
            //         "folio" => "",
            //         "monto" => $total_documento,
            //         "fecha_operacion" => $factura->fecha,
            //         "origen_entidad" => 1, # Tipo de entidad, 1 = Cliente
            //         "origen_cuenta" => $factura->cliente->rfc,
            //         "destino_entidad" => 1, # Tipo de entidad, 1 = Cuenta bancaria
            //         "destino_cuenta" => 13, # ID de la cuenta bancaria
            //         "forma_pago" => 31,
            //         "cuenta" => "",
            //         "clave_rastreo" => "",
            //         "numero_aut" => "",
            //         "referencia" => "",
            //         "descripcion" => "LINIO - Ingreso de la factura " . $factura->serie . $factura->folio,
            //         "comentarios" => ""
            //     );

            //     $crear_ingreso = \Httpful\Request::post(config('webservice.url') . "Ingresos/Insertar/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
            //         ->body($ingreso, \Httpful\Mime::FORM)
            //         ->send();

            //     $crear_ingreso_raw = $crear_ingreso->raw_body;
            //     $crear_ingreso = @json_decode($crear_ingreso);

            //     if (empty($crear_ingreso)) {
            //         file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso la factura " . $factura->serie . " " . $factura->folio . " con el UUID " . $factura->uuid . ", mensaje de error: " . base64_encode($crear_ingreso_raw) . "." . PHP_EOL, FILE_APPEND);

            //         continue;
            //     }

            //     if ($crear_ingreso->error == 1) {
            //         file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso la factura " . $factura->serie . $factura->folio . " con el UUID " . $factura->uuid . ", mensaje de error: " . $crear_ingreso->mensaje . "." . PHP_EOL, FILE_APPEND);

            //         continue;
            //     }
            // } catch (Exception $e) {
            //     file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso la factura " . $factura->serie . $factura->folio . " con el UUID " . $factura->uuid . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            //     continue;
            // }

            // $id_ingreso = $crear_ingreso->id;

            // try {
            //     $saldar_factura_data = array(
            //         "bd"        => $bd,
            //         "password"  => config('webservice.token'),
            //         "documento" => $factura_id,
            //         "operacion" => $id_ingreso
            //     );

            //     $saldar_factura = \Httpful\Request::post(config('webservice.url') . "CobroCliente/Pagar/FacturaCliente/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw")
            //         ->body($saldar_factura_data, \Httpful\Mime::FORM)
            //         ->send();

            //     $saldar_factura_raw = $saldar_factura->raw_body;
            //     $saldar_factura = @json_decode($saldar_factura);

            //     if (empty($saldar_factura)) {
            //         file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $factura->serie . " " . $factura->folio . " con el UUID " . $factura->uuid . " con el ingreso " . $id_ingreso . ", mensaje de error: " . base64_encode($saldar_factura_raw) . "." . PHP_EOL, FILE_APPEND);

            //         continue;
            //     }

            //     if ($saldar_factura->error == 1) {
            //         file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $factura->serie . " " . $factura->folio . " con el UUID " . $factura->uuid . " con el ingreso " . $id_ingreso . ", mensaje de error: " . $saldar_factura->mensaje . "." . PHP_EOL, FILE_APPEND);

            //         continue;
            //     }
            // } catch (Exception $e) {
            //     file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible saldar la factura " . $factura->serie . " " . $factura->folio . " con el UUID " . $factura->uuid . " con el ingreso " . $id_ingreso . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

            //     continue;
            // }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Facturas importadas correctamente<br><br>Favor de revisar el .log de linio https://rest.crmomg.mx/logs/linio.log"
        ]);
    }

    /* Contabilidad > Facturas */
    public function contabilidad_facturas_pendiente_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $ventas = $this->ventas_raw_data("AND usuario_empresa.id_usuario = " . $auth->id . " AND documento.id_fase = 5 AND marketplace.marketplace != 'LINIO'");

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas
        ]);
    }

    public function contabilidad_facturas_pendiente_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->terminar) {
            //Aqui ta
            $response = DocumentoService::crearFactura($data->documento, 0, 0);

            //            $movimiento = DB::table('movimiento')->where('id_documento', $data->documento)->where('id_modelo', 11623)->first();
            //            $documentoInfo = DB::table('documento')->where('id', $data->documento)->first();
            //
            //            if(!empty($movimiento)) {
            //                DB::table('modelo_inventario')->insert([
            //                    'id_modelo' => 11623,
            //                    'id_documento' => $data->documento,
            //                    'id_almacen' => $documentoInfo->id_almacen_principal_empresa,
            //                    'afecta_costo' => 0,
            //                    'cantidad' => $movimiento->cantidad,
            //                    'costo' => $movimiento->precio
            //                ]);
            //
            //                $afecta_inventario = DB::table('modelo_costo')->where('id_modelo', 11623)->first();
            //                $resta_inventario = $afecta_inventario->stock - $movimiento->cantidad;
            //
            //                DB::table('modelo_costo')->where(['id_modelo' => $afecta_inventario->id_modelo])->update([
            //                    'stock' => $resta_inventario
            //                ]);
            //            }

            if ($response->error) {
                return response()->json([
                    'code'  => 500,
                    'message'   => $response->mensaje
                ]);
            }

            DB::table('documento')->where(['id' => $data->documento])->update([
                'invoice_date'  => date('Y-m-d H:i:s'),
                'id_fase'       => 6
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => $data->terminar ? 'Documento guardado correctamente.' : 'Seguimiento guardado correctamente.'
        ]);
    }

    public function contabilidad_facturas_saldo_data()
    {
        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");

        return response()->json([
            'code'  => 200,
            'empresas'  => $empresas
        ]);
    }

    public function contabilidad_facturas_seguimiento_data($documento)
    {
        $existe_venta = DB::select("SELECT 
                                        documento.id,
                                        documento.expired_at,
                                        documento_entidad.razon_social,
                                        documento_entidad.rfc
                                    FROM documento 
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($existe_venta)) {
            return response()->json([
                'code'  => 500,
                'message'   => "El número de pedido no existe."
            ]);
        }

        $venta = $existe_venta[0];

        $seguimiento = DB::select("SELECT
                                        documento_pago_seguimiento.*, 
                                        usuario.nombre 
                                    FROM documento_pago_seguimiento 
                                    INNER JOIN usuario ON documento_pago_seguimiento.id_usuario = usuario.id 
                                    WHERE id_documento = " . $documento . "");

        $venta->seguimiento = $seguimiento;

        return response()->json([
            'code'  => 200,
            'venta' => $venta
        ]);
    }

    public function contabilidad_facturas_seguimiento_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $existe_venta = DB::select("SELECT id FROM documento WHERE id = " . $data->documento . " AND status = 1");

        if (empty($existe_venta)) {
            return response()->json([
                'code'  => 500,
                'message'   => "El número de pedido no existe."
            ]);
        }

        DB::table('documento_pago_seguimiento')->insert([
            'id_usuario'    => $auth->id,
            'id_documento'  => $data->documento,
            'seguimiento'   => $data->seguimiento
        ]);

        DB::table('documento')->where(['id' => $data->documento])->update([
            'expired_at'    => $data->fecha
        ]);

        $seguimiento = DB::select("SELECT
                                        documento_pago_seguimiento.*, 
                                        usuario.nombre 
                                    FROM documento_pago_seguimiento 
                                    INNER JOIN usuario ON documento_pago_seguimiento.id_usuario = usuario.id 
                                    WHERE id_documento = " . $data->documento . "");

        return response()->json([
            'code'  => 200,
            'message'   => "Seguimiento guardado correctamente.",
            'seguimiento'   => $seguimiento
        ]);
    }

    /* Contabilidad > Estado de cuenta */
    public function contabilidad_estado_ingreso_reporte(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        if (empty($data->entidad->select)) {
            if (empty($data->fecha_inicio) || empty($data->fecha_final)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "Si deseas buscar por fecha, la fecha de inicio y fin no deben de estar vacias."
                ]);
            }

            $fecha_inicial  = explode('-', $data->fecha_inicio);
            $fecha_final    = explode('-', $data->fecha_final);

            $response = @json_decode(file_get_contents(config('webservice.url') . 'EstadoCuenta/Ingresos/' . $data->empresa . '/rangofechas/De/' . $fecha_inicial[2] . '/' . $fecha_inicial[1] . '/' . $fecha_inicial[0] . '/Al/' . $fecha_final[2] . '/' . $fecha_final[1] . '/' . $fecha_final[0] . ''));
        } else {
            if (empty($data->fecha_inicio) && empty($data->fecha_final)) {
                $response = @json_decode(file_get_contents(config('webservice.url') . 'EstadoCuenta/Ingresos/' . $data->empresa . '/RFC/' . $data->entidad->select));
            } else {
                if (empty($data->fecha_inicio) || empty($data->fecha_final)) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "Si deseas buscar por fecha, la fecha de inicio y fin no deben de estar vacias."
                    ]);
                }

                $fecha_inicial  = explode('-', $data->fecha_inicio);
                $fecha_final    = explode('-', $data->fecha_final);

                $response = @json_decode(file_get_contents(config('webservice.url') . 'EstadoCuenta/Ingresos/' . $data->empresa . '/RFC/' . $data->entidad->select . '/rangofechas/De/' . $fecha_inicial[2] . '/' . $fecha_inicial[1] . '/' . $fecha_inicial[0] . '/Al/' . $fecha_final[2] . '/' . $fecha_final[1] . '/' . $fecha_final[0] . ''));
            }
        }

        if (empty($response)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontraron documentos con la información proporcionada."
            ]);
        }

        # Estado de cuenta
        $formas_de_pago = array();
        $spreadsheet    = new Spreadsheet();
        $sheet          = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('ESTADO DE CUENTA GENERAL');
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica

        # Cabecera
        $sheet->setCellValue('A1', 'EMPRESA');
        $sheet->setCellValue('B1', 'ID');
        $sheet->setCellValue('C1', 'CUENTA');
        $sheet->setCellValue('D1', 'MONTO');
        $sheet->setCellValue('E1', 'MONEDA');
        $sheet->setCellValue('F1', 'T.C');
        $sheet->setCellValue('G1', 'FACTURAS PAGADAS');
        $sheet->setCellValue('H1', 'FECHA');

        $contador_fila  = 2;
        $total_reporte  = 0;
        $total_ingresos = 0;

        foreach ($response as $index => $empresa) {
            $sheet->setCellValue('A' . $contador_fila, $empresa->empresa);
            $sheet->setCellValue('B' . $contador_fila, '');
            $sheet->setCellValue('C' . $contador_fila, '');
            $sheet->setCellValue('D' . $contador_fila, '');
            $sheet->setCellValue('E' . $contador_fila, '');
            $sheet->setCellValue('F' . $contador_fila, '');
            $sheet->setCellValue('G' . $contador_fila, '');

            $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ":H" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            $contador_fila++;
            $total_empresa = 0;

            foreach ($empresa->ingresos as $ingreso) {
                $facturas_pagadas = "";

                foreach ($ingreso->pago_a_facturas as $factura) {
                    $facturas_pagadas .= $factura->serie . " " . $factura->folio . "\n";
                }

                $sheet->setCellValue('A' . $contador_fila, '');
                $sheet->setCellValue('B' . $contador_fila, $ingreso->operacion);
                $sheet->setCellValue('C' . $contador_fila, $ingreso->cuenta);
                $sheet->setCellValue('D' . $contador_fila, $ingreso->monto);
                $sheet->setCellValue('E' . $contador_fila, $ingreso->moneda);
                $sheet->setCellValue('F' . $contador_fila, $ingreso->tc);
                $sheet->setCellValue('G' . $contador_fila, $facturas_pagadas);
                $sheet->setCellValue('H' . $contador_fila, $ingreso->fecha);

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ":H" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('89D8D4');

                $contador_fila++;
                $total_ingresos++;

                $total_empresa += $ingreso->monto * $ingreso->tc;

                $existe_forma_pago = 0;

                foreach ($formas_de_pago as $forma_pago) {
                    if ($forma_pago->forma_pago == $ingreso->forma_pago) {
                        $existe_forma_pago = 1;
                    }
                }

                $ingreso->empresa = $empresa->empresa;

                if (!$existe_forma_pago) {
                    $forma_pago_object = new \stdClass();

                    $forma_pago_object->forma_pago  = $ingreso->forma_pago;
                    $forma_pago_object->ingresos    = array();

                    array_push($forma_pago_object->ingresos, $ingreso);

                    array_push($formas_de_pago, $forma_pago_object);
                } else {
                    foreach ($formas_de_pago as $forma_pago) {
                        if ($forma_pago->forma_pago == $ingreso->forma_pago) {
                            array_push($forma_pago->ingresos, $ingreso);
                        }
                    }
                }
            }

            $sheet->setCellValue('A' . $contador_fila, '');
            $sheet->setCellValue('B' . $contador_fila, '');
            $sheet->setCellValue('C' . $contador_fila, '');
            $sheet->setCellValue('D' . $contador_fila, $total_empresa);
            $sheet->setCellValue('E' . $contador_fila, '');
            $sheet->setCellValue('F' . $contador_fila, '');
            $sheet->setCellValue('G' . $contador_fila, '');
            $sheet->setCellValue('H' . $contador_fila, '');

            $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

            $total_reporte += $total_empresa;
            $contador_fila += 3;
        }

        $sheet->setCellValue('A' . $contador_fila, '');
        $sheet->setCellValue('B' . $contador_fila, '');
        $sheet->setCellValue('C' . $contador_fila, '');
        $sheet->setCellValue('D' . $contador_fila, $total_reporte);
        $sheet->setCellValue('E' . $contador_fila, '');
        $sheet->setCellValue('F' . $contador_fila, '');
        $sheet->setCellValue('G' . $contador_fila, '');
        $sheet->setCellValue('H' . $contador_fila, '');

        $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('H')->setAutoSize(true);

        foreach ($formas_de_pago as $index => $forma_pago) {
            $spreadsheet->createSheet();

            $spreadsheet->setActiveSheetIndex($index + 1);
            $spreadsheet->getActiveSheet()->setTitle(substr(mb_strtoupper($forma_pago->forma_pago, 'UTF-8'), 0, 31));
            $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro
            $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica
            $sheet          = $spreadsheet->getActiveSheet();
            $contador_fila  = 2;
            $total_empresa = 0;

            $sheet->setCellValue('A1', 'EMPRESA');
            $sheet->setCellValue('B1', 'ID');
            $sheet->setCellValue('C1', 'CUENTA');
            $sheet->setCellValue('D1', 'MONTO');
            $sheet->setCellValue('E1', 'MONEDA');
            $sheet->setCellValue('F1', 'T.C');
            $sheet->setCellValue('G1', 'FACTURAS PAGADAS');
            $sheet->setCellValue('H1', 'FECHA');

            foreach ($forma_pago->ingresos as $ingreso) {
                $facturas_pagadas = "";

                foreach ($ingreso->pago_a_facturas as $factura) {
                    $facturas_pagadas .= $factura->serie . " " . $factura->folio . "\n";
                }

                $sheet->setCellValue('A' . $contador_fila, $ingreso->empresa);
                $sheet->setCellValue('B' . $contador_fila, $ingreso->operacion);
                $sheet->setCellValue('C' . $contador_fila, $ingreso->cuenta);
                $sheet->setCellValue('D' . $contador_fila, $ingreso->monto);
                $sheet->setCellValue('E' . $contador_fila, $ingreso->moneda);
                $sheet->setCellValue('F' . $contador_fila, $ingreso->tc);
                $sheet->setCellValue('G' . $contador_fila, $facturas_pagadas);
                $sheet->setCellValue('H' . $contador_fila, $ingreso->fecha);

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ":H" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('89D8D4');

                $contador_fila++;

                $total_empresa += $ingreso->monto * $ingreso->tc;
            }

            $contador_fila += 2;

            $sheet->setCellValue('A' . $contador_fila, '');
            $sheet->setCellValue('B' . $contador_fila, '');
            $sheet->setCellValue('C' . $contador_fila, '');
            $sheet->setCellValue('D' . $contador_fila, $total_empresa);
            $sheet->setCellValue('E' . $contador_fila, '');
            $sheet->setCellValue('F' . $contador_fila, '');
            $sheet->setCellValue('G' . $contador_fila, '');
            $sheet->setCellValue('H' . $contador_fila, '');

            $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

            $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
            $spreadsheet->getActiveSheet()->getColumnDimension('H')->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save('estado_cuenta.xlsx');

        $json['code']           = 200;
        $json['excel']          = base64_encode(file_get_contents('estado_cuenta.xlsx'));
        $json['total']          = $total_ingresos;
        $json['formas']         = $formas_de_pago;

        unlink('estado_cuenta.xlsx');

        return response()->json($json);
    }

    public function contabilidad_estado_factura_reporte(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $tipo_busqueda = ($data->entidad->tipo == "Clientes") ? "Cliente" : "Proveedor";
        $tipo_busqueda_pago = ($data->entidad->tipo == "Clientes") ? "Ingresos" : "Egresos";

        if (!empty($data->entidad->select)) {
            if (empty($data->fecha_inicial) || empty($data->fecha_final)) {
                $url = config('webservice.url') . 'EstadoCuenta/' . $tipo_busqueda . '/' . $data->empresa . '/rfc/' . $data->entidad->select;
                $url_pagos_pendientes = config('webservice.url') . 'PendientesAplicar/' . $data->empresa . "/" . $tipo_busqueda_pago . "/RFC/" . $data->entidad->select;
            } else {
                $url = config('webservice.url') . 'EstadoCuenta/' . $tipo_busqueda . '/' . $data->empresa . '/rfc/' . $data->entidad->select . '/rangofechas/De/' . date("d/m/Y", strtotime($data->fecha_inicial)) . '/Al/' . date("d/m/Y", strtotime($data->fecha_final)) . '';
                $url_pagos_pendientes = config('webservice.url') . 'PendientesAplicar/' . $data->empresa . "/" . $tipo_busqueda_pago . "/RFC/" . $data->entidad->select . '/rangofechas/De/' . date("d/m/Y", strtotime($data->fecha_inicial)) . '/Al/' . date("d/m/Y", strtotime($data->fecha_final)) . '';
            }
        } else {
            if (empty($data->fecha_inicial) || empty($data->fecha_final)) {
                $url = config('webservice.url') . 'EstadoCuenta/' . $tipo_busqueda . '/' . $data->empresa;

                $url_pagos_pendientes = config('webservice.url') . 'PendientesAplicar/' . $data->empresa . "/" . $tipo_busqueda_pago;
            } else {
                $url = config('webservice.url') . 'EstadoCuenta/' . $tipo_busqueda . '/' . $data->empresa . '/rangofechas/De/' . date("d/m/Y", strtotime($data->fecha_inicial)) . '/Al/' . date("d/m/Y", strtotime($data->fecha_final)) . '';

                $url_pagos_pendientes = config('webservice.url') . 'PendientesAplicar/' . $data->empresa . "/" . $tipo_busqueda_pago . '/rangofechas/De/' . date("d/m/Y", strtotime($data->fecha_inicial)) . '/Al/' . date("d/m/Y", strtotime($data->fecha_final)) . '';
            }
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        $response = json_decode(curl_exec($curl_handle));

        curl_close($curl_handle);

        if (empty($response)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontraron documentos con los criterios proporcionados."
            ]);
        }

        $nombre_entidad = $response[0]->documento->nombre;
        $titulo = empty($data->entidad->select) ? "Estado de cuenta de " . $data->entidad->tipo : $nombre_entidad;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('ESTADO DE CUENTA HISTORICO');
        $contador_fila = 7;
        $contador_fila_saldo = 7;
        $contador_fila_sin_saldo = 7;
        $contador_fila_nuevo_edc = 9;
        $total_resta_entidad = 0;
        $total_resta_entidad_saldo = 0;
        $total_resta_entidad_saldo_usd = 0;
        $total_resta_entidad_saldo_nuevo_edc = 0;
        $total_resta_15_dias = 0;
        $total_resta_30_dias = 0;
        $total_resta_45_dias = 0;
        $total_resta_60_dias = 0;
        $total_resta_75_dias = 0;
        $total_resta_15_dias_saldo = 0;
        $total_resta_30_dias_saldo = 0;
        $total_resta_45_dias_saldo = 0;
        $total_resta_60_dias_saldo = 0;
        $total_resta_75_dias_saldo = 0;
        $entidades = array();

        $sheet->setCellValue('F1', 'ESTADO DE CUENTA HISTORICO');
        $sheet->setCellValue('F2', $titulo);

        $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('K5:Q5')->getFont()->setBold(1);

        # Cabecera
        $sheet->setCellValue('A6', 'FECHA');
        $sheet->setCellValue('B6', 'FACTURA');
        $sheet->setCellValue('C6', 'TITULO');
        $sheet->setCellValue('D6', 'UUID');
        $sheet->setCellValue('E6', 'MONEDA');
        $sheet->setCellValue('F6', 'T.C');
        $sheet->setCellValue('G6', 'TOTAL');
        $sheet->setCellValue('H6', 'FECHA TRANSFERENCIA');
        $sheet->setCellValue('I6', 'BANCO');
        $sheet->setCellValue('J6', 'SALDO MXN');
        $sheet->setCellValue('K6', 'PERDIDA CAMBIARIA');
        $sheet->setCellValue('L6', 'SALDO DOCUMENTO');
        $sheet->setCellValue('M6', '1 A 15 DÍAS');
        $sheet->setCellValue('N6', '15 A 30 DÍAS');
        $sheet->setCellValue('O6', '30 A 45 DÍAS');
        $sheet->setCellValue('P6', '45 A 60 DÍAS');
        $sheet->setCellValue('Q6', '60 + DÍAS');

        if ($data->crm) {
            $sheet->setCellValue('R6', 'CLAVE RASTREO');
            $sheet->setCellValue('S6', 'AUTORIZACION');
            $sheet->setCellValue('T6', 'REFERENCIA');
        }

        if (empty($data->entidad->select)) {
            $sheet->setCellValue('U6', ($data->entidad->tipo == "Clientes") ? "Cliente" : "Proveedor");
        }

        $sheet->setCellValue('K5', 'SALDO');

        $sheet->freezePane("A7");

        $spreadsheet->getActiveSheet()->getStyle('A6:U6')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        for ($i = 0; $i < 3; $i++) {
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($i + 1);

            switch ($i) {
                case '0':
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS CON SALDO');

                    $sheet->setCellValue('F1', 'FACTURAS CON SALDO');
                    break;

                case '1':
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS SIN SALDO');
                    $sheet->setCellValue('F1', 'FACTURAS SIN SALDO');
                    break;

                default:
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS Y PAGOS');
                    $sheet->setCellValue('F1', 'FACTURAS Y PAGOS');

                    break;
            }

            $sheet->setCellValue('F2', $titulo);

            $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
            $spreadsheet->getActiveSheet()->getStyle('K5:Q5')->getFont()->setBold(1);

            $sheet->setCellValue('A6', 'FECHA');
            $sheet->setCellValue('B6', 'FACTURA');
            $sheet->setCellValue('C6', 'TITULO');
            $sheet->setCellValue('D6', 'UUID');
            $sheet->setCellValue('E6', 'MONEDA');
            $sheet->setCellValue('F6', 'T.C');
            $sheet->setCellValue('G6', 'TOTAL');
            $sheet->setCellValue('H6', 'FECHA TRANSFERENCIA');
            $sheet->setCellValue('I6', 'BANCO');
            $sheet->setCellValue('J6', 'SALDO MXN');
            $sheet->setCellValue('K6', 'PERDIDA Ó UTILIDAD CAMBIARIA');
            $sheet->setCellValue('L6', 'SALDO DOCUMENTO');
            $sheet->setCellValue('M6', '1 A 15 DÍAS');
            $sheet->setCellValue('N6', '15 A 30 DÍAS');
            $sheet->setCellValue('O6', '30 A 45 DÍAS');
            $sheet->setCellValue('P6', '45 A 60 DÍAS');
            $sheet->setCellValue('Q6', '60 + DÍAS');

            if ($data->crm) {
                $sheet->setCellValue('R6', 'CLAVE RASTREO');
                $sheet->setCellValue('S6', 'AUTORIZACION');
                $sheet->setCellValue('T6', 'REFERENCIA');
            }

            if (empty($data->entidad->select)) {
                $sheet->setCellValue('U6', ($data->entidad->tipo == "Clientes") ? "Cliente" : "Proveedor");
            }

            $sheet->setCellValue('K5', 'SALDO');

            $sheet->freezePane("A7");

            $spreadsheet->getActiveSheet()->getStyle('A6:U6')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas y de color azul
        }

        /* Nuevo estado de cuenta */
        $spreadsheet->createSheet();
        $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(5);
        $spreadsheet->getActiveSheet()->setTitle('ESTADO DE CUENTA (NUEVO)');

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('B2', mb_strtoupper($nombre_entidad, 'UTF-8'));
        $sheet->setCellValue('B3', "ESTADO DE CUENTA (FACTURAS CON SALDO)");

        $spreadsheet
            ->getActiveSheet()
            ->getStyle('B2:N3')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color("101010"));

        $spreadsheet->getActiveSheet()->getStyle("B2:N2")->getFont()->setSize(14)->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle("B3:N3")->getFont()->setSize(12)->setBold(1);

        $sheet->getStyle('B2:N3')->getAlignment()->setHorizontal('center');

        $spreadsheet->getActiveSheet()->mergeCells('B2:N2');
        $spreadsheet->getActiveSheet()->mergeCells('B3:N3');

        $sheet->setCellValue('B8', 'FECHA FACTURA');
        $sheet->setCellValue('C8', 'FOLIO FACTURA');
        $sheet->setCellValue('D8', 'CONDICIONES DE PAGO');
        $sheet->setCellValue('E8', 'MONEDA');
        $sheet->setCellValue('F8', 'TOTAL');
        $sheet->setCellValue('G8', 'FECHA DE VENCIMIENTO');
        $sheet->setCellValue('H8', "DIAS DE VENCIMIENTO");
        $sheet->setCellValue('I8', 'SALDO DOCUMENTO MXN');
        $sheet->setCellValue('J8', '1 A 15 DÍAS');
        $sheet->setCellValue('K8', '16 A 30 DÍAS');
        $sheet->setCellValue('L8', '31 A 45 DÍAS');
        $sheet->setCellValue('M8', '46 A 60 DÍAS');
        $sheet->setCellValue('N8', 'MÁS DE 60 DÍAS');

        $spreadsheet
            ->getActiveSheet()
            ->getStyle('B8:N8')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color("101010"));

        $sheet->getStyle('B8:N8')->getAlignment()->setHorizontal('center');

        $spreadsheet->getActiveSheet()->getStyle("B8:N8")->getFont()->setBold(1);

        $sheet->setCellValue('L5', 'SALDO VENCIDO MXN:');
        $sheet->setCellValue('L6', 'SALDO VENCIDO USD:');

        $spreadsheet->getActiveSheet()->getStyle('L5:N6')->getFont()->setBold(1)->setSize(12)->getColor()->setARGB('FFFFFF');

        $spreadsheet->getActiveSheet()->getStyle("L5:N6")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1717');

        $spreadsheet->getActiveSheet()->getStyle("N5")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
        $spreadsheet->getActiveSheet()->getStyle("N6")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

        $spreadsheet->getActiveSheet()->mergeCells('L5:M5');
        $spreadsheet->getActiveSheet()->mergeCells('L6:M6');

        $sheet->freezePane("A9");
        /* Termina nuevo estado de cuenta e inicia primera pestaña */

        foreach ($response as $factura) {
            $spreadsheet->setActiveSheetIndex(0);
            $sheet = $spreadsheet->getActiveSheet();

            $total_resta_factura = round((float) $factura->documento->total * (float) $factura->documento->tc, 2);

            $sheet->setCellValue('A' . $contador_fila, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila, "");
            $sheet->setCellValue('D' . $contador_fila, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila, '');
            $sheet->setCellValue('I' . $contador_fila, '');
            $sheet->setCellValue('J' . $contador_fila, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila, 0);
            $sheet->setCellValue('L' . $contador_fila, 0);

            if (empty($data->entidad->select)) {
                $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);
            }

            $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila)->getFont()->setBold(1);

            # Color de fondo de la venta verde
            $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":Q" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            # Formato accounting
            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila . ":G" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila . ":K" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            /* Poner la info de nuevo en la pestaña donde está todo revuelto */
            $spreadsheet->setActiveSheetIndex(3);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('A' . $contador_fila, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila, "");
            $sheet->setCellValue('D' . $contador_fila, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila, '');
            $sheet->setCellValue('I' . $contador_fila, '');
            $sheet->setCellValue('J' . $contador_fila, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila, 0);
            $sheet->setCellValue('L' . $contador_fila, 0);

            if (empty($data->entidad->select)) {
                $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);
            }

            $spreadsheet->setActiveSheetIndex(0);
            $sheet = $spreadsheet->getActiveSheet();

            $contador_fila_total_documento = $contador_fila;

            $contador_fila++;

            foreach ($factura->pagos as $pago) {
                if ($pago->pago_monto == 0) continue;

                $sheet->setCellValue('A' . $contador_fila, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->pago_condocumento : $pago->pago_operacion);
                $sheet->setCellValue('C' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila, $total_resta_factura - ((float) $pago->pago_monto * $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                $spreadsheet->setActiveSheetIndex(3);
                $sheet = $spreadsheet->getActiveSheet();
                $contador_fila--;

                $sheet->setCellValue('A' . $contador_fila, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
                $sheet->setCellValue('C' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila, $total_resta_factura - ((float) $pago->pago_monto * $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();
                $contador_fila++;

                if ($data->crm) {
                    $existe_pago = DB::select("SELECT
                                                    documento_pago.referencia,
                                                    documento_pago.clave_rastreo,
                                                    documento_pago.autorizacion
                                                FROM documento_pago
                                                INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                WHERE folio = '" . $pago->pago_operacion . "'
                                                AND empresa.bd = '" . $data->empresa . "'");

                    $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                    $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                    $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);

                    /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                    $spreadsheet->setActiveSheetIndex(3);
                    $sheet = $spreadsheet->getActiveSheet();

                    $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                    $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                    $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);
                }

                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();

                if (empty($data->entidad->select)) {
                    $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);

                    /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                    $spreadsheet->setActiveSheetIndex(0);
                    $sheet = $spreadsheet->getActiveSheet();

                    $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);
                }

                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();

                $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila)->getFont()->setBold(1);

                # Color de fondo de la venta verde
                $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":Q" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('45E0E9');

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila . ":G" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila . ":K" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_resta_factura -= ((float) $pago->pago_monto * (float) $factura->documento->tc);

                $contador_fila++;
            }

            $total_resta_entidad += $total_resta_factura;

            $existe_entidad = false;

            foreach ($entidades as $entidad) {
                if ($entidad->rfc == $factura->documento->rfc) {
                    $entidad->total += $total_resta_factura;

                    $existe_entidad = true;

                    break;
                }
            }

            if (!$existe_entidad) {
                $entidad_data = new \stdClass();
                $entidad_data->nombre = $factura->documento->nombre;
                $entidad_data->rfc = $factura->documento->rfc;
                $entidad_data->total = $total_resta_factura;

                array_push($entidades, $entidad_data);
            }

            if ($total_resta_factura > 0) {
                if ($factura->documento->pago_terminos == "CONTADO" || is_null($factura->documento->pago_terminos)) {
                    $dias_pago = 0;
                } else {
                    $dias_pago = explode(" ", $factura->documento->pago_terminos)[0];
                }

                $fecha_actual = time();
                $fecha_pago = strtotime(date("Y-m-d", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
                $diferencia = $fecha_actual - $fecha_pago;

                $dias_transcurridos = (int) floor($diferencia / (60 * 60 * 24));

                # Se agrega saldo de la factura dependiendo los días transcurridos
                if ($dias_transcurridos > 0) {
                    switch (true) {
                        case ($dias_transcurridos > 0 && $dias_transcurridos < 16):
                            $sheet->setCellValue('M' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_15_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 15 && $dias_transcurridos < 31):
                            $sheet->setCellValue('N' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_30_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 30 && $dias_transcurridos < 46):
                            $sheet->setCellValue('O' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_45_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 45 && $dias_transcurridos < 61):
                            $sheet->setCellValue('P' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_60_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 60):
                            $sheet->setCellValue('Q' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_75_dias += $total_resta_factura;

                            break;
                    }
                }
            }

            $total_resta_factura = round($total_resta_factura, 2);

            $sheet->setCellValue('L' . $contador_fila_total_documento, $total_resta_factura);
            $spreadsheet->getActiveSheet()->getStyle("L" . $contador_fila_total_documento)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            $contador_fila += 2;

            /* Agregar factura a la pestaña con o sin saldo */
            $spreadsheet->setActiveSheetIndex($total_resta_factura > 15 ? 1 : 2);
            $sheet = $spreadsheet->getActiveSheet();
            $contador_fila_actual = $total_resta_factura > 15 ? $contador_fila_saldo : $contador_fila_sin_saldo;

            $total_resta_factura = round((float) $factura->documento->total * (float) $factura->documento->tc, 2);

            $sheet->setCellValue('A' . $contador_fila_actual, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila_actual, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila_actual, "");
            $sheet->setCellValue('D' . $contador_fila_actual, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila_actual, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila_actual, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila_actual, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila_actual, '');
            $sheet->setCellValue('I' . $contador_fila_actual, '');
            $sheet->setCellValue('J' . $contador_fila_actual, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila_actual, 0);
            $sheet->setCellValue('L' . $contador_fila_actual, 0);

            if (empty($data->entidad->select)) {
                $sheet->setCellValue('R' . $contador_fila_actual, $factura->documento->nombre);
            }

            $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila_actual)->getFont()->setBold(1);

            # Color de fondo de la venta verde
            $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            # Formato accounting
            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_actual . ":G" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            $contador_fila_actual_total_documento = $contador_fila_actual;

            $contador_fila_actual++;

            foreach ($factura->pagos as $pago) {
                if ($pago->pago_monto == 0) continue;

                $sheet->setCellValue('A' . $contador_fila_actual, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->pago_condocumento : $pago->pago_operacion);
                $sheet->setCellValue('C' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila_actual, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila_actual, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila_actual, $total_resta_factura - ((float) $pago->pago_monto * (float) $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila_actual, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                if ($data->crm) {
                    $existe_pago = DB::select("SELECT
                                                    documento_pago.referencia,
                                                    documento_pago.clave_rastreo,
                                                    documento_pago.autorizacion
                                                FROM documento_pago
                                                INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                WHERE folio = '" . $pago->pago_operacion . "'
                                                AND empresa.bd = '" . $data->empresa . "'");

                    $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                    $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                    $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);
                }

                if (empty($data->entidad->select)) {
                    $sheet->setCellValue('U' . $contador_fila_actual, $factura->documento->nombre);
                }

                $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila_actual)->getFont()->setBold(1);

                # Color de fondo de la venta verde
                $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('45E0E9');

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_actual . ":G" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila_actual . ":K" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_resta_factura -= (float) $pago->pago_monto * (float) $factura->documento->tc;
                $contador_fila_actual++;
            }

            $total_resta_factura = round($total_resta_factura, 2);

            $dias_pago = 0;

            if ($factura->documento->pago_terminos == "CONTADO" || is_null($factura->documento->pago_terminos)) {
                $dias_pago = 0;
            } else {
                $dias_pago = explode(" ", $factura->documento->pago_terminos)[0];
            }

            $fecha_actual = time();
            $fecha_pago = strtotime(date("Y-m-d", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
            $diferencia = $fecha_actual - $fecha_pago;

            $dias_transcurridos = (int) floor($diferencia / (60 * 60 * 24));

            if ($total_resta_factura > 0) {
                if ($dias_transcurridos > 0) {
                    switch (true) {
                        case ($dias_transcurridos > 0 && $dias_transcurridos < 16):
                            $sheet->setCellValue('M' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_15_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('J' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 15 && $dias_transcurridos < 31):
                            $sheet->setCellValue('N' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_30_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('K' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 30 && $dias_transcurridos < 46):
                            $sheet->setCellValue('O' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_45_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('L' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 45 && $dias_transcurridos < 61):
                            $sheet->setCellValue('P' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_60_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('M' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 60):
                            $sheet->setCellValue('Q' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_75_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('N' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;
                    }
                }
            }

            $spreadsheet->setActiveSheetIndex($total_resta_factura > 15 ? 1 : 2);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('L' . $contador_fila_actual_total_documento, $total_resta_factura);
            $spreadsheet->getActiveSheet()->getStyle("L" . $contador_fila_actual_total_documento)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            $contador_fila_actual += 2;

            if ($total_resta_factura > 15) {
                $contador_fila_saldo = $contador_fila_actual;
            } else {
                $contador_fila_sin_saldo = $contador_fila_actual;
            }

            $total_resta_entidad_saldo += $total_resta_factura > 15 ? $total_resta_factura : 0;
            $total_resta_entidad_saldo_nuevo_edc += $dias_transcurridos > 0 ? $total_resta_factura > 15 ? ($factura->documento->moneda == "Peso mexicano" ? $total_resta_factura : 0) : 0 : 0;
            $total_resta_entidad_saldo_usd += $dias_transcurridos > 0 ? $total_resta_factura > 15 ? ($factura->documento->moneda != "Peso mexicano" ? (float) $factura->documento->total : 0) : 0 : 0;

            if ($total_resta_factura < 15) continue;

            /* Nuevo estado de cuenta */
            $spreadsheet->setActiveSheetIndex(5);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('B' . $contador_fila_nuevo_edc, date("d/m/Y", strtotime($factura->documento->fecha)));
            $sheet->setCellValue('C' . $contador_fila_nuevo_edc, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('D' . $contador_fila_nuevo_edc, $factura->documento->pago_terminos);
            $sheet->setCellValue('E' . $contador_fila_nuevo_edc, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila_nuevo_edc, $factura->documento->total);
            $sheet->setCellValue('G' . $contador_fila_nuevo_edc, date("d/m/Y", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
            $sheet->setCellValue('H' . $contador_fila_nuevo_edc, $total_resta_factura > 15 ? ($dias_transcurridos > 0 ? $dias_transcurridos : 0) : 0);
            $sheet->setCellValue('I' . $contador_fila_nuevo_edc, $total_resta_factura > 15 ? $total_resta_factura : 0);

            if ($total_resta_factura > 15 && $dias_transcurridos > 0) {
                $spreadsheet->getActiveSheet()->getStyle("H" . $contador_fila_nuevo_edc)->getFont()->setSize(12)->setBold(1)->getColor()->setARGB('FF1717');
            }

            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_nuevo_edc)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc . ":N" . $contador_fila_nuevo_edc)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc . ":N" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $spreadsheet->getActiveSheet()->getStyle("G" . $contador_fila_nuevo_edc)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E7F023');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E7F023');
            $spreadsheet->getActiveSheet()->getStyle("G" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $sheet->setCellValue('N5', $total_resta_entidad_saldo_nuevo_edc);
            $sheet->setCellValue('N6', $total_resta_entidad_saldo_usd);
            $sheet->getStyle('B' . $contador_fila_nuevo_edc . ':N' . $contador_fila_nuevo_edc)->getAlignment()->setHorizontal('center');

            $contador_fila_nuevo_edc++;
            /* Termina nuevo estado de cuenta */
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url_pagos_pendientes);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        $response = json_decode(curl_exec($curl_handle));

        curl_close($curl_handle);

        $titulo = empty($data->entidad->select) ? $data->entidad->tipo : $titulo;

        $contador_fila = 5;
        // $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(4);
        $spreadsheet->getActiveSheet()->setTitle('PENDIENTES POR APLICAR');
        $total_por_aplicar = 0;

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('F1', $tipo_busqueda_pago . " pendientes por aplicar");
        $sheet->setCellValue('F2', $titulo);

        $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('M3')->getFont()->setBold(1);

        # Cabecera
        $sheet->setCellValue('A4', 'FECHA');
        $sheet->setCellValue('B4', 'OPERACION');
        $sheet->setCellValue('C4', 'TIPO');
        $sheet->setCellValue('D4', 'DEPOSITADO');
        $sheet->setCellValue('E4', 'CUENTA');
        $sheet->setCellValue('F4', 'DESCRIPCIÓN');
        $sheet->setCellValue('G4', 'REFERENCIA');
        $sheet->setCellValue('H4', 'MONEDA');
        $sheet->setCellValue('I4', 'T.C');
        $sheet->setCellValue('J4', 'TOTAL');
        $sheet->setCellValue('K4', 'MONEDA NACIONAL MONTO APLICADO');
        $sheet->setCellValue('L4', 'MONEDA EXTRANJERA MONTO APLICADO');
        $sheet->setCellValue('M4', 'BALANCE');

        if ($data->crm) {
            $sheet->setCellValue('N4', 'CLAVE RASTREO');
            $sheet->setCellValue('O4', 'AUTORIZACION');
            $sheet->setCellValue('P4', 'REFERENCIA');
        }

        if (empty($data->entidad->select)) {
            $sheet->setCellValue('Q4', 'ENTIDAD');
        }

        $spreadsheet->getActiveSheet()->getStyle('A4:Q4')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        $sheet->freezePane("A5");

        if (!empty($response)) {
            foreach ($response as $pago) {
                $sheet->setCellValue('A' . $contador_fila, $pago->fecha);
                $sheet->setCellValue('B' . $contador_fila, $pago->operacionid);
                $sheet->setCellValue('C' . $contador_fila, $pago->modulo);
                $sheet->setCellValue('D' . $contador_fila, $pago->financialentitytype);
                $sheet->setCellValue('E' . $contador_fila, $pago->financialentity);
                $sheet->setCellValue('F' . $contador_fila, $pago->descripcion);
                $sheet->setCellValue('G' . $contador_fila, $pago->referencia);
                $sheet->setCellValue('H' . $contador_fila, $pago->moneda);
                $sheet->setCellValue('I' . $contador_fila, $pago->tc);
                $sheet->setCellValue('J' . $contador_fila, $pago->monto);
                $sheet->setCellValue('K' . $contador_fila, $pago->MNAplicado);
                $sheet->setCellValue('L' . $contador_fila, $pago->MEAplicado);
                $sheet->setCellValue('M' . $contador_fila, $pago->MNBalance);

                if ($data->crm) {
                    $existe_pago = DB::select("SELECT
                                                    documento_pago.referencia,
                                                    documento_pago.clave_rastreo,
                                                    documento_pago.autorizacion
                                                FROM documento_pago
                                                INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                WHERE folio = '" . $pago->operacionid . "'
                                                AND empresa.bd = '" . $data->empresa . "'");

                    $sheet->setCellValue('N' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                    $sheet->setCellValue('O' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                    $sheet->setCellValue('P' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);
                }

                if (empty($data->entidad->select)) {
                    $sheet->setCellValue('Q' . $contador_fila, $pago->empresa);
                }

                $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila . ":M" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_por_aplicar += $pago->MNBalance;

                $contador_fila++;
            }

            $sheet->setCellValue('M3', $total_por_aplicar);
            $spreadsheet->getActiveSheet()->getStyle("M3")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            foreach (range('A', 'P') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }

            if (empty($data->entidad->select)) {
                $spreadsheet->getActiveSheet()->getColumnDimension("Q")->setAutoSize(true);
            }
        }

        for ($i = 0; $i < 4; $i++) {
            $spreadsheet->setActiveSheetIndex($i);

            foreach (range('A', 'T') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }

            # Suma de resta de facturas
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue("L5", $i == 2 ? 0 : ($i == 0 ? $total_resta_entidad : $total_resta_entidad_saldo));
            $sheet->setCellValue("M5", $i == 2 ? 0 : ($i == 0 ? $total_resta_15_dias : $total_resta_15_dias_saldo));
            $sheet->setCellValue("N5", $i == 2 ? 0 : ($i == 0 ? $total_resta_30_dias : $total_resta_30_dias_saldo));
            $sheet->setCellValue("O5", $i == 2 ? 0 : ($i == 0 ? $total_resta_45_dias : $total_resta_45_dias_saldo));
            $sheet->setCellValue("P5", $i == 2 ? 0 : ($i == 0 ? $total_resta_60_dias : $total_resta_60_dias_saldo));
            $sheet->setCellValue("Q5", $i == 2 ? 0 : ($i == 0 ? $total_resta_75_dias : $total_resta_75_dias_saldo));

            $spreadsheet->getActiveSheet()->getStyle("L5:Q5")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
        }

        $spreadsheet->setActiveSheetIndex(5);

        foreach (range('A', 'N') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $archivo = 'reporte/contabilidad/factura/ESTADO DE CUENTA FACTURA ' . date('d.m.y H.i.s') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($archivo);

        return response()->json([
            'code' => 200,
            'message' => "Reporte generado correctamente",
            'archivo' => $archivo,
            'entidades' => $entidades
        ]);
    }

    /* Facturas > Flujo */
    public function contabilidad_ingreso_generar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $id_empresa = DB::select("SELECT id FROM empresa WHERE bd = '" . $data->empresa . "'");

        if (empty($id_empresa)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre la BD de la empresa, favor de contactar a un administrador."
            ]);
        }

        $id_empresa = $id_empresa[0]->id;

        if ($data->authy->necesita_authy) {
            $validate_authy = DocumentoService::authy($auth->id, $data->authy->authy_code);

            if ($validate_authy->error) {
                return response()->json([
                    "message" => $validate_authy->mensaje
                ], 500);
            }
        }

        $documento_pago = DB::table('documento_pago')->insertGetId([
            'id_empresa' => $id_empresa,
            'id_usuario' => $auth->id,
            'id_metodopago' => $data->metodo_pago,
            'id_vertical' => !empty($data->vertical) ? $data->vertical : 0,
            'id_categoria' => !empty($data->categoria) ? $data->categoria : 0,
            'id_clasificacion' => !empty($data->clasificacion) ? $data->clasificacion : 0,
            'tipo' => $data->tipo_documento,
            'origen_importe' => $data->origen->monto,
            'entidad_origen' => $data->origen->entidad,
            'origen_entidad' => ($data->tipo_documento == 1) ? ((property_exists($data->origen, 'entidad_rfc')) ? (($data->origen->entidad_rfc == 'XEXX010101000') ? $data->origen->cuenta_bancaria : $data->origen->entidad_rfc) : $data->origen->cuenta_bancaria) : $data->origen->cuenta_bancaria,
            'entidad_destino' => $data->destino->entidad,
            'destino_entidad' => ($data->tipo_documento == 0) ? ((property_exists($data->destino, 'entidad_rfc')) ? (($data->destino->entidad_rfc == 'XEXX010101000') ? $data->destino->cuenta_bancaria : $data->destino->entidad_rfc) : $data->destino->cuenta_bancaria) : $data->destino->cuenta_bancaria,
            'referencia' => $data->referencia,
            'clave_rastreo' => $data->clave_rastreo,
            'autorizacion' => $data->autorizacion,
            'cuenta_cliente' => $data->cuenta_proveedor,
            'destino_fecha_operacion' => $data->destino->fecha_operacion,
            'destino_fecha_afectacion' => $data->destino->fecha_operacion,
            'origen_fecha_operacion' => $data->origen->fecha_operacion,
            'origen_fecha_afectacion'   => $data->origen->fecha_operacion,
            'tipo_cambio' => $data->tipo_cambio
        ]);

        $movimiento = DB::select("SELECT * FROM documento_pago WHERE id = " . $documento_pago . "")[0];

        $crear_movimiento = DocumentoService::crearMovimientoFlujo($movimiento, $data->empresa);

        if ($crear_movimiento->error) {
            DB::table('documento_pago')->where(['id' => $documento_pago])->delete();

            return response()->json([
                'code'  => 500,
                'message'   => $crear_movimiento->mensaje,
                'data'  => property_exists($crear_movimiento, 'data') ? $crear_movimiento->data : 0,
                'raw'   => property_exists($crear_movimiento, 'raw') ? $crear_movimiento->raw : 0
            ]);
        }

        $message = "Movimiento creado correctamente con el folio: " . $crear_movimiento->id;

        if (in_array($data->tipo_documento, [0, 1])) {
            foreach ($data->facturas_a_pagar as $factura) {
                $tipo_documento = $data->tipo_documento == "1" ? "FacturaCliente" : "CompraGasto";

                $array_pago = array(
                    "password" => config("webservice.token"),
                    "bd" => $data->empresa,
                    "documentoid" => $factura->documento,
                    "pagoid" => $crear_movimiento->id,
                    "tipopago" => 1, # siempre será ingreso en esta archivo
                    "monto" => $factura->monto_aplicar,
                    "tc" => $factura->tc,
                );

                $saldar_factura = \Httpful\Request::post(config('webservice.url') . $tipo_documento . '/Pagar')
                    ->body($array_pago, \Httpful\Mime::FORM)
                    ->send();

                $saldar_factura_raw = $saldar_factura->raw_body;
                $saldar_factura = @json_decode($saldar_factura_raw);

                if (empty($saldar_factura)) {
                    file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error: Ocurrió un error al saldar el documento con el ID " . $factura->documento . " con el movimiento " . $crear_movimiento->id . " en la empresa " . $data->empresa . ", Raw data: " . base64_encode($saldar_factura_raw) . "." . PHP_EOL, FILE_APPEND);

                    # Eliminar movimiento
                    $movimiento_eliminado = DocumentoService::__eliminarMovimientoFlujo($crear_movimiento->id, $factura->folio, $data->empresa);

                    if ($movimiento_eliminado->error) {
                        return response()->json([
                            "code" => 500,
                            "message" => "No fue posible saldar la factura " . $factura->folio . " con el movimiento " .  $crear_movimiento->id . " en la empresa " . $data->empresa . "<br><br>No fue posible eliminar el movimiento, mensaje de error: " . $movimiento_eliminado->mensaje . "<br>Favor de contactar a un administador.",
                            "raw" => $saldar_factura_raw
                        ]);
                    }

                    return response()->json([
                        "code" => 500,
                        "message" => "No fue posible saldar la factura " . $factura->folio . " con el movimiento " .  $crear_movimiento->id . " en la empresa " . $data->empresa . ", favor de contactar a un administador.",
                        "raw" => $saldar_factura_raw
                    ]);
                }

                if ($saldar_factura->error) {
                    $movimiento_eliminado = DocumentoService::__eliminarMovimientoFlujo($crear_movimiento->id, $factura->folio, $data->empresa);

                    if ($movimiento_eliminado->error) {
                        return response()->json([
                            "code" => 500,
                            "message" => "No fue posible saldar la factura " . $factura->folio . " con el movimiento " .  $crear_movimiento->id . " en la empresa " . $data->empresa . ", mensaje de error: " . $saldar_factura->mensaje . "<br><br>No fue posible eliminar el movimiento, mensaje de error: " . $movimiento_eliminado->mensaje . "<br>Favor de contactar a un administador.",
                            "raw" => $saldar_factura_raw
                        ]);
                    }

                    return response()->json([
                        "code" => 500,
                        "message" => "No fue posible saldar la factura " . $factura->folio . " con el movimiento " .  $crear_movimiento->id . " en la empresa " . $data->empresa . ", mensaje de error: " . $saldar_factura->mensaje . ".",
                        "raw" => $saldar_factura_raw
                    ]);
                }
            }
        }

        if (property_exists($data, "documento_relacionado")) {
            if ($data->documento_relacionado != 0) {
                DB::table('documento')->where(['id' => $data->documento_relacionado])->update([
                    'id_fase'   => 303
                ]);

                DB::table('documento_pago_re')->insert([
                    'id_documento'  => $data->documento_relacionado,
                    'id_pago'       => $documento_pago
                ]);
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => $message
        ]);
    }

    public function contabilidad_ingreso_generar_data()
    {
        $metodos = DB::select("SELECT * FROM metodo_pago");
        $verticales = DB::select("SELECT * FROM documento_pago_vertical");
        $categorias = DB::select("SELECT * FROM documento_pago_categoria");
        $divisas = DB::select("SELECT * FROM moneda");
        $empresas = DB::select("SELECT id, empresa, bd FROM empresa WHERE status = 1 AND id != 0");
        $clasificaciones = DB::select("SELECT id, clasificacion FROM documento_pago_clasificacion");

        foreach ($empresas as $empresa) {
            $empresa->conciliaciones = DB::table("documento_pago_cuenta AS DPC")
                ->select("DPC.cuenta", "DPC.descripcion", DB::raw("MAX(DPCC.fecha) AS fecha"))
                ->join("documento_pago_cuenta_conciliacion AS DPCC", "DPC.id", "=", "DPCC.id_cuenta")
                ->where("DPC.id_empresa", $empresa->id)
                ->orderBy("DPCC.fecha", "DESC")
                ->groupBy("DPC.cuenta")
                ->get();
        }

        return response()->json([
            'code' => 200,
            'divisas' => $divisas,
            'metodos' => $metodos,
            'empresas' => $empresas,
            'verticales' => $verticales,
            'categorias' => $categorias,
            'clasificaciones' => $clasificaciones
        ]);
    }

    public function contabilidad_ingreso_generar_ultimo($entidad)
    {
        $informacion = DB::select("SELECT * FROM documento_pago WHERE tipo = 0 AND destino_entidad = '" . $entidad . "' ORDER BY created_at DESC LIMIT 1");

        return response()->json([
            'code'  => 200,
            'informacion'   => $informacion
        ]);
    }

    public function contabilidad_ingreso_editar_cliente(Request $request)
    {
        $data = json_decode($request->input("data"));

        $editar_movimiento = DocumentoService::actualizarClienteIngreso($data->cliente, $data->movimiento, $data->empresa);

        return response()->json([
            "message" => $editar_movimiento->error ? $editar_movimiento->mensaje : "Ingreso editado correctamente.",
            "raw" => property_exists($editar_movimiento, "raw") ? $editar_movimiento->raw : 0
        ], $editar_movimiento->error ? 500 : 200);
    }

    public function contabilidad_ingreso_historial_data(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input("data"));
        $cuentas = [];

        $extra_data = "";

        if (empty($data->folio)) {
            $extra_data .= " AND documento_pago.folio != ''";
            $extra_data .= "AND IF(documento_pago.origen_fecha_operacion = '0000-00-00', documento_pago.destino_fecha_operacion, documento_pago.origen_fecha_operacion) BETWEEN '" . $data->fecha_inicial . "' AND '" . $data->fecha_final . "'";
        } else {
            $extra_data .= " AND documento_pago.id_vertical != 0 AND documento_pago.folio = '" . $data->folio . "'";
        }

        if (!empty($data->cuenta)) {
            switch ($data->tipo) {
                case '0':

                    $extra_data .= " AND origen_entidad = '" . $data->cuenta . "'";

                    break;

                case '1':

                    $extra_data .= " AND destino_entidad = '" . $data->cuenta . "'";

                    break;

                default:

                    $extra_data .= " AND (destino_entidad = '" . $data->cuenta . "' OR origen_entidad = '" . $data->cuenta . "')";

                    break;
            }
        }

        if ($data->empresa != '') {
            $extra_data .= " AND documento_pago.id_empresa = " . $data->empresa . "";
        }

        $empresa = DB::table("empresa")
            ->select("empresa", "bd")
            ->where("id", $data->empresa)
            ->first();

        $url = config('webservice.url') . 'Consulta/CuentasBancarias/' . $empresa->bd;

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        $response = json_decode(curl_exec($curl_handle));

        $cuentas = $response;

        $tipo_documento_url = $data->tipo == "0" ? "Egresos" : "CobrosCliente";
        $tipo_documento = $data->tipo == "0" ? "Egresos" : "Ingresos";
        $titulo = 'Historial de ' . $tipo_documento;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle(substr($titulo, 0, 30));
        $contador_fila = 6;

        $sheet->setCellValue('A1', $empresa->empresa);
        $sheet->setCellValue('A2', $titulo);

        $spreadsheet->getActiveSheet()->getStyle('A1:A2')->getFont()->setBold(1);

        # Cabecera
        $sheet->setCellValue('A5', 'Folio');
        $sheet->setCellValue('B5', 'Origen');
        $sheet->setCellValue('C5', 'Destino');
        $sheet->setCellValue('D5', 'Cuenta');
        $sheet->setCellValue('E5', 'Monto');
        $sheet->setCellValue('F5', 'Referencia');
        $sheet->setCellValue('G5', 'Vertical');
        $sheet->setCellValue('H5', 'Categoría');
        $sheet->setCellValue('I5', 'Fecha Operación');
        $sheet->setCellValue('J5', 'Fecha Afectación');
        $sheet->setCellValue('K5', 'Método de pago');
        $sheet->setCellValue('L5', 'Documento relacionado');
        $sheet->setCellValue('M5', 'Monto');
        $sheet->setCellValue('N5', 'T.C');
        $sheet->setCellValue('O5', 'Moneda');
        $sheet->setCellValue('P5', 'UUID');

        $sheet->freezePane("A6");

        $spreadsheet->getActiveSheet()->getStyle('A5:P5')->getFont()->setBold(1)->getColor()->setARGB('A8CEA0'); # Cabecera en negritas con color negro

        $documentos = DB::select("SELECT
                                        documento_pago.id,
                                        documento_pago.id_empresa,
                                        documento_pago.origen_importe,
                                        documento_pago.destino_importe,
                                        documento_pago.folio,
                                        documento_pago.entidad_destino,
                                        documento_pago.destino_entidad,
                                        documento_pago.entidad_origen,
                                        documento_pago.origen_entidad,
                                        documento_pago.referencia,
                                        documento_pago.clave_rastreo,
                                        documento_pago.autorizacion,
                                        documento_pago.cuenta_cliente,
                                        documento_pago.destino_fecha_operacion,
                                        documento_pago.destino_fecha_afectacion,
                                        documento_pago.origen_fecha_operacion,
                                        documento_pago.origen_fecha_afectacion,
                                        documento_pago.traspasado,
                                        documento_pago.tipo,
                                        usuario.nombre,
                                        metodo_pago.metodo_pago,
                                        documento_pago_vertical.vertical,
                                        documento_pago_categoria.categoria
                                    FROM documento_pago
                                    INNER JOIN usuario ON documento_pago.id_usuario = usuario.id
                                    INNER JOIN metodo_pago ON documento_pago.id_metodopago = metodo_pago.id
                                    LEFT JOIN documento_pago_vertical ON documento_pago.id_vertical = documento_pago_vertical.id
                                    LEFT JOIN documento_pago_categoria ON documento_pago.id_categoria = documento_pago_categoria.id
                                    WHERE documento_pago.tipo = '" . $data->tipo . "'
                                    AND documento_pago.status = 1
                                    " . $extra_data . "");

        foreach ($documentos as $documento) {
            $tiene_pedido = DB::select("SELECT id_documento FROM documento_pago_re WHERE id_pago = " . $documento->id . "");

            $documento->referencia .= (empty($tiene_pedido)) ? "" : " - Pedido: " . $tiene_pedido[0]->id_documento;

            switch ($data->tipo) {
                case '0':

                    switch ($documento->entidad_origen) {
                        case '1':
                            $documento->entidad_origen = "Cuenta bancaria";
                            break;

                        case '2':
                            $documento->entidad_origen = "Tarjeta";
                            break;

                        case '3':
                            $documento->entidad_origen = "Caja chica";
                            break;

                        case '6':
                            $documento->entidad_origen = "Acreedor";
                            break;

                        default:
                            $documento->entidad_origen = "Deudor";
                            break;
                    }

                    switch ($documento->entidad_destino) {
                        case '1':
                            $documento->entidad_destino = "Cliente";
                            break;

                        case '2':
                            $documento->entidad_destino = "Proveedor";
                            break;

                        case '3':
                            $documento->entidad_destino = "Empleado";
                            break;

                        case '4':
                            $documento->entidad_destino = "Acreedor";
                            break;

                        default:
                            $documento->entidad_destino = "Deudor";
                            break;
                    }

                    break;

                case '1':

                    switch ($documento->entidad_origen) {
                        case '1':
                            $documento->entidad_origen = "Cliente";
                            break;

                        case '2':
                            $documento->entidad_origen = "Proveedor";
                            break;

                        case '3':
                            $documento->entidad_origen = "Empleado";
                            break;

                        case '4':
                            $documento->entidad_origen = "Acreedor";
                            break;

                        default:
                            $documento->entidad_origen = "Deudor";
                            break;
                    }

                    switch ($documento->entidad_destino) {
                        case '1':
                            $documento->entidad_destino = "Cuenta bancaria";
                            break;

                        case '2':
                            $documento->entidad_destino = "Tarjeta";
                            break;

                        case '3':
                            $documento->entidad_destino = "Caja chica";
                            break;

                        case '6':
                            $documento->entidad_destino = "Acreedor";
                            break;

                        default:
                            $documento->entidad_destino = "Deudor";
                            break;
                    }

                    break;

                default:

                    switch ($documento->entidad_origen) {
                        case '1':
                            $documento->entidad_origen = "Cuenta bancaria";
                            break;

                        case '2':
                            $documento->entidad_origen = "Tarjeta";
                            break;

                        default:
                            $documento->entidad_origen = "Caja chica";
                            break;
                    }

                    switch ($documento->entidad_destino) {
                        case '1':
                            $documento->entidad_destino = "Cuenta bancaria";
                            break;

                        case '2':
                            $documento->entidad_destino = "Tarjeta";
                            break;

                        default:
                            $documento->entidad_destino = "Caja chica";
                            break;
                    }

                    break;
            }

            $url = config('webservice.url') . 'Vista/' . $empresa->bd . '/' . $tipo_documento_url . '/FinancialOperation/' . $documento->folio;

            $curl_handle = curl_init();
            curl_setopt($curl_handle, CURLOPT_URL, $url);
            curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
            $response = json_decode(curl_exec($curl_handle));

            if (empty($response)) {
                $documento->eliminado = 1;
                $documento->poliza = null;
                $documento->facturas = [];
            } else {
                if ($response[0]->eliminado || $response[0]->cancelado) {
                    $documento->eliminado = 1;
                } else {
                    $documento->eliminado = 0;
                }

                $documento->facturas = $response[0]->SaldandoA;
                $documento->poliza = $response[0]->polizaid;
                $documento->monedaid = $response[0]->monedaid;
            }

            curl_close($curl_handle);

            $documento->cuenta = "Definido por pedido";
            $cuenta_id = $data->tipo == "0" ? $documento->origen_entidad : $documento->destino_entidad;

            foreach ($cuentas as $cuenta) {
                if ($cuenta->id == $cuenta_id) {
                    $documento->cuenta = $cuenta->cuenta;
                }
            }

            # Cabecera
            $sheet->setCellValue('A' . $contador_fila, $documento->folio);
            $sheet->setCellValue('B' . $contador_fila, $documento->entidad_origen);
            $sheet->setCellValue('C' . $contador_fila, $documento->entidad_destino);
            $sheet->setCellValue('D' . $contador_fila, $documento->cuenta);
            $sheet->setCellValue('E' . $contador_fila, empty($tiene_pedido) ? $documento->origen_importe : $documento->destino_importe);
            $sheet->setCellValue('F' . $contador_fila, $documento->referencia);
            $sheet->setCellValue('G' . $contador_fila, $documento->vertical);
            $sheet->setCellValue('H' . $contador_fila, $documento->categoria);
            $sheet->setCellValue('I' . $contador_fila, $documento->destino_fecha_operacion);
            $sheet->setCellValue('J' . $contador_fila, $documento->destino_fecha_afectacion);
            $sheet->setCellValue('K' . $contador_fila, $documento->metodo_pago);

            $spreadsheet->getActiveSheet()->getStyle("E" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            if ($documento->eliminado) {
                $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":P" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E26D6D');
            }

            foreach ($documento->facturas as $factura) {
                $sheet->setCellValue('A' . $contador_fila, $documento->folio);
                $sheet->setCellValue('B' . $contador_fila, $documento->entidad_origen);
                $sheet->setCellValue('C' . $contador_fila, $documento->entidad_destino);
                $sheet->setCellValue('D' . $contador_fila, $documento->cuenta);
                $sheet->setCellValue('E' . $contador_fila, empty($tiene_pedido) ? $documento->origen_importe : $documento->destino_importe);
                $sheet->setCellValue('F' . $contador_fila, $documento->referencia);
                $sheet->setCellValue('G' . $contador_fila, $documento->vertical);
                $sheet->setCellValue('H' . $contador_fila, $documento->categoria);
                $sheet->setCellValue('I' . $contador_fila, $documento->destino_fecha_operacion);
                $sheet->setCellValue('J' . $contador_fila, $documento->destino_fecha_afectacion);
                $sheet->setCellValue('K' . $contador_fila, $documento->metodo_pago);
                $sheet->setCellValue('L' . $contador_fila, $factura->serie . " " . $factura->folio);
                $sheet->setCellValue('M' . $contador_fila, $factura->monto);
                $sheet->setCellValue('N' . $contador_fila, $factura->tc);
                $sheet->setCellValue('O' . $contador_fila, $factura->moneda);
                $sheet->setCellValue('P' . $contador_fila, $factura->uuid);

                $spreadsheet->getActiveSheet()->getStyle("E" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("M" . $contador_fila . ":N" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                if ($documento->eliminado) {
                    $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":P" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E26D6D');
                }

                $contador_fila++;
            }
        }

        foreach (range('A', 'P') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $file_name = $titulo . "_" . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($titulo);

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents($titulo));
        $json['file_name'] = $file_name;
        $json['documentos'] = $documentos;

        unlink($titulo);

        return response()->json($json);
    }

    public function contabilidad_ingreso_historial_eliminar($movimiento, Request $request)
    {
        $auth = json_decode($request->auth);

        $contiene_documento = DB::select("SELECT id_documento FROM documento_pago_re WHERE id_pago = " . $movimiento . "");

        DB::table('documento_pago')->where(['id' => $movimiento])->update([
            'status'        => 0,
            'deleted_by'    => $auth->id
        ]);

        if (!empty($contiene_documento)) {
            DB::table('documento')->where(['id' => $contiene_documento[0]->id_documento])->update([
                'pagado'    => 0
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Movimiento eliminado correctamente."
        ]);
    }

    public function contabilidad_ingreso_historial_poliza(Request $request)
    {
        $data = json_decode($request->input("data"));

        $crear_poliza = DocumentoService::crearPolizaIngresoEgreso($data);

        if ($crear_poliza->error) {
            return response()->json([
                "code" => 500,
                "message" => $crear_poliza->mensaje,
                "raw" => property_exists($crear_poliza, "raw") ? $crear_poliza->raw : 0
            ]);
        }

        return response()->json([
            "code" => 200,
            "message" => "Poliza creada correctamente con el ID " . $crear_poliza->id,
            "data" => $crear_poliza->id
        ]);
    }

    public function contabilidad_ingreso_historial_traspaso(Request $request)
    {
        $data                       = json_decode($request->input('data'));
        $user_id                    = $request->input('user_id');
        $ingresos_convertidos       = [];
        $ingresos_no_convertidos    = [];

        $id_empresa = DB::select("SELECT id FROM empresa WHERE bd = '" . $data->empresa . "'");

        if (empty($id_empresa)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre la BD de la empresa, favor de contactar a un administrador."
            ]);
        }

        $id_empresa = $id_empresa[0]->id;

        foreach ($data->ingresos as $ingreso) {
            $informacion_ingreso = DB::select("SELECT * FROM documento_pago WHERE id = " . $ingreso . "")[0];

            if ($informacion_ingreso->tipo != 1) {
                array_push($ingresos_no_convertidos, $informacion_ingreso->folio);

                continue;
            }
            # se cambia el id clasificacion a 0 ya que no existe otro

            $documento_pago = DB::table('documento_pago')->insertGetId([
                'id_empresa'                => $id_empresa,
                'id_usuario'                => $user_id,
                'id_metodopago'             => 3,
                'id_vertical'               => $informacion_ingreso->id_vertical,
                'id_categoria'              => $informacion_ingreso->id_categoria,
                'id_clasificacion'          => 0,
                'tipo'                      => "2", // traspaso
                'folio'                     => $response->id,
                'origen_importe'            => ($informacion_ingreso->origen_importe == 0) ? $informacion_ingreso->destino_importe : $informacion_ingreso->origen_importe,
                'entidad_origen'            => $informacion_ingreso->entidad_destino,
                'origen_entidad'            => $informacion_ingreso->destino_entidad,
                'entidad_destino'           => 1,
                'destino_entidad'           => $data->cuenta,
                'referencia'                => $informacion_ingreso->referencia,
                'clave_rastreo'             => $informacion_ingreso->clave_rastreo,
                'autorizacion'              => $informacion_ingreso->autorizacion,
                'cuenta_cliente'            => $informacion_ingreso->cuenta_cliente,
                'destino_fecha_operacion'   => date('Y-m-d'),
                'destino_fecha_afectacion'  => date('Y-m-d'),
                'origen_fecha_operacion'    => date('Y-m-d'),
                'origen_fecha_afectacion'   => date('Y-m-d'),
                'tipo_cambio'               => $data->tipo_cambio
            ]);

            $traspaso = DB::select("SELECT * FROM documento_pago WHERE id = " . $documento_pago . "")[0];

            $crear_traspaso = DocumentoService::crearMovimientoFlujo($traspaso, $data->empresa);

            if ($crear_traspaso->error) {
                DB::table('documento_pago')->where(['id' => $documento_pago])->delete();

                array_push($ingresos_no_convertidos, $informacion_ingreso->folio);

                continue;
            }

            DB::table('documento_pago')->where(['id' => $ingreso])->update(['traspasado' => 1]);

            array_push($ingresos_convertidos, "Ingreso: " . $informacion_ingreso->folio . " - Traspaso: " . $crear_traspaso->id . "<br>");
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Ingresos convertidos correctamente:<br> " . implode("", $ingresos_convertidos) . "<br><br>Ingresos fallidos: " . implode(", ", $ingresos_no_convertidos) . ""
        ]);
    }

    public function contabilidad_ingreso_cuenta_data($empresa)
    {
        $cuentas = DB::select("SELECT 
                                documento_pago_cuenta.*,
                                moneda.moneda 
                            FROM documento_pago_cuenta 
                            INNER JOIN moneda ON documento_pago_cuenta.id_moneda = moneda.id
                            WHERE documento_pago_cuenta.id_empresa = " . $empresa . "
                            GROUP BY documento_pago_cuenta.id");

        foreach ($cuentas as $cuenta) {
            $conciliacion = DB::table("documento_pago_cuenta_conciliacion")
                ->select("fecha")
                ->where("id_cuenta", $cuenta->id)
                ->orderBy("fecha", "DESC")
                ->first();

            $cuenta->fecha_conciliacion = $conciliacion ? $conciliacion->fecha : null;
        }

        $usuarios = DB::select("SELECT
                                    usuario.authy,
                                    usuario.nombre,
                                    nivel.nivel
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                INNER JOIN subnivel ON subnivel_nivel.id_subnivel = subnivel.id
                                WHERE (nivel.nivel = 'CONTABILIDAD' AND subnivel.subnivel = 'ADMINISTRADOR')
                                OR nivel.nivel = 'ADMINISTRADOR'
                                AND usuario.id != 1
                                GROUP BY usuario.id");

        return response()->json([
            'code'  => 200,
            'cuentas'   => $cuentas,
            'usuarios'  => $usuarios
        ]);
    }

    public function contabilidad_ingreso_cuenta_sincronizar($empresa)
    {
        $bd         = DB::select("SELECT bd FROM empresa WHERE id = " . $empresa . "")[0]->bd;

        $cuentas = @json_decode(file_get_contents(config('webservice.url') . 'api/adminpro/Consulta/CuentasBancarias/' . $bd));

        foreach ($cuentas as $cuenta) {
            $existe_cuenta = DB::select("SELECT id FROM documento_pago_cuenta WHERE cuenta = " . $cuenta->id . " AND id_empresa = " . $empresa . "");

            if (empty($existe_cuenta)) {
                DB::table('documento_pago_cuenta')->insertGetId([
                    'id_empresa'    => $empresa,
                    'id_moneda'     => $cuenta->monedaid,
                    'cuenta'        => $cuenta->id,
                    'descripcion'   => $cuenta->cuenta,
                    'saldo_inicial' => 0
                ]);
            }
        }

        $cuentas = DB::select("SELECT
                                documento_pago_cuenta.*, 
                                moneda.moneda 
                            FROM documento_pago_cuenta 
                            INNER JOIN moneda ON documento_pago_cuenta.id_moneda = moneda.id
                            WHERE documento_pago_cuenta.id_empresa = " . $empresa . "");

        return response()->json([
            'code'  => 200,
            'cuentas'   => $cuentas
        ]);
    }

    public function contabilidad_ingreso_cuenta_actualizar(Request $request)
    {
        $cuenta = json_decode($request->input('cuenta'));

        DB::table('documento_pago_cuenta')->where(['id' => $cuenta->id])->update([
            'descripcion'   => $cuenta->descripcion,
            'saldo_inicial' => $cuenta->saldo_inicial
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Cuenta actualizada correctamente."
        ]);
    }

    public function contabilidad_ingreso_cuenta_conciliar(Request $request)
    {
        $data = json_decode($request->input('conciliacion'));
        $auth = json_decode($request->auth);

        $existe_conciliacion = DB::select("SELECT
                                                dpcc.id,
                                                dpcc.status
                                            FROM documento_pago_cuenta AS dpc
                                            INNER JOIN documento_pago_cuenta_conciliacion AS dpcc ON dpc.id = dpcc.id_cuenta
                                            WHERE dpc.cuenta = '" . $data->cuenta . "'
                                            AND dpcc.fecha >= '" . $data->fecha . "'");

        if (!empty($existe_conciliacion)) {
            if (!$existe_conciliacion[0]->status) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "Ya existe una conciliación de la cuenta con fecha más reciente. ¿Deseas desconciliarlo?",
                    'registro'  => $existe_conciliacion[0]->id
                ]);
            }

            DB::table('documento_pago_cuenta_conciliacion')->where(['id' => $existe_conciliacion[0]->id])->update([
                'status'        => 0,
                'concilied_by'  => $auth->id
            ]);

            return response()->json([
                'code'  => 200,
                'message'   => "Día conciliado correctamente."
            ]);
        }

        DB::table('documento_pago_cuenta_conciliacion')->insert([
            'id_cuenta'     => $data->cuenta,
            'fecha'         => $data->fecha,
            'status'        => 0,
            'concilied_by'  => $auth->id
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Día conciliado correctamente."
        ]);
    }

    public function contabilidad_ingreso_cuenta_desconciliar(Request $request)
    {
        $registro   = $request->input('registro');
        $token      = $request->input('token');
        $authy      = $request->input('authy');

        $authy_user_id = DB::select("SELECT id FROM usuario WHERE authy = '" . $authy . "' AND status = 1");

        if (empty($authy_user_id)) {
            return response()->json([
                'code'  => 403,
                'message'   => "Usuario no encontrado, favor de contactar a un administrador."
            ]);
        }

        $authy_user_id = $authy_user_id[0]->id;

        $authy_request = new \Authy\AuthyApi('qPXDpKmDp7A71cxk7JBPspwbB9oFJb4t');

        try {
            $verification = $authy_request->verifyToken($authy, $token);

            if ($verification->ok()) {
                DB::table('documento_pago_cuenta_conciliacion')->where(['id' => $registro])->update([
                    'status'    => 1
                ]);

                return response()->json([
                    'code'  => 200,
                    'message'   => "Día desconciliado correctamente."
                ]);
            } else {
                return response()->json([
                    'code'  => 403,
                    'message'   => "Token incorrecto"
                ]);
            }
        } catch (\Authy\AuthyFormatException $e) {
            return response()->json([
                'code'  => 500,
                'message'   => "Token incorrecto. " . $e->getMessage()
            ]);
        }
    }

    public function contabilidad_ingreso_cuenta_estado(Request $request)
    {
        $data = json_decode($request->input('data'));

        $saldo_final_cuenta     = 0;
        $saldo_inicial_cuenta   = DB::select("SELECT saldo_inicial FROM documento_pago_cuenta WHERE cuenta = " . $data->cuenta . "");

        if (empty($saldo_inicial_cuenta)) {
            return response()->json([
                'code'  => 404,
                'message'   => "No se encontró la cuenta, favor de contactar a un administrador."
            ]);
        }

        $estado_cuenta = @json_decode(file_get_contents(config("webservice.url") . "api/adminpro/Reporte/7/Contabilidad/IE/Cuenta/" . $data->cuenta . "/rangofechas/De/" . explode("-", $data->fecha_inicial)[2] . "/" . explode("-", $data->fecha_inicial)[1] . "/" . explode("-", $data->fecha_inicial)[0] . "/Al/" . explode("-", $data->fecha_final)[2] . "/" . explode("-", $data->fecha_final)[1] . "/" . explode("-", $data->fecha_final)[0] . ""));

        if (empty($estado_cuenta)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No fue posible obtener el estado de cuenta de Comercial."
            ]);
        }

        $saldo_inicial_cuenta = $saldo_inicial_cuenta[0]->saldo_inicial;

        $total_ingreso_mes_antes    = (is_null($estado_cuenta->IE_parte_1->ingreso)) ? 0 : $estado_cuenta->IE_parte_1->ingreso;
        $total_egreso_mes_antes     = (is_null($estado_cuenta->IE_parte_1->egreso)) ? 0 : $estado_cuenta->IE_parte_1->egreso;

        $saldo_inicial_cuenta       = (float) $saldo_inicial_cuenta + (float) $total_ingreso_mes_antes - (float) $total_egreso_mes_antes;

        $total_ingreso_mes_actual   = (is_null($estado_cuenta->IE_parte_2->ingreso)) ? 0 : $estado_cuenta->IE_parte_2->ingreso;
        $total_egreso_mes_actual    = (is_null($estado_cuenta->IE_parte_2->egreso)) ? 0 : $estado_cuenta->IE_parte_2->egreso;

        $saldo_final_cuenta = $saldo_inicial_cuenta + (float) $total_ingreso_mes_actual - (float) $total_egreso_mes_actual;

        return response()->json([
            'code'  => 200,
            'saldo_inicial' => $saldo_inicial_cuenta,
            'saldo_final'   => $saldo_final_cuenta,
            'movimientos'   => $$estado_cuenta->IE_parte_2->ingresos_egresos_raw
        ]);
    }

    public function contabilidad_ingreso_cuenta_crear(Request $request)
    {
        $data           = json_decode($request->input('data'));
        $empresa        = $request->input('empresa');
        $rfc_entidad    = $request->input('rfc_entidad');

        $array_pro = array(
            'bd'        => $empresa,
            'password'  => config("webservice.token"),
            'empresa'   => $rfc_entidad,
            'nombre'    => $data->nombre,
            'banco'     => $data->banco,
            'razon'     => $data->razon_social_banco,
            'rfc'       => $data->rfc_banco,
            'cuenta'    => $data->no_cuenta,
            'clabe'     => $data->clabe,
            'divisa'    => $data->divisa
        );

        $response = \Httpful\Request::post('http://201.7.208.53:11903/api/adminpro/Empresas/Cuentas/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($array_pro, \Httpful\Mime::FORM)
            ->send();

        $response = json_decode($response);

        if ($response->error == 1) {
            return response()->json([
                'code'  => 500,
                'message'   => "Error al generar el documento, mensaje de error: " . $response->mensaje
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Cuenta creada correctamente."
        ]);
    }

    public function contabilidad_ingreso_configuracion_data()
    {
        $categorias = DB::select("SELECT * FROM documento_pago_categoria");
        $verticales = DB::select("SELECT * FROM documento_pago_vertical");

        return response()->json([
            'code'  => 200,
            'categorias'    => $categorias,
            'verticales'    => $verticales
        ]);
    }

    public function contabilidad_ingreso_configuracion_vertical(Request $request)
    {
        $data = json_decode($request->input('data'));

        if ($data->id == 0) {
            $existe_vertical = DB::select("SELECT id FROM documento_pago_vertical WHERE vertical = '" . TRIM($data->vertical) . "'");

            if (empty($existe_vertical)) {
                $vertical = DB::table('documento_pago_vertical')->insertGetId([
                    'vertical'  => TRIM($data->vertical)
                ]);

                return response()->json([
                    'code'  => 200,
                    'message'   => "Vertical creada correctamente",
                    'vertical'  => $vertical
                ]);
            }

            return response()->json([
                'code'  => 500,
                'message'   => "Ya éxiste una vertical con el nombre proporcionado."
            ]);
        } else {
            $existe_vertical = DB::select("SELECT id FROM documento_pago_vertical WHERE vertical = '" . TRIM($data->vertical) . "' AND id != " . $data->id . "");

            if (empty($existe_vertical)) {
                DB::table('documento_pago_vertical')->where(['id' => $data->id])->update([
                    'vertical' => TRIM($data->vertical)
                ]);

                return response()->json([
                    'code'  => 200,
                    'message'   => "Vertical actualizada correctamente."
                ]);
            } else {
                return response()->json([
                    'code'  => 500,
                    'message'   => "Ya éxiste una vertical con el nombre proporcionado."
                ]);
            }
        }
    }

    public function contabilidad_ingreso_configuracion_categoria(Request $request)
    {
        $data = json_decode($request->input('data'));

        if ($data->id == 0) {
            $existe_categoria = DB::select("SELECT id FROM documento_pago_categoria WHERE categoria = '" . TRIM($data->categoria) . "'");

            if (empty($existe_categoria)) {
                $categoria = DB::table('documento_pago_categoria')->insertGetId([
                    'categoria'     => TRIM($data->categoria),
                    'tipo_gasto'    => TRIM($data->tipo_gasto),
                    'afectacion'    => TRIM($data->afectacion),
                    'familia'       => TRIM($data->familia)
                ]);

                return response()->json([
                    'code'  => 200,
                    'message'   => "Categoria creada correctamente.",
                    'categoria' => $categoria
                ]);
            }
            return response()->json([
                'code'  => 500,
                'message'   => "Ya éxiste una categoria con el nombre proporcionado."
            ]);
        } else {
            $existe_categoria = DB::select("SELECT id FROM documento_pago_categoria WHERE categoria = '" . TRIM($data->categoria) . "' AND id != " . $data->id . "");

            if (empty($existe_categoria)) {
                DB::table('documento_pago_categoria')->where(['id' => $data->id])->update([
                    'categoria'     => TRIM($data->categoria),
                    'tipo_gasto'    => TRIM($data->tipo_gasto),
                    'afectacion'    => TRIM($data->afectacion),
                    'familia'       => trim($data->familia)
                ]);

                return response()->json([
                    'code'  => 200,
                    'message'   => "Categoria actualizada correctamente."
                ]);
            }

            return response()->json([
                'code'  => 500,
                'message'   => "Ya éxiste una Categoria con el nombre proporcionado."
            ]);
        }
    }

    public function contabilidad_ingreso_eliminar_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $empresas = DB::table("empresa")
            ->join("usuario_empresa", "empresa.id", "=", "usuario_empresa.id_empresa")
            ->select("empresa", "bd")
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->get();

        return response()->json([
            "code" => 200,
            "data" => $empresas
        ]);
    }

    public function contabilidad_proveedor_archivos($rfc)
    {
        $archivos = DB::select("SELECT
                                    documento_entidad_archivo.*,
                                    usuario.nombre AS creador
                                FROM documento_entidad
                                INNER JOIN documento_entidad_archivo ON documento_entidad.id = documento_entidad_archivo.id_entidad
                                INNER JOIN usuario ON documento_entidad_archivo.id_usuario = usuario.id
                                WHERE documento_entidad.rfc = '" . trim($rfc) . "'");

        return response()->json([
            'code'  => 200,
            'archivos'  => $archivos
        ]);
    }

    public function contabilidad_proveedor_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!property_exists($data, "entidad")) {
            return response()->json([
                "code" => 500,
                "message" => "El archivo seleccionado es demasiado grande, intenta reduciendo el tamaño del mismo y vuelve a intentarlo."
            ]);
        }

        $existe_entidad = DB::select("SELECT id FROM documento_entidad WHERE rfc = '" . trim($data->entidad->rfc) . "' AND tipo = 2");

        if (empty($existe_entidad)) {
            $entidad = DB::table('documento_entidad')->insertGetId([
                'tipo'          => 2,
                'razon_social'  => mb_strtoupper($data->entidad->razon, 'UTF-8'),
                'rfc'           => mb_strtoupper($data->entidad->rfc, 'UTF-8'),
                'telefono'      => $data->entidad->telefono,
                'correo'        => $data->entidad->email
            ]);
        } else {
            $entidad = $existe_entidad[0]->id;
        }

        try {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                        ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_entidad_archivo')->insert([
                        'id_entidad'    =>  $entidad,
                        'id_usuario'    =>  $auth->id,
                        'nombre'        =>  $archivo->nombre,
                        'dropbox'       =>  $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'code'  => 500,
                'message'   => "No fue posible subir el archivo " . $archivo->nombre . "a dropbox, los archivos anteriores a este fueron subidos correctamente. Mensaje de error: " . $e->getMessage()
            ]);
        }

        $archivos = DB::select("SELECT
                                    documento_entidad_archivo.*,
                                    usuario.nombre AS creador 
                                FROM documento_entidad_archivo 
                                INNER JOIN usuario ON documento_entidad_archivo.id_usuario = usuario.id
                                WHERE id_entidad = " . $entidad . "");

        return response()->json([
            'code'  => 200,
            'message'   => "Archivos subidos correctamente.",
            'archivos'  => $archivos
        ]);
    }

    public function contabilidad_compra_gasto_crear_gasto(Request $request)
    {
        $data = json_decode($request->input("data"));

        $gasto = array(
            "bd" => $data->empresa,
            "password" => config('webservice.token'),
            "serie" => property_exists($data, "serie") ? $data->serie : "",
            "folio" => $data->folio,
            "proveedor" => $data->proveedor->rfc,
            "titulo" => "",
            "monedaid" => $data->moneda,
            "tc" => $data->tipo_cambio,
            "metodo_pago" => $data->metodo_pago,
            "forma_pago" => $data->periodo,
            "uso_cfdi" => $data->uso_cfdi,
            "subtotal" => $data->subtotal,
            "descuento" => $data->descuento,
            "impuesto" => $data->impuesto ? $data->impuesto : 0,
            "retencion" => $data->retencion,
            "total" => $data->total,
            "uuid" => $data->uuid,
            "fecha" => $data->fecha,
            "detalles" => json_encode($data->productos),
            "impuestos_extras" => json_encode($data->impuestos_locales)
        );

        $crear_gasto = \Httpful\Request::post(config('webservice.url') . "Gastos/Insertar")
            ->body($gasto, \Httpful\Mime::FORM)
            ->send();

        $crear_gasto_raw = $crear_gasto->raw_body;
        $crear_gasto = @json_decode($crear_gasto);
        # Si sucedio algo mal, se regresa un mensaje de error
        if (empty($crear_gasto)) {
            return response()->json([
                "code" => 500,
                "message" => "No fue posible crear el gasto en el ERP con la BD: " . $data->empresa . ", error: desconocido",
                "raw" => $crear_gasto_raw,
                "data" => $gasto
            ]);
        }
        # Si sucedio algo mal, se regresa un mensaje de error
        if ($crear_gasto->error == 1) {
            return response()->json([
                "code" => 500,
                "message" => "No fue posible crear el gasto en el ERP con la BD: " . $data->empresa . ", error: " . $crear_gasto->mensaje . "",
                "raw" => 0,
                "data" => $gasto
            ]);
        }

        return response()->json([
            "code" => 200,
            "message" => "Gasto creado correctamente con el ID " . $crear_gasto->id
        ]);
    }

    private function ventas_raw_data($extra_data)
    {
        $ventas = DB::select("SELECT 
                                documento.id,
                                documento.id_marketplace_area,
                                documento.documento_extra,
                                documento.id_moneda,
                                documento.tipo_cambio,
                                documento.referencia,
                                documento.created_at, 
                                documento_entidad.razon_social AS cliente,
                                documento_entidad.rfc,
                                documento_entidad.correo,
                                documento_entidad.telefono,
                                documento_entidad.telefono_alt,
                                marketplace.marketplace, 
                                area.area, 
                                paqueteria.paqueteria, 
                                usuario.nombre AS usuario,
                                documento_entidad.razon_social AS cliente,
                                documento_entidad.rfc,
                                empresa.bd
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN usuario_empresa ON empresa_almacen.id_empresa = usuario_empresa.id_empresa
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                            WHERE documento.status = 1
                            AND documento.id_tipo = 2
                            AND documento.problema = 0
                            " . $extra_data . "");

        foreach ($ventas as $venta) {
            $total_productos = 0;

            $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    movimiento.cantidad,
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

            foreach ($productos as $producto) {
                $total_productos += $producto->cantidad * $producto->precio;
            }

            $pago = DB::select("SELECT
                                    documento_pago.*
                                FROM documento_pago
                                INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                WHERE documento_pago_re.id_documento = " . $venta->id . "");

            $archivos = DB::select("SELECT
                                        usuario.id,
                                        usuario.nombre AS usuario,
                                        documento_archivo.nombre AS archivo,
                                        documento_archivo.dropbox
                                    FROM documento_archivo
                                    INNER JOIN usuario ON documento_archivo.id_usuario = usuario.id
                                    WHERE documento_archivo.id_documento = " . $venta->id . " AND documento_archivo.status = 1");

            $seguimiento = DB::select("SELECT seguimiento.*, usuario.nombre FROM seguimiento INNER JOIN usuario ON seguimiento.id_usuario = usuario.id WHERE id_documento = " . $venta->id . "");

            $venta->empresa = DB::select("SELECT
                                            empresa.bd
                                        FROM marketplace_area_empresa
                                        INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                        WHERE marketplace_area_empresa.id_marketplace_area = " . $venta->id_marketplace_area . "");

            $venta->seguimiento     = $seguimiento;
            $venta->productos       = $productos;
            $venta->archivos        = $archivos;
            $venta->pago            = (!empty($pago)) ? $pago[0] : "";
            $venta->total_productos = $total_productos;
        }

        return $ventas;
    }

    public function contabilidad_globalizar_data(Request $request)
    {
        $rse = DB::select("SELECT no_venta FROM documento WHERE id = $request->data");

        if (!empty($rse)) {
            return response()->json([

                'venta' => $rse[0]->no_venta
            ]);
        } else {
            return response()->json([
                'venta' => "N/A"
            ]);
        }
    }

    public function contabilidad_documentos_importar_data($anio)
    {
        set_time_limit(0);

        $ventas = DB::table('documento as d')
            ->join('documento_fase as df', 'df.id', '=', 'd.id_fase')
            ->join('documento_tipo as dt', 'dt.id', '=', 'd.id_tipo')
            ->join('empresa_almacen as ea', 'ea.id', '=', 'd.id_almacen_principal_empresa')
            ->join('almacen as a', 'a.id', '=', 'ea.id_almacen')
            ->join('empresa as emp', 'emp.id', '=', 'ea.id_empresa')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('marketplace as m', 'm.id', '=', 'ma.id_marketplace')
            ->join('area as ar', 'ar.id', '=', 'ma.id_area')
            ->select('d.id', 'd.documento_extra', 'd.id_almacen_principal_empresa', 'd.factura_serie', 'ar.area', 'm.marketplace', 'emp.empresa', 'd.factura_folio', 'd.id_fase', 'd.created_at', 'dt.tipo', 'df.fase', 'a.almacen')
            ->whereIn('d.documento_extra', ['N/A', ''])
            ->where('d.id_tipo', 2)->where('d.id_almacen_principal_empresa', '>', 0)
            ->where('d.id_fase', '>=', 5)->where('d.status', 1)
            ->where('d.created_at', 'like', $anio . '%')
            ->get();

        $ventasValidas = [];

        foreach ($ventas as $key => $venta) {
            // Obtener los movimientos de cada venta
            $movimientos = DB::table('movimiento')->where('id_documento', $venta->id)->get();

            // Verificar si hay movimientos
            if (!empty($movimientos)) {
                // Si hay movimientos, se añade la venta al nuevo arreglo
                $ventasValidas[] = $venta;
            }
        }

        $ventas = $ventasValidas;

        if (!empty($ventas)) {
            return response()->json([
                'ventas' => $ventas
            ]);
        } else {
            return response()->json([
                'ventas' => "N/A"
            ]);
        }
    }

    public function contabilidad_documentos_importar_importar($venta)
    {
        set_time_limit(0);

        $bd = DB::table('documento')
            ->select('empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->where('documento.id', $venta)
            ->get();
        $bd = $bd[0];

        $response = DocumentoService::crearFactura($venta, 0, 0);

        if ($response->error) {
            DB::table('documento')->where(['id' => $venta])->update([
                'id_fase' => 5
            ]);

            DB::table('seguimiento')->insert([
                'id_documento' => $venta,
                'id_usuario' => 1,
                'seguimiento' => "No se pudo importar el documento. Mensaje de Error: " . $response->mensaje ?? "Error desconocido"
            ]);

            return response()->json([
                'code'  => 500,
                'message'   => "No se pudo importar el documento. Mensaje de Error: " . $response->mensaje,
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_documento' => $venta,
            'id_usuario' => 1,
            'seguimiento' => "Se Importa el pedido a comercial manualmente."
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Importado correctamente",
            'response' => $response
        ]);
    }

    public function contabilidad_documentos_actualizar_data($documento)
    {
        set_time_limit(0);

        $doc = DB::table('documento as d')
            ->join('documento_fase as df', 'df.id', '=', 'd.id_fase')
            ->join('documento_tipo as dt', 'dt.id', '=', 'd.id_tipo')
            ->join('empresa_almacen as ea', 'ea.id', '=', 'd.id_almacen_principal_empresa')
            ->join('almacen as a', 'a.id', '=', 'ea.id_almacen')
            ->join('empresa as emp', 'emp.id', '=', 'ea.id_empresa')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('marketplace as m', 'm.id', '=', 'ma.id_marketplace')
            ->join('area as ar', 'ar.id', '=', 'ma.id_area')
            ->select('d.id', 'd.documento_extra', 'd.factura_serie', 'ar.area', 'm.marketplace', 'emp.empresa', 'd.factura_folio', 'd.id_fase', 'd.created_at', 'dt.tipo', 'df.fase', 'a.almacen')
            ->where('d.id_fase', 5)->where('d.status', 1)
            ->where('d.id', $documento)
            ->get();

        if (!empty($doc)) {
            return response()->json([
                'code' => 200,
                'message' => 'Busqueda correcta',
                'ventas' => $doc
            ]);
        } else {
            return response()->json([
                'code' => 500,
                'message' => "No se encuentra la venta",
                'ventas' => []
            ]);
        }
    }

    public function contabilidad_documentos_actualizar_terminar($documento)
    {
        set_time_limit(0);

        $doc = DB::table('documento')->where('id', $documento)->first();

        if ($doc->documento_extra == 'N/A' || $doc->factura_serie == 'N/A' || $doc->factura_folio == 'N/A') {
            return response()->json([
                'code' => 500,
                'message' => 'El documento no está en comercial, no se puede cambiar de fase'
            ]);
        }

        DB::table('documento')->where('id', $documento)->update([
            'id_fase' => 6
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => "Se Actualiza el pedido manualmente, se timbró y no pasó a fase Terminado en un principio."
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Documento actualizado correctamente"
        ]);
    }

    public function contabilidad_globalizar_linio(Request $request)
    {
        set_time_limit(0);
        file_put_contents("logs/linio.log", "");

        $documentos_comercial = [];
        $data = json_decode($request->input('data'));

        if (empty($data->xmls)) {
            return response()->json([
                'message' => "No se encontró ningun XML para importar."
            ], 500);
        }

        $informacion_empresa = DB::table("empresa")->find($data->empresa);

        if (!$informacion_empresa) {
            return response()->json([
                "message" => "No se encontró información de la empresa seleccionada"
            ], 404);
        }

        foreach ($data->xmls as $xml) {

            $xml_data = simplexml_load_string($xml->path, 'SimpleXMLElement', LIBXML_NOWARNING);

            if (empty($xml_data)) {
                file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: XML Invalido." . PHP_EOL, FILE_APPEND);

                continue;
            }

            $documento = new DOMDocument;
            $documento->loadXML($xml_data->asXML());
            $comprobante = $documento->getElementsByTagName('Comprobante')->item('0');
            $emisor = $documento->getElementsByTagName('Emisor')->item('0');
            $uuid = $documento->getElementsByTagName('TimbreFiscalDigital')->item('0');
            $addenda = $documento->getElementsByTagName('Addenda')->item('0');

            if ($addenda) {
                $encabezado = $addenda->getElementsByTagName('Encabezado')->item('0');
                //SINGLE SALE
                if ($encabezado->getAttribute('FolioOrdenCompra')) {
                    array_push($xml->ventas, $encabezado->getAttribute('FolioOrdenCompra'));
                }
                //MULTI SALE
                if (!$encabezado->getAttribute('FolioOrdenCompra')) {
                    $menu_nodes = $encabezado->getElementsByTagName('Cuerpo');
                    foreach ($menu_nodes as $key) {
                        $last_word_start = strrpos($key->getAttribute('Concepto'), ' ') + 1; // +1 so we don't include the space in our result
                        $last_word = substr($key->getAttribute('Concepto'), $last_word_start);
                        array_push($xml->ventas, $last_word);
                    }
                }

                if ($emisor->getAttribute('Rfc') === $informacion_empresa->rfc) {

                    $conceptos = $documento->getElementsByTagName('Concepto');

                    if (empty($conceptos)) {
                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: El XML con el UUID " . $uuid->getAttribute('UUID') . " no contiene conceptos." . PHP_EOL, FILE_APPEND);

                        continue;
                    }

                    foreach ($xml->ventas as $venta) {
                        $existe_venta_crm = DB::select("SELECT
                                                    documento.id,
                                                    documento.documento_extra,
                									documento.no_venta
                                                FROM documento
                                                WHERE (no_venta = '" . TRIM($venta) . "' OR no_venta = '" . TRIM($venta) . "F') AND status = 1");
                        if (empty($existe_venta_crm)) {
                            file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: La venta " . $venta . " relacionada al UUID " . $uuid->getAttribute('UUID') . " no existe registrada en el sistema como activa." . PHP_EOL, FILE_APPEND);

                            continue 2;
                        }
                        array_push($documentos_comercial, $existe_venta_crm[0]->documento_extra);
                    }

                    try {
                        $linio = [
                            'uuid' => $uuid->getAttribute('UUID'),
                            'fecha' => explode("T", $comprobante->getAttribute('Fecha'))[0],
                            'serie' => mb_strtoupper($comprobante->getAttribute('Serie'), 'UTF-8'),
                            'folio' => mb_strtoupper($comprobante->getAttribute('Folio'), 'UTF-8'),
                        ];

                        $array_pro = array(
                            "bd" => $informacion_empresa->bd,
                            "documentos" => $documentos_comercial,
                            "linio" => $linio,
                        );

                        $crear_documento = \Httpful\Request::post(config('webservice.url') . "ventas/globalizar")
                            ->body($array_pro, \Httpful\Mime::FORM)
                            ->send();

                        $crear_documento_raw = $crear_documento->raw_body;
                        $crear_documento = @json_decode($crear_documento);

                        if (empty($crear_documento)) {
                            file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la globalización en el ERP, mensaje de error: " . $crear_documento_raw . "." . PHP_EOL, FILE_APPEND);

                            continue;
                        }

                        if ($crear_documento->error == 1) {
                            file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear la globalización en el ERP, mensaje de error: " . $crear_documento->mensaje . "." . PHP_EOL, FILE_APPEND);


                            continue;
                        }
                    } catch (Exception $e) {
                        file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: No fue posible crear el ingreso la factura " . $factura->serie . $factura->folio . " con el UUID " . $factura->uuid . ", mensaje de error: " . $e->getMessage() . "." . PHP_EOL, FILE_APPEND);

                        continue;
                    }
                } else {
                    file_put_contents("logs/linio.log", date("d/m/Y H:i:s") . " Error: El emisor de la factura no corresponde a la empresa seleccionada, RFC emisor " . $emisor->getAttribute('Rfc') . "." . PHP_EOL, FILE_APPEND);
                }
            } else {
                return response()->json([
                    "code" => 500,
                    "message" => 'El archivo ' . $uuid->getAttribute('UUID') . ' no contiene Addenda<br/>Se reiniciará el proceso',
                ]);
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Facturas globalizadas correctamente<br><br>Favor de revisar el .log de linio https://rest.crmomg.mx/logs/linio.log"
        ]);
    }

    public static function contabilidad_globalizar_excel(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet();
        $fila = 2;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Reporte a globalizar');

        $spreadsheet->getActiveSheet()->getStyle('A1:G1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        $sheet->setCellValue('A1', 'Documento'); //1
        $sheet->setCellValue('B1', 'ID Comercial'); //2
        $sheet->setCellValue('C1', 'Venta'); //3
        $sheet->setCellValue('D1', 'Fecha'); //4
        $sheet->setCellValue('E1', 'Factura'); //5
        $sheet->setCellValue('F1', 'Total'); //6
        $sheet->setCellValue('G1', 'Moneda'); //7

        foreach ($data as $venta) {
            $sheet->setCellValue('A' . $fila, $venta->folio);
            $sheet->setCellValue('B' . $fila, $venta->id);
            // $sheet->setCellValue('C' . $fila, $venta->no_venta);
            $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $fila, $venta->fecha);
            $sheet->setCellValue('E' . $fila, $venta->factura);
            $sheet->setCellValue('F' . $fila, '$' . $venta->total);
            $sheet->setCellValue('G' . $fila, $venta->moneda);
            $fila++;
        }
        foreach (range('A', 'G') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->removeSheetByIndex(1);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_a_globalizar.xlsx');
        $excel
            = base64_encode(file_get_contents('reporte_a_globalizar.xlsx'));
        unlink('reporte_a_globalizar.xlsx');

        return response()->json([
            'excel' => $excel
        ]);
    }

    public function contabilidad_refacturacion_data()
    {
        $query = DB::table('refacturacion')
            ->select('refacturacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'c.nombre as denegente', 'documento.no_venta')
            ->leftJoin('usuario as a', 'refacturacion.id_usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'refacturacion.id_autoriza', '=', 'b.id')
            ->leftJoin('usuario as c', 'refacturacion.id_rechaza', '=', 'c.id')
            ->leftJoin('documento', 'refacturacion.id_documento', '=', 'documento.id');

        $queryPendientes = clone $query;
        $queryTerminados = clone $query;

        $pendientes = $queryPendientes->whereNotIn('step', ['99', '3'])->get();
        $terminados = $queryTerminados->whereIn('step', ['99', '3'])->get();

        return response()->json([
            'pendientes' => $pendientes,
            'terminados' => $terminados
        ]);
    }

    public function contabilidad_refacturacion_cancelar($documento, Request $request)
    {
        set_time_limit(0);
        $auth = json_decode($request->auth);

        try {
            DB::beginTransaction();

            DB::table('refacturacion')->where('id', $documento)->update([
                'step' => 99,
                'denied_at' => date("Y-m-d H:i:s"),
                'id_rechaza' => $auth->id
            ]);

            DB::commit();

            return response()->json([
                'message' => "Refacturación cancelada",
                'code' => 200
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }

    public function contabilidad_refacturacion_crear(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $existe = DB::table('refacturacion')->where('id_documento', $data->documento)->where('step', '!=', 99)->first();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe una solicitud para este documento',
                'code' => 500
            ]);
        }

        if ($data->necesita_token) {
            $validate_authy = DocumentoService::authy($auth->id, $data->token);

            if ($validate_authy->error) {
                return response()->json([
                    "code" => 500,
                    "message" => $validate_authy->mensaje
                ]);
            }
        }

        try {
            DB::beginTransaction();

            DB::table('refacturacion')->insert([
                'id_documento' => $data->documento,
                'id_usuario' => $auth->id,
                'data' => $request->input('data')
            ]);

            DB::commit();

            return response()->json([
                'message' => "Solicitud de Refacturación creada",
                'code' => 200
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }

    public function contabilidad_estado_factura_reporte_semanal($reRun)
    {
        set_time_limit(0);

        $empresas = [
            5 => ['nombre' => 'GRANOTECNICA', 'columna' => 'fase_tres_end', 'columna_control' => 'fase_tres_status'],
            6 => ['nombre' => 'GOFE', 'columna' => 'fase_dos_end', 'columna_control' => 'fase_dos_status'],
            7 => ['nombre' => 'OMG', 'columna' => 'fase_uno_end', 'columna_control' => 'fase_uno_status']
        ];

        $startingYear = 2022;
        $currentYear = date('Y');
        $anios = range($startingYear, $currentYear);

        $terminacion = DB::table('bitacora_reporte_facturas')->orderBy('id', 'desc')->first();

        $bitacora = $reRun ? $terminacion->id : DB::table('bitacora_reporte_facturas')->insertGetId(['started' => 1]);

        foreach ($empresas as $empresa => $data) {
            $control = $data['columna_control'];

            if (!empty($terminacion)) {
                if ($terminacion->$control && $reRun) {
                    continue;
                }
            }

            $aniosParaProcesar = $empresa == 7 ? $anios : ['Totalidad'];

            foreach ($aniosParaProcesar as $anio) {
                $url = $this->construirUrl('EstadoCuenta/Cliente', $empresa, $anio);
                $url_pagos_pendientes = $this->construirUrl('PendientesAplicar', $empresa, $anio, true);

                $curl_handle = curl_init();
                curl_setopt($curl_handle, CURLOPT_URL, $url);
                curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
                $response = json_decode(curl_exec($curl_handle));
                curl_close($curl_handle);

                if (empty($response)) {
                    continue;
                }

                self::procesar_contabilidad_estado_factura_reporte_semanal($response, $empresa, $data, $url_pagos_pendientes, $anio);
            }
            DB::table('bitacora_reporte_facturas')->where(['id' => $bitacora])->update([
                $data['columna'] => date("Y-m-d H:i:s", strtotime('-1 hour')),
                $data['columna_control'] => 1,
            ]);
        }
    }

    private function construirUrl($endpoint, $empresa, $anio, $isIngresos = false)
    {
        $url = config('webservice.url') . "$endpoint/$empresa";

        if ($isIngresos) {
            $url .= "/Ingresos";
        }

        if ($anio !== 'Totalidad') {
            $url .= "/rangofechas/De/01/01/$anio/Al/31/12/$anio";
        }

        return $url;
    }

    public static function procesar_contabilidad_estado_factura_reporte_semanal($response, $empresa, $data, $url_pagos_pendientes, $anio)
    {
        $nombre_entidad = $response[0]->documento->nombre;
        $titulo = "Estado de cuenta de Clientes";

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('ESTADO DE CUENTA HISTORICO');
        $contador_fila = 7;
        $contador_fila_saldo = 7;
        $contador_fila_sin_saldo = 7;
        $contador_fila_nuevo_edc = 9;
        $total_resta_entidad = 0;
        $total_resta_entidad_saldo = 0;
        $total_resta_entidad_saldo_usd = 0;
        $total_resta_entidad_saldo_nuevo_edc = 0;
        $total_resta_15_dias = 0;
        $total_resta_30_dias = 0;
        $total_resta_45_dias = 0;
        $total_resta_60_dias = 0;
        $total_resta_75_dias = 0;
        $total_resta_15_dias_saldo = 0;
        $total_resta_30_dias_saldo = 0;
        $total_resta_45_dias_saldo = 0;
        $total_resta_60_dias_saldo = 0;
        $total_resta_75_dias_saldo = 0;
        $entidades = array();

        $sheet->setCellValue('F1', 'ESTADO DE CUENTA HISTORICO');
        $sheet->setCellValue('F2', $titulo);

        $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('K5:Q5')->getFont()->setBold(1);

        # Cabecera
        $sheet->setCellValue('A6', 'FECHA');
        $sheet->setCellValue('B6', 'FACTURA');
        $sheet->setCellValue('C6', 'TITULO');
        $sheet->setCellValue('D6', 'UUID');
        $sheet->setCellValue('E6', 'MONEDA');
        $sheet->setCellValue('F6', 'T.C');
        $sheet->setCellValue('G6', 'TOTAL');
        $sheet->setCellValue('H6', 'FECHA TRANSFERENCIA');
        $sheet->setCellValue('I6', 'BANCO');
        $sheet->setCellValue('J6', 'SALDO MXN');
        $sheet->setCellValue('K6', 'PERDIDA CAMBIARIA');
        $sheet->setCellValue('L6', 'SALDO DOCUMENTO');
        $sheet->setCellValue('M6', '1 A 15 DÍAS');
        $sheet->setCellValue('N6', '15 A 30 DÍAS');
        $sheet->setCellValue('O6', '30 A 45 DÍAS');
        $sheet->setCellValue('P6', '45 A 60 DÍAS');
        $sheet->setCellValue('Q6', '60 + DÍAS');
        $sheet->setCellValue('R6', 'CLAVE RASTREO');
        $sheet->setCellValue('S6', 'AUTORIZACION');
        $sheet->setCellValue('T6', 'REFERENCIA');
        $sheet->setCellValue('U6', "Cliente");


        $sheet->setCellValue('K5', 'SALDO');

        $sheet->freezePane("A7");

        $spreadsheet->getActiveSheet()->getStyle('A6:U6')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        for ($i = 0; $i < 3; $i++) {
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($i + 1);

            switch ($i) {
                case '0':
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS CON SALDO');

                    $sheet->setCellValue('F1', 'FACTURAS CON SALDO');
                    break;

                case '1':
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS SIN SALDO');
                    $sheet->setCellValue('F1', 'FACTURAS SIN SALDO');
                    break;

                default:
                    $sheet = $spreadsheet->getActiveSheet()->setTitle('FACTURAS Y PAGOS');
                    $sheet->setCellValue('F1', 'FACTURAS Y PAGOS');

                    break;
            }

            $sheet->setCellValue('F2', $titulo);

            $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
            $spreadsheet->getActiveSheet()->getStyle('K5:Q5')->getFont()->setBold(1);

            $sheet->setCellValue('A6', 'FECHA');
            $sheet->setCellValue('B6', 'FACTURA');
            $sheet->setCellValue('C6', 'TITULO');
            $sheet->setCellValue('D6', 'UUID');
            $sheet->setCellValue('E6', 'MONEDA');
            $sheet->setCellValue('F6', 'T.C');
            $sheet->setCellValue('G6', 'TOTAL');
            $sheet->setCellValue('H6', 'FECHA TRANSFERENCIA');
            $sheet->setCellValue('I6', 'BANCO');
            $sheet->setCellValue('J6', 'SALDO MXN');
            $sheet->setCellValue('K6', 'PERDIDA Ó UTILIDAD CAMBIARIA');
            $sheet->setCellValue('L6', 'SALDO DOCUMENTO');
            $sheet->setCellValue('M6', '1 A 15 DÍAS');
            $sheet->setCellValue('N6', '15 A 30 DÍAS');
            $sheet->setCellValue('O6', '30 A 45 DÍAS');
            $sheet->setCellValue('P6', '45 A 60 DÍAS');
            $sheet->setCellValue('Q6', '60 + DÍAS');
            $sheet->setCellValue('R6', 'CLAVE RASTREO');
            $sheet->setCellValue('S6', 'AUTORIZACION');
            $sheet->setCellValue('T6', 'REFERENCIA');
            $sheet->setCellValue('U6', "Cliente");

            $sheet->setCellValue('K5', 'SALDO');

            $sheet->freezePane("A7");

            $spreadsheet->getActiveSheet()->getStyle('A6:U6')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas y de color azul
        }

        /* Nuevo estado de cuenta */
        $spreadsheet->createSheet();
        $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(5);
        $spreadsheet->getActiveSheet()->setTitle('ESTADO DE CUENTA (NUEVO)');

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('B2', mb_strtoupper($nombre_entidad, 'UTF-8'));
        $sheet->setCellValue('B3', "ESTADO DE CUENTA (FACTURAS CON SALDO)");

        $spreadsheet
            ->getActiveSheet()
            ->getStyle('B2:N3')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color("101010"));

        $spreadsheet->getActiveSheet()->getStyle("B2:N2")->getFont()->setSize(14)->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle("B3:N3")->getFont()->setSize(12)->setBold(1);

        $sheet->getStyle('B2:N3')->getAlignment()->setHorizontal('center');

        $spreadsheet->getActiveSheet()->mergeCells('B2:N2');
        $spreadsheet->getActiveSheet()->mergeCells('B3:N3');

        $sheet->setCellValue('B8', 'FECHA FACTURA');
        $sheet->setCellValue('C8', 'FOLIO FACTURA');
        $sheet->setCellValue('D8', 'CONDICIONES DE PAGO');
        $sheet->setCellValue('E8', 'MONEDA');
        $sheet->setCellValue('F8', 'TOTAL');
        $sheet->setCellValue('G8', 'FECHA DE VENCIMIENTO');
        $sheet->setCellValue('H8', "DIAS DE VENCIMIENTO");
        $sheet->setCellValue('I8', 'SALDO DOCUMENTO MXN');
        $sheet->setCellValue('J8', '1 A 15 DÍAS');
        $sheet->setCellValue('K8', '16 A 30 DÍAS');
        $sheet->setCellValue('L8', '31 A 45 DÍAS');
        $sheet->setCellValue('M8', '46 A 60 DÍAS');
        $sheet->setCellValue('N8', 'MÁS DE 60 DÍAS');

        $spreadsheet
            ->getActiveSheet()
            ->getStyle('B8:N8')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color("101010"));

        $sheet->getStyle('B8:N8')->getAlignment()->setHorizontal('center');

        $spreadsheet->getActiveSheet()->getStyle("B8:N8")->getFont()->setBold(1);

        $sheet->setCellValue('L5', 'SALDO VENCIDO MXN:');
        $sheet->setCellValue('L6', 'SALDO VENCIDO USD:');

        $spreadsheet->getActiveSheet()->getStyle('L5:N6')->getFont()->setBold(1)->setSize(12)->getColor()->setARGB('FFFFFF');

        $spreadsheet->getActiveSheet()->getStyle("L5:N6")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1717');

        $spreadsheet->getActiveSheet()->getStyle("N5")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
        $spreadsheet->getActiveSheet()->getStyle("N6")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

        $spreadsheet->getActiveSheet()->mergeCells('L5:M5');
        $spreadsheet->getActiveSheet()->mergeCells('L6:M6');

        $sheet->freezePane("A9");
        /* Termina nuevo estado de cuenta e inicia primera pestaña */

        foreach ($response as $factura) {
            $spreadsheet->setActiveSheetIndex(0);
            $sheet = $spreadsheet->getActiveSheet();

            $total_resta_factura = round((float) $factura->documento->total * (float) $factura->documento->tc, 2);

            $sheet->setCellValue('A' . $contador_fila, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila, "");
            $sheet->setCellValue('D' . $contador_fila, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila, '');
            $sheet->setCellValue('I' . $contador_fila, '');
            $sheet->setCellValue('J' . $contador_fila, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila, 0);
            $sheet->setCellValue('L' . $contador_fila, 0);
            $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);

            $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila)->getFont()->setBold(1);

            # Color de fondo de la venta verde
            $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":Q" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            # Formato accounting
            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila . ":G" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila . ":K" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            /* Poner la info de nuevo en la pestaña donde está todo revuelto */
            $spreadsheet->setActiveSheetIndex(3);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('A' . $contador_fila, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila, "");
            $sheet->setCellValue('D' . $contador_fila, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila, '');
            $sheet->setCellValue('I' . $contador_fila, '');
            $sheet->setCellValue('J' . $contador_fila, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila, 0);
            $sheet->setCellValue('L' . $contador_fila, 0);
            $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);


            $spreadsheet->setActiveSheetIndex(0);
            $sheet = $spreadsheet->getActiveSheet();

            $contador_fila_total_documento = $contador_fila;

            $contador_fila++;

            foreach ($factura->pagos as $pago) {
                if ($pago->pago_monto == 0) continue;

                $sheet->setCellValue('A' . $contador_fila, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->pago_condocumento : $pago->pago_operacion);
                $sheet->setCellValue('C' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila, $total_resta_factura - ((float) $pago->pago_monto * $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                $spreadsheet->setActiveSheetIndex(3);
                $sheet = $spreadsheet->getActiveSheet();
                $contador_fila--;

                $sheet->setCellValue('A' . $contador_fila, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila, $factura->documento->serie . " " . $factura->documento->folio);
                $sheet->setCellValue('C' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila, $total_resta_factura - ((float) $pago->pago_monto * $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();
                $contador_fila++;

                $existe_pago = DB::select("SELECT
                                                        documento_pago.referencia,
                                                        documento_pago.clave_rastreo,
                                                        documento_pago.autorizacion
                                                    FROM documento_pago
                                                    INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                    WHERE folio = '" . $pago->pago_operacion . "'
                                                    AND empresa.bd = '" . $empresa . "'");

                $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);

                /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                $spreadsheet->setActiveSheetIndex(3);
                $sheet = $spreadsheet->getActiveSheet();

                $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);


                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();

                $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);

                /* Poner la info de nuevo en la pestaña donde está todo revuelto */
                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();

                $sheet->setCellValue('U' . $contador_fila, $factura->documento->nombre);

                $spreadsheet->setActiveSheetIndex(0);
                $sheet = $spreadsheet->getActiveSheet();

                $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila)->getFont()->setBold(1);

                # Color de fondo de la venta verde
                $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":Q" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('45E0E9');

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila . ":G" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila . ":K" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_resta_factura -= ((float) $pago->pago_monto * (float) $factura->documento->tc);

                $contador_fila++;
            }

            $total_resta_entidad += $total_resta_factura;

            $existe_entidad = false;

            foreach ($entidades as $entidad) {
                if ($entidad->rfc == $factura->documento->rfc) {
                    $entidad->total += $total_resta_factura;

                    $existe_entidad = true;

                    break;
                }
            }

            if (!$existe_entidad) {
                $entidad_data = new \stdClass();
                $entidad_data->nombre = $factura->documento->nombre;
                $entidad_data->rfc = $factura->documento->rfc;
                $entidad_data->total = $total_resta_factura;

                array_push($entidades, $entidad_data);
            }

            if ($total_resta_factura > 0) {
                if ($factura->documento->pago_terminos == "CONTADO" || is_null($factura->documento->pago_terminos)) {
                    $dias_pago = 0;
                } else {
                    $dias_pago = explode(" ", $factura->documento->pago_terminos)[0];
                }

                $fecha_actual = time();
                $fecha_pago = strtotime(date("Y-m-d", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
                $diferencia = $fecha_actual - $fecha_pago;

                $dias_transcurridos = (int) floor($diferencia / (60 * 60 * 24));

                # Se agrega saldo de la factura dependiendo los días transcurridos
                if ($dias_transcurridos > 0) {
                    switch (true) {
                        case ($dias_transcurridos > 0 && $dias_transcurridos < 16):
                            $sheet->setCellValue('M' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_15_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 15 && $dias_transcurridos < 31):
                            $sheet->setCellValue('N' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_30_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 30 && $dias_transcurridos < 46):
                            $sheet->setCellValue('O' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_45_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 45 && $dias_transcurridos < 61):
                            $sheet->setCellValue('P' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_60_dias += $total_resta_factura;

                            break;

                        case ($dias_transcurridos > 60):
                            $sheet->setCellValue('Q' . $contador_fila_total_documento, $total_resta_factura);

                            $total_resta_75_dias += $total_resta_factura;

                            break;
                    }
                }
            }

            $total_resta_factura = round($total_resta_factura, 2);

            $sheet->setCellValue('L' . $contador_fila_total_documento, $total_resta_factura);
            $spreadsheet->getActiveSheet()->getStyle("L" . $contador_fila_total_documento)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            $contador_fila += 2;

            /* Agregar factura a la pestaña con o sin saldo */
            $spreadsheet->setActiveSheetIndex($total_resta_factura > 15 ? 1 : 2);
            $sheet = $spreadsheet->getActiveSheet();
            $contador_fila_actual = $total_resta_factura > 15 ? $contador_fila_saldo : $contador_fila_sin_saldo;

            $total_resta_factura = round((float) $factura->documento->total * (float) $factura->documento->tc, 2);

            $sheet->setCellValue('A' . $contador_fila_actual, $factura->documento->fecha);
            $sheet->setCellValue('B' . $contador_fila_actual, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('C' . $contador_fila_actual, "");
            $sheet->setCellValue('D' . $contador_fila_actual, $factura->documento->uuid);
            $sheet->setCellValue('E' . $contador_fila_actual, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila_actual, $factura->documento->tc);
            $sheet->setCellValue('G' . $contador_fila_actual, $factura->documento->total);
            $sheet->setCellValue('H' . $contador_fila_actual, '');
            $sheet->setCellValue('I' . $contador_fila_actual, '');
            $sheet->setCellValue('J' . $contador_fila_actual, $total_resta_factura);
            $sheet->setCellValue('K' . $contador_fila_actual, 0);
            $sheet->setCellValue('L' . $contador_fila_actual, 0);
            $sheet->setCellValue('R' . $contador_fila_actual, $factura->documento->nombre);

            $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila_actual)->getFont()->setBold(1);

            # Color de fondo de la venta verde
            $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            # Formato accounting
            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_actual . ":G" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            $contador_fila_actual_total_documento = $contador_fila_actual;

            $contador_fila_actual++;

            foreach ($factura->pagos as $pago) {
                if ($pago->pago_monto == 0) continue;

                $sheet->setCellValue('A' . $contador_fila_actual, ($pago->pago_operacion == 0) ? 'Nota de credito' : 'Cobro cliente');
                $sheet->setCellValue('B' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->pago_condocumento : $pago->pago_operacion);
                $sheet->setCellValue('C' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_titulo : "");
                $sheet->setCellValue('D' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_uuid : $pago->info_operacion->op_uuid);
                $sheet->setCellValue('E' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_moneda : $pago->info_operacion->op_moneda);
                $sheet->setCellValue('F' . $contador_fila_actual, $pago->pago_tc);
                $sheet->setCellValue('G' . $contador_fila_actual, $pago->pago_monto);
                $sheet->setCellValue('H' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_fecha : $pago->info_operacion->op_fecha);
                $sheet->setCellValue('I' . $contador_fila_actual, ($pago->pago_operacion == 0) ? $pago->info_documento->pwd_folio . " " . $pago->info_documento->pwd_folio : $pago->info_operacion->op_cuentadestino);
                $sheet->setCellValue('J' . $contador_fila_actual, $total_resta_factura - ((float) $pago->pago_monto * (float) $factura->documento->tc));
                $sheet->setCellValue('K' . $contador_fila_actual, ((float) $pago->pago_monto * $pago->pago_tc) - ((float) $pago->pago_monto * $factura->documento->tc));

                $existe_pago = DB::select("SELECT
                                                        documento_pago.referencia,
                                                        documento_pago.clave_rastreo,
                                                        documento_pago.autorizacion
                                                    FROM documento_pago
                                                    INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                    WHERE folio = '" . $pago->pago_operacion . "'
                                                    AND empresa.bd = '" . $empresa . "'");

                $sheet->setCellValue('R' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                $sheet->setCellValue('S' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                $sheet->setCellValue('T' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);

                $sheet->setCellValue('U' . $contador_fila_actual, $factura->documento->nombre);

                $spreadsheet->getActiveSheet()->getStyle('R' . $contador_fila_actual)->getFont()->setBold(1);

                # Color de fondo de la venta verde
                $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila_actual . ":Q" . $contador_fila_actual)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('45E0E9');

                # Formato accounting
                $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_actual . ":G" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("J" . $contador_fila_actual . ":K" . $contador_fila_actual)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_resta_factura -= (float) $pago->pago_monto * (float) $factura->documento->tc;
                $contador_fila_actual++;
            }

            $total_resta_factura = round($total_resta_factura, 2);

            $dias_pago = 0;

            if ($factura->documento->pago_terminos == "CONTADO" || is_null($factura->documento->pago_terminos)) {
                $dias_pago = 0;
            } else {
                $dias_pago = explode(" ", $factura->documento->pago_terminos)[0];
            }

            $fecha_actual = time();
            $fecha_pago = strtotime(date("Y-m-d", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
            $diferencia = $fecha_actual - $fecha_pago;

            $dias_transcurridos = (int) floor($diferencia / (60 * 60 * 24));

            if ($total_resta_factura > 0) {
                if ($dias_transcurridos > 0) {
                    switch (true) {
                        case ($dias_transcurridos > 0 && $dias_transcurridos < 16):
                            $sheet->setCellValue('M' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_15_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('J' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 15 && $dias_transcurridos < 31):
                            $sheet->setCellValue('N' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_30_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('K' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 30 && $dias_transcurridos < 46):
                            $sheet->setCellValue('O' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_45_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('L' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 45 && $dias_transcurridos < 61):
                            $sheet->setCellValue('P' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_60_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('M' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;

                        case ($dias_transcurridos > 60):
                            $sheet->setCellValue('Q' . $contador_fila_actual_total_documento, $total_resta_factura);

                            if ($total_resta_factura > 15) {
                                $total_resta_75_dias_saldo += $total_resta_factura;

                                $spreadsheet->setActiveSheetIndex(5);
                                $sheet = $spreadsheet->getActiveSheet();

                                $sheet->setCellValue('N' . $contador_fila_nuevo_edc, $total_resta_factura);
                            }

                            break;
                    }
                }
            }

            $spreadsheet->setActiveSheetIndex($total_resta_factura > 15 ? 1 : 2);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('L' . $contador_fila_actual_total_documento, $total_resta_factura);
            $spreadsheet->getActiveSheet()->getStyle("L" . $contador_fila_actual_total_documento)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('6DDC7F');

            $contador_fila_actual += 2;

            if ($total_resta_factura > 15) {
                $contador_fila_saldo = $contador_fila_actual;
            } else {
                $contador_fila_sin_saldo = $contador_fila_actual;
            }

            $total_resta_entidad_saldo += $total_resta_factura > 15 ? $total_resta_factura : 0;
            $total_resta_entidad_saldo_nuevo_edc += $dias_transcurridos > 0 ? $total_resta_factura > 15 ? ($factura->documento->moneda == "Peso mexicano" ? $total_resta_factura : 0) : 0 : 0;
            $total_resta_entidad_saldo_usd += $dias_transcurridos > 0 ? $total_resta_factura > 15 ? ($factura->documento->moneda != "Peso mexicano" ? (float) $factura->documento->total : 0) : 0 : 0;

            if ($total_resta_factura < 15) continue;

            /* Nuevo estado de cuenta */
            $spreadsheet->setActiveSheetIndex(5);
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('B' . $contador_fila_nuevo_edc, date("d/m/Y", strtotime($factura->documento->fecha)));
            $sheet->setCellValue('C' . $contador_fila_nuevo_edc, $factura->documento->serie . " " . $factura->documento->folio);
            $sheet->setCellValue('D' . $contador_fila_nuevo_edc, $factura->documento->pago_terminos);
            $sheet->setCellValue('E' . $contador_fila_nuevo_edc, $factura->documento->moneda);
            $sheet->setCellValue('F' . $contador_fila_nuevo_edc, $factura->documento->total);
            $sheet->setCellValue('G' . $contador_fila_nuevo_edc, date("d/m/Y", strtotime($factura->documento->fecha . " +" . $dias_pago . " days")));
            $sheet->setCellValue('H' . $contador_fila_nuevo_edc, $total_resta_factura > 15 ? ($dias_transcurridos > 0 ? $dias_transcurridos : 0) : 0);
            $sheet->setCellValue('I' . $contador_fila_nuevo_edc, $total_resta_factura > 15 ? $total_resta_factura : 0);

            if ($total_resta_factura > 15 && $dias_transcurridos > 0) {
                $spreadsheet->getActiveSheet()->getStyle("H" . $contador_fila_nuevo_edc)->getFont()->setSize(12)->setBold(1)->getColor()->setARGB('FF1717');
            }

            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $spreadsheet->getActiveSheet()->getStyle("F" . $contador_fila_nuevo_edc)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc . ":N" . $contador_fila_nuevo_edc)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc . ":N" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $spreadsheet->getActiveSheet()->getStyle("G" . $contador_fila_nuevo_edc)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E7F023');
            $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila_nuevo_edc)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('E7F023');
            $spreadsheet->getActiveSheet()->getStyle("G" . $contador_fila_nuevo_edc)->getFont()->setBold(1);

            $sheet->setCellValue('N5', $total_resta_entidad_saldo_nuevo_edc);
            $sheet->setCellValue('N6', $total_resta_entidad_saldo_usd);
            $sheet->getStyle('B' . $contador_fila_nuevo_edc . ':N' . $contador_fila_nuevo_edc)->getAlignment()->setHorizontal('center');

            $contador_fila_nuevo_edc++;
            /* Termina nuevo estado de cuenta */
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url_pagos_pendientes);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        $response = json_decode(curl_exec($curl_handle));

        curl_close($curl_handle);

        $titulo = 'Clientes';

        $contador_fila = 5;
        // $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(4);
        $spreadsheet->getActiveSheet()->setTitle('PENDIENTES POR APLICAR');
        $total_por_aplicar = 0;

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('F1', "Ingresos pendientes por aplicar");
        $sheet->setCellValue('F2', $titulo);

        $spreadsheet->getActiveSheet()->getStyle('F1:F2')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('M3')->getFont()->setBold(1);

        # Cabecera
        $sheet->setCellValue('A4', 'FECHA');
        $sheet->setCellValue('B4', 'OPERACION');
        $sheet->setCellValue('C4', 'TIPO');
        $sheet->setCellValue('D4', 'DEPOSITADO');
        $sheet->setCellValue('E4', 'CUENTA');
        $sheet->setCellValue('F4', 'DESCRIPCIÓN');
        $sheet->setCellValue('G4', 'REFERENCIA');
        $sheet->setCellValue('H4', 'MONEDA');
        $sheet->setCellValue('I4', 'T.C');
        $sheet->setCellValue('J4', 'TOTAL');
        $sheet->setCellValue('K4', 'MONEDA NACIONAL MONTO APLICADO');
        $sheet->setCellValue('L4', 'MONEDA EXTRANJERA MONTO APLICADO');
        $sheet->setCellValue('M4', 'BALANCE');
        $sheet->setCellValue('N4', 'CLAVE RASTREO');
        $sheet->setCellValue('O4', 'AUTORIZACION');
        $sheet->setCellValue('P4', 'REFERENCIA');
        $sheet->setCellValue('Q4', 'ENTIDAD');

        $spreadsheet->getActiveSheet()->getStyle('A4:Q4')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        $sheet->freezePane("A5");

        if (!empty($response)) {
            foreach ($response as $pago) {
                $sheet->setCellValue('A' . $contador_fila, $pago->fecha);
                $sheet->setCellValue('B' . $contador_fila, $pago->operacionid);
                $sheet->setCellValue('C' . $contador_fila, $pago->modulo);
                $sheet->setCellValue('D' . $contador_fila, $pago->financialentitytype);
                $sheet->setCellValue('E' . $contador_fila, $pago->financialentity);
                $sheet->setCellValue('F' . $contador_fila, $pago->descripcion);
                $sheet->setCellValue('G' . $contador_fila, $pago->referencia);
                $sheet->setCellValue('H' . $contador_fila, $pago->moneda);
                $sheet->setCellValue('I' . $contador_fila, $pago->tc);
                $sheet->setCellValue('J' . $contador_fila, $pago->monto);
                $sheet->setCellValue('K' . $contador_fila, $pago->MNAplicado);
                $sheet->setCellValue('L' . $contador_fila, $pago->MEAplicado);
                $sheet->setCellValue('M' . $contador_fila, $pago->MNBalance);

                $existe_pago = DB::select("SELECT
                                                        documento_pago.referencia,
                                                        documento_pago.clave_rastreo,
                                                        documento_pago.autorizacion
                                                    FROM documento_pago
                                                    INNER JOIN empresa ON documento_pago.id_empresa = empresa.id
                                                    WHERE folio = '" . $pago->operacionid . "'
                                                    AND empresa.bd = '" . $empresa . "'");

                $sheet->setCellValue('N' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->clave_rastreo);
                $sheet->setCellValue('O' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->autorizacion);
                $sheet->setCellValue('P' . $contador_fila, empty($existe_pago) ? "NO EXISTE" : $existe_pago[0]->referencia);
                $sheet->setCellValue('Q' . $contador_fila, $pago->empresa);


                $spreadsheet->getActiveSheet()->getStyle("I" . $contador_fila . ":M" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $total_por_aplicar += $pago->MNBalance;

                $contador_fila++;
            }

            $sheet->setCellValue('M3', $total_por_aplicar);
            $spreadsheet->getActiveSheet()->getStyle("M3")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            foreach (range('A', 'P') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }

            $spreadsheet->getActiveSheet()->getColumnDimension("Q")->setAutoSize(true);
        }

        for ($i = 0; $i < 4; $i++) {
            $spreadsheet->setActiveSheetIndex($i);

            foreach (range('A', 'T') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }

            # Suma de resta de facturas
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue("L5", $i == 2 ? 0 : ($i == 0 ? $total_resta_entidad : $total_resta_entidad_saldo));
            $sheet->setCellValue("M5", $i == 2 ? 0 : ($i == 0 ? $total_resta_15_dias : $total_resta_15_dias_saldo));
            $sheet->setCellValue("N5", $i == 2 ? 0 : ($i == 0 ? $total_resta_30_dias : $total_resta_30_dias_saldo));
            $sheet->setCellValue("O5", $i == 2 ? 0 : ($i == 0 ? $total_resta_45_dias : $total_resta_45_dias_saldo));
            $sheet->setCellValue("P5", $i == 2 ? 0 : ($i == 0 ? $total_resta_60_dias : $total_resta_60_dias_saldo));
            $sheet->setCellValue("Q5", $i == 2 ? 0 : ($i == 0 ? $total_resta_75_dias : $total_resta_75_dias_saldo));

            $spreadsheet->getActiveSheet()->getStyle("L5:Q5")->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
        }

        $spreadsheet->setActiveSheetIndex(5);

        foreach (range('A', 'N') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $archivo = 'Reporte Estado Facturas Clientes ' . $data['nombre'] . '-' . $anio . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($archivo);

        $view = view('email.notificacion_reporte_factura')->with([
            "recurso" => 'Empresa: ' . $data['nombre'],
            "mensaje" => 'Tipo: Clientes',
            "anio" => date("Y")
        ])->render();

        $emails = ['cxc@omg.com.mx', 'sebastiancifer@gmail.com'];

        $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
        $domain = "omg.com.mx";
        $mg->sendMessage(
            $domain,
            array(
                'from' => 'CRM OMG International <crm@omg.com.mx>',
                'to' => $emails,
                'subject' => 'Reporte Estado Facturas Clientes ' . $data['nombre'] . '-' . $anio,
                'html' => $view
            ),
            array(
                'attachment' => array(
                    $archivo
                )
            )
        );

        unlink($archivo);
    }
}
