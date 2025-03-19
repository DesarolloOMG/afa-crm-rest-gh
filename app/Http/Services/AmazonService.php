<?php
/** @noinspection PhpDynamicFieldDeclarationInspection */
/** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection PhpUndefinedClassInspection */
/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Http\Services;

use Httpful\Request;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use DB;
use stdClass;

class AmazonService
{
    public static function venta($venta, $credenciales)
    {
        $response = new stdClass();
        $uri = '/Orders/2013-09-01';

        $parameters = array(
            'Action' => 'GetOrder',
            'AmazonOrderId.Id.1' => $venta,
            'SellerId' => $credenciales->extra_1,
            'SignatureVersion' => 2,
            'SignatureMethod' => 'HmacSHA256',
            'Version' => '2013-09-01'
        );

        $response_data = Request::post(self::create_signature($credenciales->app_id, $credenciales->secret, $parameters, $uri))->send();

        $orden = simplexml_load_string($response_data->raw_body);

        $parameters = array(
            'Action' => 'ListOrderItems',
            'SellerId' => $credenciales->extra_1,
            'AmazonOrderId' => $venta,
            'SignatureVersion' => 2,
            'SignatureMethod' => 'HmacSHA256',
            'Version' => '2013-09-01'
        );

        $response_data = Request::post(self::create_signature($credenciales->app_id, $credenciales->secret, $parameters, $uri))->send();

        $productos = simplexml_load_string($response_data->raw_body);

        $orden = (object)(array) $orden->GetOrderResult->Orders->Order;
        $orden->OrderItems = $productos->ListOrderItemsResult->OrderItems;

        $response->error = 0;
        $response->data = $orden;

        return $response;
    }

    public static function importarVentas($marketplace_id, $usuario, $ventas)
    {
        set_time_limit(0);
        LoggerService::writeLog('amazon', "Inicio de la importacion masiva");

        if (empty($ventas)) {
            $mensaje = "No se encontraron ventas en el archivo." . self::logVariableLocation();
            return self::errorResponse($mensaje);
        }

        $response = new stdClass();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $spreadsheet->getActiveSheet()->setTitle('REPORTE DE IMPORTACION');
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); # Alineación centrica

        $sheet->setCellValue('A1', 'FECHA');
        $sheet->setCellValue('B1', 'TIPO');
        $sheet->setCellValue('C1', 'VENTA');
        $sheet->setCellValue('D1', 'MENSAJE');
        $sheet->setCellValue('E1', 'DOCUMENTO');
        $sheet->setCellValue('F1', 'ALMACÉN');
        $sheet->setCellValue('G1', 'TIPO');
        $sheet->setCellValue('H1', 'ASIN');

        $fila = 2;

