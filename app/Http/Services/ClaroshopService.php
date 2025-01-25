<?php

namespace App\Http\Services;

use Exception;
use DateTime;
use DB;
use Illuminate\Support\Facades\Crypt;

class ClaroshopService
{
    //! YA
    public static function venta($venta, $credenciales)
    {
        $response = new \stdClass();

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
        $informacion = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta . ""));

        if (empty($informacion)) {
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de la venta en claroshop." . self::logVariableLocation();

            return $response;
        }

        if (property_exists($informacion, 'estatus')) {
            if (in_array($informacion->estatus, ['error', 'warning'])) {
                $response->error = 1;
                $response->mensaje = $informacion->mensaje . "" . self::logVariableLocation();

                return $response;
            }
        }

        $response->error = 0;
        $response->data = $informacion;

        return $response;
    }

    //! YA
    public static function documento($venta, $credenciales, $paqueteria)
    {
        $response = new \stdClass();

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
        $informacion = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta . ""));

        if (empty($informacion)) {
            $response->error = 1;
            $response->mensaje = "No fue posible obtener los informacion de la venta " . $venta . "" . self::logVariableLocation();

            return $response;
        }

        if (property_exists($informacion, "error")) {
            $response->error = 1;
            $response->mensaje = $informacion->mensaje . "" . self::logVariableLocation();

            return $response;
        }

        if (property_exists($informacion, 'estatuspedido')) {
            if (property_exists($informacion->estatuspedido, "estatus")) {
                $id_relacion = array();
                $guia = "";

                foreach ($informacion->productos as $producto) {
                    array_push($id_relacion, $producto->idpedidorelacion);
                }

                $guia = $informacion->productos[0]->guia;

                if (empty($guia)) {
                    $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);

                    $content = array(
                        "nopedido" => $venta,
                        "guia" => "automatica",
                        "mensajeria" => $paqueteria,
                        "idpedidorelacion" => implode(",", $id_relacion)
                    );

                    $genera_guia = \Httpful\Request::post(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/Embarque")
                        ->body(json_encode($content), \Httpful\Mime::FORM)
                        ->send();

                    $genera_guia = json_decode($genera_guia->raw_body);

                    if (property_exists($genera_guia, 'estatus')) {
                        if (in_array($genera_guia->estatus, ['false', 'error', 'warning'])) {
                            $response->error = 1;
                            $response->mensaje = $genera_guia->mensaje . "" . self::logVariableLocation();

                            return $response;
                        }
                    }

                    $guia = $genera_guia->guia;

                    sleep(2);
                }

                if (empty($guia)) {
                    $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
                    $informacion = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta . ""));

                    if (empty($informacion)) {
                        $response->error = 1;
                        $response->mensaje = "No fue posible obtener los informacion de la venta " . $venta . "" . self::logVariableLocation();

                        return $response;
                    }

                    $guia = "";

                    if (property_exists($informacion, "productos")) {
                        $guia = $informacion->productos[0]->guia;
                    }
                }

                if (empty($guia)) {
                    $response->error = 1;
                    $response->mensaje = "No fue posible obtener el número de guía en la plataforma de claroshop, favor de contactar a un administrador." . self::logVariableLocation();

                    return $response;
                }

                $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
                $documento = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=guiasyanexos&nopedido=" . $venta));

                if (empty($documento)) {
                    $response->error = 1;
                    $response->mensaje = "No fue posible obtener los documentos de embarque de la venta " . $venta . "" . self::logVariableLocation();

                    return $response;
                }

                if (property_exists($documento, 'estatus')) {
                    if (in_array($documento->estatus, ['false', 'error', 'warning'])) {
                        $response->error = 1;
                        $response->mensaje = $documento->mensaje . "" . self::logVariableLocation();

                        return $response;
                    }
                }

                $response->error = 0;
                $response->file = preg_replace('#^data:image/\w+;base64,#i', '', $documento->guia_embarque);

                return $response;
            }
        }

        $response->error = 0;
        $response->file = preg_replace('#^data:image/\w+;base64,#i', '', $informacion->guia_embarque);

        return $response;
    }

    public static function importarVentasMasiva($marketplace_id, $usuario, $empresa_almacen, $empresa)
    {

        set_time_limit(0);
        $response = new \stdClass();
        $p = array();
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
                                        WHERE marketplace_area.id =" . $marketplace_id . "")[0];

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);

        $respuesta = \Httpful\Request::get(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=pendientes")->send();

        $informacion    = json_decode($respuesta->raw_body);

        foreach ($informacion->listapendientes as $venta) {
            $existe_venta = DB::table('documento')->where('no_venta', $venta->nopedido)->first();

            if (!empty($existe_venta)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Ya existe la venta registrada en el sistema, pedido: " . $existe_venta->id . "" . PHP_EOL, FILE_APPEND);

                continue;
            }

            $signature2 = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
            $pedido = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature2 . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta->nopedido));

            if (property_exists($pedido, 'estatus')) {
                if (in_array($pedido->estatus, ['error', 'warning'])) {
                    DB::table('importacion_error')->insert([
                        'marketplace' => "CLAROSHOP",
                        'no_venta' => $venta->nopedido,
                        'error' => "Ocurrio un error al buscar la venta en los sistemas de Claroshop"
                    ]);
                    file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Ocurrió un error al buscar la venta " . $venta->nopedido . " en los sistemas de Claroshop, mensaje de error: " . $informacion->mensaje . "." . PHP_EOL, FILE_APPEND);
                    continue;
                }
            }

            $documento = DB::table('documento')->insertGetId([
                'documento_extra'               => 'N/A',
                'id_periodo'                    => 1,
                'id_cfdi'                       => 3,
                'id_almacen_principal_empresa'  => $empresa_almacen,
                'id_almacen_secundario_empresa' => 0,
                'id_marketplace_area'           => $marketplace_id,
                'id_usuario'                    => $usuario,
                'id_moneda'                     => 3,
                'id_paqueteria'                 => 1,
                'id_fase'                       => 1,
                'id_modelo_proveedor'           => 0,
                'no_venta'                      => $venta->nopedido,
                'tipo_cambio'                   => 1,
                'referencia'                    => $venta->nopedido,
                'observacion'                   => 'Pedido Importado Claroshop',
                'info_extra'                    => "N/A",
                'fulfillment'                   => 0,
                'mkt_total'                     => $venta->totalpedido,
                'mkt_fee'                       => $venta->comision ?? 0,
                'mkt_coupon'                    => 0,
                'mkt_shipping_total'            => 0,
                'mkt_created_at'                => $pedido->estatuspedido->fechacolocado,
                'started_at'                    => date('Y-m-d H:i:s')
            ]);

            $entidad = DB::table('documento_entidad')->insertGetId([
                'razon_social'  => mb_strtoupper($pedido->datosenvio->entregara, 'UTF-8'),
                'rfc'           => mb_strtoupper('XAXX010101000', 'UTF-8'),
                'telefono'      => '0',
                'telefono_alt'  => '0',
                'correo'        => $empresa == 7 ? 'm.guerrero@omg.com.mx' : 'isabel@arome.mx',
                'info_extra'    => 'Pedido Claro'
            ]);

            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario' => 1,
                'id_metodopago' => 31,
                'id_vertical' => 0,
                'id_categoria' => 0,
                'id_clasificacion' => 0,
                'tipo' => 1,
                'origen_importe' => 0,
                'destino_importe' => $venta->totalpedido, //puede ser total_price
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

            DB::table('documento_entidad_re')->insert([
                'id_entidad'    => $entidad,
                'id_documento'  => $documento
            ]);

            try {
                $direccion = \Httpful\Request::get(config("webservice.url") . 'Consultas/CP/' . $informacion->datosenvio->cp)->send();

                $direccion = json_decode($direccion->raw_body);

                if ($direccion->code == 200) {
                    $estado             = $direccion->estado[0]->estado;
                    $ciudad             = $direccion->municipio[0]->municipio;
                    $colonia            = "";
                    $id_direccion_pro   = "";

                    foreach ($direccion->colonia as $colonia_text) {
                        if (strtolower($colonia_text->colonia) == strtolower($informacion->datosenvio->colonia)) {
                            $colonia            = $colonia_text->colonia;
                            $id_direccion_pro   = $colonia_text->codigo;
                        }
                    }
                } else {
                    $estado             = $informacion->datosenvio->estado;
                    $ciudad             = $informacion->datosenvio->ciudad;
                    $colonia            = $informacion->datosenvio->colonia;
                    $id_direccion_pro   = "";
                }
            } catch (Exception $e) {
                $estado             = $informacion->datosenvio->estado;
                $ciudad             = $informacion->datosenvio->ciudad;
                $colonia            = $informacion->datosenvio->colonia;
                $id_direccion_pro   = "";
            }

            DB::table('documento_direccion')->insert([
                'id_documento'      => $documento,
                'id_direccion_pro'  => $id_direccion_pro,
                'contacto'          => mb_strtoupper($informacion->datosenvio->entregara, 'UTF-8'),
                'calle'             => mb_strtoupper($informacion->datosenvio->direccion, 'UTF-8'),
                'numero'            => '',
                'numero_int'        => '',
                'colonia'           => $colonia,
                'ciudad'            => $ciudad,
                'estado'            => $estado,
                'codigo_postal'     => mb_strtoupper($informacion->datosenvio->cp, 'UTF-8'),
                'referencia'        => mb_strtoupper($informacion->datosenvio->entrecalles, 'UTF-8'),
            ]);

            $response = \Httpful\Request::get(config("webservice.url") . 'producto/Consulta/Productos/SKU/7/' . rawurlencode(trim($venta->sku)) . '')->send();

            $productos_info = $response->body;

            if (empty($productos_info)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Producto no encontrado, codigo del producto " . $venta->sku . ", venta: " . $venta->nopedido . " " . PHP_EOL, FILE_APPEND);

                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => "Producto no encontrado, codigo del producto " . $venta->sku . ", venta: " . $venta->nopedido . " "
                ]);

                $publicacion = DB::table('marketplace_publicacion')->where('publicacion_id', $venta->claroid)->first();

                if (!empty($publicacion)) {
                    $publicacion_productos = DB::table('marketplace_publicacion_producto')->where('id_publicacion', $publicacion->id)->get();

                    if (!empty($publicacion_productos)) {
                        foreach ($publicacion_productos as $productos) {
                            // Calcular el total sin IVA del pedido
                            $totalSinIVA = (float) $pedido->productos[0]->importe / 1.16;

                            // Convertir el porcentaje de entero a decimal dividiéndolo por 100
                            $porcentajeDecimal = $productos->porcentaje / 100;

                            // Calcular el precio sin IVA para el producto actual según su porcentaje del importe
                            $precioSinIVA = $totalSinIVA * $porcentajeDecimal;

                            $movimiento = DB::table('movimiento')->insertGetId([
                                'id_documento' => $documento,
                                'id_modelo' => $productos->id_modelo,
                                'cantidad' => count($pedido->productos) * $productos->cantidad ?? 1, //quantity
                                'precio' => $precioSinIVA, //price
                                'garantia' => 0, //null
                                'modificacion' => '',
                                'regalo' => '' //null
                            ]);
                        }
                    } else {
                        DB::table('seguimiento')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'seguimiento' => "No hay relacion entre publicacion y los productos"
                        ]);
                    }
                } else {
                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "No existe la publicación en crm."
                    ]);
                }
            } else {
                if ($productos_info[0]->tipo == 1) {
                    $existencia_real = 0;

                    foreach ($productos_info[0]->existencias->almacenes as $almacen) {
                        if ($almacen->almacenid == 1) {
                            $pendientes_surtir = DB::select("SELECT
                                                            IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                        FROM documento
                                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                        WHERE modelo.sku = '" . $venta->sku . "'
                                                        AND empresa_almacen.id_erp = 1
                                                        AND documento.id_tipo = 2
                                                        AND documento.status = 1
                                                        AND documento.anticipada = 0
                                                        AND documento.id_fase < 6")[0]->cantidad;

                            $pendientes_pretransferencia = DB::select("SELECT
                                                                    IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                                FROM documento
                                                                INNER JOIN empresa_almacen ON documento.id_almacen_secundario_empresa = empresa_almacen.id
                                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                WHERE modelo.sku = '" . $venta->sku . "'
                                                                AND empresa_almacen.id_erp = 1
                                                                AND documento.id_tipo = 9
                                                                AND documento.status = 1
                                                                AND documento.id_fase IN (401, 402, 403, 404)")[0]->cantidad;

                            $pendientes_recibir = DB::select("SELECT
                                                            movimiento.id AS movimiento_id,
                                                            modelo.sku,
                                                            modelo.serie,
                                                            movimiento.completa,
                                                            movimiento.cantidad,
                                                            (SELECT
                                                                COUNT(*) AS cantidad
                                                            FROM movimiento
                                                            INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                            WHERE movimiento.id = movimiento_id) AS recepcionadas
                                                        FROM documento
                                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                        WHERE documento.id_tipo = 1
                                                        AND documento.status = 1
                                                        AND modelo.sku = '" . $venta->sku . "'
                                                        AND empresa_almacen.id_erp = 1
                                                        AND documento.id_fase = 89");

                            $existencia_real = (int) $almacen->fisico - (int) $pendientes_surtir - (int) $pendientes_pretransferencia - (int) $pendientes_recibir;
                        }
                    }

                    if ($existencia_real < $venta->cantidad) {
                        file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: No hay suficiente existencia para procesar la venta " . $venta->venta . ", codigo del producto " . $venta->sku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                        DB::table('seguimiento')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'seguimiento' => "No hay suficiente existencia para procesar la venta " . $venta->venta . ", codigo del producto " . $venta->sku
                        ]);

                        $publicacion = DB::table('marketplace_publicacion')->where('publicacion_id', $venta->claroid)->first();

                        if (!empty($publicacion)) {
                            $publicacion_productos = DB::table('marketplace_publicacion_producto')->where('id_publicacion', $publicacion->id)->get();

                            if (!empty($publicacion_productos)) {
                                foreach ($publicacion_productos as $productos) {
                                    // Calcular el total sin IVA del pedido
                                    $totalSinIVA = (float) $pedido->productos[0]->importe / 1.16;

                                    // Convertir el porcentaje de entero a decimal dividiéndolo por 100
                                    $porcentajeDecimal = $productos->porcentaje / 100;

                                    // Calcular el precio sin IVA para el producto actual según su porcentaje del importe
                                    $precioSinIVA = $totalSinIVA * $porcentajeDecimal;

                                    $movimiento = DB::table('movimiento')->insertGetId([
                                        'id_documento' => $documento,
                                        'id_modelo' => $productos->id_modelo,
                                        'cantidad' => count($pedido->productos) * $productos->cantidad ?? 1, //quantity
                                        'precio' => $precioSinIVA, //price
                                        'garantia' => 0, //null
                                        'modificacion' => '',
                                        'regalo' => '' //null
                                    ]);
                                }
                            } else {
                                DB::table('seguimiento')->insert([
                                    'id_documento' => $documento,
                                    'id_usuario' => 1,
                                    'seguimiento' => "No hay relacion entre publicacion y los productos"
                                ]);
                            }
                        } else {
                            DB::table('seguimiento')->insert([
                                'id_documento' => $documento,
                                'id_usuario' => 1,
                                'seguimiento' => "No existe la publicación en crm."
                            ]);
                        }
                    }
                }
            }
        } // Fin Foreach

        $response->error = 0;
        $response->mensaje = "Ventas importadas correctamente";

        return $response;
    }

    public static function extraerNumero($pattern, $string)
    {
        if (preg_match($pattern, $string, $matches)) {
            $numero = $matches[1];
            return !empty($numero) ? $numero : null;
        } else {
            return null;
        }
    }

    public static function importarVentas($marketplace_id, $usuario, $ventas)
    {
        set_time_limit(0);

        $response = new \stdClass();
        $archivos = array();

        if (empty($ventas)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron ventas en el archivo." . self::logVariableLocation();

            return $response;
        }

        file_put_contents("logs/claroshop.log", "");

        foreach ($ventas as $venta) {
            $existe_venta = DB::select("SELECT id FROM documento WHERE no_venta = '" . TRIM($venta->venta) . "'");

            if (!empty($existe_venta)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Ya existe la venta registrada en el sistema, pedido: " . $existe_venta[0]->id . "" . PHP_EOL, FILE_APPEND);

                continue;
            }

            $credenciales = DB::select("SELECT
                                        app_id,
                                        secret
                                    FROM marketplace_api
                                    WHERE id_marketplace_area = " . $marketplace_id . "");

            if (empty($credenciales)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: El marketplace no cuenta con credenciales para utilizar la API." . PHP_EOL, FILE_APPEND);

                continue;
            }

            $signature      = hash('sha256', $credenciales[0]->app_id . date('Y-m-d\TH:i:s') . $credenciales[0]->secret);

            $response       = \Httpful\Request::get(config("webservice.claroshop_enpoint") . $credenciales[0]->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta->venta . "")->send();

            $informacion    = json_decode($response->raw_body);

            if (property_exists($informacion, 'estatus')) {
                if (in_array($informacion->estatus, ['error', 'warning'])) {
                    file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Ocurrió un error al buscar la venta " . $venta->venta . " en los sistemas de Claroshop, mensaje de error: " . $informacion->mensaje . "." . PHP_EOL, FILE_APPEND);

                    continue;
                }
            }

            $response = \Httpful\Request::get(config("webservice.url") . 'producto/Consulta/Productos/SKU/7/' . rawurlencode(trim($venta->sku)) . '')->send();

            $productos_info = $response->body;

            if (empty($productos_info)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Producto no encontrado, codigo del producto " . $venta->sku . ", venta: " . $venta->venta . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                continue;
            }

            if (!is_array($productos_info)) {
                file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Ocurrió un error al buscar el producto en el ERP, codigo del producto " . $venta->sku . ", venta: " . $venta->venta . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                $break_producto = 1;

                continue;
            }

            if ($productos_info[0]->tipo == 1) {
                $existencia_real = 0;

                foreach ($productos_info[0]->existencias->almacenes as $almacen) {
                    if ($almacen->almacenid == 1) {
                        $pendientes_surtir = DB::select("SELECT
                                                            IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                        FROM documento
                                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                        WHERE modelo.sku = '" . $venta->sku . "'
                                                        AND empresa_almacen.id_erp = 1
                                                        AND documento.id_tipo = 2
                                                        AND documento.status = 1
                                                        AND documento.anticipada = 0
                                                        AND documento.id_fase < 6")[0]->cantidad;

                        $pendientes_pretransferencia = DB::select("SELECT
                                                                    IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                                FROM documento
                                                                INNER JOIN empresa_almacen ON documento.id_almacen_secundario_empresa = empresa_almacen.id
                                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                                WHERE modelo.sku = '" . $venta->sku . "'
                                                                AND empresa_almacen.id_erp = 1
                                                                AND documento.id_tipo = 9
                                                                AND documento.status = 1
                                                                AND documento.id_fase IN (401, 402, 403, 404)")[0]->cantidad;

                        $pendientes_recibir = DB::select("SELECT
                                                            movimiento.id AS movimiento_id,
                                                            modelo.sku,
                                                            modelo.serie,
                                                            movimiento.completa,
                                                            movimiento.cantidad,
                                                            (SELECT
                                                                COUNT(*) AS cantidad
                                                            FROM movimiento
                                                            INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                            WHERE movimiento.id = movimiento_id) AS recepcionadas
                                                        FROM documento
                                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                        WHERE documento.id_tipo = 1
                                                        AND documento.status = 1
                                                        AND modelo.sku = '" . $venta->sku . "'
                                                        AND empresa_almacen.id_erp = 1
                                                        AND documento.id_fase = 89");

                        $existencia_real = (int) $almacen->fisico - (int) $pendientes_surtir - (int) $pendientes_pretransferencia - (int) $pendientes_recibir;
                    }
                }

                if ($existencia_real < $venta->cantidad) {
                    file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: No hay suficiente existencia para procesar la venta " . $venta->venta . ", codigo del producto " . $venta->sku . ", creación cancelada." . PHP_EOL, FILE_APPEND);

                    continue;
                }
            }

            $existe_producto_crm = DB::select("SELECT id FROM modelo WHERE sku = '" . $venta->sku . "'");

            if (empty($existe_producto_crm)) {
                $id_modelo = DB::table('modelo')->insertGetId([
                    'id_tipo'       => 1,
                    'sku'           => $productos_info[0]->sku,
                    'descripcion'   => $productos_info[0]->producto,
                    'costo'         => 0,
                    'costo_extra'   => 0,
                    'alto'          => 0,
                    'ancho'         => 0,
                    'largo'         => 0,
                    'peso'          => 0,
                    'serie'         => 0,
                    'clave_sat'     => $productos_info[0]->claveprodserv,
                    'unidad'        => $productos_info[0]->unidad,
                    'clave_unidad'  => $productos_info[0]->claveunidad
                ]);
            } else {
                $id_modelo = $existe_producto_crm[0]->id;
            }

            $entidad = DB::table('documento_entidad')->insertGetId([
                'razon_social'  => mb_strtoupper($informacion->datosenvio->entregara, 'UTF-8'),
                'rfc'           => mb_strtoupper('XAXX010101000', 'UTF-8'),
                'telefono'      => '0',
                'telefono_alt'  => '0',
                'correo'        => mb_strtoupper('MARCO.SANCHEZ@OMGCORP.COM.MX', 'UTF-8')
            ]);

            $documento = DB::table('documento')->insertGetId([
                'documento_extra'               => '',
                'id_periodo'                    => 1,
                'id_cfdi'                       => 3,
                'id_almacen_principal_empresa'  => 2,
                'id_almacen_secundario_empresa' => 1,
                'id_marketplace_area'           => $marketplace_id,
                'id_usuario'                    => $usuario,
                'id_moneda'                     => 3,
                'id_paqueteria'                 => 3,
                'id_fase'                       => 3,
                'no_venta'                      => $venta->venta,
                'tipo_cambio'                   => 1,
                'referencia'                    => $venta->venta,
                'observacion'                   => '',
                'info_extra'                    => 0,
                'fulfillment'                   => 0,
                'mkt_total'                     => $venta->cantidad * $venta->precio,
                'mkt_fee'                       => 0,
                'mkt_coupon'                    => 0,
                'mkt_shipping_total'            => 0,
                'mkt_created_at'                => $informacion->estatuspedido->fechacolocado,
                'started_at'                    => date('Y-m-d H:i:s')
            ]);

            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario'                => $usuario,
                'id_metodopago'             => 99,
                'id_vertical'               => 0,
                'id_categoria'              => 0,
                'id_clasificacion'          => 1,
                'tipo'                      => 1,
                'origen_importe'            => 0,
                'destino_importe'           => $venta->cantidad * $venta->precio,
                'folio'                     => "",
                'entidad_origen'            => 1,
                'origen_entidad'            => 'XAXX010101000',
                'entidad_destino'           => '',
                'destino_entidad'           => '',
                'referencia'                => '',
                'clave_rastreo'             => '',
                'autorizacion'              => '',
                'destino_fecha_operacion'   => date('Y-m-d'),
                'destino_fecha_afectacion'  => '',
                'cuenta_cliente'            => ''
            ]);

            DB::table('documento_pago_re')->insert([
                'id_documento'  => $documento,
                'id_pago'       => $pago
            ]);

            DB::table('seguimiento')->insert([
                'id_documento'  => $documento,
                'id_usuario'    => 1,
                'seguimiento'   => "<p>VENTA IMPORTADA MASIVAMENTE</p>"
            ]);

            DB::table('documento_entidad_re')->insert([
                'id_entidad'    => $entidad,
                'id_documento'  => $documento
            ]);

            try {
                $direccion = \Httpful\Request::get(config("webservice.url") . 'Consultas/CP/' . $informacion->datosenvio->cp)->send();

                $direccion = json_decode($direccion->raw_body);

                if ($direccion->code == 200) {
                    $estado             = $direccion->estado[0]->estado;
                    $ciudad             = $direccion->municipio[0]->municipio;
                    $colonia            = "";
                    $id_direccion_pro   = "";

                    foreach ($direccion->colonia as $colonia_text) {
                        if (strtolower($colonia_text->colonia) == strtolower($informacion->datosenvio->colonia)) {
                            $colonia            = $colonia_text->colonia;
                            $id_direccion_pro   = $colonia_text->codigo;
                        }
                    }
                } else {
                    $estado             = $informacion->datosenvio->estado;
                    $ciudad             = $informacion->datosenvio->ciudad;
                    $colonia            = $informacion->datosenvio->colonia;
                    $id_direccion_pro   = "";
                }
            } catch (Exception $e) {
                $estado             = $envio->receiver_address->state->name;
                $ciudad             = $envio->receiver_address->city->name;
                $colonia            = $envio->receiver_address->neighborhood->name;
                $id_direccion_pro   = "";
            }

            DB::table('documento_direccion')->insert([
                'id_documento'      => $documento,
                'id_direccion_pro'  => $id_direccion_pro,
                'contacto'          => mb_strtoupper($informacion->datosenvio->entregara, 'UTF-8'),
                'calle'             => mb_strtoupper($informacion->datosenvio->direccion, 'UTF-8'),
                'numero'            => '',
                'numero_int'        => '',
                'colonia'           => $colonia,
                'ciudad'            => $ciudad,
                'estado'            => $estado,
                'codigo_postal'     => mb_strtoupper($informacion->datosenvio->cp, 'UTF-8'),
                'referencia'        => mb_strtoupper($informacion->datosenvio->entrecalles, 'UTF-8'),
            ]);

            $modelo_data = DB::select("SELECT * FROM modelo WHERE sku = '" . $venta->sku . "'");

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento,
                'id_modelo'     => $id_modelo,
                'cantidad'      => $venta->cantidad,
                'precio'        => (float) $venta->precio / $venta->cantidad / 1.16,
                'garantia'      => '90',
                'modificacion'  => '',
                'regalo'        => 0
            ]);

            file_put_contents("logs/claroshop.log", date("d/m/Y H:i:s") . " Error: Correcto, venta creada " . $venta->venta . " correctamente con el pedido " . $documento . "" . PHP_EOL, FILE_APPEND);
        }

        array_push($archivos, [
            'file'  => base64_encode(file_get_contents("logs/claroshop.log")),
            'name'  => "REPORTE_CLAROSHOPA_IMPORTACION_" . date('Y-m-d') . ".log"
        ]);

        $response->error = 1;
        $response->mensaje = "Venta importadas correctamente, se descargará un archivo con la información de las ventas importadas y las no importadas.";
        $response->archivos = $archivos;

        return $response;
    }

    //! YA
    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'CS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'HOP'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }


    //!SOLO RESPUESTA DE API

    public static function developerVenta($venta, $marketplace_id)
    {
        $response = new \stdClass();

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
                                        WHERE marketplace_area.id =" . $marketplace_id . "")[0];

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);
        $informacion = @json_decode(file_get_contents(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=detallepedido&nopedido=" . $venta . ""));

        if (empty($informacion)) {
            $response->error = 1;
            $response->mensaje = "No fue posible obtener información de la venta en claroshop." . self::logVariableLocation();

            return $response;
        }

        if (property_exists($informacion, 'estatus')) {
            if (in_array($informacion->estatus, ['error', 'warning'])) {
                $response->error = 1;
                $response->mensaje = $informacion->mensaje . "" . self::logVariableLocation();

                return $response;
            }
        }

        $response->error = 0;
        $response->data = $informacion;

        return $response;
    }

    public static function developerImportarVentasMasiva($marketplace_id)
    {
        set_time_limit(0);

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
                                        WHERE marketplace_area.id =" . $marketplace_id . "")[0];

        try {
            $credenciales->secret = Crypt::decrypt($credenciales->secret);
        } catch (DecryptException $e) {
            $credenciales->secret = "";
        }

        $signature = hash('sha256', $credenciales->app_id . date('Y-m-d\TH:i:s') . $credenciales->secret);

        $respuesta = \Httpful\Request::get(config("webservice.claroshop_enpoint") . $credenciales->app_id . "/" . $signature . "/" . date('Y-m-d\TH:i:s') . "/pedidos?action=pendientes")->send();

        $informacion    = json_decode($respuesta->raw_body);

        return $informacion;
    }
}
