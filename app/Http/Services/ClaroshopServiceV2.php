<?php /** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/** @noinspection PhpUndefinedClassInspection */

namespace App\Http\Services;

use Exception;
use DB;
use Httpful\Mime;
use Httpful\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Carbon;

use stdClass;


class ClaroshopServiceV2
{
    private static $urlToken = 'https://loginclaro.com/auth/realms/plataforma-claro/protocol/openid-connect/token';
    private static $urlBase = 'https://pcapi.plataforma-claro.com/kidal/v1/';

    private static function logVariableLocation()
    {
        $sis = 'BE'; //Front o Back
        $ini = 'CS'; //Primera letra del Controlador y Letra de la segunda Palabra: Controller, service
        $fin = 'EV2'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    private static function errorResponse($mensaje, $linea)
    {
        LoggerService::writeLog('t1', $mensaje . ' ' . $linea);
        $response = new stdClass();
        $response->error = 1;
        $response->mensaje = $mensaje . ' ' . $linea;
        return $response;
    }

    private static function token($credenciales)
    {
        $datetime = Carbon::now();
        $now = $datetime->toDateTimeString();
        $oneHourLater = $datetime->copy()->addHour()->toDateTimeString();

        $existe = DB::table('marketplace_api')
            ->select('token')
            ->where('app_id', $credenciales->app_id)
            ->where('secret', $credenciales->secret)
            ->where('time_token_created_at', '<=', $now)
            ->where('time_token_expired_at', '>=', $now)
            ->where('token', '!=', 'N/A')
            ->first();

        if (empty($existe)) {
            try {
                $decoded_secret_key = Crypt::decrypt($credenciales->secret);
            } catch (DecryptException $e) {
                $decoded_secret_key = "";
            }

            $url = self::$urlToken;

            $postData = [
                'client_id' => $credenciales->extra_1,
                'grant_type' => $credenciales->extra_2,
                'username' => $credenciales->app_id,
                'password' => $decoded_secret_key,
            ];

            $response = Request::post($url)
                ->sendsType(Mime::FORM)
                ->expectsJson()
                ->body(http_build_query($postData))
                ->send();

            $decodedResponse = $response->body;

            $access_token = $decodedResponse->access_token;

            DB::table("marketplace_api")->where(["app_id" => $credenciales->app_id])->update([
                "token" => $access_token,
                "token_created_at" => $now,
                "time_token_created_at" => $now,
                "token_expired_at" => $oneHourLater,
                "time_token_expired_at" => $oneHourLater
            ]);

            return $access_token;
        }
        return $existe->token;
    }

    public static function venta($pedido, $credenciales)
    {
        $response = new stdClass();
        //!! RELEASE T1 reempalzar

        // $marketplaceMap =
        // [
        //     'SR' => 'TESTSEARS',
        //     'SN' => 'TESTSANBORNS',
        //     'CS' => 'TESTCLAROSHOP'
        // ];
        $marketplaceMap =
            [
                'SR' => 'SEARS',
                'SN' => 'SANBORNS',
                'CS' => 'CLAROSHOP'
            ];

        $mp = DB::table('marketplace_area')
            ->select('marketplace.marketplace')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->where('marketplace_area.id', '=', $credenciales->id)
            ->first();

        if (!$mp) {
            return self::errorResponse("No se encontró el marketplace relacionado a las credenciales.", self::logVariableLocation());
        }

        $url = self::$urlBase;
        $token = self::token($credenciales);
        $idseller = explode('_', $credenciales->app_id)[1] ?? '';
        $url .= 'Ordersfull/seller/' . $idseller . '?filter-orderId=' . $pedido;

        $informacion = Request::get($url)
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->send();

        $data = $informacion->body->data[0] ?? null;
        if (!$data) {
            return self::errorResponse("No fue posible obtener información de la venta en " . $mp->marketplace . ".", self::logVariableLocation());
        }

        $marketplaceCode = $data->marketplace ?? null;
        if (!$marketplaceCode || !array_key_exists($marketplaceCode, $marketplaceMap)) {
            return self::errorResponse("No fue posible obtener información de la venta.", self::logVariableLocation());
        }

        if ($marketplaceMap[$marketplaceCode] !== $mp->marketplace) {
            return self::errorResponse("No fue posible obtener información de la venta en " . $mp->marketplace . '.', self::logVariableLocation());
        }

        $data->shippingAddress->street .=
            (!empty($data->shippingAddress->interiorNumber) ? ' num int. ' . $data->shippingAddress->interiorNumber : '') .
            (!empty($data->shippingAddress->outdoorNumber) ? ', num ext. ' . $data->shippingAddress->outdoorNumber : '');

        $uniqueProducts = [];

        foreach ($data->orderedProductsList as $producto) {
            $colocationId = $producto->colocationId;
            $uniqueProducts[$colocationId] = $producto;
        }
        $data->orderedProductsList = array_values($uniqueProducts);

        $response->error = 0;
        $response->data = $data;
        return $response;
    }

    public static function documento($pedido, $credenciales, $paqueteria = "DHL")
    {
        set_time_limit(0);

        $response = new stdClass();

        $token = self::token($credenciales);
        $informacionResponse = self::venta($pedido, $credenciales);

        if ($informacionResponse->error) {
            return $informacionResponse;
        }

        $informacion = $informacionResponse->data;

        $id_relacion = array_column($informacion->orderedProductsList, 'colocationId');

        $guia = $informacion->orderedProductsList[0]->deliveryTrackId ?? '';


        if (empty($guia)) {

            LoggerService::writeLog('t1', "El pedido no tiene guía asignada todavía, se intentará generar. .".self::logVariableLocation());

            $url = self::$urlBase;
            $seller = '/seller/' . explode('_', $credenciales->app_id)[1] ?? '';
            $marketplace = '/marketplace/' . $informacion->marketplace;
            $order = '/order/' . $pedido;

            $url .= 'order' . $seller . $marketplace . $order . '/shipment';

            $postData = [
                "kind" => "automatica",
                "carrier" => $paqueteria,
                "colocations" => $id_relacion,
                "trackId" => ""
            ];

            Request::post($url)
                ->addHeaders([
                    'Accept' => '*/*',
                    'Authorization' => 'Bearer ' . $token
                ])
                ->sendsJson()
                ->body(json_encode($postData))
                ->send();

            sleep(5);

            $token = self::token($credenciales);
            $informacionResponse = self::venta($pedido, $credenciales);

            if ($informacionResponse->error) {
                return $informacionResponse;
            }

            $informacion = $informacionResponse->data;

            $guia = $informacion->orderedProductsList[0]->deliveryTrackId ?? '';

            if (empty($guia)) {
                LoggerService::writeLog('t1', "No fue posible obtener los documentos de embarque de la venta (paso 1) " . $pedido.' '.self::logVariableLocation());
            }

            sleep(2);
        }

        if (empty($guia)) {
            $token = self::token($credenciales);
            $informacionResponse = self::venta($pedido, $credenciales);

            if ($informacionResponse->error) {
                return $informacionResponse;
            }

            $informacion = $informacionResponse->data;

            $guia = $informacion->orderedProductsList[0]->deliveryTrackId ?? '';

            if (empty($guia)) {
                LoggerService::writeLog('t1', "No fue posible obtener los documentos de embarque de la venta (paso 2) " . $pedido.' '.self::logVariableLocation());
            }

            sleep(5);
        }

        $url = self::$urlBase;
        $seller = '/seller/' . explode('_', $credenciales->app_id)[1] ?? '';
        $marketplace = '/marketplace/' . $informacion->marketplace;
        $order = '/order/' . $pedido;
        $shipping_label = '/shipping_label/' . $guia;

        $url .= 'order' . $seller . $marketplace . $order . $shipping_label;

        $documento = Request::get($url)
            ->addHeaders([
                'Accept' => '*/*',
                'Authorization' => 'Bearer ' . $token
            ])
            ->send();

        if (empty($documento)) {
            return self::errorResponse("Hubo un error al obtener los documentos de embarque de la venta " . $pedido, self::logVariableLocation());
        }

        $response->error = 0;
        $response->file = base64_encode($documento);
        $response->pdf = 1;

        return $response;
    }

    public static function importarVentasMasiva($marketplace_id, $usuario, $empresa_almacen, $empresa)
    {
        set_time_limit(0);
        $response = new stdClass();
        //!! RELEASE T1 reempalzar

        // $marketplaceMap = [
        //     'TESTSEARS' => 'SR',
        //     'TESTSANBORNS' => 'SN',
        //     'TESTCLAROSHOP' => 'CS'
        // ];
        $marketplaceMap = [
            'SEARS' => 'SR',
            'SANBORNS' => 'SN',
            'CLAROSHOP' => 'CS'
        ];

        $credenciales = DB::select("SELECT
                                    marketplace_area.id,
                                    marketplace_api.app_id,
                                    marketplace_api.secret,
                                    marketplace_api.extra_2,
                                    marketplace_api.extra_1,
                                    marketplace.marketplace
                                FROM marketplace_area
                                INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE marketplace_area.id = ?", [$marketplace_id])[0];

        $url = self::$urlBase;
        $token = self::token($credenciales);
        $idseller = explode('_', $credenciales->app_id)[1] ?? '';
        $url .= 'Ordersfull/seller/' . $idseller;

        $informacion = Request::get($url)
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer ' . $token)
            ->send();

        $data = $informacion->body->data ?? null;

        if (!$data) {
            return self::errorResponse("No fue posible obtener información de las ventas en " . $credenciales->marketplace . ".", self::logVariableLocation());
        }

        if (isset($marketplaceMap[$credenciales->marketplace])) {
            $marketplaceCode = $marketplaceMap[$credenciales->marketplace];
        } else {
            return self::errorResponse("No se encontró el marketplace relacionado a las credenciales: " . $credenciales->marketplace, self::logVariableLocation());
        }

        $filteredOrders = array_filter($data, function ($order) use ($marketplaceCode) {
            return $order->marketplace === $marketplaceCode;
        });

        if (empty($filteredOrders)) {
            return self::errorResponse('No hay ventas para importar en ' . $credenciales->marketplace, self::logVariableLocation());
        }

        $orderIds = array_column($filteredOrders, 'orderid');

        $ventasExistentes = DB::table('documento')
            ->whereIn('no_venta', $orderIds)
            ->pluck('id', 'no_venta')
            ->toArray();

        $finalOrders = [];
        foreach ($filteredOrders as $pedido) {
            if (isset($ventasExistentes[$pedido->orderid])) {
                LoggerService::writeLog('t1', "Error: Ya existe la venta " . $pedido->orderid . " registrada en el sistema, pedido: " . $ventasExistentes[$pedido->orderid] . " " . self::logVariableLocation());
                continue;
            }

            $checkResponse = self::venta($pedido->orderid, $credenciales);
            if ($checkResponse->error) {
                return $checkResponse;
            }

            $check = $checkResponse->data;
            if ($check->orderedProductsList[0]->colocationStatus->id != 1) {
                LoggerService::writeLog('t1', "Error: La venta " . $pedido->orderid . " está en status: " . $check->orderedProductsList[0]->colocationStatus->name . " " . self::logVariableLocation());
                continue;
            }

            $finalOrders[] = $pedido;
        }

        if (empty($finalOrders)) {
            return self::errorResponse('No hay ventas para importar en ' . $credenciales->marketplace, self::logVariableLocation());
        }

        try {
            foreach ($finalOrders as $pedido) {

                DB::beginTransaction();

                $productosVenta = $pedido->orderedProductsList;
                $direccionVenta = $pedido->shippingAddress;
                $pagosVenta = $pedido->paymentData;
                $fulfillment = $productosVenta[0]->deliveryType == 'fulfillment' ? 1 : 0;
                $paqueteriaVenta = $fulfillment ?  DB::table('paqueteria')->where('paqueteria', $productosVenta[0]->deliveryCarrierName)->first() : 2;

                list($date, $time) = explode('T', $pedido->purchase_date);
                $time = explode('.', $time)[0];
                $formattedDate = $date . ' ' . $time;

                $entidad = DB::table('documento_entidad')->insertGetId([
                    'razon_social'  => mb_strtoupper($direccionVenta->addressee, 'UTF-8'),
                    'rfc'           => mb_strtoupper('XAXX010101000', 'UTF-8'),
                    'telefono'      => '0',
                    'telefono_alt'  => '0',
                    'correo'        => $empresa == 7 ? 'm.guerrero@omg.com.mx' : 'isabel@arome.mx',
                    'info_extra'    => 'Pedido Claro'
                ]);

                $documento = DB::table('documento')->insertGetId([
                    'documento_extra' => 'N/A',
                    'id_periodo' => 1,
                    'id_cfdi' => 3,
                    'id_almacen_principal_empresa' => $empresa_almacen,
                    'id_almacen_secundario_empresa' => 0,
                    'id_marketplace_area' => $marketplace_id,
                    'id_usuario' => $usuario,
                    'id_moneda' => 3,
                    'id_paqueteria' => $paqueteriaVenta->id ?? 2,
                    'id_fase' => $fulfillment ? 6 : 1,
                    'id_modelo_proveedor' => 0,
                    'id_entidad' => $entidad,
                    'no_venta' => $pedido->orderid,
                    'tipo_cambio' => 1,
                    'referencia' => $pedido->orderid,
                    'observacion' => 'Pedido Importado Claroshop V2',
                    'info_extra' => "N/A",
                    'fulfillment' => $fulfillment,
                    'mkt_total' => $pagosVenta->total,
                    'mkt_fee' => $pagosVenta->commissions ?? 0,
                    'mkt_coupon' => $pagosVenta->couponAmount ?? 0,
                    'mkt_shipping_total' => $pagosVenta->shippingCosts ?? 0,
                    'mkt_created_at' => $formattedDate,
                    'started_at' => date('Y-m-d H:i:s')
                ]);

                $pago = DB::table('documento_pago')->insertGetId([
                    'id_usuario' => 1,
                    'id_metodopago' => 31,
                    'id_vertical' => 0,
                    'id_categoria' => 0,
                    'id_clasificacion' => 0,
                    'tipo' => 1,
                    'origen_importe' => 0,
                    'destino_importe' => $pagosVenta->total,
                    'folio' => "",
                    'entidad_origen' => 1,
                    'origen_entidad' => 'XAXX010101000',
                    'entidad_destino' => "",
                    'destino_entidad' => '',
                    'referencia' => 'Pedido Claro',
                    'clave_rastreo' => '',
                    'autorizacion' => '',
                    'destino_fecha_operacion' => date('Y-m-d'),
                    'destino_fecha_afectacion' => '',
                    'cuenta_cliente' => ''
                ]);

                DB::table('documento_pago_re')->insert([
                    'id_documento'  => $documento,
                    'id_pago'       => $pago
                ]);

                DB::table('seguimiento')->insert([
                    'id_documento'  => $documento,
                    'id_usuario'    => 1,
                    'seguimiento'   => "<p>VENTA IMPORTADA MASIVAMENTE T1 API V2</p"
                ]);

                try {
                    $direccion = Request::get(config("webservice.url") . 'Consultas/CP/' . $direccionVenta->zipCode)->send();

                    $direccion = json_decode($direccion->raw_body);

                    if ($direccion->code == 200) {
                        $estado             = $direccion->estado[0]->estado;
                        $ciudad             = $direccion->municipio[0]->municipio;
                        $colonia            = "";
                        $id_direccion_pro   = "";

                        foreach ($direccion->colonia as $colonia_text) {
                            if (strtolower($colonia_text->colonia) == strtolower($direccionVenta->suburb)) {
                                $colonia            = $colonia_text->colonia;
                                $id_direccion_pro   = $colonia_text->codigo;
                            }
                        }
                    } else {
                        $estado             = $direccionVenta->state;
                        $ciudad             = $direccionVenta->city;
                        $colonia            = $direccionVenta->suburb;
                        $id_direccion_pro   = "";
                    }
                } catch (Exception $e) {
                    $estado             = $direccionVenta->state;
                    $ciudad             = $direccionVenta->city;
                    $colonia            = $direccionVenta->suburb;
                    $id_direccion_pro   = "";
                }

                $direccionV = $direccionVenta->street .
                    (!empty($direccionVenta->interiorNumber) ? ' num int. ' . $direccionVenta->interiorNumber : '') .
                    (!empty($direccionVenta->outdoorNumber) ? ', num ext. ' . $direccionVenta->outdoorNumber : '');

                DB::table('documento_direccion')->insert([
                    'id_documento'      => $documento,
                    'id_direccion_pro'  => $id_direccion_pro,
                    'contacto'          => mb_strtoupper($direccionVenta->addressee, 'UTF-8'),
                    'calle'             => mb_strtoupper($direccionV, 'UTF-8'),
                    'numero'            => '',
                    'numero_int'        => '',
                    'colonia'           => $colonia,
                    'ciudad'            => $ciudad,
                    'estado'            => $estado,
                    'codigo_postal'     => mb_strtoupper($direccionVenta->zipCode, 'UTF-8'),
                    'referencia'        => mb_strtoupper($direccionVenta->betweenStreets, 'UTF-8'),
                ]);

                //!PENDIENTE PUBLICACIONES
                foreach ($productosVenta as $producto) {

                    $existe_modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                    if (empty($existe_modelo)) {

                        LoggerService::writeLog('t1', "Error: No existe el producto en la bd " . $producto->sku . ' ' . self::logVariableLocation());

                        DB::rollBack();
                        continue 2;
                    }

                    $existencia = DocumentoService::existenciaProducto(trim($existe_modelo->sku), $empresa_almacen);

                    if ($existencia->error) {

                        LoggerService::writeLog('t1', $existencia->mensaje . ' ' . self::logVariableLocation());

                        DB::rollBack();
                        continue 2;
                    }

                    $precioProducto = $producto->price / 1.16;

                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documento,
                        'id_modelo' => $existe_modelo->id,
                        'cantidad' => 1,
                        'precio' => $precioProducto,
                        'garantia' => 0,
                        'modificacion' => '',
                        'regalo' => ''
                    ]);
                }

                if ($fulfillment) {
                    //Aqui ta
                    $response = DocumentoService::crearFactura($documento, 0, 0);

                    if ($response->error) {
                        LoggerService::writeLog('t1', 'Error: No se pudo crear la factura de la venta: ' . $pedido->orderid . ' en el ERP, mensaje de error: ' . $response->mensaje . self::logVariableLocation());

                        DB::rollBack();
                        continue;
                    }
                }

                DB::commit();
            }
        } catch (Exception $e) {
            DB::rollBack();
            return self::errorResponse("Hubo un problema en la transacción", self::logVariableLocation() . ' ' . $e->getMessage());
        }

        $response->error = 0;
        $response->mensaje = "Ventas importadas correctamente";

        return $response;
    }
}