        foreach ($ventas as $venta) {
            $almacen = $venta->almacen;
            $fila++;

            $empresa = DB::select("SELECT
                                empresa.bd
                            FROM empresa_almacen
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            WHERE empresa_almacen.id = " . $almacen);

            if (empty($empresa)) {

                $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                $sheet->setCellValue('B' . $fila, 'ERROR');
                $sheet->setCellValue('C' . $fila, $venta->venta);
                $sheet->setCellValue('D' . $fila, "No se encontró la empresa del almacén seleccionado: " . $almacen);

                LoggerService::writeLog('amazon', 'Error Venta:'. $venta->venta ." No se encontró la empresa del almacén seleccionado: " . $almacen. ' ' . self::logVariableLocation());

                continue;
            }

            $almacen_nombre = DB::select("SELECT 
                                            almacen.almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = " . $almacen)[0]->almacen;

            $existe_venta = DB::select("SELECT id FROM documento WHERE no_venta = '" . TRIM($venta->venta) . "'");

            $sheet->setCellValue('E' . $fila, "N/A");
            $sheet->setCellValue('F' . $fila, $almacen_nombre);
            $sheet->setCellValue('G' . $fila, $venta->fulfillment ? 'Ful' : 'No Ful');
            $sheet->setCellValue('H' . $fila, $venta->asin);

            if (!empty($existe_venta)) {
                $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                $sheet->setCellValue('B' . $fila, 'ERROR');
                $sheet->setCellValue('C' . $fila, $venta->venta);
                $sheet->setCellValue('D' . $fila, "La venta ya se encuentra en el sistema");
                $sheet->setCellValue('E' . $fila, $existe_venta[0]->id);

                LoggerService::writeLog('amazon', 'Error Venta:'. $venta->venta ." La venta ya se encuentra en el sistema " .self::logVariableLocation());

                continue;
            }

            $existe_producto = DB::select("SELECT marketplace_publicacion_producto.*, modelo.sku
            FROM marketplace_publicacion_producto
            INNER JOIN marketplace_publicacion ON marketplace_publicacion_producto.id_publicacion = marketplace_publicacion.id
            INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
            WHERE marketplace_publicacion.publicacion_id = '" . TRIM($venta->asin) . "'");

            // $existe_producto = DB::select("SELECT id FROM modelo WHERE sku = '" . TRIM($venta->sku) . "'");

            if (empty($existe_producto)) {
                $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                $sheet->setCellValue('B' . $fila, 'ERROR');
                $sheet->setCellValue('C' . $fila, $venta->venta);
                $sheet->setCellValue('D' . $fila, "No se encontró el codigo " . $venta->asin);

                LoggerService::writeLog('amazon', 'Error Venta:'. $venta->venta ." No se encontró el codigo " . $venta->asin. ' ' . self::logVariableLocation());

                continue;
            }

            foreach ($existe_producto as $producto) {

                $existe_modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                if (empty($existe_modelo)) {

                    LoggerService::writeLog('amazon', "Error: No existe el producto en la bd " . $producto->sku . ' ' . self::logVariableLocation());

                    continue 2;
                }

                $existencia = DocumentoService::existenciaProducto(trim($existe_modelo->sku), $almacen);

                if ($existencia->error) {
                    $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                    $sheet->setCellValue('B' . $fila, 'ERROR');
                    $sheet->setCellValue('C' . $fila, $venta->venta);
                    $sheet->setCellValue('D' . $fila, $existencia->mensaje);

                    LoggerService::writeLog('t1', 'Error: ' . $existencia->mensaje . ' ' . self::logVariableLocation());

                    continue 2;
                }

                if ($existencia->existencia < (int) $venta->cantidad) {
                    $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                    $sheet->setCellValue('B' . $fila, 'ERROR');
                    $sheet->setCellValue('C' . $fila, $venta->venta);
                    $sheet->setCellValue('D' . $fila, "No hay suficiente existencia para procesar la venta, codigo del producto " . $producto->sku);

                    LoggerService::writeLog('amazon', "Error: No hay suficiente existencia para procesar la venta, codigo del producto " . $producto->sku . ' ' . self::logVariableLocation());

                    continue 2;
                }
            }

            try {
                DB::beginTransaction();

                $entidad = DB::table('documento_entidad')->insertGetId([
                    'razon_social'  => "PUBLICO GENERAL",
                    'rfc'           => mb_strtoupper('XAXX010101000', 'UTF-8'),
                    'telefono'      => '0',
                    'telefono_alt'  => '0',
                    'correo'        => '0'
                ]);

                $documento = DB::table('documento')->insertGetId([
                    'documento_extra' => '',
                    'id_periodo' => 1,
                    'id_cfdi' => 3,
                    'id_almacen_principal_empresa' => $almacen, # Almacén de amazon
                    'id_marketplace_area' => $marketplace_id,
                    'id_usuario' => $usuario,
                    'id_moneda' => 3,
                    'id_paqueteria' => $venta->paqueteria,
                    'id_fase' => $venta->fulfillment ? 6 : 1,
                    'no_venta' => $venta->venta,
                    'tipo_cambio' => 1,
                    'id_entidad' => $entidad,
                    'referencia' => $venta->referencia,
                    'observacion' => $venta->referencia,
                    'info_extra' => $venta->asin,
                    'fulfillment' => $venta->fulfillment,
                    'mkt_total' => $venta->total,
                    'mkt_fee' => (property_exists($venta, 'free')) ? $venta->fee : '0',
                    'mkt_coupon' => '0',
                    'mkt_shipping_total' => $venta->envio,
                    'mkt_created_at' => $venta->fecha,
                    'started_at' => date('Y-m-d H:i:s')
                ]);

                DB::table('seguimiento')->insert([
                    'id_documento'  => $documento,
                    'id_usuario'    => 1,
                    'seguimiento'   => "<p>VENTA IMPORTADA MASIVAMENTE</p"
                ]);
                
                DB::table('documento_direccion')->insert([
                    'id_documento'      => $documento,
                    'id_direccion_pro'  => ".",
                    'contacto'          => $venta->contacto,
                    'calle'             => $venta->calle,
                    'numero'            => ".",
                    'numero_int'        => ".",
                    'colonia'           => $venta->colonia,
                    'ciudad'            => $venta->ciudad,
                    'estado'            => $venta->estado,
                    'codigo_postal'     => $venta->codigo_postal,
                    'referencia'        => '',
                ]);

                foreach ($existe_producto as $producto) {

                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documento,
                        'id_modelo' => $producto->id_modelo,
                        'cantidad' => $venta->cantidad,
                        'precio' => ((float)$venta->total / $venta->cantidad / 1.16)*($producto->porcentaje / 100),
                        'garantia' => '90',
                        'modificacion' => '',
                        'regalo' => 0
                    ]);
                }

                if ($venta->fulfillment) {
                    //Aqui ta
                    $response = DocumentoService::crearFactura($documento, 0, 0);

                    //                $movimiento = DB::table('movimiento')->where('id_documento', $documento)->where('id_modelo', 11623)->first();
                    //                $documentoInfo = DB::table('documento')->where('id', $documento)->first();
                    //
                    //                if(!empty($movimiento)) {
                    //                    DB::table('modelo_inventario')->insert([
                    //                        'id_modelo' => 11623,
                    //                        'id_documento' => $documento,
                    //                        'id_almacen' => $documentoInfo->id_almacen_principal_empresa,
                    //                        'afecta_costo' => 0,
                    //                        'cantidad' => $movimiento->cantidad,
                    //                        'costo' => $movimiento->precio
                    //                    ]);
                    //
                    //                    $afecta_inventario = DB::table('modelo_costo')->where('id_modelo', 11623)->first();
                    //                    $resta_inventario = $afecta_inventario->stock - $movimiento->cantidad;
                    //
                    //                    DB::table('modelo_costo')->where(['id_modelo' => $afecta_inventario->id_modelo])->update([
                    //                        'stock' => $resta_inventario
                    //                    ]);
                    //                }

                    if ($response->error) {

                        $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
                        $sheet->setCellValue('B' . $fila, 'ERROR');
                        $sheet->setCellValue('C' . $fila, $venta->venta);
                        $sheet->setCellValue('D' . $fila, "No se pudo crear la factura en el ERP, mensaje de error: " . $response->mensaje);

                        LoggerService::writeLog('amazon', "Error: No se pudo crear la factura en el ERP, mensaje de error: " . $response->mensaje . ' ' . self::logVariableLocation());
                        DB::rollBack();

                        continue;
                    }
                }

                DB::commit();

            } catch (Exception $e) {
                DB::rollBack();
                return self::errorResponse("Hubo un problema en la transacción ".self::logVariableLocation() . ' ' . $e->getMessage());
            }

            $sheet->setCellValue('A' . $fila, date('Y-m-d H:i:s'));
            $sheet->setCellValue('B' . $fila, 'CORRECTO');
            $sheet->setCellValue('C' . $fila, $venta->venta);
            $sheet->setCellValue('D' . $fila, "Venta importada correctamente");
            $sheet->setCellValue('E' . $fila, $documento);
        }

        foreach (range('A', 'H') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $archivo = 'reporte/importacion/amazon/REPORTE DE IMPORTACION ' . date('d.m.y H.i.s') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($archivo);

        $excel = base64_encode(file_get_contents($archivo));

        unlink($archivo);

        $response->error = 0;
        $response->excel = $excel;
        $response->archivo = 'REPORTE DE IMPORTACION ' . date('d.m.y H.i.s') . '.xlsx';
        $response->mensaje = "Importación terminada";

        LoggerService::writeLog('amazon', "Fin de la importacion masiva");

        return $response;
    }

//    public static function importarVentasWeb($marketplace_id)
//    {
//        set_time_limit(0);
//
//        $response = new \stdClass();
//        $response->error = 1;
//
//        if (!file_exists("logs")) {
//            mkdir("logs", 777);
//            mkdir("logs/amazon", 777);
//        }
//
//        $credenciales = DB::table("marketplace_api")
//            ->select("extra_1", "extra_2", "app_id", "secret")
//            ->where("id_marketplace_area", $marketplace_id)
//            ->first();
//
//        if (empty($credenciales)) {
//            $response->mensaje = "No se encontró información de las credenciales de la cuenta, favor de contactar a un administrador." . self::logVariableLocation();
//
//            return $response;
//        }
//
//        $uri = '/Orders/2013-09-01';
//
//        $parameters = array(
//            'Action' => 'ListOrders',
//            'FulfillmentChannel.Channel.1' => 'MFN',
//            'OrderStatus.Status.1' => 'Unshipped',
//            'MarketplaceId.Id.1' => $credenciales->extra_2,
//            'CreatedAfter' => date("Y-m-d\T00:00:00", strtotime("-7 days")),
//            'SellerId' => $credenciales->extra_1,
//            'SignatureVersion' => 2,
//            'SignatureMethod' => 'HmacSHA256',
//            'Version' => '2013-09-01',
//        );
//
//        $response = \Httpful\Request::post(self::create_signature($credenciales->app_id, $credenciales->secret, $parameters, $uri))->send();
//
//        $list = simplexml_load_string($response->raw_body, 'SimpleXMLElement', LIBXML_NOCDATA);
//        $list = json_decode(json_encode($list));
//
//        if (property_exists($list, "Error")) {
//            $response->mensaje = $list->Error->Message . "" . self::logVariableLocation();
//
//            return $response;
//        }
//
//        $ventas = $list->ListOrdersResult->Orders->Order;
//
//        foreach ($ventas as $venta) {
//            $parameters = array(
//                'Action' => 'ListOrderItems',
//                'SellerId' => $credenciales->extra_1,
//                'AmazonOrderId' => $venta->AmazonOrderId,
//                'SignatureVersion' => 2,
//                'SignatureMethod' => 'HmacSHA256',
//                'Version' => '2013-09-01'
//            );
//
//            $response = \Httpful\Request::post(self::create_signature($credenciales->app_id, $credenciales->secret, $parameters, $uri))->send();
//
//            $productos = simplexml_load_string($response->raw_body, 'SimpleXMLElement', LIBXML_NOCDATA);
//            $productos = json_decode(json_encode($productos));
//
//            $venta->OrderItems = is_array($productos->ListOrderItemsResult->OrderItems) ? $productos->ListOrderItemsResult->OrderItems : [$productos->ListOrderItemsResult->OrderItems->OrderItem];
//        }
//
//        foreach ($ventas as $venta) {
//            # Se busca si la venta ya existe registrada
//            $existe = DB::table("documento")
//                ->where("no_venta", $venta->AmazonOrderId)
//                ->where("status", 1)
//                ->first();
//
//            if (!empty($existe)) {
//                $venta->error = 1;
//                $venta->mensaje = "La venta ya existe registrada en el sistema." . self::logVariableLocation();
//
//                file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La venta " . $venta->AmazonOrderId . " ya existe registrada en el sistema." . PHP_EOL, FILE_APPEND);
//
//                continue;
//            }
//
//            foreach ($venta->OrderItems as $Item) {
//                # Se busca la publicación registrada en el sistema
//                $existe_publicacion = DB::table("marketplace_publicacion")
//                    ->select("marketplace_publicacion.id", "marketplace_publicacion.id_almacen_empresa", "marketplace_publicacion.id_almacen_empresa_fulfillment", "marketplace_publicacion.id_proveedor", "modelo_proveedor.razon_social", "empresa.bd")
//                    ->selectRaw("empresa_almacen.id_erp AS id_almacen")
//                    ->join("modelo_proveedor", "marketplace_publicacion.id_proveedor", "=", "modelo_proveedor.id")
//                    ->join("empresa_almacen", "marketplace_publicacion.id_almacen_empresa", "=", "empresa_almacen.id")
//                    ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
//                    ->where("marketplace_publicacion.publicacion_id", $Item->SellerSKU)
//                    ->first();
//
//                if (empty($existe_publicacion)) {
//                    $venta->error = 1;
//                    $venta->mensaje = "No se encontró la publicación de la venta " . $venta->AmazonOrderId . " registrada en el sistema, por lo tanto, no hay relación de productos" . self::logVariableLocation();
//
//                    file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: No se encontró la publicación de la venta " . $venta->AmazonOrderId . " registrada en el sistema, por lo tanto, no hay relación de productos" . $Item->SellerSKU . PHP_EOL, FILE_APPEND);
//
//                    continue 2;
//                }
//                # Productos por cada publicación
//                $productos_publicacion = DB::table("marketplace_publicacion_producto")
//                    ->where("id_publicacion", $existe_publicacion->id)
//                    ->first();
//
//                if (empty($productos_publicacion)) {
//                    $venta->error = 1;
//                    $venta->mensaje = "No hay relación entre productos y la publicación " . $Item->SellerSKU . " en la venta " . $venta->AmazonOrderId . self::logVariableLocation();
//                    $venta->seguimiento = "No hay relación entre productos y la publicación " . $Item->SellerSKU . " en la venta " . $venta->AmazonOrderId;
//
//                    file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: No hay relación entre productos y la publicación " . $Item->SellerSKU . " en la venta " . $venta->AmazonOrderId . PHP_EOL, FILE_APPEND);
//
//                    continue 2;
//                }
//
//                $productos_secundarios = 0;
//                $productos_principales = 0;
//                # Se saca el calculo de cuantos productos son principales y cuales regalo (solo puede haber uno principal)
//                foreach ($productos_publicacion as $producto) {
//                    if ($producto->regalo) {
//                        $productos_secundarios++;
//                    } else {
//                        $productos_principales++;
//                    }
//                }
//
//                if ($productos_principales > 1) {
//                    $venta->error = 1;
//                    $venta->mensaje = "La publicación " . $Item->SellerSKU . " no puede tener más de un producto principal" . self::logVariableLocation();
//
//                    file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La publicación " . $Item->SellerSKU . " no puede tener más de un producto principal." . PHP_EOL, FILE_APPEND);
//
//                    continue 2;
//                }
//
//                if ($productos_principales < 1) {
//                    $venta->error = 1;
//                    $venta->mensaje = "La publicación " . $Item->SellerSKU . " debe tener al menos un producto principal" . self::logVariableLocation();
//
//                    file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: La publicación " . $Item->SellerSKU . " debe tener al menos un producto principal." . PHP_EOL, FILE_APPEND);
//
//                    continue 2;
//                }
//
//                # La publicacion es dropshipping, y no se checa existencias
//                if ($existe_publicacion->id_proveedor == 0) {
//                    foreach ($productos_publicacion as $producto) {
//                        $producto->precio = $producto->regalo ? 1 : round(((float) $Item->ItemPrice->Amount - $productos_secundarios) / (int) $producto->cantidad, 2);
//                        $producto->cantidad = $producto->cantidad * $Item->QuantityOrdered;
//
//                        $producto_sku = DB::table("modelo")
//                            ->select("sku")
//                            ->where("id", $producto->id_modelo)
//                            ->first();
//
//                        $existencia = DocumentoService::existenciaProducto($producto_sku->sku, $pack->venta_principal->almacen);
//
//                        if ($existencia->error) {
//                            $venta->error = 1;
//                            $venta->seguimiento = "Ocurrió un error al buscar la existencia del producto " . $producto_sku->sku . " en la venta " . $venta->AmazonOrderId . ", mensaje de error: " . $existencia->mensaje . self::logVariableLocation();
//
//                            file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: Ocurrió un error al buscar la existencia del producto " . $producto_sku->sku . " en la venta " . $venta->AmazonOrderId . ", mensaje de error: " . $existencia->mensaje . PHP_EOL, FILE_APPEND);
//
//                            continue 3;
//                        }
//
//                        if ((int) $existencia->existencia < (int) $producto->cantidad) {
//                            $venta->error = 1;
//                            $venta->seguimiento = "No hay suficiente existencia para procesar la venta " . $venta->AmazonOrderId . " en el almacén " . $existe_publicacion->id_almacen_empresa . " del producto " . $producto_sku->sku . self::logVariableLocation();
//
//                            file_put_contents("logs/amazon/" . date("Y.m.d") . ".log", date("H:i:s") . " Error: No hay suficiente existencia para procesar la venta " . $venta->AmazonOrderId . " en el almacén " . $existe_publicacion->id_almacen_empresa . " del producto " . $producto_sku->sku . PHP_EOL, FILE_APPEND);
//
//                            continue 3;
//                        }
//                    }
//                }
//
//                $venta->productos = $productos_publicacion;
//            }
//        }
//
//        return $ventas;
//    }

    public static function actualizarPublicaciones($marketplace_id)
    {
        set_time_limit(0);

        $response = new stdClass();
        $uri = '/Reports/2009-01-01';

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        extra_1,
                                        extra_2,
                                        app_id,
                                        secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id);

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información de la API del marketplace con el ID " . $marketplace_id . self::logVariableLocation();

            return $response;
        }

