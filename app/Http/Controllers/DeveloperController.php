<?php

namespace App\Http\Controllers;

use App\Http\Services\AutoAzurService;
use App\Http\Services\ClaroshopService;
use App\Http\Services\DocumentoService;
use App\Http\Services\InventarioService;
use App\Http\Services\LiverpoolService;
use App\Http\Services\MercadolibreService;
use App\Http\Services\ShopifyService;
use App\Http\Services\WalmartService;
use App\Models\Modelo;
use DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use App\Http\Services\GeneralService;
use App\Http\Services\LinioService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Crypt;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use DateTime;
use App\Events\PusherEvent;
use App\Http\Services\ClaroshopServiceV2;
use App\Http\Services\LoggerService;
use App\Http\Services\ReparacionService;
use DOMDocument;
use Illuminate\Support\Carbon;

use stdClass;

class DeveloperController extends Controller
{
    public function confirmar_authy(Request $request)
    {
        $data = json_decode($request->input("data"));

        $validate_authy = DocumentoService::authy($data->authy_id, $data->authy_token);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje
            ], 500);
        }

        $user = DB::table("usuario")->find($data->authy_id);

        GeneralService::sendEmailToAdmins("Dev Zone", "El usuario " . $user->nombre . " solicitó abrir el modal " . $data->nombre_modal, "", 1);
    }

    public function confirmar_authy_cce(Request $request)
    {
        $data = json_decode($request->input("data"));

        $validate_authy = DocumentoService::authy($data->authy_id, $data->authy_token);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje
            ], 500);
        }
    }

    public function agregarInventarioCRM()
    {
        set_time_limit(0);

        $importar = InventarioService::importarExistencias()->getData();

        if ($importar->code == 200) {
            return "Todo ha salido bien";
        } else {
            return "Algo fallo";
        }
    }

    public function recuperarPedidosGranotecnica()
    {
        set_time_limit(0);

        //Conseguir la venta

        $venta = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/5/Factura/Estado/Folio/1213111'));
        if (is_array($venta) && count($venta) > 0) {
            $venta = $venta[0];
        }


        $existe_venta = DB::table('documento')->where('id', $venta->folio)->first();

        if (!empty($existe_venta)) {
            return response()->json([
                'code'  => 300,
                'message'   => 'Ya existe folio'
            ]);
        }

        $uso_cfdi = DB::table('documento_uso_cfdi')->where('codigo', $venta->usocfdi)->first();


        try {
            DB::beginTransaction();

            $documento = DB::table('documento')->insertGetId([
                'id' => $venta->folio,
                'id_cfdi' => $uso_cfdi->id,
                'id_almacen_principal_empresa' => 108,
                'id_periodo' => 1,
                'id_marketplace_area' => 22,
                'id_usuario' => 1,
                'id_paqueteria' => 13,
                'id_fase' => 6,
                'id_modelo_proveedor' => 0,
                'factura_folio' => $venta->folio,
                'factura_serie' => $venta->serie,
                'no_venta' => '.',
                'observacion' => "Pedido Importado Backup",
                'documento_extra' => $venta->documentoid,
                'fulfillment' => 1,
                'uuid' => $venta->uuid,
                'mkt_publicacion' => "N/A",
                'mkt_total' => 0,
                'mkt_user_total' => $venta->total,
                'mkt_fee' => 0,
                'mkt_coupon' => 0,
                'mkt_shipping_total' => 0,
                'mkt_created_at' => $venta->fecha_timbrada,
                'started_at' => date('Y-m-d H:i:s'),
            ]);

            DB::table('documento_entidad_re')->insert([
                'id_entidad' => 994414,
                'id_documento' => $documento
            ]);

            $direccion = DB::table('documento_direccion')->insert([
                'id_documento' => $documento,
                'id_direccion_pro' => 0,
                'contacto' => "N/A",
                'calle' => "N/A",
                'numero' => "N/A",
                'numero_int' => "N/A",
                'colonia' => "N/A",
                'ciudad' => "N/A",
                'estado' => "N/A",
                'codigo_postal' => "N/A",
                'referencia' => "N/A"
            ]);

            foreach ($venta->documento_productos as $producto) {
                $modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                $movimiento = DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $modelo->id,
                    'cantidad' => $producto->cantidad,
                    'precio' => $producto->precio,
                    'garantia' => 90,
                    'modificacion' => '',
                    'regalo' => ''
                ]);
            }


            // $pago = DB::table('documento_pago')->insertGetId([
            //     'id_usuario' => 1,
            //     'id_metodopago' => 31,
            //     'id_vertical' => 0,
            //     'id_categoria' => 0,
            //     'id_clasificacion' => 0,
            //     'tipo' => 1,
            //     'origen_importe' => 0,
            //     'destino_importe' => $venta->total, //puede ser total_price
            //     'folio' => "",
            //     'entidad_origen' => 1,
            //     'origen_entidad' => 'XAXX010101000',
            //     'entidad_destino' => "",
            //     'destino_entidad' => '',
            //     'referencia' => '',
            //     'clave_rastreo' => '',
            //     'autorizacion' => '',
            //     'destino_fecha_operacion' => date('Y-m-d'),
            //     'destino_fecha_afectacion' => '',
            //     'cuenta_cliente' => ''
            // ]);

            DB::commit();

            return response()->json([
                'message' => "Correctamente",
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

    public function recuperarPedidos(Request $request)
    {
        set_time_limit(0);
        $ventas = json_decode($request->input("ventas"));

        foreach ($ventas as $venta) {
            $existe_venta = DB::table('documento')->where('id', $venta->pedido)->first();

            if (!empty($existe_venta)) {
                DB::table('documento')->where('id', $venta->pedido)->update(['id_fase' => 6, 'no_venta' => $venta->venta]);
                continue;
            }

            $uso_cfdi = DB::table('documento_uso_cfdi')->where('codigo', $venta->uso_cfdi)->first();

            $documento = DB::table('documento')->insertGetId([
                'id' => $venta->pedido,
                'id_cfdi' => $uso_cfdi->id,
                'id_almacen_principal_empresa' => $venta->almacen, //122 empresa de prueba
                'id_marketplace_area' => $venta->titulo, //35 arome-shopify
                'id_usuario' => 1, //1 omg
                'id_paqueteria' => 1, //omg 13
                'id_fase' => 6, //1 Pedido
                'id_modelo_proveedor' => 0, //null
                'factura_folio' => $venta->pedido ?? "N/A", //null
                'factura_serie' => $venta->factura ?? "N/A", //null
                'no_venta' => $venta->venta ?? 0, //order_number
                'referencia' => $venta->venta_shopify ?? "N/A", //id
                'observacion' => "Pedido Importado Backup", //null
                'documento_extra' => $venta->id ?? "N/A", //null
                'fulfillment' => 0, //0
                'comentario' => $venta->id ?? 0, //id
                'mkt_publicacion' => "N/A", //null
                'mkt_total' => $venta->total ?? 0, //current_total_price
                'mkt_fee' => 0,
                'mkt_coupon' => 0, //total_discounts
                'mkt_shipping_total' => 0, //total_shipping_price-shop_money-amount
                'mkt_created_at' => $venta->fecha ?? 0, //created_at
                'mkt_user_total' => 0, //0
                'started_at' => date('Y-m-d H:i:s'),
            ]);

            //Esto se puede cambiar por la entidad de publico general para no ir crenado nuevas
            DB::table('documento_entidad_re')->insert([
                'id_entidad' => 737184,
                'id_documento' => $documento
            ]);

            $direccion = DB::table('documento_direccion')->insert([ //shipping_address
                'id_documento' => $documento,
                'id_direccion_pro' => 0,
                'contacto' => "N/A", //name
                'calle' => "N/A", //address1
                'numero' => "N/A",
                'numero_int' => "N/A",
                'colonia' => "N/A",
                'ciudad' => "N/A", //city
                'estado' => "N/A", //province
                'codigo_postal' => "N/A", //zip
                'referencia' => "N/A" //address2
            ]);

            foreach ($venta->productos as $producto) {
                $modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                $movimiento = DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $modelo->id,
                    'cantidad' => $producto->cantidad ?? 0, //quantity
                    'precio' => $producto->precio ?? 0, //price
                    'garantia' => 90, //null
                    'modificacion' => '',
                    'regalo' => '' //null
                ]);
            }

            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario' => 1,
                'id_metodopago' => 31,
                'id_vertical' => 0,
                'id_categoria' => 0,
                'id_clasificacion' => 0,
                'tipo' => 1,
                'origen_importe' => 0,
                'destino_importe' => $venta->total, //puede ser total_price
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
        }

        return response()->json([
            'code' => 200,
            'message' => 'Sonrie Paps, todo salió bien',
            'data' => $ventas
        ]);
    }

    public function conciliar(Request $request)
    {
        $delete = json_decode($request->input("skus"));
        $new = $request->input("new");
        $costo = $request->input("costo");
        $eliminados = array();

        $oldsku = DB::table('modelo')->whereIn('sku', $delete)->get();
        $nuevo = DB::table('modelo')->where('sku', $new)->first();

        //Si tiene costo se actualiza si no no pasa nada
        if (!empty($costo)) {
            if (!empty($nuevo)) {
                DB::table('modelo')->where('id', $nuevo->id)->update([
                    'costo' => $costo
                ]);
            }
        }

        if (!empty($oldsku)) {
            foreach ($oldsku as $modelo) {
                //Obtener los movimientos de los skus a eliminar
                $movimientos = DB::table('movimiento')->where('id_modelo', $modelo->id)->get();
                $modelo_margen = DB::table('documento_entidad_modelo_margen')->where('id_modelo', $modelo->id)->first();

                if (!empty($modelo_margen)) {
                    foreach ($modelo_margen as $mod_mar) {
                        DB::table('documento_entidad_modelo_margen')->where('id', $mod_mar)->update([
                            'id_modelo' => $nuevo->id,
                        ]);
                    }
                }

                if (!empty($movimientos)) {
                    foreach ($movimientos as $m) {
                        DB::table('movimiento')->where('id', $m->id)->update([
                            'id_modelo' => $nuevo->id
                        ]);
                    }

                    $eliminado = DB::table('modelo')->where('id', $modelo->id)->delete();

                    if ($eliminado) {
                        array_push($eliminados, $eliminado);
                    } else {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No se pudieron eliminar los productos, contacta al desarrollador.",
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Si a veces soy una cosa barbara... Hechizo completado.",
            'Viejo(s)' => $oldsku,
            'Nuevo' => $nuevo,
            'Eliminados' => $eliminados
        ]);
    }

    private static function descryptKey($secret_key)
    {
        try {
            $decoded_secret_key = Crypt::decrypt($secret_key);
        } catch (DecryptException $e) {
            $decoded_secret_key = "";
        }

        return $decoded_secret_key;
    }

    public static function seller($pseudonimo)
    {
        $pseudonimo = str_replace(" ", "%20", $pseudonimo);

        $seller = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "sites/MLM/search?nickname=" . $pseudonimo));

        return $seller;
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

    //    public static function seller($pseudonimo)
    //    {
    //        $pseudonimo = str_replace(" ", "%20", $pseudonimo);
    //
    //        $seller = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "sites/MLM/search?nickname=" . $pseudonimo));
    //
    //        return $seller;
    //    }

    //    public static function token($marketplace)
    //    {
    //        $response = new \stdClass();
    //        $response->error = 1;
    //
    //        try {
    //            $marketplace->secret = Crypt::decrypt($marketplace->secret);
    //        } catch (DecryptException $e) {
    //            $marketplace->secret = "";
    //        }
    //
    //        if (empty($marketplace->secret)) {
    //            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";
    //
    //            return $response;
    //        }
    //
    //        $data = array(
    //            "grant_type" => "client_credentials"
    //        );
    //
    //        $request_data = \Httpful\Request::post(config("webservice.walmart_endpoint") . "v3/token")
    //            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
    //            ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
    //            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
    //            ->addHeader('WM_MARKET', 'mx')
    //            ->body($data, \Httpful\Mime::FORM)
    //            ->send();
    //
    //        $request_raw = $request_data->raw_body;
    //        $request = json_decode($request_raw);
    //
    //        if (property_exists($request, "error")) {
    //            $response->mensaje = $request->error_description . ", line 169";
    //            $response->raw = $request_data;
    //
    //            return $response;
    //        }
    //
    //        $response->error = 0;
    //        $response->token = $request->access_token;
    //
    //        return $response;
    //    }

    public function quitarSeriesDuplicadas()
    {
        set_time_limit(0);
        // Obtén todas las series que contienen '\' o "'"
        $seriesConCaracteresIndeseados = DB::table('producto')
            ->where('serie', 'like', '%\'%')
            ->orWhere('serie', 'like', '%\\%')
            ->orderBy('created_at', "DESC")
            ->get();

        // Inicializa un array para almacenar las series después de la limpieza
        $seriesLimpias = [];

        foreach ($seriesConCaracteresIndeseados as $producto) {
            $serieOriginal = $producto->serie;

            // Limpia la serie
            $serieLimpia = str_replace(["'", "\\"], "", $serieOriginal);

            // Verifica si la serie limpia ya existe en el array
            if (in_array($serieLimpia, $seriesLimpias)) {
                // La serie limpia ya existe, entonces elimina el registro actual
                $productoLimpio = DB::table('producto')->where('serie', $serieLimpia)->first();
                $movimiento_productos = DB::table('movimiento_producto')->where('id_producto', $producto->id)->get();

                if (!empty($movimiento_productos)) {
                    foreach ($movimiento_productos as $mov) {
                        DB::table('movimiento_producto')->where('id', $mov->id)->update(['id_producto' => $productoLimpio->id]);
                    }
                    $producto_anterior = DB::table('producto')->where('id', $producto->id)->first();
                    DB::table('producto')->where('serie', $serieLimpia)->update(['status' => $producto_anterior->status]);

                    DB::table('producto')->where('id', $producto->id)->delete();
                }
            } else {
                // Agrega la serie limpia al array
                $seriesLimpias[] = $serieLimpia;

                $existe_serie = DB::table('producto')->where('serie', $serieLimpia)->first();

                if (!empty($existe_serie)) {
                    $movimiento_productos = DB::table('movimiento_producto')->where('id_producto', $producto->id)->get();

                    if (!empty($movimiento_productos)) {
                        foreach ($movimiento_productos as $mov) {
                            DB::table('movimiento_producto')->where('id', $mov->id)->update(['id_producto' => $existe_serie->id]);
                        }

                        $producto_anterior = DB::table('producto')->where('id', $producto->id)->first();
                        DB::table('producto')->where('serie', $serieLimpia)->update(['status' => $producto_anterior->status]);

                        DB::table('producto')->where('id', $producto->id)->delete();
                    }
                } else {
                    // Actualiza la serie en el registro actual en la tabla producto
                    DB::table('producto')->where('id', $producto->id)->update(['serie' => $serieLimpia]);
                }
            }
        }

        return 0;
    }

    public function actualizarPedido()
    {
        set_time_limit(0);
        // Configuración inicial y obtención del primer conjunto de órdenes
        $marketplace = DB::select("SELECT
                                    marketplace_area.id,
                                    marketplace_api.extra_1,
                                    marketplace_api.extra_2,
                                    marketplace_api.app_id,
                                    marketplace_api.secret
                                FROM marketplace_area
                                INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE marketplace_area.id = 35")[0];

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        $api = $marketplace->app_id;
        $token = $marketplace->secret;
        $todasLasOrdenes = [];
        $url = "https://" . $api . ":" . $token . "@aromemexico.myshopify.com/admin/api/2024-01/orders.json?status=open";

        $respuestas = [];

        do {
            $response = \Httpful\Request::get($url)->send();
            array_push($respuestas, $response);
            $ventas_pending = json_decode($response->raw_body, true);

            if (!empty($ventas_pending['orders'])) {
                $todasLasOrdenes = array_merge($todasLasOrdenes, $ventas_pending['orders']);
            }

            $page_info = $this->parse_next_page_url($response->raw_headers);
            if ($page_info !== null) {
                $url = "https://" . $api . ":" . $token . "@aromemexico.myshopify.com/admin/api/2024-01/orders.json?&page_info=" . $page_info;
            } else {
                $url = null;
            }
        } while ($url !== null);

        return response()->json([
            "ordenes" => $todasLasOrdenes,
            "response" => $respuestas
        ]);
    }

    protected function parse_next_page_url($raw_headers)
    {
        set_time_limit(0);
        $matches = [];
        if (preg_match('/<([^>]+)>; rel="next"/', $raw_headers, $matches)) {
            $url = $matches[1];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);
            if (isset($query_params['page_info'])) {
                return $query_params['page_info'];
            }
        }
        return null;
    }

    /*public function actualizarPedido()
    {
        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = 35");

        $marketplace = $marketplace[0];

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        $api = $marketplace->app_id;
        $token = $marketplace->secret;

        $url = "https://" . $api . ":" . $token . "@aromemexico.myshopify.com/admin/api/2024-01/orders.json?status=open&limit=250";

        $response = \Httpful\Request::get($url)->send();
        $ventas_pending = @json_decode($response);

        return response()->json([
            "data" => $response
        ]);

        $credenciales = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_api.extra_1,
                                            marketplace_api.extra_2,
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = 11")[0];

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);

        $response       = \Httpful\Request::get(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=pendientes")->send();

        $informacion    = json_decode($response->raw_body);

        $signature2 = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
        $pedido = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature2 . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=7839402"));

        //        $genera_guia = \Httpful\Request::post(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/Embarque")
        //            ->body(json_encode($content), \Httpful\Mime::FORM)
        //            ->send();
        //
        //        $genera_guia = json_decode($genera_guia->raw_body);

        $primerSku = $pedido->productos[0]->sku;
        $sonIguales = true;
        for ($i = 0; $i < count($pedido->productos); $i++) {
            if ($pedido->productos[$i]->sku !== $primerSku) {
                $sonIguales = false;
                break;
            }
        }

        return response()->json([
            "ventas" => $informacion,
            "pedido" => $pedido,
            "productos" => $sonIguales
        ]);
    }*/

    public function getAlmacenes()
    {
        $almacenes = DB::table('almacen')->get();
        $fechas = DB::table('doc_comercial')
            ->select(DB::raw("STR_TO_DATE(referencia, '%d%m%Y') as fecha"), 'referencia')
            ->whereNotNull('referencia')
            ->distinct()
            ->orderBy('fecha', 'asc')
            ->get();

        return response()->json([
            "almacenes" => $almacenes,
            "fechas" => $fechas
        ]);
    }

    public function asignarSeriePedido(Request $request)
    {
        $documento = $request->input("documento");
        $sku = $request->input("sku");
        $serie = $request->input("serie");
        $almacen = $request->input("almacen");

        $modelo = DB::table('modelo')->where('sku', $sku)->first();
        $movimiento = DB::table('movimiento')->where('id_documento', $documento)->where('id_modelo', $modelo->id)->first();
        $producto = DB::table('producto')->where('serie', $serie)->first();

        if (empty($movimiento)) {
            return response()->json([
                'code' => 500,
                'message' => 'Movimiento no encontrado'
            ]);
        }

        if (empty($modelo)) {
            return response()->json([
                'code' => 500,
                'message' => 'Sku no encontrado'
            ]);
        }

        if (empty($producto)) {
            $productoNuevo = DB::table('producto')->insertGetId([
                'id_almacen' => $almacen,
                'serie' => $serie,
                'status' => 1,
                'extra' => "Serie agregada por sistemas, por una devolución.",
                'id_modelo' => $modelo->id,
            ]);
        }

        $movimiento_producto = DB::table('movimiento_producto')->insert([
            'id_producto' => $producto->id ?? $productoNuevo,
            'id_movimiento' => $movimiento->id,
        ]);

        if (empty($movimiento_producto)) {
            return response()->json([
                'code' => 500,
                'message' => 'No fue posible asignar la serie.'
            ]);
        } else {
            return response()->json([
                'code' => 200,
                'message' => 'Serie asignada correctamente'
            ]);
        }
    }

    public function actualizarSeriePedido(Request $request)
    {
        $documentoCompraId = $request->input("documento"); // El ID del documento de compra que te pasan

        // Paso 1: Obtener las series del documento de compra
        $seriesCompra = DB::table('movimiento_producto as mp')
            ->join('movimiento as m', 'mp.id_movimiento', '=', 'm.id')
            ->select('mp.id_producto')
            ->where('m.id_documento', $documentoCompraId)
            ->pluck('mp.id_producto');

        if (empty($seriesCompra)) {
            return response()->json([
                'code' => 500,
                'message' => 'El documento no tiene series asignadas.'
            ]);
        }

        // Paso 2: Verificar si las series están en documentos tipo 2, 4, o 11
        $seriesEnDocumentosEspeciales = DB::table('movimiento_producto as mp')
            ->join('movimiento as m', 'mp.id_movimiento', '=', 'm.id')
            ->join('documento as d', 'm.id_documento', '=', 'd.id')
            ->select('mp.id_producto')
            ->whereIn('mp.id_producto', $seriesCompra)
            ->whereIn('d.id_tipo', [2, 4, 11])
            ->distinct()
            ->pluck('mp.id_producto');

        // Series que no están en documentos tipo 2, 4, o 11
        $seriesNoEspeciales = $seriesCompra->diff($seriesEnDocumentosEspeciales);

        // Paso 3: Actualizar el estado del producto
        // Actualizar estado a 1 para series no especiales
        DB::table('producto')
            ->whereIn('id', $seriesNoEspeciales)
            ->update(['status' => 1]);

        // Actualizar estado a 0 para series especiales
        DB::table('producto')
            ->whereIn('id', $seriesEnDocumentosEspeciales)
            ->update(['status' => 0]);

        return response()->json([
            'code' => 200,
            'message' => 'Series actualizada correctamente'
        ]);
    }

    public function actualizar_pedidos_enviados_walmart()
    {
        set_time_limit(0);
        $respuestas = array();

        $manifiestos = DB::table('manifiesto')
            ->join('documento_guia', 'manifiesto.guia', '=', 'documento_guia.guia')
            ->join('documento', 'documento_guia.id_documento', '=', 'documento.id')
            ->where('manifiesto.created_at', '>', '2024-07-01 00:00:00')
            ->where('manifiesto.id_marketplace_area', 64)
            ->where('manifiesto.notificado', 0)
            ->where('manifiesto.salida', 1)
            ->select('documento.id', 'manifiesto.guia', 'documento.id_marketplace_area', 'documento.no_venta', 'documento.info_extra as index', 'documento.fulfillment as full')
            ->get();

        if (empty($manifiestos)) {
            return response()->json([
                'code' => 500,
                'message' => 'No hay manifiestos por actualizar.'
            ]);
        }

        foreach ($manifiestos as $manifiesto) {
            if ($manifiesto->index == "N/A") {
                $manifiesto->index = 0;
            }

            if(!$manifiesto->full) {
                $actualizar = WalmartService::actualizar_estado_envio($manifiesto->id_marketplace_area, $manifiesto->no_venta, $manifiesto->index);

                DB::table('doc_walmart')->insert([
                    'id_documento' => $manifiesto->id,
                    'no_venta' => $manifiesto->no_venta,
                    'guia' => $manifiesto->guia,
                    'request' => json_encode($actualizar->data),
                    'index' => $manifiesto->index,
                ]);

                $respuestas[] = $actualizar;
            }

            DB::table('manifiesto')->where('guia', $manifiesto->guia)->update([
                'notificado' => 1
            ]);
        }

        return response()->json([
            'code' => 200,
            'respuestas' => $respuestas
        ]);
    }

    public function copiar_series(Request $request)
    {
        set_time_limit(0);

        $doc_viejo = $request->input("doc_viejo");
        $doc_nuevo = $request->input("doc_nuevo");

        $movimiento_new = DB::table('movimiento')->where('id_documento', $doc_nuevo)->first();

        $series_asignadas = DB::table('movimiento_producto')
            ->select('movimiento_producto.*')
            ->join('movimiento', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
            ->join('producto', 'producto.id', '=', 'movimiento_producto.id_producto')
            ->where('movimiento.id_documento', $doc_viejo)
            ->get();

        foreach ($series_asignadas as $serie) {
            DB::table('movimiento_producto')->insert([
                'id_movimiento' => $movimiento_new->id,
                'id_producto' => $serie->id_producto,
            ]);
        }

        DB::table('documento')->where('id', $doc_nuevo)->update([
            'id_fase' => 6
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Series copiadas correctamente'
        ]);
    }

    public function actualizarGuiaWalmart()
    {
        set_time_limit(0);

        $documentos = DB::table('documento')
            ->where('referencia', 'Sin informacion de la guia')
            ->where('fulfillment', 0)
            ->where('status', 1)
            ->get();

        foreach ($documentos as $documento) {
            $venta = WalmartService::venta($documento->no_venta, 64);

            DB::table('documento')->where('id', $documento->id)->update([
                'referencia' => isset($venta->shipments[$documento->info_extra]) ? $venta->shipments[$documento->info_extra]->trackingNumber ?? "Sin informacion de la guía" : "N/A"
            ]);

            dump($venta);
        }

        dd($documentos);
    }

    public function importarSeriesAPedido(Request $request)
    {
        set_time_limit(0);
        $series = json_decode($request->input("series"));

        $movimiento = DB::table('movimiento')->where('id_documento', 1420199)->first();

        foreach ($series as $serie) {
            $existe_serie = DB::table('producto')->where('serie', $serie->serie)->first();

            if($existe_serie){
                DB::table('movimiento_producto')->insert([
                    'id_movimiento' => $movimiento->id,
                    'id_producto' => $existe_serie->id,
                ]);
            } else {
                DB::table('producto')->insert([
                    'id_almacen' => 25,
                    'serie' => $serie->serie,
                    'status' => 1,
                    'extra' => 1420199,
                    'relacionado' => 6
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'message' => 'Series importadas correctamente'
        ]);
    }

    public function testApiWalmart()
    {
        //Se utilizara para validar los documentos repetidos o numeros de venta repetidos
        set_time_limit(0);

        $venta = DocumentoService::crearFactura(1487562, 1,0);
        dd($venta);
    }

    private static function existeVenta($venta)
    {
        $existe_venta = DB::table('documento')->where('no_venta', $venta)->first();

        if (empty($existe_venta)) {
            return false;
        } else {
            return true;
        }
    }


    public function importMovimientosComercial()
    {
        set_time_limit(0);

        $ventas = DB::select("SELECT
                                    documento.*
                                FROM
                                    documento
                                WHERE
                                    id_fase in (6,100,607)
                                    AND ( documento_extra = 'N/A' OR documento_extra = '' )
                                    AND documento.created_at > '2024-01-01 00:00:00'
                                    AND documento.id_tipo in (3,4,5,11)
                                    AND documento.`status` = 1
                                    ORDER BY created_at");

        if (!empty($ventas)) {
            foreach ($ventas as $venta) {
                $movimiento = DocumentoService::crearMovimiento($venta->id);

                $existe = DB::table('doc_comercial')->where('id_documento', $venta->id)->first();

                if ($movimiento->error) {
                    if (empty($existe)) {
                        DB::table('doc_comercial')->insert([
                            'id_documento' => $venta->id,
                            'tipo' => $venta->id_tipo,
                            'mensaje' => $movimiento->mensaje,
                            'importado' => 0,
                            'referencia' => date('dmY')
                        ]);
                    } else {
                        DB::table('doc_comercial')->where('id_documento', $venta->id)->update([
                            'tipo' => $venta->id_tipo,
                            'mensaje' => $movimiento->mensaje,
                            'referencia' => date('dmY')
                        ]);
                    }
                } else {
                    if (empty($existe)) {
                        DB::table('doc_comercial')->insert([
                            'id_documento' => $venta->id,
                            'tipo' => $venta->id_tipo,
                            'mensaje' => $movimiento->mensaje,
                            'importado' => 1,
                            'referencia' => date('dmY')
                        ]);
                    } else {
                        DB::table('doc_comercial')->where('id_documento', $venta->id)->update([
                            'tipo' => $venta->id_tipo,
                            'mensaje' => $movimiento->mensaje,
                            'importado' => 1,
                            'referencia' => date('dmY')
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'code' => 200,
            'message' => empty($ventas) ? "No hay movimientos para insertar" : "Todo salio perfectamente"
        ]);
    }

    public function importVentasComercial()
    {
        set_time_limit(0);

        $ventas = DB::select("SELECT
                                    documento.*
                                FROM
                                    documento
                                WHERE
                                    id_fase in (5, 6) 
                                    AND ( documento_extra = 'N/A' OR documento_extra = '' ) 
                                    AND documento.created_at > '2024-01-01 00:00:00'
                                    AND documento.id_tipo = 2 
                                    AND documento.`status` = 1
                                    ORDER BY created_at");

        foreach ($ventas as $venta) {
            //Bandera para ver si tiene todos los datos para importar a comercial
            $continuar = true;
            //El pedido no tiene empresa_almacen
            if ($venta->id_almacen_principal_empresa == 0) {
                dump($venta->id . " Sin empresa, se manda a fase pedido");
                //Si no es mercadolibre, se manda a fase pedido para que agreguen el correcto
                self::registrar_evento($venta->id, 'El pedido no tiene la informacion de la empresa. Se manda a fase Pedido');
                continue;
            }
            //Se obtienen los movimientos del pedido
            $movimientos = DB::table('movimiento')->where('id_documento', $venta->id)->get();

            //Si no tiene movimientos, es decir, no tiene productos el pedido
            if (empty($movimientos) || count($movimientos) < 1 || $movimientos->isEmpty()) {
                dump($venta->id . " No tiene productos");
                self::registrar_evento($venta->id, 'El pedido no tiene productos. Se manda a Fase Pedido.');
                $continuar = false;
            }

            //Si se importaron los producto o tenia la informacion completa se importa a comercial
            if ($continuar) {
                dump($venta->id . " Se importa a comercial");
                self::importarDocumentoComercial($venta->id);
            }
        }

        return response()->json([
            'code' => 200,
            'message' => "Todo salio bien"
        ]);
    }

    public static function registrar_evento($documento, $mensaje)
    {
        $existe = DB::table('doc_comercial')->where('id_documento', $documento)->first();

        if (empty($existe)) {
            DB::table('doc_comercial')->insert([
                'id_documento' => $documento,
                'mensaje' => $mensaje,
                'importado' => 0,
                'referencia' => date('dmY')
            ]);
        } else {
            DB::table('doc_comercial')->where('id_documento', $documento)->update([
                'mensaje' => $mensaje,
                'referencia' => date('dmY')
            ]);
        }

        DB::table('documento')->where('id', $documento)->update([
            'id_fase' => 1,
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => "Mensaje al importar a comercial: " . $mensaje
        ]);
    }

    public static function registrar_evento_importado($documento, $mensaje)
    {
        $existe = DB::table('doc_comercial')->where('id_documento', $documento)->first();

        if (empty($existe)) {
            DB::table('doc_comercial')->insert([
                'id_documento' => $documento,
                'mensaje' => $mensaje,
                'importado' => 0,
                'referencia' => date('dmY')
            ]);
        } else {
            DB::table('doc_comercial')->where('id_documento', $documento)->update([
                'mensaje' => $mensaje,
                'referencia' => date('dmY')
            ]);
        }

        DB::table('documento')->where('id', $documento)->update([
            'id_fase' => 6,
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => "Mensaje al importar a comercial: " . $mensaje
        ]);
    }

    public function descargarExcelImportacionComercial(Request $request)
    {
        $fecha = $request->input("fecha");

        $ventas = DB::select("
                                SELECT
                                    documento.id,
                                    documento_tipo.tipo,
                                    empresa.empresa,
                                    almacen.almacen,
                                    marketplace.marketplace,
                                    area.area,
                                    doc_comercial.mensaje,
                                    CASE
                                        WHEN doc_comercial.importado = 1 THEN 'Importado correctamente'
                                        ELSE 'Documento no importado'
                                    END AS estado_importacion,
                                    documento.created_at
                                FROM
                                    documento
                                    INNER JOIN empresa_almacen ea ON ea.id = documento.id_almacen_principal_empresa
                                    INNER JOIN empresa ON empresa.id = ea.id_empresa
                                    INNER JOIN almacen ON almacen.id = ea.id_almacen
                                    INNER JOIN documento_fase ON documento_fase.id = documento.id_fase
                                    INNER JOIN documento_tipo ON documento_tipo.id = documento.id_tipo
                                    INNER JOIN marketplace_area ON marketplace_area.id = documento.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace.id = marketplace_area.id_marketplace
                                    INNER JOIN area ON area.id = marketplace_area.id_area
                                    INNER JOIN doc_comercial ON doc_comercial.id_documento = documento.id
                                WHERE
                                    documento.id IN (SELECT id_documento FROM doc_comercial WHERE referencia = ?)
                            ", [$fecha]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('FALTANTES DE SURTIR');
        $contador_fila = 2;

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'EMPRESA');
        $sheet->setCellValue('C1', 'ALMACEN');
        $sheet->setCellValue('D1', 'MARKETPLACE');
        $sheet->setCellValue('E1', 'AREA');
        $sheet->setCellValue('F1', 'MENSAJE');
        $sheet->setCellValue('G1', 'ESTADO_IMPORTACION');
        $sheet->setCellValue('H1', 'CREACION');

        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($ventas as $venta) {
            $sheet->setCellValue('A' . $contador_fila, $venta->id);
            $sheet->setCellValue('B' . $contador_fila, $venta->empresa);
            $sheet->setCellValue('C' . $contador_fila, $venta->almacen);
            $sheet->setCellValue('D' . $contador_fila, $venta->marketplace);
            $sheet->setCellValue('E' . $contador_fila, $venta->area);
            $sheet->setCellValue('F' . $contador_fila, $venta->mensaje);
            $sheet->setCellValue('G' . $contador_fila, $venta->estado_importacion);
            $sheet->setCellValue('H' . $contador_fila, $venta->created_at);

            $contador_fila++;
        }

        foreach (range('A', 'H') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('Importacion_Comercial_' . $fecha . '.xlsx');

        $json['code'] = 200;
        $json['ventas'] = $ventas;
        $json['excel'] = base64_encode(file_get_contents('Importacion_Comercial_' . $fecha . '.xlsx'));

        unlink('Importacion_Comercial_' . $fecha . '.xlsx');

        return response()->json($json);
    }

    public function actualizarVentasDeComercial()
    {
        set_time_limit(0);

        $ventas = DB::table('doc_comercial')->where('importado', 0)->get();

        foreach ($ventas as $venta) {
            $info_documento = DB::table('documento')->where('id', $venta->id_documento)->first();

            if (!empty($info_documento)) {
                if ($info_documento->status == 0) {
                    DB::table('doc_comercial')->where('id_documento', $info_documento->id)->update([
                        'importado' => 1,
                        'mensaje' => 'PEDIDO CANCELADO',
                        'ultimo_seg' => ''
                    ]);
                } else {
                    if ($info_documento->documento_extra != 'N/A') {
                        if ($info_documento->documento_extra != '') {
                            DB::table('doc_comercial')->where('id_documento', $info_documento->id)->update([
                                'importado' => 1,
                                'mensaje' => '',
                                'ultimo_seg' => ''
                            ]);
                        }
                    }
                }
            } else {
                DB::table('doc_comercial')->where('id_documento', $venta->id_documento)->delete();
            }
        }

        return response()->json([
            "data" => 1,
        ]);
    }

    public function importarProductosML()
    {
        set_time_limit(0);
        $pedidos = DB::select("SELECT
                                documento.*
                            FROM
                                documento
                            WHERE
                                id_fase >= 5 
                                AND ( documento_extra = 'N/A' OR documento_extra = '' ) 
                                AND documento.created_at > '2024-04-17 00:00:00' AND documento.created_at < '2024-04-19 00:00:00'
                                AND documento.id_tipo = 2 
                                AND documento.`status` = 1");

        if (!empty($pedidos)) {
            foreach ($pedidos as $pedido) {
                $mov = DB::table('movimiento')->where('id_documento', $pedido->id)->get();

                if ($mov->isEmpty()) {
                    if ($pedido->id_marketplace_area == 1 || $pedido->id_marketplace_area == 32) {
                        $respuesta = MercadolibreService::venta($pedido->no_venta, $pedido->id_marketplace_area);
                        if (!empty($respuesta)) {
                            $productos = $respuesta->data[0]->order_items;
                            foreach ($productos as $producto) {
                                if ($producto->item->seller_sku == null || $producto->item->seller_sku == '') {
                                    dump("Pedido " . $pedido->id . " no tiene sku relacionado");
                                    DB::table('doc_comercial')->where('id_documento', $pedido->id)->update([
                                        'mensaje' => 'El marketplace no tiene el sku'
                                    ]);
                                } else {
                                    $modelo = DB::table('modelo')->where('sku', $producto->item->seller_sku)->first();
                                    if (!empty($modelo)) {
                                        $movimiento = DB::table('movimiento')->insertGetId([
                                            'id_documento' => $pedido->id,
                                            'id_modelo' => $modelo->id,
                                            'cantidad' => $producto->quantity,
                                            'precio' => $producto->unit_price / 1.16,
                                            'garantia' => 90,
                                            'modificacion' => '',
                                        ]);
                                    } else {
                                        dump("Del pedido " . $pedido->id . " no se encontro el sku relacionado - " . $producto->item->seller_sku);
                                        DB::table('doc_comercial')->where('id_documento', $pedido->id)->update([
                                            'mensaje' => 'El sku ' . $producto->item->seller_sku . ' no fue encontrado en crm'
                                        ]);
                                        $publicaciones = $respuesta->data[0]->productos;

                                        if (empty($publicaciones)) {
                                            dump("Del pedido" . $pedido->id . " la publicacion no tiene productos relacionados");
                                            DB::table('doc_comercial')->where('id_documento', $pedido->id)->update([
                                                'mensaje' => 'No hay relacion entre el sku y la publicacion'
                                            ]);
                                        } else {
                                            foreach ($publicaciones as $publicacion) {
                                                foreach ($publicacion->productos as $prod) {
                                                    $movimiento = DB::table('movimiento')->insertGetId([
                                                        'id_documento' => $pedido->id,
                                                        'id_modelo' => $prod->id_modelo,
                                                        'cantidad' => $prod->cantidad,
                                                        'precio' => $producto->unit_price / 1.16,
                                                        'garantia' => $prod->garantia,
                                                        'modificacion' => '',
                                                    ]);
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            $id_pago = DB::table('documento_pago_re')->where('id_documento', $pedido->id)->first();
                            $pago = DB::table('documento_pago')->where('id', $id_pago->id)->update(['destino_importe' => $respuesta->data[0]->paid_amount]);
                        } else {
                            dump("Pedido " . $pedido->id . " con el numero de venta " . $pedido->no_venta . " la api no dio respuesta");
                        }
                    }
                }
            }
        }

        return response()->json([
            "data" => "esperemos que quedara"
        ]);
    }

    public function descargarReporteSD()
    {
        set_time_limit(0);

        $documento = 1173572;

        $series = DB::select("SELECT
                                    producto.id
                                FROM movimiento
                                INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                WHERE movimiento.id_documento = " . $documento);

        return response()->json([
            "data" => $series
        ]);

        //        $publicacion_data = DB::select("SELECT
        //                                            marketplace_api.app_id,
        //                                            marketplace_api.secret
        //                                        FROM marketplace_api
        //                                        WHERE id_marketplace_area = 1");
        //
        //        if (empty($publicacion_data)) {
        //            return response()->json([
        //                'data' => $publicacion_data
        //            ]);
        //        }
        //
        //        $publicacion_data = $publicacion_data[0];
        //
        //        $token = self::token($publicacion_data->app_id, $publicacion_data->secret);
        //
        //        $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/MLM2425883538?access_token=" . $token));
        //
        //        return response()->json([
        //            'data' => $publicacion_info
        //        ]);

        //        $movimientos = DB::select("SELECT movimiento.id_documento, documento_tipo.tipo, modelo.descripcion, mp.id_producto, COUNT(mp.id_producto) as cantidad_repetidos
        //                                    FROM movimiento_producto mp
        //                                    inner join movimiento on movimiento.id = mp.id_movimiento
        //                                    inner join modelo on modelo.id = movimiento.id_modelo
        //                                    inner join documento on documento.id = movimiento.id_documento
        //                                    inner join documento_tipo on documento_tipo.id = documento.id_tipo
        //                                    GROUP BY mp.id_movimiento, mp.id_producto
        //                                    HAVING COUNT(mp.id_producto) > 1");
        //
        //        $spreadsheet = new Spreadsheet();
        //        $sheet = $spreadsheet->getActiveSheet()->setTitle('Series Repetidas');
        //        $contador_fila = 2;
        //
        //        $sheet->setCellValue('A1', 'PEDIDO');
        //        $sheet->setCellValue('B1', 'TIPO');
        //        $sheet->setCellValue('C1', 'PRODUCTO');
        //        $sheet->setCellValue('D1', 'SERIE');
        //        $sheet->setCellValue('E1', 'CANTIDAD');
        //
        //        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        //        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');
        //
        //
        //        foreach ($movimientos as $mov) {
        //            $producto = DB::table('producto')->where('id', $mov->id_producto)->first();
        //
        //            $sheet->setCellValue('A' . $contador_fila, $mov->id_documento);
        //            $sheet->setCellValue('B' . $contador_fila, $mov->tipo);
        //            $sheet->setCellValue('C' . $contador_fila, $mov->descripcion);
        //            $sheet->setCellValue('D' . $contador_fila, $producto->serie);
        //            $sheet->setCellValue('E' . $contador_fila, $mov->cantidad_repetidos);
        //
        //            $sheet->getCellByColumnAndRow(4, $contador_fila)->setValueExplicit($producto->serie, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        //
        //            $contador_fila++;
        //        }
        //
        //        foreach (range('A', 'E') as $columna) {
        //            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        //        }
        //
        //        $writer = new Xlsx($spreadsheet);
        //        $writer->save('seriesRepetidas.xlsx');
        //
        //        $json['code'] = 200;
        //        $json['movimientos'] = $movimientos;
        //        $json['excel'] = base64_encode(file_get_contents('seriesRepetidas.xlsx'));
        //
        //        unlink('seriesRepetidas.xlsx');

        //        return response()->json($json);
    }

    public function descargarReporteML(Request $request)
    {
        set_time_limit(0);
        $sku = 8806094726756;
        $model = DB::table('modelo')->where('sku', $sku)->first();
        $documentos = DB::select("SELECT documento.id, documento.no_venta,marketplace.marketplace, area.area, empresa.empresa, 
                                        almacen.almacen, documento.id_tipo, dt.tipo, df.fase, modelo.descripcion, movimiento.cantidad, 
                                        documento.created_at, documento.`status` FROM movimiento
                                            INNER JOIN documento ON documento.id = movimiento.id_documento
                                            INNER JOIN empresa_almacen AS ea ON ea.id = documento.id_almacen_principal_empresa
                                            INNER JOIN empresa ON empresa.id = ea.id_empresa
                                            INNER JOIN almacen ON almacen.id = ea.id_almacen
                                            INNER JOIN documento_tipo AS dt ON dt.id = documento.id_tipo
                                            INNER JOIN documento_fase AS df ON df.id = documento.id_fase
                                            INNER JOIN modelo on modelo.id = movimiento.id_modelo
                                            inner join marketplace_area as ma on ma.id = documento.id_marketplace_area
                                            inner join marketplace on marketplace.id = ma.id_marketplace
                                            inner join area on area.id = ma.id_area
                                        WHERE
                                            documento.created_at LIKE '%2023-12-%'
                                            AND movimiento.id_modelo = 24160
                                        ORDER BY
                                            documento.created_at ASC ");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('FALTANTES DE SURTIR');
        $contador_fila = 2;

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'VENTA');
        $sheet->setCellValue('C1', 'MARKETPLACE');
        $sheet->setCellValue('D1', 'AREA');
        $sheet->setCellValue('E1', 'EMPRESA');
        $sheet->setCellValue('F1', 'ALMACEN');
        $sheet->setCellValue('G1', 'TIPO');
        $sheet->setCellValue('H1', 'FASE');
        $sheet->setCellValue('I1', 'DESCRIPCION');
        $sheet->setCellValue('J1', 'CANTIDAD');
        $sheet->setCellValue('K1', 'FECHA');
        $sheet->setCellValue('L1', 'ESTATUS CRM');
        $sheet->setCellValue('M1', 'ESTATUS ML');

        $spreadsheet->getActiveSheet()->getStyle('A1:N1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:N1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($documentos as $venta) {
            $esML = false;
            if ($venta->id_tipo == 2 && $venta->marketplace == "MERCADOLIBRE") {
                $validar_buffered = MercadolibreService::validarPendingBuffered($venta->id);
                $esML = true;
            }

            $sheet->setCellValue('A' . $contador_fila, $venta->id);
            $sheet->setCellValue('B' . $contador_fila, $venta->no_venta);
            $sheet->setCellValue('C' . $contador_fila, $venta->marketplace);
            $sheet->setCellValue('D' . $contador_fila, $venta->area);
            $sheet->setCellValue('E' . $contador_fila, $venta->empresa);
            $sheet->setCellValue('F' . $contador_fila, $venta->almacen);
            $sheet->setCellValue('G' . $contador_fila, $venta->tipo);
            $sheet->setCellValue('H' . $contador_fila, $venta->fase);
            $sheet->setCellValue('I' . $contador_fila, $venta->descripcion);
            $sheet->setCellValue('J' . $contador_fila, $venta->cantidad);
            $sheet->setCellValue('K' . $contador_fila, $venta->created_at);
            $sheet->setCellValue('L' . $contador_fila, $venta->status ? "Activa" : "Cancelada");
            $sheet->setCellValue('M' . $contador_fila, $esML ? $validar_buffered->substatus : "N/A");

            $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $contador_fila++;
        }

        foreach (range('A', 'M') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('reportePantallas.xlsx');

        $json['code'] = 200;
        $json['ventas'] = $documentos;
        $json['excel'] = base64_encode(file_get_contents('reportePantallas.xlsx'));

        unlink('reportePantallas.xlsx');

        return response()->json($json);

        /*$documentos = DB::select("SELECT documento.id, documento.no_venta,empresa.empresa, almacen.almacen, dt.tipo, df.fase, documento.created_at from documento
                                    inner join empresa_almacen as ea on ea.id = documento.id_almacen_principal_empresa
                                    inner join empresa on empresa.id = ea.id_empresa
                                    inner join almacen on almacen.id = ea.id_almacen
                                    inner join documento_tipo as dt on dt.id = documento.id_tipo
                                    inner join documento_fase as df on df.id = documento.id_fase
                                    where documento.id_fase in(1,3,5,6) and documento.id_marketplace_area in(1, 32, 43, 52, 55, 58) 
                                      and documento.id not in (select id_documento from documento_guia where guia in (select guia from manifiesto)) 
                                      and documento.`status` = 1 and documento.id_tipo = 2 and documento.created_at like '%2023-12-%' and almacen.id != 2
                                    ORDER BY documento.created_at ASC
                                    ");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('FALTANTES DE SURTIR');
        $contador_fila = 2;

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'VENTA');
        $sheet->setCellValue('C1', 'EMPRESA');
        $sheet->setCellValue('D1', 'ALMACEN');
        $sheet->setCellValue('E1', 'TIPO');
        $sheet->setCellValue('F1', 'FASE');
        $sheet->setCellValue('G1', 'STATUS ML');
        $sheet->setCellValue('H1', 'FECHA');

        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($documentos as $venta) {
            $validar_buffered = MercadolibreService::validarPendingBuffered($venta->id);

            $sheet->setCellValue('A' . $contador_fila, $venta->id);
            $sheet->setCellValue('B' . $contador_fila, $venta->no_venta);
            $sheet->setCellValue('C' . $contador_fila, $venta->empresa);
            $sheet->setCellValue('D' . $contador_fila, $venta->almacen);
            $sheet->setCellValue('E' . $contador_fila, $venta->tipo);
            $sheet->setCellValue('F' . $contador_fila, $venta->fase);
            $sheet->setCellValue('G' . $contador_fila, $validar_buffered->error ? $validar_buffered->mensaje : $validar_buffered->substatus);
            $sheet->setCellValue('H' . $contador_fila, $venta->created_at);

            $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $contador_fila++;
        }

        foreach (range('A', 'H') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_ML.xlsx');

        $json['code'] = 200;
        $json['ventas'] = $documentos;
        $json['excel'] = base64_encode(file_get_contents('reporte_ML.xlsx'));

        unlink('reporte_ML.xlsx');

        return response()->json($json);*/
    }

    public function inventarioinicial(Request $request)
    {
        set_time_limit(0);
        $productos = json_decode($request->input("productos"));

        foreach ($productos as $producto) {
            if ($producto->sku != '' && $producto->sku != "NO DISPONIBLE") {
                $modelo = DB::table('modelo')->where('sku', $producto->sku)->orWhere('np', $producto->sku)->first();

                if (!empty($modelo)) {
                    if ($modelo->id_tipo != 4) {
                        $existeModelo = DB::table('modelo_costo')->where('id_modelo', $modelo->id)->first();

                        if (empty($existeModelo)) {
                            DB::table('modelo_costo')->insert([
                                'id_modelo' => $modelo->id,
                                'stock' => $producto->stock,
                                'stock_inicial' => $producto->stock,
                                'costo_inicial' => $producto->precio,
                                'costo_promedio' => $producto->precio,
                                'ultimo_costo' => 0
                            ]);
                        } else {
                            DB::table('modelo_costo')->where('id_modelo', $modelo->id)->update([
                                'stock' => $existeModelo->stock + $producto->stock,
                                'stock_inicial' => $existeModelo->stock + $producto->stock
                            ]);
                        }

                        $empresa = DB::table('empresa')->where('empresa', $producto->empresa)->first();
                        $almacenCRM = DB::table('almacen')->where('almacen', $producto->almacen)->first();
                        $empresa_almacen = DB::table('empresa_almacen')->where('id_empresa', $empresa->id)->where('id_almacen', $almacenCRM->id)->first();
                        $modeloAlmacen = DB::table('modelo_costo_almacen')->where('id_modelo', $modelo->id)->where('id_almacen', $empresa_almacen->id)->first();

                        if (empty($modeloAlmacen)) {
                            DB::table('modelo_costo_almacen')->insert([
                                'id_modelo' => $modelo->id,
                                'id_almacen' => $empresa_almacen->id,
                                'stock' => $producto->stock,
                                'stock_inicial' => $producto->stock,
                                'costo_inicial' => $producto->precio,
                                'costo_promedio' => $producto->precio,
                                'ultimo_costo' => 0
                            ]);
                        } else {
                            DB::table('modelo_costo_almacen')->where('id_modelo', $modelo->id)->where('id_almacen', $empresa_almacen->id)->update([
                                'stock' => $modeloAlmacen->stock + $producto->stock,
                                'stock_inicial' => $modeloAlmacen->stock_inicial + $producto->stock
                            ]);
                        }
                    }
                }
            }
        }
        //        $noEncontrados = [];
        //        $faltantes = [];
        //        $encontrado = false;
        //
        //        $spreadsheet = new Spreadsheet();
        //        $sheet = $spreadsheet->getActiveSheet()->setTitle('NoEncontrados');
        //        $contador_fila = 2;
        //
        //        $sheet->setCellValue('A1', 'MODELO');
        //        $sheet->setCellValue('B1', 'SKU');
        //        $sheet->setCellValue('C1', 'NUMERO DE PARTE');
        //        $sheet->setCellValue('D1', 'DESCRIPCION');
        //
        //        $spreadsheet->getActiveSheet()->getStyle('A1:D1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        //        $spreadsheet->getActiveSheet()->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');
        //
        //        $modelos = DB::table('modelo')->get();
        //
        //        foreach ($modelos as $produ) {
        //            foreach ($productos as $producto) {
        //                if($producto->sku != '' && $producto->sku != "NO DISPONIBLE") {
        //                    if($produ->sku == $producto->sku || $produ->np == $producto->sku) {
        //                        $encontrado = true;
        //                    }
        //                }
        //            }
        //            if(!$encontrado) {
        //                $sheet->setCellValue('A' . $contador_fila, $produ->id);
        //                $sheet->setCellValue('B' . $contador_fila, $produ->sku);
        //                $sheet->setCellValue('C' . $contador_fila, $produ->np);
        //                $sheet->setCellValue('D' . $contador_fila, $produ->descripcion);
        //
        //                $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit($produ->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        //                $sheet->getCellByColumnAndRow(3, $contador_fila)->setValueExplicit($produ->np, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        //
        //                $contador_fila++;
        //            } else {
        //                $encontrado = false;
        //            }
        //        }

        //        foreach ($productos as $producto) {
        //            if($producto->sku != '' && $producto->sku != "NO DISPONIBLE") {
        //                array_push($noEncontrados, $producto->sku);
        //                $modelo = DB::table('modelo')->where('sku', $producto->sku)->orWhere('np', $producto->sku)->first();
        //
        //                if (empty($modelo)) {
        //                    array_push($noEncontrados, $producto->sku);
        //                    $sheet->setCellValue('A' . $contador_fila, $producto->sku);
        //                    $sheet->setCellValue('B' . $contador_fila, $producto->descripcion);
        //                    $sheet->setCellValue('C' . $contador_fila, $producto->precio);
        //                    $sheet->setCellValue('D' . $contador_fila, $producto->stock);
        //                    $sheet->setCellValue('E' . $contador_fila, $producto->almacen);
        //                    $sheet->setCellValue('F' . $contador_fila, $producto->empresa);
        //
        //                    $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        //
        //                    $contador_fila++;
        //                }
        //            }
        //        }

        //        foreach (range('A', 'D') as $columna) {
        //            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        //        }
        //
        //        $writer = new Xlsx($spreadsheet);
        //        $writer->save('faltantes.xlsx');
        //
        //        $json['code'] = 200;
        //        $json['excel'] = base64_encode(file_get_contents('faltantes.xlsx'));
        //
        //        unlink('faltantes.xlsx');

        //        return response()->json($json);

        return response()->json([
            'code'  => 200,
            'message'   => "Si a veces soy una cosa barbara...."
        ]);
    }

    public function recepciones(Request $request)
    {
        $documentoERP = $request->input("idErp");
        $documento = 0;

        $movimientos = DB::table('movimiento')->whereIn('id', function ($querybuilder) use ($documentoERP) {
            $querybuilder->select('id_movimiento')->from('documento_recepcion')->where('documento_erp', $documentoERP)->distinct();
        })->get();

        if (empty($movimientos)) {
            return response()->json([
                'code'  => 200,
                'message'   => "No se encontraron registros con el documento.",
            ]);
        } else {
            foreach ($movimientos as $mov) {
                if ($documento == 0) {
                    $documento = $mov->id_documento;
                }
                DB::table('movimiento')->where('id', $mov->id)->update([
                    'cantidad_aceptada' => 0,
                    'cantidad_recepcionada' => 0
                ]);

                $movProd = DB::table('movimiento_producto')->where('id_movimiento', $mov->id)->get();

                foreach ($movProd as $mp) {
                    DB::table('producto')->where('id', $mp->id_producto)->delete();
                    DB::table('movimiento_producto')->where('id', $mp->id)->delete();
                }
            }
        }

        if ($documento != 0) {
            DB::table('documento')->where('id', $documento)->update([
                'id_fase' => 606
            ]);
        }

        $recepciones = DB::table('documento_recepcion')->where('documento_erp', $documentoERP)->get();

        foreach ($recepciones as $recepcion) {
            DB::table('documento_recepcion')->where('id', $recepcion->id)->delete();
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Si a veces soy una cosa barbara.... Recepcion Borrada",
            'Movimientos' => $movimientos,
            'Recepciones' => $recepciones,
            'ERP' => $documentoERP
        ]);
    }

    public static function actualizarDocumentoComercial($documento)
    {
        set_time_limit(0);

        $bd = DB::table('documento')
            ->select('empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->where('documento.id', $documento)
            ->first();

        $tiene_doc_comercial2 = DB::table('doc_comercial')->where('id_documento', $documento)->first();

        $info_factura = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/' . $bd->bd . '/Factura/Estado/Folio/' . $documento));

        if (is_array($info_factura)) {
            if (empty($info_factura[0])) {

                if (empty($tiene_doc_comercial2)) {
                    DB::table('doc_comercial')->insert([
                        'id_documento' => $documento,
                        'mensaje' => "Actualizado",
                        'importado' => 5
                    ]);
                } else {
                    DB::table('doc_comercial')->where('id_documento', $documento)->update([
                        'mensaje' => 'No hay informacion de la factura',
                        'importado' => 5,
                        'ultimo_seg' => ''
                    ]);
                }

                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                ]);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 6,
                'factura_serie' => $info_factura[0]->serie,
                'factura_folio' => $info_factura[0]->folio,
                'documento_extra' => $info_factura[0]->documentoid
            ]);

            if (empty($tiene_doc_comercial2)) {
                DB::table('doc_comercial')->insert([
                    'id_documento' => $documento,
                    'mensaje' => "Actualizado",
                    'importado' => 1
                ]);
            } else {
                DB::table('doc_comercial')->where('id_documento', $documento)->update([
                    'mensaje' => '',
                    'importado' => 1,
                    'ultimo_seg' => ''
                ]);
            }
        } else {
            if (empty($info_factura)) {
                if (empty($tiene_doc_comercial2)) {
                    DB::table('doc_comercial')->insert([
                        'id_documento' => $documento,
                        'mensaje' => "No se pudo actualizar",
                        'importado' => 0
                    ]);
                } else {
                    DB::table('doc_comercial')->where('id_documento', $documento)->update([
                        'mensaje' => '',
                        'importado' => 1,
                        'ultimo_seg' => ''
                    ]);
                }

                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                ]);
            }

            if (empty($tiene_doc_comercial2)) {
                DB::table('doc_comercial')->insert([
                    'id_documento' => $documento,
                    'mensaje' => "Actualizado",
                    'importado' => 1
                ]);
            } else {
                DB::table('doc_comercial')->where('id_documento', $documento)->update([
                    'mensaje' => '',
                    'importado' => 1,
                    'ultimo_seg' => ''
                ]);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 6,
                'factura_serie' => $info_factura->serie,
                'factura_folio' => $info_factura->folio,
                'documento_extra' => $info_factura->documentoid
            ]);
        }

        return response()->json([
            'code'  => 200
        ]);
    }

    public static function importarDocumentoComercial($documento)
    {
        set_time_limit(0);

        $bd = DB::table('documento')
            ->select('empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->where('documento.id', $documento)
            ->get();

        $bd = $bd[0];

        //Aqui ta
        $response = DocumentoService::crearFactura($documento, 0, 0);

        if ($response->error) {

            $tiene_doc_comercial = DB::table('doc_comercial')->where('id_documento', $documento)->first();

            if (empty($tiene_doc_comercial)) {
                //nO EXISTE
                DB::table('doc_comercial')->insert([
                    'id_documento' => $documento,
                    'mensaje' => $response->mensaje ?? 'Error desconocido',
                    'importado' => 0,
                    'referencia' => date('dmY')
                ]);
            } else {
                //Si existe
                DB::table('doc_comercial')->where('id_documento', $documento)->update([
                    'mensaje' => $response->mensaje ?? 'Error desconocido',
                    'importado' => 0,
                    'referencia' => date('dmY')
                ]);
            }

            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje ?? 'Error desconocido'
            ]);
        } else {

            $tiene_doc_comercial2 = DB::table('doc_comercial')->where('id_documento', $documento)->first();

            if (empty($tiene_doc_comercial2)) {
                DB::table('doc_comercial')->insert([
                    'id_documento' => $documento,
                    'importado' => 1,
                    'referencia' => date('dmY')
                ]);
            } else {
                DB::table('doc_comercial')->where('id_documento', $documento)->update([
                    'mensaje' => '',
                    'importado' => 1,
                    'referencia' => date('dmY')
                ]);
            }

            $info_factura = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/' . $bd->bd . '/Factura/Estado/Folio/' . $documento));

            if (is_array($info_factura)) {
                if (empty($info_factura[0])) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                    ]);
                }

                DB::table('documento')->where('id', $documento)->update([
                    'id_fase' => 6,
                    'factura_serie' => $info_factura[0]->serie,
                    'factura_folio' => $info_factura[0]->folio,
                    'documento_extra' => $info_factura[0]->documentoid
                ]);
            } else {
                if (empty($info_factura)) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                    ]);
                }

                DB::table('documento')->where('id', $documento)->update([
                    'id_fase' => 6,
                    'factura_serie' => $info_factura->serie,
                    'factura_folio' => $info_factura->folio,
                    'documento_extra' => $info_factura->documentoid
                ]);
            }

            return response()->json([
                'code'  => 200,
                'message'   => $response->mensaje,
                'developer' => $response->developer
            ]);
        }
    }


    public static function ventacomercial($documento)
    {
        set_time_limit(0);

        $bd = DB::table('documento')
            ->select('empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->where('documento.id', $documento)
            ->get();

        $bd = $bd[0];

        $info_documento = DB::table('documento')->where('id', $documento)->first();

        if ($info_documento->id_tipo == 2) {
            if ($info_documento->id_fase < 5) {
                return response()->json([
                    'code'  => 400,
                    'message'   => 'El documento no ha sido finalizado.',
                ]);
            }
            //Aqui ta
            $response = DocumentoService::crearFactura($documento, 0, 0);

            if ($response->error) {

                $tiene_doc_comercial = DB::table('doc_comercial')->where('id_documento', $documento)->first();

                if (empty($tiene_doc_comercial)) {
                    DB::table('doc_comercial')->insert([
                        'id_documento' => $documento,
                        'mensaje' => $response->mensaje ?? 'Error desconocido',
                        'importado' => 0
                    ]);
                } else {
                    DB::table('doc_comercial')->where('id_documento', $documento)->update([
                        'mensaje' => $response->mensaje ?? 'Error desconocido',
                        'importado' => 0
                    ]);
                }

                return response()->json([
                    'code'  => 500,
                    'message'   => $response->mensaje ?? 'Error desconocido'
                ]);
            } else {

                $tiene_doc_comercial2 = DB::table('doc_comercial')->where('id_documento', $documento)->first();

                if (empty($tiene_doc_comercial2)) {
                    DB::table('doc_comercial')->insert([
                        'id_documento' => $documento,
                        'importado' => 1
                    ]);
                } else {
                    DB::table('doc_comercial')->where('id_documento', $documento)->update([
                        'mensaje' => '',
                        'importado' => 1
                    ]);
                }

                $info_factura = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/' . $bd->bd . '/Factura/Estado/Folio/' . $documento));

                if (is_array($info_factura)) {
                    if (empty($info_factura[0])) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                        ]);
                    }

                    DB::table('documento')->where(['id' => $documento])->update([
                        'id_fase' => 6,
                        'factura_serie' => $info_factura[0]->serie,
                        'factura_folio' => $info_factura[0]->folio,
                        'documento_extra' => $info_factura[0]->documentoid
                    ]);
                } else {
                    if (empty($info_factura)) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                        ]);
                    }

                    DB::table('documento')->where(['id' => $documento])->update([
                        'id_fase' => 6,
                        'factura_serie' => $info_factura->serie,
                        'factura_folio' => $info_factura->folio,
                        'documento_extra' => $info_factura->documentoid
                    ]);
                }

                return response()->json([
                    'code'  => 200,
                    'message'   => $response->mensaje,
                    'developer' => $response->developer
                ]);
            }
        } else if (in_array($info_documento->id_tipo, array(3, 4, 5, 11))) {
            if ($info_documento->id_fase != 100) {
                return response()->json([
                    'code'  => 400,
                    'message'   => 'El documento no ha sido finalizado.',
                ]);
            }
            $movimiento = DocumentoService::crearMovimiento($documento);

            if ($movimiento->error) {
                return response()->json([
                    'code'  => 500,
                    'message'   => $movimiento->mensaje ?? 'Error desconocido',
                ]);
            } else {
                return response()->json([
                    'code'  => 200,
                    'message'   => $movimiento->mensaje
                ]);
            }
        }

        return response()->json([
            'code'  => 400,
            'message' => "Documento no valido"
        ]);
    }


    public static function actualizar_docextra()
    {
        set_time_limit(0);

        $documento =

            $bd = DB::table('documento')
            ->select('empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->where('documento.id', $documento)
            ->first();

        $info_factura = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/' . $bd->bd . '/Factura/Estado/Folio/' . $documento));

        if (is_array($info_factura)) {
            if (empty($info_factura[0])) {

                DB::table('doc_comercial')->where(['id' => $documento])->update([
                    'id_documento' => $documento,
                    'mensaje' => "No se encontró información de la factura, favor de contactar a un administrador.",
                    'importado' => 4
                ]);

                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                ]);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 6,
                'factura_serie' => $info_factura[0]->serie,
                'factura_folio' => $info_factura[0]->folio,
                'documento_extra' => $info_factura[0]->documentoid,
            ]);
        } else {

            if (empty($info_factura)) {
                DB::table('doc_comercial')->where(['id' => $documento])->update([
                    'id_documento' => $documento,
                    'mensaje' => "No se encontró información de la factura, favor de contactar a un administrador.",
                    'importado' => 4
                ]);
                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró información de la factura, favor de contactar a un administrador."
                ]);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 6,
                'factura_serie' => $info_factura->serie,
                'factura_folio' => $info_factura->folio,
                'documento_extra' => $info_factura->documentoid

            ]);

            return response()->json([
                'code'  => 200,
            ]);
        }
    }

    public function recostear()
    {
        set_time_limit(0);

        $modelos_costo = DB::table('modelo_costo')->get();

        if (!empty($modelos_costo)) {
            foreach ($modelos_costo as $model) {
                $movimientos = DB::select(
                    "( SELECT
                                                m.id,
                                                m.id_modelo,
                                                m.id_documento,
                                                d.id_fase,
                                                d.id_tipo,
                                                d.tipo_cambio,
                                                d.factura_folio,
                                                d.id_almacen_principal_empresa AS id_almacen,
                                                d.id_almacen_secundario_empresa AS id_almacen_dos,
                                                m.cantidad,
                                                m.precio,
                                                m.created_at AS fecha
                                                FROM
                                                    movimiento AS m
                                                    INNER JOIN documento AS d ON d.id = m.id_documento
                                                WHERE
                                                    m.created_at > '2023-09-30 23:59:59'
                                                    AND d.`status` = 1
                                                    AND m.id_modelo = " . $model->id_modelo . "
                                                ) UNION
                                                (
                                                SELECT
                                                    m.id,
                                                    m.id_modelo,
                                                    m.id_documento,
                                                    d.id_fase,
                                                    d.id_tipo,
                                                    d.tipo_cambio,
                                                    d.factura_folio,
                                                    d.id_almacen_principal_empresa AS id_almacen,
                                                    d.id_almacen_secundario_empresa AS id_almacen_dos,
                                                    dr.cantidad,
                                                    m.precio,
                                                    dr.created_at AS fecha
                                                FROM
                                                    documento_recepcion AS dr
                                                    INNER JOIN movimiento AS m ON m.id = dr.id_movimiento
                                                    INNER JOIN documento AS d ON d.id = m.id_documento
                                                WHERE
                                                    dr.created_at > '2023-09-30 23:59:59'
                                                    AND d.`status` = 1
                                                    AND m.id_modelo = " . $model->id_modelo . "
                                                    AND dr.id_movimiento NOT IN (
                                                    SELECT
                                                        mo.id
                                                    FROM
                                                        movimiento AS mo
                                                        INNER JOIN documento AS doc ON doc.id = mo.id_documento
                                                    WHERE
                                                        mo.created_at > '2023-09-30 23:59:59'
                                                        AND doc.`status` = 1
                                                    ))
                                            ORDER BY
                                                fecha"
                );

                if (!empty($movimientos)) {
                    foreach ($movimientos as $venta) {
                        $datosIniciales = DB::table('modelo_costo')->where('id_modelo', $venta->id_modelo)->first();
                        //Si tenemos el registro en nuestras tablas de costos
                        $stock_total = $datosIniciales->stock;
                        $montoTotal = 0;
                        $costo_promedio = $datosIniciales->costo_promedio;

                        if ($venta->id_tipo == 0) {
                            //Si el documento es una compra afectamos inventario y costeamos
                            $montoCompra = round($venta->cantidad * $venta->precio * $venta->tipo_cambio, 2);

                            if ($stock_total <= 0) {
                                //Si ya no hay inventario el costo promedio nuevo es el de la compra
                                $this->actualizarInventario(
                                    $venta,
                                    $stock_total + $venta->cantidad,
                                    $venta->precio * $venta->tipo_cambio,
                                    $datosIniciales->costo_promedio,
                                    $stock_total,
                                    1
                                );

                                $this->agregarAKardex(
                                    $venta,
                                    $venta->cantidad,
                                    $stock_total,
                                    1,
                                    $costo_promedio
                                );
                            } else {
                                $montoTotal = $stock_total * $costo_promedio;
                                $costo_promedio = ($montoTotal + $montoCompra) / ($stock_total + $venta->cantidad);

                                $this->actualizarInventario(
                                    $venta,
                                    $stock_total + $venta->cantidad,
                                    $costo_promedio,
                                    $datosIniciales->costo_promedio,
                                    $stock_total,
                                    1
                                );

                                $this->agregarAKardex(
                                    $venta,
                                    $venta->cantidad,
                                    $stock_total,
                                    1,
                                    $datosIniciales->costo_promedio
                                );
                            }
                        } else if ($venta->id_tipo == 3) {
                            //Documento tipo entrada solo se aumenta el inventario
                            $this->actualizarInventario(
                                $venta,
                                $stock_total + $venta->cantidad,
                                $costo_promedio,
                                0,
                                $stock_total,
                                1
                            );

                            $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
                        } else if ($venta->id_tipo == 6) {
                            //Documento tipo nota de credito(devolucion) se aumenta el inventario
                            $this->actualizarInventario(
                                $venta,
                                $stock_total + $venta->cantidad,
                                $costo_promedio,
                                0,
                                $stock_total,
                                1
                            );

                            $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
                        } else if ($venta->id_tipo == 4) {
                            //Documento tipo salida de almacen si ya tiene factura en comercial es que ya salio y se reduce inventario
                            $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);

                            if (($stock_total - $venta->cantidad) <= 0) {
                                $this->actualizarInventario(
                                    $venta,
                                    $stock_total - $venta->cantidad,
                                    0,
                                    $costo_promedio,
                                    $stock_total,
                                    0
                                );
                            } else {
                                $this->actualizarInventario(
                                    $venta,
                                    $stock_total - $venta->cantidad,
                                    $costo_promedio,
                                    0,
                                    $stock_total,
                                    0
                                );
                            }
                        } else if ($venta->id_tipo == 5) {
                            //Documento tipo traspaso movimiento entre almacenes
                            $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);

                            $this->movimientoEntreAlmacen($venta, $venta->id_almacen, $venta->id_almacen_dos);
                        } else if ($venta->id_tipo == 2) {
                            //Documento tipo venta se reduce inventario si esta terminado o en fase factura(va a desaparecer)
                            if ($venta->id_fase > 4) {
                                $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);

                                if (($stock_total - $venta->cantidad) <= 0) {
                                    $this->actualizarInventario(
                                        $venta,
                                        $stock_total - $venta->cantidad,
                                        0,
                                        $costo_promedio,
                                        $stock_total,
                                        0
                                    );
                                } else {
                                    $this->actualizarInventario(
                                        $venta,
                                        $stock_total - $venta->cantidad,
                                        $costo_promedio,
                                        0,
                                        $stock_total,
                                        0
                                    );
                                }
                            }
                        } else {
                            $this->agregarAKardex($venta, $venta->cantidad, 0, 0, 0);
                        }
                    }
                }
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Si a veces soy una cosa barbara....",
            'Movimientos' => $movimientos
        ]);
    }

    //    public function recostear()
    //    {
    //        set_time_limit(0);
    //
    //        $modelos_costo = DB::table('modelo_costo as mc')
    //            ->join('modelo as m', 'm.id', '=', 'mc.id_modelo')
    //            ->select('mc.*', '.id_tipo as tipo_modelo')->get();
    //
    //        if (!empty($modelos_costo)) {
    //            foreach ($modelos_costo as $model) {
    //                if ($model->tipo_modelo == 1) {
    //                    $movimientos = DB::table('movimiento as m')
    //                        ->join('documento as d', 'd.id', '=', 'm.id_documento')
    //                        ->leftJoin('documento_recepcion as dr', 'dr.id_movimiento', '=', 'm.id')
    //                        ->select('d.id_tipo as tipo_doc', 'd.id_fase', 'd.factura_folio',  'm.created_at', 'd.id_almacen_principal_empresa as id_almacen', 'dr.cantidad as recepcionada', 'd.tipo_cambio', 'm.*', 'd.id_tipo')
    //                        ->where('m.created_at', '>', '2023-04-30 23:59:59')->where('id_modelo', $model->id_modelo)->orderBy('m.created_at', 'asc')->get();
    //
    //                    if (!empty($movimientos)) {
    //                        foreach ($movimientos as $venta) {
    //                            $datosIniciales = DB::table('modelo_costo')->where('id_modelo', $venta->id_modelo)->first();
    //                            //Si tenemos el registro en nuestras tablas de costos
    //                            $stock_total = $datosIniciales->stock;
    //                            $montoTotal = 0;
    //                            $costo_promedio = $datosIniciales->costo_promedio;
    //
    //                            if ($venta->id_tipo == 0) {
    //                                //Si el documento es una compra afectamos inventario y costeamos
    //                                $montoCompra = round($venta->cantidad * $venta->precio * $venta->tipo_cambio, 2);
    //
    //                                if ($stock_total <= 0) {
    //                                    //Si ya no hay inventario el costo promedio nuevo es el de la compra
    //                                    $this->actualizarInventario($venta,
    //                                        $venta->cantidad,
    //                                        $venta->precio * $venta->tipo_cambio,
    //                                        $datosIniciales->costo_promedio,
    //                                        $stock_total);
    //
    //                                    $this->agregarAKardex($venta,
    //                                        $venta->recepcionada,
    //                                        $stock_total,
    //                                        1,
    //                                        $costo_promedio
    //                                    );
    //                                } else {
    //                                    $montoTotal = $stock_total * $costo_promedio;
    //                                    $costo_promedio = ($montoTotal + $montoCompra) / ($stock_total + $venta->recepcionada);
    //
    //                                    $this->actualizarInventario($venta,
    //                                        $stock_total + $venta->recepcionada,
    //                                        $costo_promedio,
    //                                        $datosIniciales->costo_promedio,
    //                                        $stock_total
    //                                    );
    //
    //                                    $this->agregarAKardex(
    //                                        $venta,
    //                                        $venta->recepcionada,
    //                                        $stock_total,
    //                                        1,
    //                                        $datosIniciales->costo_promedio
    //                                    );
    //                                }
    //                            } else if ($venta->id_tipo == 3) {
    //                                //Documento tipo entrada solo se aumenta el inventario
    //                                $this->actualizarInventario(
    //                                    $venta,
    //                                    $stock_total + $venta->cantidad,
    //                                    $costo_promedio,
    //                                    0,
    //                                    $stock_total
    //                                );
    //
    //                                $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
    //                            } else if ($venta->id_tipo == 6) {
    //                                //Documento tipo nota de credito(devolucion) se aumenta el inventario
    //                                $this->actualizarInventario(
    //                                    $venta,
    //                                    $stock_total + $venta->cantidad,
    //                                    $costo_promedio,
    //                                    0,
    //                                    $stock_total
    //                                );
    //
    //                                $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
    //                            } else if ($venta->id_tipo == 4) {
    //                                //Documento tipo salida de almacen si ya tiene factura en comercial es que ya salio y se reduce inventario
    //                                if ($venta->factura_folio != "N/A") {
    //                                    $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
    //
    //                                    if (($stock_total - $venta->cantidad) <= 0) {
    //                                        $this->actualizarInventario(
    //                                            $venta,
    //                                            $stock_total - $venta->cantidad,
    //                                            0,
    //                                            $costo_promedio,
    //                                            $stock_total
    //                                        );
    //                                    } else {
    //                                        $this->actualizarInventario(
    //                                            $venta,
    //                                            $stock_total - $venta->cantidad,
    //                                            $costo_promedio,
    //                                            0,
    //                                            $stock_total
    //                                        );
    //                                    }
    //                                }
    //                            } else if ($venta->id_tipo == 2) {
    //                                //Documento tipo venta se reduce inventario si esta terminado o en fase factura(va a desaparecer)
    //                                if ($venta->id_fase == 6 || $venta->id_fase == 5) {
    //                                    $this->agregarAKardex($venta, $venta->cantidad, $stock_total, 0, $costo_promedio);
    //
    //                                    if (($stock_total - $venta->cantidad) <= 0) {
    //                                        $this->actualizarInventario(
    //                                            $venta,
    //                                            $stock_total - $venta->cantidad,
    //                                            0,
    //                                            $costo_promedio,
    //                                            $stock_total
    //                                        );
    //                                    } else {
    //                                        $this->actualizarInventario(
    //                                            $venta,
    //                                            $stock_total - $venta->cantidad,
    //                                            $costo_promedio,
    //                                            0,
    //                                            $stock_total
    //                                        );
    //                                    }
    //                                }
    //                            } else {
    //                                $this->agregarAKardex($venta, $venta->cantidad,0,0,0);
    //                            }
    //                            $stock_total = 0;
    //                            $montoTotal = 0;
    //                            $costo_promedio = 0;
    //                        }
    //                    }
    //                }
    //            }
    //        }
    //
    //        return response()->json([
    //            'code'  => 200,
    //            'message'   => "Si a veces soy una cosa barbara...."
    //        ]);
    //    }

    public function actualizarInventario($venta, $stock, $costoPromedio, $ultimoCosto, $stockAnterior, $cantidad)
    {
        DB::table('modelo_costo')->where('id_modelo', $venta->id_modelo)->update([
            'stock' => $stock,
            'stock_anterior' => $stockAnterior,
            'costo_promedio' => $costoPromedio,
            'ultimo_costo' => $ultimoCosto
        ]);

        $datosAlmacen = DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $venta->id_almacen)->first();

        if (!empty($datosAlmacen)) {
            DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $venta->id_almacen)->update([
                'stock' => $cantidad == 0 ? $datosAlmacen->stock - $venta->cantidad : $datosAlmacen->stock + $venta->cantidad,
                'stock_anterior' => $datosAlmacen->stock,
                'costo_promedio' => $costoPromedio,
                'ultimo_costo' => $ultimoCosto
            ]);
        } else {
            DB::table('modelo_costo_almacen')->insert([
                'id_modelo' => $venta->id_modelo,
                'id_almacen' => $venta->id_almacen,
                'stock' => $cantidad == 0 ? 0 - $venta->cantidad : $venta->cantidad,
                'stock_inicial' => $venta->cantidad,
                'costo_inicial' => $costoPromedio,
                'costo_promedio' => $costoPromedio,
                'ultimo_costo' => 0
            ]);
        }
    }

    public function movimientoEntreAlmacen($venta, $almacenEntrada, $almacenSalida)
    {
        $entrada = DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $almacenEntrada)->first();

        if (!empty($entrada)) {
            DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $almacenEntrada)->update([
                'stock' => $entrada->stock + $venta->cantidad
            ]);
        } else {
            DB::table('modelo_costo_almacen')->insert([
                'id_modelo' => $venta->id_modelo,
                'id_almacen' => $almacenEntrada,
                'stock' => $venta->cantidad,
                'stock_inicial' => $venta->cantidad,
                'costo_inicial' => 0,
                'costo_promedio' => 0,
                'ultimo_costo' => 0
            ]);
        }

        $salida = DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $almacenSalida)->first();

        if (!empty($salida)) {
            DB::table('modelo_costo_almacen')->where('id_modelo', $venta->id_modelo)->where('id_almacen', $almacenSalida)->update([
                'stock' => $salida->stock - $venta->cantidad
            ]);
        } else {
            DB::table('modelo_costo_almacen')->insert([
                'id_modelo' => $venta->id_modelo,
                'id_almacen' => $almacenSalida,
                'stock' => 0 - $venta->cantidad,
                'stock_inicial' => 0 - $venta->cantidad,
                'costo_inicial' => 0,
                'costo_promedio' => 0,
                'ultimo_costo' => 0
            ]);
        }
    }
    public function agregarAInventario($modelo, $cantidad, $precio, $almacen)
    {
        DB::table('modelo_costo')->insert([
            'id_modelo' => $modelo,
            'stock' => $cantidad,
            'stock_inicial' => $cantidad,
            'costo_inicial' => $precio,
            'costo_promedio' => $precio,
            'ultimo_costo' => $precio
        ]);

        DB::table('modelo_costo_almacen')->insert([
            'id_modelo' => $modelo,
            'id_almacen' => $almacen,
            'stock' => $cantidad,
            'stock_inicial' => $cantidad,
            'costo_inicial' => $precio,
            'costo_promedio' => $precio,
            'ultimo_costo' => $precio
        ]);
    }

    public function agregarAKardex($venta, $cantidad, $stock_anterior, $afectaCosto, $costo_promedio)
    {
        DB::table('modelo_inventario')->insert([
            'id_modelo' => $venta->id_modelo,
            'id_documento' => $venta->id_documento,
            'id_tipo_documento' => $venta->id_tipo,
            'id_fase' => $venta->id_fase,
            'id_almacen' => $venta->id_almacen,
            'id_almacen_salida' => $venta->id_almacen_dos,
            'afecta_costo' => $afectaCosto,
            'cantidad' => $cantidad,
            'costo' => $venta->precio * $venta->tipo_cambio,
            'total' => round($cantidad * $venta->precio * $venta->tipo_cambio, 2),
            'stock_anterior' => $stock_anterior,
            'costo_promedio' => $costo_promedio,
            'created_at' => $venta->fecha
        ]);
    }

    public function reporteinventario(Request $request)
    {
        set_time_limit(0);

        $sku = $request->input("sku");

        $modelo = DB::table('modelo')->where('sku', $sku)->orWhere('np', $sku)->first();

        if (!empty($modelo)) {

            $documentos = DB::select(
                "( SELECT
                                                m.id_documento,
                                                df.fase,
                                                dt.tipo,
                                                mo.descripcion,
                                                d.tipo_cambio,
                                                d.factura_folio,
                                                a.almacen,
                                                d.id_almacen_principal_empresa AS id_almacen,
                                                d.id_almacen_secundario_empresa AS id_almacen_dos,
                                                m.cantidad,
                                                d.id_tipo,
                                                d.id_fase,
                                                m.id_modelo,
                                                m.precio,
                                                m.created_at AS fecha
                                                FROM
                                                    movimiento AS m
                                                    INNER JOIN documento AS d ON d.id = m.id_documento
                                                    INNER JOIN documento_fase as df ON df.id = d.id_fase
                                                    INNER JOIN documento_tipo as dt ON dt.id = d.id_tipo
                                                    INNER JOIN modelo as mo ON mo.id = m.id_modelo
                                                    INNER JOIN empresa_almacen as ea ON ea.id = d.id_almacen_principal_empresa
                                                    INNER JOIN almacen as a ON a.id = ea.id_almacen
                                                WHERE
                                                    m.created_at > '2023-09-30 23:59:59'
                                                    AND d.`status` = 1
                                                    AND m.id_modelo = " . $modelo->id . "
                                                ) UNION
                                                (
                                                SELECT
                                                    m.id_documento,
                                                    df.fase,
                                                    dt.tipo,
                                                    mo.descripcion,
                                                    d.tipo_cambio,
                                                    d.factura_folio,
                                                    a.almacen,
                                                    d.id_almacen_principal_empresa AS id_almacen,
                                                    d.id_almacen_secundario_empresa AS id_almacen_dos,
                                                    dr.cantidad,
                                                    d.id_tipo,
                                                    d.id_fase,
                                                    m.id_modelo,
                                                    m.precio,
                                                    dr.created_at AS fecha
                                                FROM
                                                    documento_recepcion AS dr
                                                    INNER JOIN movimiento AS m ON m.id = dr.id_movimiento
                                                    INNER JOIN documento AS d ON d.id = m.id_documento
                                                    INNER JOIN documento_fase as df ON df.id = d.id_fase
                                                    INNER JOIN documento_tipo as dt ON dt.id = d.id_tipo
                                                    INNER JOIN modelo as mo ON mo.id = m.id_modelo
                                                    INNER JOIN empresa_almacen as ea ON ea.id = d.id_almacen_principal_empresa
                                                    INNER JOIN almacen as a ON a.id = ea.id_almacen
                                                WHERE
                                                    dr.created_at > '2023-09-30 23:59:59'
                                                    AND d.`status` = 1
                                                    AND m.id_modelo = " . $modelo->id . "
                                                    AND dr.id_movimiento NOT IN (
                                                    SELECT
                                                        mo.id
                                                    FROM
                                                        movimiento AS mo
                                                        INNER JOIN documento AS doc ON doc.id = mo.id_documento
                                                    WHERE
                                                        mo.created_at > '2023-09-30 23:59:59'
                                                        AND d.`status` = 1
                                                    ))
                                            ORDER BY
                                                fecha"
            );

            //            $documentos = DB::table('movimiento as m')
            //                ->join('modelo as mo', 'mo.id', '=', 'm.id_modelo')
            //                ->leftJoin('documento_recepcion as dr', 'dr.id_movimiento', '=', 'm.id')
            //                ->join('documento as d', 'd.id', '=', 'm.id_documento')
            //                ->join('documento_fase as df', 'df.id', '=', 'd.id_fase')
            //                ->join('documento_tipo as dt', 'dt.id', '=', 'd.id_tipo')
            //                ->join('empresa_almacen as ea', 'ea.id', '=', 'd.id_almacen_principal_empresa')
            //                ->join('almacen as a', 'a.id', '=', 'ea.id_almacen')
            //                ->select('d.id_fase', 'd.id_almacen_principal_empresa as id_almacen', 'd.factura_folio', 'd.documento_extra', 'd.tipo_cambio', 'm.*', 'dr.cantidad as recepcionada', 'dr.documento_erp as erp', 'dr.documento_erp_compra as erp_compra', 'mo.descripcion', 'a.almacen', 'df.fase', 'dt.tipo', 'd.id_tipo', 'd.id_moneda')
            //                ->where('m.id_modelo', $modelo->id)->where('m.created_at', '>', '2023-09-30 23:59:59')->orderBy('m.created_at', 'asc')->get();


            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet()->setTitle('REPORTE DE INVENTARIO NAUTICA');
            $contador_fila = 2;
            $stock_venta = 0;
            $stock_compra = $request->input("stockI");
            $stock_total = $request->input("stockI");
            $montoTotal = 0;
            $montoCompra = 0;
            $costo_promedio = $request->input("costoP");
            $ultimo_costo = 0;
            $costo_inicial = $request->input("costoP");
            $primera_compra = true;

            $sheet->setCellValue('A1', 'PEDIDO');
            $sheet->setCellValue('B1', 'FASE');
            $sheet->setCellValue('C1', 'TIPO');
            $sheet->setCellValue('D1', 'MODELO');
            $sheet->setCellValue('E1', 'ALMACEN');
            $sheet->setCellValue('F1', 'CANTIDAD');
            $sheet->setCellValue('G1', 'PRECIO');
            $sheet->setCellValue('H1', 'TOTAL');
            $sheet->setCellValue('I1', 'COSTO PROMEDIO');
            $sheet->setCellValue('J1', 'FECHA');
            $sheet->setCellValue('K1', 'STOCK');
            $sheet->setCellValue('L1', 'FOLIO');

            $spreadsheet->getActiveSheet()->getStyle('A1:L1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
            $spreadsheet->getActiveSheet()->getStyle('A1:L1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

            foreach ($documentos as $venta) {
                if ($venta->id_tipo == 0) {
                    $montoCompra = round($venta->cantidad * $venta->precio * $venta->tipo_cambio, 2);

                    if ($stock_total <= 0) {
                        $costo_promedio = $venta->precio * $venta->tipo_cambio;
                    } else {
                        $montoTotal = $stock_total * $costo_promedio;
                        $costo_promedio = ($montoTotal + $montoCompra) / ($stock_total + $venta->cantidad);
                    }
                    $stock_compra = $stock_compra + $venta->cantidad;
                    $stock_total = $stock_total + $venta->cantidad;

                    //                $this->actualizarTablas($venta, $venta->recepcionada == '' ? 0 : $venta->recepcionada, $stock_total, 1, $costo_promedio, $costo_inicial, $ultimo_costo);

                } else if ($venta->id_tipo == 3) {
                    $stock_compra = $stock_compra + $venta->cantidad;
                    $stock_total = $stock_total + $venta->cantidad;

                    //                $this->actualizarTablas($venta, $venta->cantidad, $stock_total, 0, $costo_promedio, $costo_inicial, $ultimo_costo);

                } else if ($venta->id_tipo == 6) {
                    $stock_compra = $stock_compra + $venta->cantidad;
                    $stock_total = $stock_total + $venta->cantidad;

                    //                $this->actualizarTablas($venta, $venta->cantidad, $stock_total, 0, $costo_promedio, $costo_inicial, $ultimo_costo);

                } else if ($venta->id_tipo == 4) {
                    if ($venta->factura_folio != "N/A") {
                        $stock_venta = $stock_venta - $venta->cantidad;
                        $stock_total = $stock_total - $venta->cantidad;

                        //                    $this->actualizarTablas($venta, $venta->cantidad,  $stock_total, 0, $costo_promedio, $costo_inicial, $ultimo_costo);

                        if ($stock_total <= 0) {
                            $ultimo_costo = $costo_promedio;
                            $costo_promedio = 0;
                        }
                    }
                } else if ($venta->id_tipo == 2) {
                    if ($venta->id_fase == 6 || $venta->id_fase == 5) {
                        $stock_venta = $stock_venta - $venta->cantidad;
                        $stock_total = $stock_total - $venta->cantidad;

                        //                    $this->actualizarTablas($venta, $venta->cantidad,  $stock_total, 0, $costo_promedio, $costo_inicial, $ultimo_costo);

                        if ($stock_total <= 0) {
                            $ultimo_costo = $costo_promedio;
                            $costo_promedio = 0;
                        }
                    }
                }

                $sheet->setCellValue('A' . $contador_fila, $venta->id_documento);
                $sheet->setCellValue('B' . $contador_fila, $venta->fase);
                $sheet->setCellValue('C' . $contador_fila, $venta->tipo);
                $sheet->setCellValue('D' . $contador_fila, $venta->descripcion);
                $sheet->setCellValue('E' . $contador_fila, $venta->almacen);
                $sheet->setCellValue('F' . $contador_fila, $venta->cantidad);
                $sheet->setCellValue('G' . $contador_fila, $venta->precio * $venta->tipo_cambio);
                $sheet->setCellValue('H' . $contador_fila, round($venta->cantidad * $venta->precio * $venta->tipo_cambio, 2));
                $sheet->setCellValue('I' . $contador_fila, $costo_promedio);
                $sheet->setCellValue('J' . $contador_fila, $venta->fecha);
                $sheet->setCellValue('K' . $contador_fila, $stock_total);
                $sheet->setCellValue('L' . $contador_fila, $venta->factura_folio);
                //                $sheet->setCellValue('M' . $contador_fila, $stock_total);
                //                $sheet->setCellValue('N' . $contador_fila, $venta->factura_folio);
                //                $sheet->setCellValue('O' . $contador_fila, $venta->documento_extra);
                //                $sheet->setCellValue('P' . $contador_fila, $venta->erp_compra);


                $contador_fila++;
            }

            $contador_fila++;
            $contador_fila++;

            $sheet->setCellValue('A' . $contador_fila, 'COMPRADO');
            $sheet->setCellValue('B' . $contador_fila, $stock_compra);
            $contador_fila++;

            $sheet->setCellValue('A' . $contador_fila, 'VENDIDO');
            $sheet->setCellValue('B' . $contador_fila, $stock_venta);
            $contador_fila++;

            $sheet->setCellValue('A' . $contador_fila, 'INVENTARIO');
            $sheet->setCellValue('B' . $contador_fila, $stock_total);
            $contador_fila++;

            $sheet->setCellValue('A' . $contador_fila, 'COSTO PROMEDIO');
            $sheet->setCellValue('B' . $contador_fila, $costo_promedio);
            $contador_fila++;

            # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
            foreach (range('A', 'P') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('inventario_nautica.xlsx');

            $json['code'] = 200;
            $json['ventas'] = $documentos;
            $json['excel'] = base64_encode(file_get_contents('inventario_nautica.xlsx'));

            unlink('inventario_nautica.xlsx');
        } else {
            $json['code'] = 500;
            $json['mensaje'] = "No se encontro el producto";
        }
        return response()->json($json);
    }

    public function reporte()
    {
        $almacenes = array();

        set_time_limit(0);

        $ventasOMG = DB::select("SELECT 
                                documento.id, 
                                documento.no_venta,
                                documento.status,
                                documento.created_at, 
                                area.area,
                                almacen.almacen                               
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE id_marketplace_area = 17 AND documento.id_tipo = 2");
        array_push($almacenes, $ventasOMG);

        $ventasETG = DB::select("SELECT 
                                documento.id, 
                                documento.no_venta,
                                documento.status,
                                documento.created_at, 
                                area.area,
                                almacen.almacen                               
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE id_marketplace_area = 29 AND documento.id_tipo = 2");
        array_push($almacenes, $ventasETG);

        $spreadsheet = new Spreadsheet();
        $index = 0;

        foreach ($almacenes as $almacen) {
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($index);
            $sheet = $spreadsheet->getActiveSheet()->setTitle($almacen[0]->area);
            $fila = 2;

            $sheet->setCellValue('A1', 'ID CRM');
            $sheet->setCellValue('B1', '# VENTA');
            $sheet->setCellValue('C1', 'ID MODELO');
            $sheet->setCellValue('D1', 'SKU');
            $sheet->setCellValue('E1', 'CANTIDAD');
            $sheet->setCellValue('F1', 'PRECIO');
            $sheet->setCellValue('G1', 'FECHA');
            $sheet->setCellValue('H1', 'ALMACEN');

            $index = $index + 1;

            $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
            $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

            foreach ($almacen as $venta) {
                $productos = DB::select("SELECT 
                                    movimiento.id,
                                    movimiento.cantidad,
                                    movimiento.garantia,
                                    modelo.id as skuid,
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    modelo.serie,
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

                foreach ($productos as $documento) {
                    $sheet->setCellValue('A' . $fila, $venta->id);
                    $sheet->getCellByColumnAndRow(2, $fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($documento->skuid, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->getCellByColumnAndRow(4, $fila)->setValueExplicit($documento->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('E' . $fila, $documento->cantidad);
                    $sheet->setCellValue('F' . $fila, $documento->precio);
                    $sheet->setCellValue('G' . $fila, $venta->created_at);
                    $sheet->setCellValue('H' . $fila, $venta->almacen);

                    $spreadsheet->getActiveSheet()->getStyle("F" . $fila . ":F" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                    if (!$venta->status) {
                        $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ":H" . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('CD5C5C');
                    }

                    foreach (range('A', 'H') as $columna) {
                        $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
                    }
                    $fila++;
                }
            }

            $primera = 1;
            $ultima = $fila - 1;

            $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":H" . $ultima);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_LINIO.xlsx');

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents('reporte_LINIO.xlsx'));
        $json['message'] = "Creacion correcta del reporte";

        unlink('reporte_LINIO.xlsx');

        return response()->json($json);
    }

    public function getSeries($codigo)
    {
        set_time_limit(0);
        $movimientos = array();
        $productos = array();
        $series = array();

        $modelos = DB::table('modelo')
            ->select('id')
            ->where('sku', $codigo)
            ->get()
            ->toArray();

        foreach ($modelos as $modelo) {
            $movimiento = DB::table('movimiento')
                ->select('id')
                ->where('id_modelo', $modelo->id)
                ->get()
                ->toArray();
            foreach ($movimiento as $key) {
                array_push($movimientos, $key->id);
            }
        }

        $producto = DB::table('movimiento_producto')
            ->select('id_producto')
            ->whereIn('id_movimiento', $movimientos)
            ->get()
            ->toArray();

        foreach ($producto as $key) {
            if (!in_array($key->id_producto, $productos)) {
                array_push($productos, $key->id_producto);
            }
        }

        $series
            = DB::table('producto')
            ->select('producto.serie', 'producto.status', 'almacen.almacen')
            ->join('almacen', 'almacen.id', '=', 'producto.id_almacen')
            ->whereIn('producto.id', $productos)
            ->get()->toArray();


        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet();
        $fila = 2;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Series');

        $sheet->setCellValue('A1', 'SERIE');
        $sheet->setCellValue('B1', 'ALMACEN');
        $sheet->setCellValue('C1', 'STATUS');

        $spreadsheet->getActiveSheet()->getStyle('A1:C1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($series as $serie) {

            $sheet->getCellByColumnAndRow(1, $fila)->setValueExplicit($serie->serie, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $fila, $serie->almacen);
            $sheet->setCellValue('C' . $fila, $serie->status == 1 ? 'Activo' : 'Inactivo');


            if ($serie->status == 0) {
                $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ":C" . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('CD5C5C');
            }

            foreach (range('A', 'C') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }
            $fila++;
        }

        $primera = 1;
        $ultima = $fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":C" . $ultima);

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_SERIES.xlsx');

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents('reporte_SERIES.xlsx'));
        $json['message'] = "Creacion correcta del reporte";
        $json['series'] = $series;

        unlink('reporte_SERIES.xlsx');

        return response()->json($json);
    }

    //WALMART
    public function getWalmartData()
    {
        $venta = '231244001004';
        $response = new \stdClass();
        $response->error = 1;

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = 64");

        if (empty($marketplace)) {
            $log = self::logVariableLocation();
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador" . $log;

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace->app_id, $marketplace->secret);

        if ($token->error) {
            return $token;
        }

        $request = \Httpful\Request::get(config("webservice.walmart_endpoint") . "v3/orders?purchaseOrderId=" . $venta)
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
            ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")
            ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Accept', 'application/json')
            ->send();

        $request = json_decode($request->raw_body);

        if (property_exists($request, "error")) {
            if (property_exists($request, "error_description")) {
                $response->mensaje = $request->error_description;
            } else {
                $response->mensaje = $request->error->description;
            }

            return $response;
        }

        if (empty($request->order)) {
            $response->mensaje = "No se encontró la venta con el ID proporcionado";

            return $response;
        }

        $response->error = 0;
        $response->data = $request->order[0];

        return response()->json(["a" => $response]);
    }
    public function getWalmartData2()
    {
        $documento = '1143919';
        $marketplace_id = '64';
        $response = new \stdClass();
        $response->error = 1;
        $archivos = array();

        $guia = DB::select("SELECT referencia FROM documento WHERE id = " . $documento . "");

        if (empty($guia)) {
            $response->mensaje = "No se encontró información del documento para descargar el documento de embarque.";

            return $response;
        }

        $guia = trim($guia[0]->referencia);
        if (str_contains($guia, ',')) {
            $guia = str_replace(',', '', $guia);
        }

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace_api.app_id,
                                        marketplace_api.secret
                                    FROM marketplace_area
                                    INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron las credenciales del marketplace seleccionado, favor de contactar al administrador.";

            return $response;
        }

        $marketplace = $marketplace[0];

        $token = self::token($marketplace->app_id, $marketplace->secret);

        if ($token->error) {
            return $token;
        }

        $request = \Httpful\Request::get(config("webservice.walmart_endpoint") . "v3/orders/label/" . $guia)
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SEC.ACCESS_TOKEN', $token->token)
            ->addHeader('WM_CONSUMER.CHANNEL.TYPE', "0f3e4dd4-0514-4346-b39d-af0e00ea066d")->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->addHeader('Accept', 'application/pdf')
            ->send();

        if (is_null($request->body)) {
            $response->mensaje = "No se encontró la guía de embarque para el documento.";

            return $response;
        }

        $response->error = 0;
        $response->file = base64_encode($request->body);
        $response->pdf = 0;

        return response()->json([
            'a' => $response
        ]);
    }

    /*public static function token($marketplace)
    {
        $response = new \stdClass();
        $response->error = 1;

        try {
            $marketplace->secret = Crypt::decrypt($marketplace->secret);
        } catch (DecryptException $e) {
            $marketplace->secret = "";
        }

        if (empty($marketplace->secret)) {
            $response->mensaje = "Ocurrió un error al desencriptar la llave del marketplace";

            return $response;
        }

        $data = array(
            "grant_type" => "client_credentials"
        );

        $request_data = \Httpful\Request::post(config("webservice.walmart_endpoint") . "v3/token")
            ->addHeader('Authorization', "Basic " . base64_encode($marketplace->app_id . ":" . $marketplace->secret) . "")
            ->addHeader('WM_SVC.NAME', 'Walmart Marketplace')
            ->addHeader('WM_QOS.CORRELATION_ID', uniqid())
            ->addHeader('WM_MARKET', 'mx')
            ->body($data, \Httpful\Mime::FORM)
            ->send();

        $request_raw = $request_data->raw_body;
        $request = json_decode($request_raw);

        if (property_exists($request, "error")) {
            $response->mensaje = $request->error_description . ", line 169";
            $response->raw = $request_data;

            return $response;
        }

        $response->error = 0;
        $response->token = $request->access_token;

        return $response;
    }*/

    public static function actualizarWalmart(Request $request)
    {
        set_time_limit(0);
        $venta = json_decode($request->input("venta"));
        $precio = json_decode($request->input("precio"));

        foreach ($venta as $key) {
            DB::table('documento')->where('id', $key->id)->update([
                'mkt_total' => $precio
            ]);
        }

        return response()->json([
            "code" => 200,
            "message" => 'Actualización correcta, precio nuevo: $' . $precio,
        ]);
    }

    public static function buscarWalmartVenta($data)
    {
        set_time_limit(0);
        $ventas =  DB::table('documento')
            ->select('id', 'mkt_total', 'mkt_user_total', 'no_venta')
            ->where('id_tipo', 2)
            ->where('id_marketplace_area', 64)
            ->where('status', 1)
            ->where('no_venta', $data)
            ->get()
            ->toArray();

        return response()->json([
            "ventas" => $ventas
        ]);
    }

    public static function buscarWalmartPedido($data)
    {
        set_time_limit(0);
        $id =
            DB::table('documento')
            ->select('no_venta')
            ->where('id', $data)
            ->where('id_marketplace_area', 64)
            ->where('status', 1)
            ->first();

        $ventas =  DB::table('documento')
            ->select('id', 'mkt_total', 'mkt_user_total', 'no_venta')
            ->where('id_tipo', 2)
            ->where('id_marketplace_area', 64)
            ->where('status', 1)
            ->where('no_venta', $id->no_venta)
            ->get()
            ->toArray();


        return response()->json([
            "ventas" => $ventas
        ]);
    }

    public function faltantesComercial(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input("data"));
        $fill = true;

        if (empty($data)) {
            return response()->json([
                'code' => '404',
                'message' => "No se encontró ningun Excel para importar."
            ], 404);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet();
        $fila = 2;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Faltantes');

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'ID COMERCIAL');
        $sheet->setCellValue('C1', 'CODIGO');
        $sheet->setCellValue('D1', 'CANTIDAD');
        $sheet->setCellValue('E1', 'SKU');
        $sheet->setCellValue('F1', 'PRECIO');
        $sheet->setCellValue('G1', 'DESCRIPCION');

        $spreadsheet->getActiveSheet()->getStyle('A1:G1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($data as $venta) {

            $sheet->setCellValue('A' . $fila, $venta->idcrm);
            $sheet->setCellValue('B' . $fila, $venta->idcomercial);

            $productos =
                DB::table('movimiento')
                ->select(
                    'movimiento.id',
                    'movimiento.cantidad',
                    'movimiento.garantia',
                    'modelo.sku',
                    'modelo.descripcion',
                    'modelo.serie',
                    DB::raw("ROUND((movimiento.precio * 1.16), 2) as precio")
                )
                ->join('modelo', 'movimiento.id_modelo', 'modelo.id')
                ->where('id_documento', $venta->idcrm)
                ->get()
                ->toArray();

            foreach ($productos as $producto) {

                $sheet->setCellValue('D' . $fila, $producto->cantidad);
                $sheet->setCellValue('F' . $fila, $producto->precio);
                $sheet->setCellValue('G' . $fila, $producto->descripcion);

                $spreadsheet->getActiveSheet()->getStyle("F" . $fila . ":F" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $series
                    = DB::table('movimiento_producto')
                    ->select('producto.id', 'producto.serie')
                    ->join('producto', 'movimiento_producto.id_producto', 'producto.id')
                    ->where('movimiento_producto.id_movimiento',  $producto->id)
                    ->get()
                    ->toArray();

                if ($producto->serie != 0 && count($series) > 0) {
                    foreach ($series as $serie) {
                        $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet->getCellByColumnAndRow(5, $fila)->setValueExplicit($serie->serie, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        if ($fill) {
                            $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ":G" . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('999999');
                        }
                        $fila++;
                    }
                } else {
                    $sheet->setCellValue('C' . $fila, $producto->sku);
                    $sheet->setCellValue('E' . $fila, 'N/A');
                    if ($fill) {
                        $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ":G" . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('999999');
                    }
                    $fila++;
                }
            }
            $fill = !$fill;
        }

        foreach (range('A', 'G') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $primera = 1;
        $ultima = $fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":G" . $ultima);

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_FALTANTES.xlsx');

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents('reporte_FALTANTES.xlsx'));
        $json['message'] = "Creacion correcta del reporte";

        unlink('reporte_FALTANTES.xlsx');

        return response()->json($json);
    }


    public function getTraspasoNDC(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        $documentos = DB::table('documento')
            ->select('id', 'observacion')
            ->where('observacion', 'like', '%' . $data->documento)
            ->where('id_tipo', 5)
            ->get()
            ->toArray();

        if (empty($documentos)) {
            return response()->json([
                'code'  => 500,
                'message'   => 'No hay un documento con esa especificación'
            ]);
        }

        return response()->json([
            'code' => 200,
            'documento' => $documentos
        ]);
    }

    public function testsAlexget()
    {
        set_time_limit(0);

        $documentos = DB::table('documento')
            ->select('documento.id', 'empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
            ->where('documento.id_tipo', 2)
            ->where('documento.factura_serie', 'N/A')
            ->where('documento.factura_folio', 'N/A')
            ->where('documento.documento_extra', '!=', 'N/A')
            ->where('documento.documento_extra', '!=', '')
            ->where('documento.status', 1)
            ->where('documento.id_fase', 6)
            ->where('documento.id_marketplace_area', '!=', 17)
            ->where('documento.id_marketplace_area', '!=', 29)
            ->whereBetween('documento.created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->orderBy('documento.created_at', 'asc')
            ->get()
            ->toArray();

        $doc_array = array();
        $doc_array_two = array();

        if (!empty($documentos)) {
            foreach ($documentos as $documento) {
                $documento_data = @json_decode(file_get_contents(config('webservice.url') . $documento->bd  . '/Factura/Estado/Folio/' . $documento->id));
                array_push($doc_array, $documento_data);
                array_push($doc_array_two, $documento);

                if (is_array($documento_data) && count($documento_data) > 0) {
                    $documento_data = $documento_data[0];
                }

                if (!empty($documento_data)) {
                    DB::table('documento')->where('id', $documento->id)->update([
                        'factura_serie' => $documento_data->serie,
                        'factura_folio' => $documento_data->folio,
                        'uuid' => $documento_data->uuid,
                    ]);
                }
            }
        }

        return response()->json(['A' => 'Finished', 'b' => $doc_array_two, 'c' => $doc_array, 'd' => $documentos]);
    }


    public function testsAlexpost(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        set_time_limit(0);

        //Todos los documentos
        $documentos = DB::table('documento')
            ->select('documento.id', 'empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
            ->where('documento.id_tipo', 2)
            ->where('documento.uuid', 'N/A')
            ->where('documento.status', 1)
            ->whereBetween('documento.created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->where('documento.id_fase', 6)
            ->where('documento.id_marketplace_area', '!=', 17)
            ->where('documento.id_marketplace_area', '!=', 29)
            ->orderBy('documento.created_at', 'asc')
            // ->limit(50)
            // ->offset(0)
            ->get()
            ->toArray();

        $documentos_entrados = array();
        if (!empty($documentos)) {
            foreach ($documentos as $documento) {

                $documento_data = @json_decode(file_get_contents(config('webservice.url') . $documento->bd  . '/Factura/Estado/Folio/' . $documento->id));

                if (is_array($documento_data) && count($documento_data) > 0) {
                    $documento_data = $documento_data[0];
                }

                if (!empty($documento_data)) {
                    if (property_exists($documento_data, "uuid")) {
                        array_push($documentos_entrados, $documento_data);
                        DB::table('documento')->where('id', $documento->id)->update([
                            'factura_serie' => $documento_data->serie,
                            'factura_folio' => $documento_data->folio,
                            'uuid' => $documento_data->uuid,
                        ]);
                    }
                }
            }
        }

        return response()->json(['A' => $documentos_entrados, 'b' => $documentos]);
    }

    public function testAlexExtra()
    {
        $documentos =
            DB::table('documento')
            ->select('*')
            ->where('id_tipo', '=', '2')
            ->where('id_fase', '=', '3')
            ->where('documento_extra', '!=', '')
            ->where('documento_extra', '!=', 'N/A')
            ->where('status', '=', '1')
            ->where('packing_by', '!=', 0)
            ->get()
            ->toArray();

        if (!empty($documentos)) {
            foreach ($documentos as $documento) {

                DB::table('documento')->where('id', $documento->id)->update([
                    'id_fase' => 6,
                ]);
            }
        }
    }

    public function testAlexData()
    {
        $fasetres =
            DB::table('documento')
            ->select('*')
            ->where('id_tipo', '=', '2')
            ->where('id_fase', '=', '3')
            ->where('documento_extra', '!=', '')
            ->where('documento_extra', '!=', 'N/A')
            ->where('status', '=', '1')
            ->where('packing_by', '!=', 0)
            ->get()
            ->toArray();

        $nouuid = DB::table('documento')
            ->select('documento.id', 'empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
            ->where('documento.id_tipo', 2)
            ->where('documento.uuid', 'N/A')
            ->where('documento.status', 1)
            ->whereBetween('documento.created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->where('documento.id_fase', 6)
            ->where('documento.id_marketplace_area', '!=', 17)
            ->where('documento.id_marketplace_area', '!=', 29)
            ->orderBy('documento.created_at', 'asc')
            ->get()
            ->toArray();

        $nofactura = DB::table('documento')
            ->select('documento.id', 'empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
            ->where('documento.id_tipo', 2)
            ->where('documento.factura_serie', 'N/A')
            ->where('documento.factura_folio', 'N/A')
            ->where('documento.documento_extra', '!=', 'N/A')
            ->where('documento.documento_extra', '!=', '')
            ->where('documento.status', 1)
            ->where('documento.id_fase', 6)
            ->where('documento.id_marketplace_area', '!=', 17)
            ->where('documento.id_marketplace_area', '!=', 29)
            ->whereBetween('documento.created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->orderBy('documento.created_at', 'asc')
            ->get()
            ->toArray();

        return response()->json([
            'Factura' => count($nofactura),
            'UUID' => count($nouuid),
            'Fase3' => count($fasetres)
        ]);
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'DC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'PER'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }

    public static function rawactualizarUuidCrm()
    {
        set_time_limit(0);

        //Todos los documentos
        $documentos = DB::table('documento')
            ->select('documento.id', 'empresa.bd')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
            ->where('documento.id_tipo', 2)
            ->where('documento.uuid', 'N/A')
            ->where('documento.status', 1)
            ->where('documento.id_fase', 6)
            ->where('documento.id_marketplace_area', '!=', 17)
            ->where('documento.id_marketplace_area', '!=', 29)
            ->orderBy('documento.created_at', 'asc')
            ->get()
            ->toArray();

        if (!empty($documentos)) {
            foreach ($documentos as $documento) {

                $documento_data = @json_decode(file_get_contents(config('webservice.url') . $documento->bd  . '/Factura/Estado/Folio/' . $documento->id));

                if (is_array($documento_data) && count($documento_data) > 0) {
                    $documento_data = $documento_data[0];
                }

                if (!empty($documento_data)) {
                    if ($documento_data->uuid != null) {
                        DB::table('documento')->where('id', $documento->id)->update([
                            'factura_serie' => $documento_data->serie,
                            'factura_folio' => $documento_data->folio,
                            'uuid' => $documento_data->uuid,
                        ]);
                    }
                }
            }
        }

        return response()->json(['A' => 'Finished']);
    }

    public function conseguirLinio(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

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
            ->where("marketplace_area.id", $data->marketplace)
            ->first();

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }
        $now = new DateTime();

        $parameters = array(
            'Action' => 'GetOrderItems',
            'UserID' => $credenciales->app_id,
            'Version' => '1.0',
            'OrderId' => $data->venta,
            'Format' => 'JSON',
            'Timestamp' => $now->format(DateTime::ATOM),
        );

        // $parameters = array(
        //     'Action' => 'GetOrder',
        //     'UserID' => $credenciales->app_id,
        //     'Version' => '1.0',
        //     'OrderId' => (int)$data->venta,
        //     'Format' => 'JSON',
        //     'Timestamp' => $now->format(DateTime::ISO8601),
        // );

        $raw_venta_data = self::request_data($parameters, $credenciales->secret);
        $venta_data = @json_decode($raw_venta_data);

        return response()->json([
            'A' => $venta_data
        ]);
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

    public function imprimirPicking()
    {
        $response = @json_decode(file_get_contents('http://bit.ly/3xaugil'));
        return $response;
    }

    public function getDuplicadosData(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        $response =
            DB::table('marketplace_area')
            ->select('marketplace_area.id', 'marketplace.marketplace', 'area.area')
            ->join('area', 'area.id', '=', 'marketplace_area.id_area')
            ->join('marketplace', 'marketplace.id', '=', 'marketplace_area.id_marketplace')
            ->whereIn('marketplace_area.id', $data)
            ->get()
            ->toArray();

        return response()->json([
            'ima' => $response
        ]);
    }

    public function getDuplicados(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        $duplicadas = DB::select("SELECT
            no_venta, COUNT(*)
        FROM
            documento
        WHERE
        	id_marketplace_area = $data
        AND
        	created_at > '2023-11-01 00:00:00'
        AND
        	id_tipo = 2
        AND
        	status = 1
        GROUP BY
            no_venta
        HAVING 
            COUNT(*) > 1");

        $dupl = array();

        foreach ($duplicadas as $key) {
            array_push($dupl, $key->no_venta);
        }

        $response = DB::table('documento')
            ->select('id', 'documento_extra', 'no_venta', 'comentario')
            ->whereIn('no_venta', $dupl)
            ->get()
            ->toArray();

        if (empty($response)) {
            return response()->json([
                'code' => 500,
                'message' => 'No se encontraron ventas duplicadas'
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Se encontraron ventas duplicadas',
            'ima' => $response
        ]);
    }

    public function getUsuariosNotificaciones()
    {
        set_time_limit(0);

        $usuarios =
            DB::table('usuario')
            ->select('id', 'nombre', 'area', 'email', 'celular')
            ->where('status', 1)
            ->where('id', '!=', 1)
            ->get()
            ->toarray();

        $areas =
            DB::table('usuario')
            ->select('area')
            ->where('status', 1)
            ->groupBy('area')
            ->orderBy('area')
            ->get()
            ->toarray();

        return response()->json([
            'codigo' => 200,
            'areas' =>  $areas,
            'usuarios' => $usuarios
        ]);
    }

    public function enviarNotificaciones(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));


        $usuarios = $data->usuarios;
        $notificacion['titulo'] = $data->titulo;
        $notificacion['message'] = $data->mensaje;
        $notificacion['tipo'] = $data->tipo;
        $notificacion['alerta'] = $data->alerta;

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data' => json_encode($notificacion),
            'tipo' => 2
        ]);

        foreach ($usuarios as $usuario) {
            DB::table('notificacion_usuario')->insert([
                'id_usuario' => $usuario,
                'id_notificacion' => $notificacion_id
            ]);
        }

        $notificacion['usuario'] = $usuarios;

        event(new PusherEvent(json_encode($notificacion)));

        return response()->json([
            'code' => 200,
            'message' => "Registro guardado correctamente."
        ]);
    }

    public function repararNDC(Request $request)
    {
        $auth = json_decode($request->auth);
        $data = json_decode($request->input('data'));

        $documentos = DB::table('documento')
            ->select('id', 'observacion')
            ->where('observacion', 'like', '%' . $data->documento)
            ->where('id_tipo', 5)
            ->get()
            ->toArray();

        $hay_traspaso = empty($documentos) ? false : true;

        $response = ReparacionService::autorizacionNota($data, $hay_traspaso, $auth);

        return $response;
    }

    public function repararNDCVentas(Request $request)
    {
        $auth = json_decode($request->auth);
        $data = json_decode($request->input('data'));

        $documento = $data->documento;
        $id_usuario = $auth->id;
        $id = $data->id;

        $response = ReparacionService::autorizacionNotaVenta($id_usuario, $documento, $id);

        return $response;
    }

    public function barridoStatus($anio, $mes)
    {
        set_time_limit(0);

        $datetimeStart = Carbon::now();
        $start = $datetimeStart->toDateTimeString();

        $bitacora = DB::table('bitacora_reportes')->insertGetId([
            'reporte' => 'developer/barridoStatus/' . $anio . '/' . $mes,
            'started_at' => $start
        ]);

        LoggerService::writeLog('developer', 'INICIO Reporte de Barrido Status');

        $documentos = DB::table('documento')
            ->select('documento.id', 'documento.status', 'documento.status_erp', 'documento.fecha_timbrado_erp', 'empresa.bd', 'documento.factura_folio', 'documento.factura_serie', 'documento.created_at')
            ->where('documento.id_tipo', 2)
            ->where('erp_check', 0)
            ->where('documento.id_fase', 6)
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->whereBetween('documento.created_at', [$anio . '-' . $mes . '-01 00:00:00', $anio . '-' . $mes . '-31 23:59:59'])
            ->get()
            ->toArray();

        try {
            foreach ($documentos as $documento) {

                $folio = ($documento->factura_folio == 'N/A' || $documento->factura_serie == 'N/A') ? $documento->id : $documento->factura_folio;
                $estatus_factura = 1;
                $total_nota_credito = 0;
                $fecha_timbre = null;

                if ($documento->bd == 0) {
                    $estatus_factura = 3;
                } else {
                    $factura_data = @json_decode(file_get_contents(config('webservice.url') . $documento->bd  . '/Factura/Estado/Folio/' . $folio));
                    if (empty($factura_data)) {
                        $estatus_factura = 2;
                    } else {
                        $factura_data = is_array($factura_data) ? $factura_data[0] : $factura_data;
                        $estatus_factura = 1;
                        if (($factura_data->eliminado ?? 1) || ($factura_data->cancelado ?? 1)) {
                            $estatus_factura = 0;
                        }
                        if ($factura_data->pagos) {
                            foreach ($factura_data->pagos as $pago) {
                                if ($pago->operacion == 0) {
                                    $total_nota_credito += $pago->monto ?? 0;
                                }
                            }
                            if (abs($total_nota_credito - $factura_data->total) < 1) {
                                $estatus_factura = 4;
                            }
                        }
                        $fecha_timbre = $factura_data->fecha_timbrada ?? null;
                    }
                }

                if ($documento->status_erp != $estatus_factura || $documento->fecha_timbrado_erp != $fecha_timbre) {
                    $this->actualizarStatusERP($documento, $estatus_factura, $folio, $fecha_timbre);
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            LoggerService::writeLog('developer', 'Error: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }

        $endtimeStart = Carbon::now();
        $end = $endtimeStart->toDateTimeString();

        DB::table('bitacora_reportes')->where(['id' => $bitacora])->update([
            'finished_at' => $end,
        ]);

        LoggerService::writeLog('developer', 'FIN Reporte de Barrido Status');

        return response()->json([
            'message' => 'Actualización correcta',
            'documentos' => count($documentos)
        ]);
    }

    public function barridoStatusfase($anio, $info)
    {
        set_time_limit(0);

        $bitacora = DB::table('bitacora_actualizacion_facturas')->insertGetId([
            'consulta' => $info
        ]);

        $documentTypes = [
            'noSerie' => ['column' => 'factura_serie', 'condition' => 'N/A'],
            'noFolio' => ['column' => 'factura_folio', 'condition' => 'N/A'],
            'noUuid' => ['column' => 'uuid', 'condition' => 'N/A'],
            'noFecha' => ['column' => 'fecha_timbrado_erp', 'condition' => null]
        ];

        $response = [];
        $columnname = '';

        foreach ($documentTypes as $type => $filter) {

            $columnname = $this->getColumnName($type);

            DB::beginTransaction();
            try {

                DB::table('bitacora_actualizacion_facturas')->where(['id' => $bitacora])->update([
                    $columnname => date("Y-m-d H:i:s", strtotime('-1 hour'))
                ]);

                $documentos = DB::table('documento')
                    ->select('documento.id', 'documento.factura_serie', 'documento.factura_folio', 'empresa.bd')
                    ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', 'empresa_almacen.id')
                    ->join('empresa', 'empresa_almacen.id_empresa', 'empresa.id')
                    ->where($filter['column'], $filter['condition'])
                    ->where('documento.id_tipo', 2)
                    ->where('documento.id_fase', 6)
                    ->whereBetween('documento.created_at', [$anio . '-01-01 00:00:00', $anio . '-12-31 23:59:59'])
                    ->whereNotIn('documento.id_marketplace_area', ['17', '29'])
                    ->orderBy('documento.created_at', "DESC")
                    ->get()
                    ->toArray();

                if ($info) {
                    foreach ($documentos as $documento) {
                        $folio = ($documento->factura_folio == 'N/A' || $documento->factura_serie == 'N/A') ? $documento->id : $documento->factura_folio;
                        $fecha_timbre = null;
                        $factura_serie = 'N/A';
                        $factura_folio = 'N/A';
                        $uuid = 'N/A';

                        if ($documento->bd !== 0 && $documento->bd !== '0') {

                            $factura_data = @json_decode(file_get_contents(config('webservice.url') . $documento->bd  . '/Factura/Estado/Folio/' . $folio));

                            if ($factura_data !== null && !empty($factura_data)) {

                                $factura_data = is_array($factura_data) ? $factura_data[0] : $factura_data;

                                $factura_serie = $factura_data->factura_serie ?? 'N/A';
                                $factura_folio = $factura_data->factura_folio ?? 'N/A';
                                $uuid = $factura_data->uuid ?? 'N/A';
                                $fecha_timbre = $factura_data->fecha_timbrada ?? null;

                                DB::table('documento')->where(['id' => $documento->id])->update([
                                    'factura_serie' => $factura_serie,
                                    'factura_folio' => $factura_folio,
                                    'uuid' => $uuid,
                                    'fecha_timbrado_erp' => $fecha_timbre
                                ]);
                            }
                        }
                    }
                }

                DB::commit();

                $response[$type] = count($documentos);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Error procesando el documento ' . $type . ': ' . $e->getMessage(),
                ], 500);
            }
        }

        DB::beginTransaction();
        try {
            DB::table('bitacora_actualizacion_facturas')->where(['id' => $bitacora])->update([
                'serie' => $response['noSerie'],
                'folio' => $response['noFolio'],
                'uuid' => $response['noUuid'],
                'fecha_timbrado' => $response['noFecha'],
                'fecha_final' => date("Y-m-d H:i:s", strtotime('-1 hour'))
            ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error procesando la bitacora ' . $bitacora . ': ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => !$info ? 'Información correcta' : 'Actualización correcta',
            'No Serie' => $response['noSerie'],
            'No Folio' => $response['noFolio'],
            'No Uuid' => $response['noUuid'],
            'No Fecha' => $response['noFecha'],
        ]);
    }

    protected function getColumnName($type)
    {
        $columnNames = [
            'noSerie' => 'fase_uno',
            'noFolio' => 'fase_dos',
            'noUuid' => 'fase_tres',
            'noFecha' => 'fase_cuatro'
        ];

        return $columnNames[$type] ?? '';
    }

    public function cambiarModuloComercial($documento)
    {
        set_time_limit(0);

        $documento = DB::table('documento')
            ->select('documento.documento_extra', 'empresa.bd')
            ->where('documento.id', $documento)
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa.id', '=', 'empresa_almacen.id_empresa')
            ->first();

        $array_mover = array(
            'bd' => $documento->bd,
            'documento' => $documento->documento_extra,
            'modulo' => 5
        );

        $mover_documento = \Httpful\Request::post(config('webservice.url') . "documento/cambiarmodulo")
            ->body($array_mover, \Httpful\Mime::FORM)
            ->send();

        $mover_documento_raw = $mover_documento->raw_body;
        $mover_documento = @json_decode($mover_documento_raw);

        if (empty($mover_documento)) {
            return response()->json([
                'code' => 500,
                'message' =>  "No fue posible mover el documento de módulo, error desconocido." . self::logVariableLocation(),
            ]);
        }

        if ($mover_documento->error) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible mover el documento de módulo, error: " . $mover_documento->mensaje . "" . self::logVariableLocation()
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => 1,
            'seguimiento'   => 'Documento se mueve en comercial por administradores.'
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Movimiento Correcto',
            $documento
        ]);
    }


    private function actualizarStatusERP($documento, $estatus_factura, $folio, $fecha_timbre)
    {
        set_time_limit(0);

        $mensajeMAp = [
            0 => 'Cancelada',
            1 => 'Activa',
            2 => 'No encontrada',
            3 => 'Base de datos = 0',
            4 => 'Nota de crédito'
        ];

        $status_anterior = $mensajeMAp[$documento->status_erp] ?? "No encontrado";
        $status_nuevo = $mensajeMAp[$estatus_factura] ?? "No encontrado";

        $mensaje = 'Documento: ' . $documento->id . ' de Status: ' . $status_anterior . ' a Status: ' . $status_nuevo;

        try {
            DB::beginTransaction();

            DB::table('doc_actualizar_erp')->insert([
                'id_documento' => $documento->id,
                'bd' => $documento->bd,
                'factura_folio' => $folio,
                'status_documento' => $documento->status,
                'status_erp_viejo' =>  $documento->status_erp,
                'status_erp_nuevo' => $estatus_factura,
                'documento_created_at' => $documento->created_at
            ]);

            DB::table('documento')->where(['id' => $documento->id])->update([
                'status_erp' => $estatus_factura,
                'fecha_timbrado_erp' => $fecha_timbre

            ]);

            LoggerService::writeLog('developer', $mensaje);


            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            LoggerService::writeLog('developer', 'Error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    //!ZONA DE APIS

    public function apiClaro($option)
    {
        $marketplace_areas = [11, 25, 65];
        $venta = 83684721;
        switch ($option) {
            case 1:
                return response()->json([
                    'Respuesta' => ClaroshopService::developerVenta($venta, $marketplace_areas[0])->data
                ]);
                break;
            case 2:
                return response()->json([
                    'Respuesta' => ClaroshopService::developerImportarVentasMasiva($marketplace_areas[2])
                ]);
                break;

            default:
                # code...
                break;
        }
    }

    public function apiClaroV2($option)
    {
        #SR 73 78
        #CS 74 79
        #SN 75 80
        $marketplace = 78;
        $marketplace_data = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_api.extra_1,
                                            marketplace_api.extra_2,
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = " . $marketplace . "")[0];
        switch ($option) {
            case 1:
                // return response()->json([
                //     'Respuesta' => ClaroshopServiceV2::venta(1, 2)
                // ]);
                break;
            case 2:
                return response()->json([
                    'Respuesta' => ClaroshopServiceV2::documento(84269365, $marketplace_data, 'dhl')
                ]);
                break;

            default:
                break;
        }
    }

    //!ZONA DE APIS END (MANTENER AL FINAL)
}