        $marketplace = $marketplace[0];

        $parameters = array(
            'Action' => 'RequestReport',
            'Marketplace' => $marketplace->extra_2,
            'ReportType' => '_GET_MERCHANT_LISTINGS_ALL_DATA_',
            'SellerId' => $marketplace->extra_1,
            'SignatureVersion' => 2,
            'SignatureMethod' => 'HmacSHA256',
            'Version' => '2009-01-01'
        );

        $response = Request::post(self::create_signature($marketplace->app_id, $marketplace->secret, $parameters, $uri))->send();

        $response = simplexml_load_string($response->raw_body);

        $request_id = $response->RequestReportResult->ReportRequestInfo->ReportRequestId;

        $parameters = array(
            'Action' => 'GetReportRequestList',
            'Marketplace' => $marketplace->extra_2,
            'ReportProcessingStatusList.Status.1' => '_DONE_',
            'ReportRequestIdList.Id.1' => $request_id,
            'ReportTypeList.Type.1' => '_GET_MERCHANT_LISTINGS_ALL_DATA_',
            'SellerId' => $marketplace->extra_1,
            'SignatureVersion' => 2,
            'SignatureMethod' => 'HmacSHA256',
            'Version' => '2009-01-01'
        );

        $response = Request::post(self::create_signature($marketplace->app_id, $marketplace->secret, $parameters, $uri))->send();

        $response = simplexml_load_string($response->raw_body);

        $status = $response->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus;

        while ($status != '_DONE_') {
            $response = Request::post(self::create_signature($marketplace->app_id, $marketplace->secret, $parameters, $uri))->send();

            $response = simplexml_load_string($response->raw_body);

            $status = $response->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus;

            sleep(60);
        }

        if (property_exists($response->GetReportRequestListResult->ReportRequestInfo, "GeneratedReportId")) {
            $report_id = $response->GetReportRequestListResult->ReportRequestInfo->GeneratedReportId;
        } else {
            $parameters = array(
                'Action' => 'GetReportList',
                'Marketplace' => $marketplace->extra_2,
                'ReportProcessingStatusList.Status.1' => '_DONE_',
                'ReportRequestIdList.Id.1' => $request_id,
                'ReportTypeList.Type.1' => '_GET_MERCHANT_LISTINGS_ALL_DATA_',
                'SellerId' => $marketplace->extra_1,
                'SignatureVersion' => 2,
                'SignatureMethod' => 'HmacSHA256',
                'Version' => '2009-01-01'
            );

            $response = Request::post(self::create_signature($marketplace->app_id, $marketplace->secret, $parameters, $uri))->send();

            $response = simplexml_load_string($response->raw_body);

            $report_id = $response->GetReportListResult->ReportInfo->ReportId;
        }

        $parameters = array(
            'Action' => 'GetReport',
            'Marketplace' => $marketplace->extra_2,
            'ReportId' => $report_id,
            'SellerId' => $marketplace->extra_1,
            'SignatureVersion' => 2,
            'SignatureMethod' => 'HmacSHA256',
            'Version' => '2009-01-01'
        );

        $response = Request::post(self::create_signature($marketplace->app_id, $marketplace->secret, $parameters, $uri))->send();

        $array = explode("\n", $response->raw_body);

        foreach ($array as $k => $data) {
            $array[$k] = preg_split("/	/", $data);
        }

        if (!empty($array)) {
            array_shift($array);
            array_pop($array);

            foreach ($array as $publicacion) {
                $existe_publicacion = DB::select("SELECT id FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion[3] . "'");

                if (empty($existe_publicacion)) {
                    DB::table('marketplace_publicacion')->insert([
                        'id_marketplace_area' => $marketplace->id,
                        'publicacion_id' => trim($publicacion[3]),
                        'publicacion_sku' => trim($publicacion[3]),
                        'publicacion' => $publicacion[0],
                        'status' => $publicacion[29] == 'Active' ? 'active' : 'inactive'
                    ]);
                } else {
                    DB::table('marketplace_publicacion')->where(['publicacion_id' => trim($publicacion[3])])->update([
                        'publicacion' => $publicacion[0],
                        'publicacion_sku' => trim($publicacion[3]),
                        'status' => 'active'
                    ]);
                }
            }
        }

        $response->error = 0;

        return $response;
    }

//    public static function buscarEnvio($envio, $marketplace)
//    {
//        set_time_limit(0);
//
//        $response = new \stdClass();
//        $response->error = 1;
//
//        if (!file_exists("logs")) {
//            mkdir("logs", 777);
//            mkdir("logs/amazon", 777);
//        }
//
//        $credenciales = DB::table("marketplace_api")
//            ->select("extra_1", "extra_2", "app_id", "secret")
//            ->where("id_marketplace_area", $marketplace)
//            ->first();
//
//        if (empty($credenciales)) {
//            $response->mensaje = "No se encontró información de las credenciales de la cuenta, favor de contactar a un administrador." . self::logVariableLocation();
//
//            return $response;
//        }
//
//        $uri = '/FulfillmentInboundShipment/2010-10-01';
//        $now = new DateTime();
//
//        $parameters = array(
//            'Action' => 'ListInboundShipmentItems',
//            'ShipmentId' => $envio,
//            'SellerId' => $credenciales->extra_1,
//            'SignatureVersion' => 2,
//            'SignatureMethod' => 'HmacSHA256',
//            'Version' => '2010-10-01',
//        );
//
//        $list = \Httpful\Request::post(self::create_signature($credenciales->app_id, $credenciales->secret, $parameters, $uri))->send();
//
//        $list = simplexml_load_string($list->raw_body, 'SimpleXMLElement', LIBXML_NOCDATA);
//        $list = json_decode(json_encode($list));
//
//        if (property_exists($list, "Error")) {
//            $response->mensaje = $list->Error->Message . "" . self::logVariableLocation();
//
//            return $response;
//        }
//
//        foreach ($list->ListInboundShipmentItemsResult->ItemData->member as $Member) {
//            $descripcion = DB::table("marketplace_publicacion")
//                ->select("publicacion")
//                ->where("publicacion_id", $Member->SellerSKU)
//                ->first();
//
//            $Member->Description = empty($descripcion) ? "" : $descripcion->publicacion;
//        }
//
//        $response->error = 0;
//        $response->data = $list->ListInboundShipmentItemsResult->ItemData->member;
//
//        return $response;
//    }

    private static function create_signature($key_id, $secret, $params, $uri)
    {
        // some paramters
        $method = 'POST';
        $host = 'mws.amazonservices.com';

        // additional parameters
        $params['AWSAccessKeyId'] = $key_id;
        // GMT timestamp
        $params['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');

        // sort the parameters
        ksort($params);

        // create the canonicalized query
        $canonicalized_query = array();

        foreach ($params as $param => $value) {
            $param = str_replace('%7E', '~', rawurlencode($param));
            $value = str_replace('%7E', '~', rawurlencode($value));
            $canonicalized_query[] = $param . '=' . $value;
        }

        $canonicalized_query = implode('&', $canonicalized_query);

        // create the string to sign
        $string_to_sign = $method . "\n" . $host . "\n" . $uri . "\n" . $canonicalized_query;

        // calculate HMAC with SHA256 and base64-encoding
        $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $secret, TRUE));

        // encode the signature for the request
        $signature = str_replace('%7E', '~', rawurlencode($signature));

        // create request
        return "https://" . $host . $uri . '?' . $canonicalized_query . '&Signature=' . $signature;
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'AC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'ZON'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }
    private static function errorResponse($mensaje)
    {
        LoggerService::writeLog('amazon', $mensaje);

        $response = new stdClass();
        $response->error = 1;
        $response->mensaje = 'Error '.$mensaje .' Terminacion del proceso por error.';

        return $response;
    }

}
