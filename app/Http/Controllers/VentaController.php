<?php

namespace App\Http\Controllers;

use App\Http\Services\BitacoraService;
use App\Http\Services\CorreoService;
use App\Http\Services\InventarioService;
use http\Env\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use App\Events\PickingEvent;
use App\Events\PusherEvent;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use Mailgun\Mailgun;
use Exception;

use App\Models\Usuario;
use App\Models\Enums\UsuarioNivel;
use App\Models\Paqueteria;
use App\Models\DocumentoGarantiaCausa;

use App\Http\Services\ElektraService;
use App\Http\Services\MercadolibreService;
use App\Http\Services\ClaroshopService;
use App\Http\Services\DocumentoService;
use App\Http\Services\ShopifyService;
use App\Http\Services\WalmartService;
use App\Http\Services\AmazonService;
use App\Http\Services\LinioService;
use App\Http\Services\ClaroshopServiceV2;
use App\Http\Services\AromeService;
use App\Http\Services\CoppelService;
use App\Http\Services\LiverpoolService;
use App\Http\Services\ExelDelNorteService;
use App\Http\Services\CTService;
use App\Http\Services\GeneralService;

use App\Models\MarketplaceArea;

class VentaController extends Controller
{
    /* Venta > Venta > Crear */
    public function venta_venta_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $modificacion   = 0;
        $pedidos_creados = "";
        $archivos = array();

        $existe_venta = DB::select("SELECT 
                                        documento.id,
                                        marketplace.id AS id_marketplace,
                                        marketplace.marketplace
                                    FROM documento 
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE documento.no_venta = '" . trim($data->documento->venta) . "' 
                                    AND documento.no_venta != '.' 
                                    AND documento.id_marketplace_area = " . $data->documento->marketplace . " AND documento.status = 1");

        if (!empty($existe_venta)) {
            if ($existe_venta[0]->id_marketplace != 19 && !in_array($existe_venta[0]->marketplace, ["WALMART"])) {
                $log = self::logVariableLocation();

                return response()->json([
                    'code'  => 500,
                    'message'   => "El número de venta ya se encuentra registrada<br>Documento CRM: " . $existe_venta[0]->id . ""
                ]);
            }
        }

        if (!empty($data->documento->proveedor)) {
            $data->documento->fulfillment = 1;

            foreach ($data->documento->productos as $producto) {
                $existe_modelo = DB::select("SELECT id, sku FROM modelo WHERE sku = '" . trim($producto->codigo) . "'");

                if (empty($existe_modelo)) {
                    $log = self::logVariableLocation();

                    return response()->json([
                        "code" => 500,
                        "message" => "No se encontró el codigo del producto para el proveedor de dropshipping seleccionado, favor de verificar e intentar de nuevo."
                    ]);
                }

                $existe_modelo_proveedor = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_modelo", $existe_modelo[0]->id)
                    ->where("id_modelo_proveedor", $data->documento->proveedor)
                    ->first();

                if (empty($existe_modelo_proveedor)) {
                    $log = self::logVariableLocation();

                    return response()->json([
                        "code" => 500,
                        "message" => "No se encontró el codigo del producto para el proveedor de dropshipping seleccionado, favor de verificar e intentar de nuevo."
                    ]);
                }

                $inventario = DB::table("modelo_proveedor_producto_existencia")
                    ->join("modelo_proveedor_almacen", "modelo_proveedor_producto_existencia.id_almacen", "=", "modelo_proveedor_almacen.id")
                    ->where("modelo_proveedor_producto_existencia.id_modelo", $existe_modelo_proveedor->id)
                    ->where("modelo_proveedor_producto_existencia.existencia", ">=", $producto->cantidad)
                    ->orderByRaw("CASE WHEN modelo_proveedor_almacen.id_locacion LIKE '%MX%' THEN 1 WHEN modelo_proveedor_almacen.id_locacion LIKE '%GD%' THEN 2 ELSE 3 END")
                    ->first();

                if (empty($inventario)) {
                    $log = self::logVariableLocation();

                    return response()->json([
                        "code" => 500,
                        "message" => "No hay inventario suficiente del codigo " . $existe_modelo[0]->sku . " en ningun almacén del proveedor."
                    ]);
                }
            }
        }

        if (strpos(TRIM($data->cliente->rfc), 'XAXX010101000') === false && strpos(TRIM($data->cliente->rfc), 'XEXX010101000') === false) {
            $cliente = $data->cliente->select;
        } else {
            $cliente = DB::table('documento_entidad')->insertGetId([
                'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                'rfc' => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8')),
                'regimen' => property_exists($data->cliente, "regimen") ? trim($data->cliente->regimen) : "",
                'regimen_id' => property_exists($data->cliente, "regimen") ? trim($data->cliente->regimen) : "",
                'codigo_postal_fiscal' => property_exists($data->cliente, "cp_fiscal") ? trim($data->cliente->cp_fiscal) : "",
            ]);
        }

        $marketplace_name = DB::table("marketplace_area")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $data->documento->marketplace)
            ->first();

        $mkp_area_name = DB::table("marketplace_area")
            ->join("area", "marketplace_area.id_area", "=", "area.id")
            ->where("marketplace_area.id", $data->documento->marketplace)->first();

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa'  => $data->documento->almacen,
            'id_periodo' => $data->documento->periodo,
            'id_cfdi' => $data->documento->uso_venta,
            'id_marketplace_area' => $data->documento->marketplace,
            'id_usuario' => $auth->id,
            'id_moneda' => $data->documento->moneda,
            'id_paqueteria' => $data->documento->paqueteria,
            'id_fase' => 3,
            'no_venta' => TRIM($data->documento->venta),
            'id_modelo_proveedor' => empty($data->documento->proveedor) ? 0 : $data->documento->proveedor,
            'tipo_cambio' => $data->documento->tipo_cambio,
            'referencia' => TRIM($data->documento->referencia),
            'observacion' => TRIM($data->documento->observacion),
            'info_extra' => $data->documento->info_extra,
            'id_entidad' => $cliente,
            'fulfillment' => $data->documento->fulfillment,
            'series_factura' => $data->documento->series_factura,
            'autorizado' => ($data->documento->baja_utilidad) ? 0 : 1,
            'anticipada' => $data->documento->anticipada,
            'addenda_orden_compra' => $data->addenda->orden_compra,
            'addenda_solicitud_pago' => $data->addenda->solicitud_pago,
            'addenda_tipo_documento' => $data->addenda->tipo_documento,
            'addenda_factura_asociada' => $data->addenda->factura_asociada,
            'mkt_fee' => $data->documento->mkt_fee ?? 0,
            'mkt_shipping_total' => $data->documento->costo_envio ?? 0,
            'mkt_shipping_total_cost' => $data->documento->costo_envio_total ?? 0,
            'mkt_shipping_id' => $data->documento->mkt_shipping ?? 0,
            'mkt_user_total' => $data->documento->total_user ?? 0,
            'mkt_total' => $data->documento->total ?? 0,
            'mkt_created_at' => $data->documento->mkt_created_at ?? 0,
            'started_at' => $data->documento->fecha_inicio,
            'shipping_null' => $data->documento->shipping_null ?? 0
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->documento->seguimiento
        ]);

        if (property_exists($data, 'usuario_agro')) {
            DB::table('documento_usuario_agro')->insert([
                'id_documento' => $documento,
                'id_usuarios_agro' => $data->usuario_agro,
            ]);
        }

        foreach ($data->documento->productos as $producto) {
            DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento,
                'id_modelo'     => $producto->id,
                'cantidad'      => $producto->cantidad,
                'precio'        => $producto->precio,
                'garantia'      => $producto->garantia,
                'retencion'     => $producto->ret,
                'modificacion'  => $producto->modificacion,
                'comentario'    => $producto->comentario,
                'addenda'       => $producto->addenda,
                'regalo'        => $producto->regalo
            ]);

            if (TRIM($producto->modificacion) != "") {
                DB::table('documento')->where(['id' => $documento])->update([
                    'modificacion'  => 1,
                    'id_fase'       => 2
                ]);

                $modificacion = 1;
            }
        }

        DB::table('documento_direccion')->insert([
            'id_documento' => $documento,
            'id_direccion_pro' => property_exists($data->documento->direccion_envio, "colonia") ? $data->documento->direccion_envio->colonia : "",
            'contacto' => property_exists($data->documento->direccion_envio, "contacto") ? $data->documento->direccion_envio->contacto : "",
            'calle' => property_exists($data->documento->direccion_envio, "calle") ? $data->documento->direccion_envio->calle : "",
            'numero' => property_exists($data->documento->direccion_envio, "numero") ? $data->documento->direccion_envio->numero : "",
            'numero_int' => property_exists($data->documento->direccion_envio, "numero_int") ? $data->documento->direccion_envio->numero_int : "",
            'colonia' => property_exists($data->documento->direccion_envio, "colonia_text") ? $data->documento->direccion_envio->colonia_text : "",
            'ciudad' => property_exists($data->documento->direccion_envio, "ciudad") ? $data->documento->direccion_envio->ciudad : "",
            'estado' => property_exists($data->documento->direccion_envio, "estado") ? $data->documento->direccion_envio->estado : "",
            'codigo_postal' => property_exists($data->documento->direccion_envio, "codigo_postal") ? $data->documento->direccion_envio->codigo_postal : "",
            'referencia' => property_exists($data->documento->direccion_envio, "referencia") ? $data->documento->direccion_envio->referencia : "",
            'contenido' => property_exists($data->documento->direccion_envio, "contenido") ? $data->documento->direccion_envio->contenido : "",
            'tipo_envio' => property_exists($data->documento->direccion_envio, "tipo_envio") ? $data->documento->direccion_envio->tipo_envio : "",
        ]);

        try {
            foreach ($data->documento->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento'  => $documento,
                        'id_usuario'    => $auth->id,
                        'tipo'          => $archivo->guia,
                        'id_impresora'  => $archivo->impresora,
                        'nombre'        => $archivo->nombre,
                        'dropbox'       => $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            $log = self::logVariableLocation();

            DB::table('documento')->where(['id' => $documento])->delete();

            return response()->json([
                'code' => 500,
                'message' => "No fue posible subir los archivos a dropbox, pedido cancelado, favor de contactar a un administrador. Mensaje de error: " . $e->getMessage(),
                'raw' => $e
            ]);
        }

        $message = "Venta creada correctamente. " . $documento;

        if ($data->documento->baja_utilidad) {
            $message .= "<br>El documento no alcanza la ultilidad del 5% respecto al costo de los productos seleccionados por lo cual, quedará pendiente de autorización.";

            try {
                $administradores = DB::select("SELECT 
                                                usuario.id
                                            FROM usuario 
                                            INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario 
                                            WHERE id_subnivel_nivel = 3 AND status = 1");

                if (!empty($administradores)) {
                    $usuarios = array();

                    $notificacion['titulo']     = "Autorización requerida";
                    $notificacion['message']    = "El pedido " . $documento . " requiere una autorización por no alcanzar el 5% de utilidad en el total de la venta.";
                    $notificacion['tipo']       = "warning"; // success, warning, danger
                    $notificacion['link']       = "/venta/venta/autorizar/" . $documento;

                    $notificacion_id = DB::table('notificacion')->insertGetId([
                        'data'  => json_encode($notificacion)
                    ]);

                    $notificacion['id']         = $notificacion_id;

                    foreach ($administradores as $usuario) {
                        DB::table('notificacion_usuario')->insert([
                            'id_usuario'        => $usuario->id,
                            'id_notificacion'   => $notificacion_id
                        ]);

                        array_push($usuarios, $usuario->id);
                    }

                    if (!empty($usuarios)) {
                        $notificacion['usuario']    = $usuarios;

                        event(new PusherEvent(json_encode($notificacion)));
                    }
                }
            } catch (Exception $e) {
                $log = self::logVariableLocation();

                $message .= "<br><br>No fue posible notificar a los administradores para la autorización del pedido por baja utilidad.";
            }
        }

        if ($data->documento->anticipada || $data->documento->fulfillment) {
            $response = InventarioService::aplicarMovimiento($documento);

            if ($response->error) {
                $pagos = DB::select("SELECT id_pago FROM documento_pago_re WHERE id_documento = " . $documento . "");

                foreach ($pagos as $pago) {
                    DB::table('documento_pago')->where(['id' => $pago->id_pago])->delete();
                }

                DB::table('documento')->where(['id' => $documento])->delete();

                return response()->json([
                    'code'  => 500,
                    'message' => $response->mensaje,
                    'raw' => property_exists($response, 'raw') ? $response->raw : 0,
                    'data' => property_exists($response, 'data') ? $response->data : 0
                ]);
            } else {
                $user = DB::table("usuario")->find($auth->id);

                GeneralService::sendEmailToAdmins("Crear Venta", "El usuario " . $user->nombre . " solicitó CCE para el documento: " . $documento, "", 1);
            }
        }

        if (!$data->documento->fulfillment) {
            $marketplace_info = DB::select("SELECT
                                                marketplace_area.id,
                                                marketplace_api.extra_1,
                                                marketplace_api.extra_2,
                                                marketplace_api.app_id,
                                                marketplace_api.secret,
                                                marketplace.marketplace
                                            FROM marketplace_area
                                            INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                            WHERE marketplace_area.id = " . $data->documento->marketplace . "");

            if (!empty($marketplace_info)) {
                $marketplace_info = $marketplace_info[0];
                $nombre = "etiqueta_" . trim($data->documento->venta) . ".pdf";
                $paqueteria = DB::select("SELECT paqueteria FROM paqueteria WHERE id = " . $data->documento->paqueteria . "")[0]->paqueteria;

                if (strpos($marketplace_info->marketplace, 'LINIO') !== false) {
                    $response = LinioService::cambiarEstadoVenta(trim($data->documento->referencia), $data->documento->marketplace, $paqueteria);

                    if ($response->error) {
                        if (strpos($response->mensaje, 'E073') === false) {
                            $pagos = DB::select("SELECT id_pago FROM documento_pago_re WHERE id_documento = " . $documento . "");

                            foreach ($pagos as $pago) {
                                DB::table('documento_pago')->where(['id' => $pago->id_pago])->delete();
                            }

                            DB::table('documento')->where(['id' => $documento])->delete();

                            return response()->json([
                                "code" => 500,
                                "message" => "No fue posible cargar los documentos de embarque correspondientes a la venta en dropbox, creación cancelada, mensaje de error: " . $response->mensaje . "",
                                "data" => property_exists($response, "data") ? $response->data : 0,
                                "a" => $response
                            ]);
                        }
                    }
                }

                if (strpos($marketplace_info->marketplace, 'ELEKTRA') !== false) {
                    $estado = ElektraService::cambiarEstado(trim($data->documento->venta), $marketplace_info, 1);

                    if ($estado->error) {
                        $message .= " No fue posible cambiar el estado de la venta, favor de cambiar manual.";
                    }
                }
            }
        }

        if (!empty($data->documento->proveedor)) {
            return response()->json([
                'code'  => 200,
                'message'   => $message,
                'file' => $pdf->data_odc,
                'name' => $pdf->name,
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => $message
        ]);
    }

    public function venta_venta_crear_data(Request $request)
    {
        $auth       = json_decode($request->auth);
        $json       = array();
        $cast       = array();

        $usuarios_agro = DB::table('usuarios_agro')->get()->toArray();
        $proveedores = DB::select("SELECT id, razon_social FROM modelo_proveedor WHERE status = 1 AND id != 0");
        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        $periodos = DB::select("SELECT id, periodo FROM documento_periodo WHERE status = 1");
        $paqueterias = DB::select("SELECT id, paqueteria, api FROM paqueteria WHERE status = 1");
        $impresoras = DB::select("SELECT id, nombre, tamanio FROM impresora WHERE status = 1 AND id != 0");
        $usos_venta = DB::select("SELECT * FROM documento_uso_cfdi");
        $metodos = DB::select("SELECT * FROM metodo_pago");
        $estados = DB::select("SELECT * FROM estados");
        $regimenes = DB::table("cat_regimen")->get();
        $marketplaces_publico = DB::select("SELECT
                                                marketplace.marketplace 
                                            FROM marketplace_area 
                                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id 
                                            WHERE marketplace_area.publico = 1 GROUP BY marketplace.marketplace");
        $monedas = DB::select("SELECT * FROM moneda");
        $niveles = DB::select("SELECT id_subnivel_nivel FROM usuario_subnivel_nivel WHERE id_usuario = " . $auth->id . "");
        $administrador = 0;
        $usuarios = DB::select("SELECT
                                                usuario.id,
                                                usuario.nombre
                                            FROM usuario
                                            INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                            INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                            INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                            WHERE nivel.nivel = 'ADMINISTRADOR'
                                            AND usuario.id != 1
                                            GROUP BY usuario.id");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        almacen.id AS id_almacen,
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ASC");

            $empresa->almacenes = $almacenes;
        }

        if (empty($niveles)) {
            return response()->json([
                'code'  => 404,
                'message'   => "No se encontraron los niveles del usuario, favor de contactar al administrador."
            ]);
        }

        foreach ($niveles as $nivel) {
            if ($nivel->id_subnivel_nivel == 1) {
                $administrador = 1;
            }
        }

        $areas = DB::select("SELECT
                                area.id,
                                area.area
                            FROM usuario_marketplace_area
                            INNER JOIN marketplace_area ON marketplace_area.id = usuario_marketplace_area.id_marketplace_area
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            WHERE usuario_marketplace_area.id_usuario = " . $auth->id . "
                            GROUP BY area.id");

        foreach ($areas as $area) {
            $marketplaces = DB::select("SELECT
                                            marketplace_area.id, 
                                            marketplace.marketplace, 
                                            marketplace_api.extra_1, 
                                            marketplace_api.extra_2, 
                                            marketplace_api.app_id, 
                                            marketplace_api.secret,
                                            marketplace_api.guia
                                        FROM marketplace_area 
                                        INNER JOIN usuario_marketplace_area ON marketplace_area.id = usuario_marketplace_area.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id 
                                        LEFT JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area 
                                        WHERE marketplace_area.id_area = " . $area->id . " AND marketplace_area.status = 1
                                        AND usuario_marketplace_area.id_usuario = " . $auth->id . "
                                        GROUP BY marketplace_area.id");

            if (empty($marketplaces)) {
                unset($areas[$k]);
                continue;
            }

            foreach ($marketplaces as $marketplace) {
                $marketplace->empresa = DB::select("SELECT
                                                    empresa.bd
                                                FROM marketplace_area_empresa
                                                INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                                WHERE marketplace_area_empresa.id_marketplace_area = " . $marketplace->id . "");
            }

            $area->marketplaces = $marketplaces;

            array_push($cast, $area);
        }

        foreach ($paqueterias as $paqueteria) {
            $paqueteria->tipos = DB::select("SELECT tipo, codigo FROM paqueteria_tipo WHERE id_paqueteria = " . $paqueteria->id . "");
        }

        return response()->json([
            'code'  => 200,
            'areas' => $areas,
            'metodos'   => $metodos,
            'monedas'   => $monedas,
            'usuarios'  => $usuarios,
            'periodos'  => $periodos,
            'empresas'  => $empresas,
            'almacenes' => $almacenes,
            'impresoras'    => $impresoras,
            'usos_venta'    => $usos_venta,
            'paqueterias'   => $paqueterias,
            'marketplaces'  => $marketplaces_publico,
            'proveedores' => $proveedores,
            'estados' => $estados,
            'usuarios_agro' => $usuarios_agro,
            'regimenes' => $regimenes
        ]);
    }

    public function venta_venta_crear_buscar_cliente($criterio) {
        $clientes = DB::table("documento_entidad")
                        ->where("tipo", 1)
                        ->where("rfc", $criterio)
                        ->orWhere("razon_social", "like", "%" . $criterio . "%")
                        ->get();


        return response()->json([
            "code" => 200,
            "data" => $clientes
        ]);
    }

    public function venta_venta_crear_cliente_direccion($rfc)
    {
        $direccion = DB::select("SELECT
                                    documento_direccion.*
                                FROM documento
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                INNER JOIN documento_direccion ON documento.id = documento_direccion.id_documento
                                INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                WHERE documento_entidad.rfc = '" . $rfc . "'
                                AND marketplace.id != 15
                                ORDER BY documento.created_at 
                                DESC LIMIT 1");

        $informacion = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento
                                INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                WHERE documento_entidad.rfc = '" . $rfc . "' ORDER BY documento.created_at DESC LIMIT 1");

        return response()->json([
            'direccion' => empty($direccion) ? new \stdClass() : $direccion[0],
            'informacion'   => empty($informacion) ? new \stdClass() : $informacion[0]
        ]);
    }

    public function venta_venta_crear_existe($venta, $marketplace)
    {
        $marketplace = MarketplaceArea::with("marketplace")->find($marketplace);

        if ((!empty($venta) && $venta != '.') && !in_array($marketplace->marketplace->marketplace, ["WALMART"])) {
            $existe = DB::select("SELECT id FROM documento WHERE no_venta = '" . $venta . "' AND id_marketplace_area = " . $marketplace->id . " AND status = 1");

            if (!empty($existe)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "El número de venta ya se encuentra registrada<br>Documento CRM: " . $existe[0]->id . ""
                ]);
            }
        }

        return response()->json([
            'code'  => 200
        ]);
    }

    public function venta_venta_crear_informacion(Request $request)
    {
        set_time_limit(0);

        $venta = str_replace(' ', '%20', $request->input('venta'));
        $marketplace = json_decode($request->input('marketplace'));
        //!! RELEASE T1 borrar los mp
        // if (in_array(strtolower($marketplace->marketplace), ["linio", "amazon", "claroshop", "sears"])) {
        if (in_array(strtolower($marketplace->marketplace), ["linio", "amazon"])) {
            try {
                $marketplace->secret = Crypt::decrypt($marketplace->secret);
            } catch (DecryptException $e) {
                $marketplace->secret = "";
            }

            if (empty($marketplace->secret)) {
                return response()->json([
                    "code" => 500,
                    "message" => "Ocurrió un error al desencriptar la llave del marketplace"
                ]);
            }
        }

        switch (strtolower($marketplace->marketplace)) {
            case 'mercadolibre':
                $informacion = MercadolibreService::venta($venta, $marketplace->id);
                break;

            case 'linio':
                $informacion = LinioService::venta($venta, $marketplace);
                break;

            case 'amazon':
                $informacion = AmazonService::venta($venta, $marketplace);
                break;

                // case 'claroshop':
                // case 'sears':
                //     $informacion = ClaroshopService::venta($venta, $marketplace);
                //     break;
                //!! RELEASE T1 reempalzar

            case 'claroshop':
            case 'sears':
            case 'sanborns':
                $informacion = ClaroshopServiceV2::venta($venta, $marketplace);
                break;

            case 'shopify':
                $informacion = ShopifyService::venta($venta, $marketplace->id);

                break;

            case 'elektra':
                $informacion = ElektraService::venta($venta, $marketplace->id);

                break;

            case 'walmart':
                $informacion = WalmartService::venta($venta, $marketplace->id);

                if($informacion->error){
                    $informacion2 = WalmartService::venta($venta, $marketplace->id, 1);

                    if(!$informacion2->error) {
                        $informacion = $informacion2;
                    }
                }

                break;

            case 'coppel':
                $informacion = CoppelService::venta($venta, $marketplace->id);

                break;

            case 'liverpool':
                $informacion = LiverpoolService::venta($venta, $marketplace->id);

                break;

            default:
                $informacion = new \stdClass();
                $informacion->error = 1;
                $informacion->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador. <br/> Error: BVC1048";

                break;
        }
        if ($informacion->error) {
            return response()->json([
                'code' => 500,
                'message' => $informacion->mensaje,
                'raw' => property_exists($informacion, 'raw') ? $informacion->raw : 0,
                'data' => property_exists($informacion, 'data') ? $informacion->data : 0,
            ]);
        }

        return response()->json([
            'code'  => 200,
            'venta' => $informacion->data
        ]);
    }

    public function venta_venta_crear_producto_existencia($producto, $almacen, $cantidad, Request $request)
    {
        $empresa = DB::select("SELECT
                                    empresa.id,
                                    empresa.bd
                                FROM empresa_almacen
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                WHERE empresa_almacen.id = " . $almacen . "");

        if (empty($empresa)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el almacén del documento, favor de contactar a un administrador."
            ], 500);
        }

        $empresa = $empresa[0];

        $total = InventarioService::existenciaProducto($producto, $almacen);

        if ($total->error) {
            return response()->json([
                'code' => 500,
                'message' => $total->mensaje
            ]);
        }

        if ($total->disponible < $cantidad) {
            return response()->json([
                'code' => 500,
                'message' => "Producto sin suficiente existencias.<br><br>Requerida: " . $cantidad . "<br>Disponible: " . $total->existencia
            ]);
        }

        $promociones = DB::select("SELECT
                                        promocion.id,
                                        promocion.titulo,
                                        promocion.inicio,
                                        promocion.fin
                                    FROM promocion
                                    INNER JOIN promocion_modelo ON promocion.id = promocion_modelo.id_promocion
                                    INNER JOIN modelo ON promocion_modelo.id_modelo = modelo.id
                                    WHERE '" . date("Y-m-d") . "' >= promocion.inicio AND '" . date("Y-m-d") . "' <= promocion.fin
                                    AND modelo.sku = '" . $producto . "'
                                    AND promocion.id_empresa = " . $empresa->id . "
                                    GROUP BY promocion.id");

        foreach ($promociones as $promocion) {
            $promocion->productos = DB::select("SELECT
                                                    modelo.sku AS codigo,
                                                    modelo.descripcion,
                                                    promocion_modelo.cantidad,
                                                    promocion_modelo.precio,
                                                    promocion_modelo.garantia,
                                                    promocion_modelo.regalo
                                                FROM promocion_modelo
                                                INNER JOIN modelo ON promocion_modelo.id_modelo = modelo.id
                                                WHERE promocion_modelo.id_promocion = " . $promocion->id . "");
        }

        return response()->json([
            'code' => 200,
            'existencia' => $total->disponible,
            'promociones' => $promociones
        ]);
    }

    public function venta_venta_crear_producto_proveedor_existencia($producto, $almacen, $cantidad, $proveedor, Request $request)
    {
        $empresa = DB::select("SELECT
                                    empresa.id,
                                    empresa.bd
                                FROM empresa_almacen
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                WHERE empresa_almacen.id = " . $almacen . "");

        if (empty($empresa)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el almacén del documento, favor de contactar a un administrador."
            ], 500);
        }

        $empresa = $empresa[0];

        $modelo = DB::table("modelo")->select('id')->where('sku', $producto)->get()->first();

        $total =
            DB::table('modelo_proveedor_producto')
            ->select('modelo_proveedor_producto_existencia.existencia')
            ->join('modelo_proveedor_producto_existencia', 'modelo_proveedor_producto.id', '=', 'modelo_proveedor_producto_existencia.id_modelo')
            ->where('modelo_proveedor_producto.id_modelo', '=', $modelo->id)
            ->where('modelo_proveedor_producto_existencia.existencia', '>=', $cantidad)
            ->get()
            ->first();

        $promociones = DB::select("SELECT
                                        promocion.id,
                                        promocion.titulo,
                                        promocion.inicio,
                                        promocion.fin
                                    FROM promocion
                                    INNER JOIN promocion_modelo ON promocion.id = promocion_modelo.id_promocion
                                    INNER JOIN modelo ON promocion_modelo.id_modelo = modelo.id
                                    WHERE '" . date("Y-m-d") . "' >= promocion.inicio AND '" . date("Y-m-d") . "' <= promocion.fin
                                    AND modelo.sku = '" . $producto . "'
                                    AND promocion.id_empresa = " . $empresa->id . "
                                    GROUP BY promocion.id");

        foreach ($promociones as $promocion) {
            $promocion->productos = DB::select("SELECT
                                                    modelo.sku AS codigo,
                                                    modelo.descripcion,
                                                    promocion_modelo.cantidad,
                                                    promocion_modelo.precio,
                                                    promocion_modelo.garantia,
                                                    promocion_modelo.regalo
                                                FROM promocion_modelo
                                                INNER JOIN modelo ON promocion_modelo.id_modelo = modelo.id
                                                WHERE promocion_modelo.id_promocion = " . $promocion->id . "");
        }

        return response()->json([
            'code' => 200,
            'existencia' => $total->existencia,
            'promociones' => $promociones
        ]);
    }

    public function venta_venta_crear_envio_cotizar(Request $request)
    {
        $productos          = json_decode($request->input('productos'));
        $data               = json_decode($request->input('data'));
        $tipo_envio         = $request->input('tipo_envio');
        $json               = array();
        $largo              = 0;
        $ancho              = 0;
        $alto               = 0;
        $peso               = 0;

        $json['estafeta']   = array();
        $json['fedex']      = array();
        $json['dhl']        = array();
        $json['ups']        = array();

        foreach ($productos as $producto) {
            $largo  += $producto->largo == 0 ? 1 : $producto->largo;
            $ancho  += $producto->ancho == 0 ? 1 : $producto->ancho;
            $alto   += $producto->alto == 0 ? 1 : $producto->alto;
            $peso   += $producto->peso == 0 ? 1 : $producto->peso;
        }

        $cotizar_estafeta = [
            'destino'                   => $data->ciudad,
            'peso'                      => $peso,
            'tipo'                      => ($tipo_envio == 1) ? "78" : "68",
            'largo'                     => $largo,
            'ancho'                     => $ancho,
            'alto'                      => $alto,
            'cp_ini'                    => "45130",
            'cp_end'                    => $data->codigo_postal,
            'from_cord_exists'          => $data->remitente_cord_found,
            'from_lat'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lat : 'not_found',
            'from_lng'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lng : 'not_found',
            'to_cord_exists'            => $data->destino_cord_found,
            'to_lng'                    => ($data->destino_cord_found) ? $data->destino_cord->lng : 'not_found',
            'to_lat'                    => ($data->destino_cord_found) ? $data->destino_cord->lat : 'not_found'
        ];

        $cotizar_fedex = [
            'tipo'                      => ($tipo_envio == 1) ? "FEDEX_EXPRESS_SAVER" : "STANDARD_OVERNIGHT",
            'destino'                   => $data->ciudad,
            'peso'                      => $peso,
            'cp_ini'                    => "45130",
            'cp_end'                    => $data->codigo_postal,
            'from_cord_exists'          => $data->remitente_cord_found,
            'from_lat'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lat : 'not_found',
            'from_lng'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lng : 'not_found',
            'to_cord_exists'            => $data->destino_cord_found,
            'to_lng'                    => ($data->destino_cord_found) ? $data->destino_cord->lng : 'not_found',
            'to_lat'                    => ($data->destino_cord_found) ? $data->destino_cord->lat : 'not_found'
        ];

        $cotizar_dhl = [
            'destino'                   => $data->ciudad,
            'peso'                      => $peso,
            'tipo'                      => ($tipo_envio == 1) ? "G" : "N",
            'largo'                     => $largo,
            'ancho'                     => $ancho,
            'alto'                      => $alto,
            'cp_ini'                    => "45130",
            'cp_end'                    => $data->codigo_postal,
            'from_cord_exists'          => $data->remitente_cord_found,
            'from_lat'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lat : 'not_found',
            'from_lng'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lng : 'not_found',
            'to_cord_exists'            => $data->destino_cord_found,
            'to_lng'                    => ($data->destino_cord_found) ? $data->destino_cord->lng : 'not_found',
            'to_lat'                    => ($data->destino_cord_found) ? $data->destino_cord->lat : 'not_found'
        ];

        $cotizar_ups = [
            'service_code'              => ($tipo_envio == 1) ? "65" : "07",
            'service_description'       => '',
            'packagingtype_code'        => '02',
            'peso'                      => $peso,
            'largo'                     => $largo,
            'ancho'                     => $ancho,
            'alto'                      => $alto,
            'shipper_postalcode'        => mb_strtoupper($data->codigo_postal, 'UTF-8'),
            'shipto_name'               => mb_strtoupper($data->contacto, 'UTF-8'),
            'shipto_addressline1'       => mb_strtoupper($data->calle . " " . $data->numero, 'UTF-8'),
            'shipto_addressline2'       => mb_strtoupper($data->colonia, 'UTF-8'),
            'shipto_addressline3'       => mb_strtoupper('', 'UTF-8'),
            'shipto_postalcode'         => mb_strtoupper($data->codigo_postal, 'UTF-8'),
            'shipfrom_name'             => mb_strtoupper('OMG International SA DE CV', 'UTF-8'),
            'shipfrom_addressline1'     => mb_strtoupper('INDUSTRIA VIDRIERA #105', 'UTF-8'),
            'shipfrom_addressline2'     => mb_strtoupper('FRACC. INDUSTRIAL ZAPOPAN NORTE', 'UTF-8'),
            'shipfrom_addressline3'     => mb_strtoupper('', 'UTF-8'),
            'shipfrom_postalcode'       => mb_strtoupper('45130', 'UTF-8'),
            'from_cord_exists'          => $data->remitente_cord_found,
            'from_lat'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lat : 'not_found',
            'from_lng'                  => ($data->remitente_cord_found) ? $data->remitente_cord->lng : 'not_found',
            'to_cord_exists'            => $data->destino_cord_found,
            'to_lng'                    => ($data->destino_cord_found) ? $data->destino_cord->lng : 'not_found',
            'to_lat'                    => ($data->destino_cord_found) ? $data->destino_cord->lat : 'not_found'
        ];

        return response()->json([
            'code'  => 200,
            'estafeta'  => $this->cotizar_paqueteria('Estafeta', $cotizar_estafeta),
            'fedex' => $this->cotizar_paqueteria('Fedex', $cotizar_fedex),
            'dhl'   => $this->cotizar_paqueteria('DHL', $cotizar_dhl),
            'ups'   => $this->cotizar_paqueteria('UPS', $cotizar_ups)
        ]);
    }

    public function venta_venta_crear_authy(Request $request)
    {
        $data = json_decode($request->input('data'));

        $response = DocumentoService::authy($data->usuario, $data->token);

        if ($response->error) {
            return response()->json([
                'code' => 500,
                'message' => $response->mensaje
            ]);
        }

        return response()->json([
            'code' => 200
        ]);
    }

    /* Venta > Venta > Autorizar */
    public function venta_venta_autorizar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        DB::table('documento')->where(['id' => $data->documento])->update([
            'autorizado'    => 1,
            'autorizado_by' => $auth->id
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento autorizado correctamente."
        ]);
    }

    public function venta_venta_autorizar_data(Request $request)
    {
        $documentos = $this->obtener_ventas("AND documento.id_fase = 3 AND documento.autorizado = 0");

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos
        ]);
    }

    /* Venta > Venta > Editar */
    public function venta_venta_editar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->cliente->rfc != 'XAXX010101000') {
            $id_entidad = $data->cliente->select;
        } else {
            $id_entidad = DB::select("SELECT id_entidad FROM documento WHERE id = " . $data->documento->documento . "")[0]->id_entidad;

            # Sí el cliente ya éxiste, se atualiza la información y se relaciona la venta con el cliente encontrado
            DB::table('documento_entidad')->where(['id' => $id_entidad])->update([
                'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8')),
                'regimen' => property_exists($data->cliente, "regimen") ? trim($data->cliente->regimen) : "",
                'regimen_id' => property_exists($data->cliente, "regimen") ? trim($data->cliente->regimen) : "",
                'codigo_postal_fiscal' => property_exists($data->cliente, "cp_fiscal") ? trim($data->cliente->cp_fiscal) : "",
            ]);
        }

        $documento_data = DB::table("documento")
            ->where("id", $data->documento->documento)
            ->first();

        if (!$documento_data) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró información del documento solicitado."
            ]);
        }

        if (!$documento_data->solicitar_refacturacion && $data->documento->refacturacion) {
            $usuarios_notificacion = array();
            $notificacion = new \stdClass();

            $usuario_data = DB::table("usuario")
                ->where("id", $auth->id)
                ->first();

            $notificacion->titulo = "Solicitud de refacturacón.";
            $notificacion->message = "El usuario " . $usuario_data->nombre . " solicitó refacturación para el pedido " . $data->documento->documento . "";
            $notificacion->tipo = "success"; // success, warning, danger
            $notificacion->link = "/general/busqueda/venta/id/" . $data->documento->documento;

            $notificacion_id = DB::table('notificacion')->insertGetId([
                'data' => json_encode($notificacion)
            ]);

            $notificacion->id = $notificacion_id;

            $usuarios_contabilidad = DB::table("usuario")
                ->select("usuario.id")
                ->join("usuario_subnivel_nivel", "usuario.id", "=", "usuario_subnivel_nivel.id_usuario")
                ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
                ->where("subnivel_nivel.id_nivel", 11)
                ->groupBy("usuario.id")
                ->get();

            foreach ($usuarios_contabilidad as $usuario) {
                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario->id,
                    'id_notificacion' => $notificacion_id
                ]);

                array_push($usuarios_notificacion, $usuario->id);
            }

            $notificacion->usuario = $usuarios_notificacion;

            event(new PusherEvent(json_encode($notificacion)));
        }

        DB::table('documento')->where(['id' => $data->documento->documento])->update([
            'id_almacen_principal_empresa' => $data->documento->almacen,
            'series_factura' => $data->documento->series_factura,
            'id_moneda' => $data->documento->moneda,
            'id_periodo' => $data->documento->periodo,
            'id_cfdi' => $data->documento->uso_venta,
            'tipo_cambio' => $data->documento->tipo_cambio,
            'fulfillment' => $data->documento->fulfillment,
            'observacion' => $data->documento->observacion,
            'referencia' => $data->documento->referencia,
            'id_paqueteria' => $data->documento->paqueteria,
            'solicitar_refacturacion' => $data->documento->refacturacion,
            'picking' => 0,
            'picking_by' => 0,
            'shipping_null' => $data->documento->shipping_null ?? 0,
            'zoom_guia' => $data->documento->zoom_guia ?? 0,
        ]);

        $tiene_direccion = DB::select("SELECT id FROM documento_direccion WHERE id_documento = " . $data->documento->documento . "");

        if (empty($tiene_direccion)) {
            DB::table('documento_direccion')->insert([
                'id_documento'      => $data->documento->documento,
                'id_direccion_pro'  => $data->documento->direccion_envio->colonia,
                'calle'             => $data->documento->direccion_envio->calle,
                'numero'            => $data->documento->direccion_envio->numero,
                'numero_int'        => $data->documento->direccion_envio->numero_int,
                'colonia'           => $data->documento->direccion_envio->colonia_text,
                'ciudad'            => $data->documento->direccion_envio->ciudad,
                'estado'            => $data->documento->direccion_envio->estado,
                'codigo_postal'     => $data->documento->direccion_envio->codigo_postal,
                'referencia'        => property_exists($data->documento->direccion_envio, 'referencia') ? $data->documento->direccion_envio->referencia : "N/A",
                'contenido'         => property_exists($data->documento->direccion_envio, 'contenido') ? $data->documento->direccion_envio->contenido : "PAQUETE",
                'tipo_envio'        => property_exists($data->documento->direccion_envio, 'tipo_envio') ? $data->documento->direccion_envio->tipo_envio : "N/A",
            ]);
        } else {
            DB::table('documento_direccion')->where(['id_documento' => $data->documento->documento])->update([
                'id_direccion_pro'  => $data->documento->direccion_envio->colonia,
                'calle'             => $data->documento->direccion_envio->calle,
                'numero'            => $data->documento->direccion_envio->numero,
                'numero_int'        => $data->documento->direccion_envio->numero_int,
                'colonia'           => $data->documento->direccion_envio->colonia_text,
                'ciudad'            => $data->documento->direccion_envio->ciudad,
                'estado'            => $data->documento->direccion_envio->estado,
                'codigo_postal'     => $data->documento->direccion_envio->codigo_postal,
                'referencia'        => property_exists($data->documento->direccion_envio, 'referencia') ? $data->documento->direccion_envio->referencia : "N/A",
                'contenido'         => property_exists($data->documento->direccion_envio, 'contenido') ? $data->documento->direccion_envio->contenido : "PAQUETE",
                'tipo_envio'        => property_exists($data->documento->direccion_envio, 'tipo_envio') ? $data->documento->direccion_envio->tipo_envio : "N/A",
            ]);
        }

        foreach ($data->documento->productos as $producto) {
            $existencia = InventarioService::obtenerExistencia($producto->codigo, $data->documento->almacen);

            if($existencia->error) {
                return response()->json([
                    'code' => 500,
                    'message' => $existencia->mensaje
                ]);
            }

            if($existencia->stock_disponible < $producto->cantidad) {
                return response()->json([
                    'code' => 500,
                    'message' => 'No hay suficiente existencia para procesar la venta'
                ]);
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento'  => $data->documento->documento,
                'id_modelo'     => $producto->id,
                'cantidad'      => $producto->cantidad,
                'precio'        => $producto->precio,
                'garantia'      => $producto->garantia,
                'modificacion'  => $producto->modificacion,
                'regalo'        => $producto->regalo
            ]);
        }

        foreach ($data->documento->archivos as $archivo) {
            if ($archivo->nombre != "" && $archivo->data != "") {
                $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body($archivo_data)
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento'  => $data->documento->documento,
                    'id_usuario'    => $auth->id,
                    'tipo'          => $archivo->guia,
                    'id_impresora'  => $archivo->impresora,
                    'nombre'        => $archivo->nombre,
                    'dropbox'       => $response->body->id
                ]);
            }
        }

        DB::table('seguimiento')->insert([
            'id_documento' => $data->documento->documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $data->documento->seguimiento . "<p>Fecha de edición: " . date('d-m-Y H:i:s') . "</p>"
        ]);

        $info_documento = DB::table('documento')->where('id', $data->documento->documento)->first();

        if($info_documento->pagado == 1 && $info_documento->id_fase == 3) {
            DB::table('seguimiento')->insert([
                'id_documento' => $data->documento->documento,
                'id_usuario' => $auth->id,
                'seguimiento' => "<h1>PICKING REIMPRESO</h1>"
            ]);
        }

        DB::table('documento_updates_by')->insert([
            'id_documento'  => $data->documento->documento,
            'id_usuario'    => $auth->id
        ]);

        $tiene_pago = DB::select("SELECT id_pago FROM documento_pago_re WHERE id_documento = " . $data->documento->documento . "");

        if (!empty($tiene_pago)) {
            DB::table('documento_pago')->where(['id' => $tiene_pago[0]->id_pago])->update([
                'origen_entidad'    => $data->cliente->rfc
            ]);
        }

        if ($data->documento->id_fase != 2) {
            $esta_importado = DB::select("SELECT importado FROM documento WHERE id = " . $data->documento->documento . "")[0]->importado;

            if ($data->documento->fulfillment && !$esta_importado) {
                //Aqui ta
//                $response = DocumentoService::crearFactura($data->documento->documento, 0, 0);
                $response = InventarioService::aplicarMovimiento($data->documento->documento);

                if ($response->error) {
                    DB::table('documento')->where(['id' => $data->documento->documento])->update([
                        'id_fase' => 5
                    ]);

                    CorreoService::cambioFaseConta($data->documento->documento, $response->mensaje);

                    return response()->json([
                        'code' => 200,
                        'message' => "Documento editado correctamente pero no fue posible importarlo al ERP, mensaje de error: " . $response->mensaje
                    ]);
                }

                DB::table('documento')->where(['id' => $data->documento->documento])->update([
                    'id_fase' => 6
                ]);
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Venta editada correctamente."
        ]);
    }

    public function venta_venta_editar_documento($documento)
    {
        $informacion = DB::select("SELECT
                                    documento.*,
                                    documento.referencia AS referencia_documento,
                                    documento_entidad.id AS id_entidad,
                                    documento_entidad.*,
                                    documento_direccion.id AS id_direccion,
                                    documento_direccion.*,
                                    empresa_almacen.id_almacen,
                                    empresa.bd,
                                    area.id AS area
                                FROM documento
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN documento_entidad ON documento_entidad.id = documento.id_entidad
                                LEFT JOIN documento_direccion ON documento.id = documento_direccion.id_documento
                                WHERE documento.id = " . $documento . " 
                                AND documento.status = 1");

        if (empty($informacion)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información de la venta."
            ]);
        }

        $informacion = $informacion[0];

        if ($informacion->anticipada) {
            return response()->json([
                'code'  => 404,
                'message'   => "No es posible editar la venta por que ha sido marcada como factura anticipada, favor de cancelar la factura y generar una nueva."
            ]);
        }

        $informacion->empresa = DB::select("SELECT
                                                    empresa.bd
                                                FROM marketplace_area_empresa
                                                INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                                WHERE marketplace_area_empresa.id_marketplace_area = " . $informacion->id_marketplace_area . "");

        $productos = DB::select("SELECT
                                    movimiento.id,
                                    movimiento.cantidad,
                                    movimiento.precio,
                                    movimiento.regalo,
                                    movimiento.garantia,
                                    movimiento.modificacion,
                                    modelo.sku AS codigo,
                                    modelo.descripcion,
                                    modelo.costo
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        $seguimiento        = DB::select("SELECT 
                                            seguimiento.*, 
                                            usuario.nombre 
                                        FROM seguimiento 
                                        INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                        WHERE id_documento = " . $documento . "");

        $archivos           = DB::select("SELECT * FROM documento_archivo WHERE id_documento = " . $documento . " AND status = 1");

        $informacion->seguimiento   = $seguimiento;
        $informacion->productos     = $productos;
        $informacion->archivos      = $archivos;

        return response()->json([
            'code'  => 200,
            'informacion'   => $informacion
        ]);
    }

    public function venta_venta_editar_producto_borrar($movimiento)
    {
        DB::table('movimiento')->where(['id' => $movimiento])->delete();

        return response()->json([
            'code'  => 200
        ]);
    }

    /* Venta > Venta > Cancelar */
    public function venta_venta_cancelar_data()
    {
        $usuarios = DB::select("SELECT
                                    usuario.authy,
                                    usuario.nombre,
                                    nivel.nivel
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                INNER JOIN subnivel ON subnivel_nivel.id_subnivel = subnivel.id
                                WHERE (nivel.nivel = 'ALMACEN' AND subnivel.subnivel = 'ADMINISTRADOR')
                                OR nivel.nivel = 'ADMINISTRADOR'
                                AND usuario.id != 1
                                GROUP BY usuario.id");
        return response()->json([
            'code'  => 200,
            'usuarios'  => $usuarios
        ]);
    }

    public function venta_venta_cancelar(Request $request)
    {
        $auth = json_decode($request->auth);
        $documento = $request->input('documento');
        $token = $request->input('token');
        $authy = $request->input('authy');
        $motivo = $request->input('motivo');
        $garantia = ($request->input('garantia') == "true") ? 1 : ($request->input('garantia') == "1") ? 1 : 0;
        $message = "";

        $existe_garantia_devolucion = DB::select("SELECT id FROM documento_garantia_re WHERE id_documento = " . $documento . "");

        if (!empty($existe_garantia_devolucion)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se puede cancelar el documento ya que tiene una garantía o devolución activa, favor de verificar."
            ]);
        }

        $user_nombre = DB::select("SELECT nombre FROM usuario WHERE id = " . $auth->id . " AND status = 1")[0]->nombre;

        if (empty($token)) {
            $documento_fase = DB::select("SELECT id_fase, status, anticipada FROM documento WHERE id = " . $documento . " AND id_tipo = 2");

            if (empty($documento_fase)) {
                return response()->json([
                    'code'  => 400,
                    'message'   => "Documento no encontrado, favor de verificar e intentar de nuevo."
                ]);
            }

            $existe_garantia_devolucion = DB::select("SELECT id FROM documento_garantia_re WHERE id_documento = " . $documento . "");

            if (!empty($existe_garantia_devolucion)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "No se puede cancelar el documento ya que tiene una garantía o devolución activa, favor de verificar."
                ]);
            }

            $documento_fase = $documento_fase[0];

            if ($documento_fase->status == 0) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "El documento ya ha sido cancelado."
                ]);
            }
        } else {
            $authy_user_id = DB::select("SELECT id FROM usuario WHERE authy = '" . $authy . "' AND status = 1");

            if (empty($authy_user_id)) {
                return response()->json([
                    'code'  => 403,
                    'message'   => "No se encontró el usuario que ha autorizado la cancelación."
                ]);
            }

            try {
                $authy_user_id = $authy_user_id[0]->id;

                $authy_request = new \Authy\AuthyApi('qPXDpKmDp7A71cxk7JBPspwbB9oFJb4t');

                $verification = $authy_request->verifyToken($authy, $token);

                if (!$verification->ok()) {
                    return response()->json([
                        'code'  => 403,
                        'message'   => "El token ingresado no es valido."
                    ]);
                }

                DB::table('documento')->where(['id' => $documento])->update([
                    'canceled_authorized_by' => $authy_user_id
                ]);
            } catch (\Authy\AuthyFormatException $e) {
                return response()->json([
                    'code'  => 403,
                    'message'   => "El token ingresado no es valido, error: " . $e->getMessage()
                ]);
            }
        }

        $info_documento = DB::select("SELECT
                                        documento.id_almacen_principal_empresa,
                                        documento.tipo_cambio,
                                        documento.referencia,
                                        documento.series_factura,
                                        documento.factura_folio,
                                        documento.fulfillment,
                                        documento.id_fase,
                                        documento_periodo.id AS id_periodo,
                                        documento_uso_cfdi.codigo AS uso_cfdi,
                                        documento_uso_cfdi.id AS id_cfdi,
                                        moneda.id AS id_moneda,
                                        marketplace_area.id AS id_marketplacea_area,
                                        marketplace_area.serie AS serie_factura,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN moneda ON documento.id_moneda = moneda.id
                                    INNER JOIN documento_periodo ON documento.id_periodo
                                    INNER JOIN documento_uso_cfdi ON documento.id_cfdi
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE documento.id = " . $documento . "");

        $productos = DB::select("SELECT
                                    movimiento.id AS id_movimiento,
                                    movimiento.cantidad,
                                    movimiento.precio AS precio_unitario,
                                    movimiento.id_modelo,
                                    movimiento.comentario AS comentarios,
                                    modelo.serie,
                                    modelo.sku,
                                    modelo.costo,
                                    0 as descuento,
                                    16 as impuesto
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE id_documento = " . $documento . "");

        $info_documento = $info_documento[0];
        /* Si no es venta de FBA, se hace un traspaso a garantias */
        $venta_fba = (((strtolower($info_documento->marketplace) == "amazon") && $info_documento->fulfillment)) ? true : false;

        if (!$venta_fba && $garantia && $info_documento->id_fase > 3) {
            $series_cambiadas = array();

            $documento_traspaso = DB::table('documento')->insertGetId([
                'id_almacen_principal_empresa'  => $info_documento->almacen_devolucion_garantia_sistema,
                'id_almacen_secundario_empresa' => $info_documento->id_almacen_principal_empresa,
                'id_tipo'                       => 5,
                'id_periodo'                    => 1,
                'id_cfdi'                       => 1,
                'id_marketplace_area'           => $info_documento->id_marketplacea_area,
                'id_usuario'                    => $auth->id,
                'id_moneda'                     => 3,
                'id_paqueteria'                 => 6,
                'id_fase'                       => 100,
                'autorizado_by'                 => isset($authy_user_id) ? $authy_user_id : 1,
                'autorizado'                    => 1,
                'factura_folio'                 => 'N/A',
                'tipo_cambio'                   => 1,
                'referencia'                    => 'N/A',
                'info_extra'                    => 'N/A',
                'observacion'                   => 'Traspaso entre almacenes por cancelacion de venta ' . $documento, // Status de la compra
            ]);

            foreach ($productos as $index => $producto) {
                $movimiento = DB::table('movimiento')->insertGetId([
                    'id_documento'          => $documento_traspaso,
                    'id_modelo'             => $producto->id_modelo,
                    'cantidad'              => $producto->cantidad,
                    'precio'                => $producto->precio_unitario,
                    'garantia'              => 0,
                    'modificacion'          => 'N/A',
                    'regalo'                => 0
                ]);

                if ($producto->serie) {
                    $series = DB::select("SELECT
                                        producto.id,
                                        producto.status,
                                        producto.id_almacen
                                    FROM movimiento
                                    INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento.id = " . $producto->id_movimiento . "");

                    foreach ($series as $serie) {
                        DB::table('producto')->where(['id' => $serie->id])->update([
                            'id_almacen'    => $info_documento->almacen_devolucion_garantia_serie,
                            'status'        => 1
                        ]);

                        DB::table('movimiento_producto')->insert([
                            'id_movimiento' => $movimiento,
                            'id_producto'   => $serie->id
                        ]);

                        array_push($series_cambiadas, $serie);
                    }
                }
            }
        } else {
            $productos = DB::select("SELECT
                                        movimiento.id,
                                        modelo.serie
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $documento . "");

            foreach ($productos as $producto) {
                if ($producto->serie) {
                    $series = DB::select("SELECT
                                            producto.id
                                        FROM movimiento
                                        INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                        WHERE movimiento.id = " . $producto->id . "");

                    foreach ($series as $serie) {
                        DB::table('producto')->where(['id' => $serie->id])->update(['status' => 1]);
                    }
                }
            }
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'status' => 0,
            'canceled_by' => $auth->id,
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => 'Venta cancelada por: ' . $user_nombre . '<br><br>' . $message . "<br><br>" . $motivo
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento cancelado correctamente."
        ]);
    }

    /* Venta > Venta > Problema */
    public function venta_venta_problema(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento')->where(['id' => $data->documento])->update(['problema' => $data->problema]);

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

    public function venta_venta_problema_data()
    {
        $documentos = $this->obtener_ventas("AND documento.id_fase = 3 AND documento.problema = 1");

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos
        ]);
    }

    /* Venta > Venta > Nota */
    /**
     * @throws \Throwable
     */
    public function venta_venta_nota(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!empty($data->correo)) {
            if (!filter_var($data->correo, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'code' => 500,
                    'message' => "Para enviar la nota de venta a un correo, es necesario proporcionar un correo valido."
                ]);
            }
        }

        $total = 0;

        $existe_documento = DB::select("SELECT
                                            documento.id,
                                            documento.id_periodo,
                                            documento.id_marketplace_area,
                                            documento.created_at,
                                            empresa.bd,
                                            empresa.empresa,
                                            empresa.logo,
                                            documento_uso_cfdi.codigo
                                        FROM documento
                                        INNER JOIN documento_uso_cfdi ON documento.id_cfdi = documento_uso_cfdi.id
                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                        WHERE documento.id = " . $data->documento . " AND documento.id_tipo = 2");

        if (empty($existe_documento)) {
            return response()->json([
                'code'  => 400,
                'message'   => "El documento proporcionado no fue encontrado."
            ]);
        }

        $existe_documento = $existe_documento[0];

        $empresa_externa = DB::select("SELECT
                                        empresa.empresa,
                                        empresa.logo
                                    FROM marketplace_area_empresa
                                    INNER JOIN empresa ON marketplace_area_empresa.id_empresa = empresa.id
                                    WHERE marketplace_area_empresa.id_marketplace_area = " . $existe_documento->id_marketplace_area . "");

        $cliente = DB::select("SELECT
                                    documento_entidad.razon_social AS cliente,
                                    documento_entidad.rfc,
                                    documento_entidad.telefono,
                                    documento_entidad.telefono_alt,
                                    documento_entidad.correo,
                                    usuario.nombre,
                                    usuario.email
                                FROM documento 
                                INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                WHERE documento.id = " . $data->documento . "")[0];

        $productos = DB::select("SELECT 
                                modelo.sku, 
                                modelo.descripcion, 
                                movimiento.cantidad,
                                movimiento.garantia,
                                ROUND((movimiento.precio * 1.16), 2) AS precio
                            FROM movimiento 
                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                            WHERE id_documento = " . $data->documento . "");

        $pago = DB::select("SELECT
                                metodo_pago.id,
                                metodo_pago.metodo_pago
                            FROM documento_pago_re
                            INNER JOIN documento_pago ON documento_pago_re.id_pago = documento_pago.id
                            INNER JOIN metodo_pago ON documento_pago.id_metodopago = metodo_pago.id
                            WHERE documento_pago_re.id_documento = " . $data->documento . "");

        if (empty($pago)) {
            $forma_pago = "99 - Por definir";
        } else {
            $codigo = strlen($pago[0]->id) == 1 ? "0" . $pago[0]->id : $pago[0]->id;

            $forma_pago = $codigo . " - " . $pago[0]->metodo_pago;
        }

        foreach ($productos as $producto) {
            $total += (float) $producto->precio * (float) $producto->cantidad;

            $informacion_producto = @json_decode(file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $existe_documento->bd . '/' . rawurlencode(trim($producto->sku))));

            $producto->codigo = empty($informacion_producto) ? "" : $informacion_producto[0]->claveprodserv;
            $producto->unidad = empty($informacion_producto) ? "" : $informacion_producto[0]->unidad;
            $producto->claveunidad = empty($informacion_producto) ? "" : $informacion_producto[0]->claveunidad;
        }

        $pdf = app('FPDF');

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(69, 90, 100);

        # Informacion de la empresa
        $empresa_nombre = empty($empresa_externa) ? $existe_documento->empresa : $empresa_externa[0]->empresa;
        $empresa_logo = empty($empresa_externa) ? $existe_documento->logo : $empresa_externa[0]->logo;

        # Logo de la empresa
        if ($empresa_logo != 'N/A') {
            $pdf->Image($empresa_logo, 5, 10, 60, 20, 'png');
        }

        $pdf->Ln(30);
        $pdf->Cell(20, 10, $empresa_nombre);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'Industria Vidriera #105, Fracc. Industrial Zapopan Norte');
        $pdf->Ln(5);
        $pdf->Cell(20, 10, $cliente->nombre);
        $pdf->Ln(5);
        $pdf->Cell(20, 10, $cliente->email);

        # Información del cliente
        $pdf->Ln(20);
        $pdf->Cell(100, 10, 'INFORMACION DEL CLIENTE');
        $pdf->Cell(10, 10, 'INFORMACION DE LA VENTA');

        $pdf->SetFont('Arial', 'B', 10);

        setlocale(LC_TIME, "es_MX");

        # Blank space
        $pdf->Ln(5);
        $pdf->Cell(20, 10, 'Nombre: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(80, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($cliente->cliente, 'UTF-8')));

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Documento: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $data->documento);

        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(20, 10, "Telefono: ");

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(80, 10, empty($cliente->telefono) ? 'SIN REGISTRO' : $cliente->telefono);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Fecha: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, strftime("%A %d de %B del %Y", strtotime($existe_documento->created_at)));

        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(20, 10, "Correo: ");

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(80, 10, substr($cliente->correo, 0, 35));

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Uso del CFDI: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $existe_documento->codigo);

        $pdf->Ln(5);

        if (strlen($cliente->correo) > 35) {
            $pdf->Cell(20, 10, "");
            $pdf->Cell(80, 10, substr($cliente->correo, 35, 35));
        } else {
            $pdf->Cell(100, 10, "");
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Forma de pago: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $forma_pago);

        # Blank space
        $pdf->Ln(5);
        $pdf->Cell(100, 10, "");

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 10, 'Metodo de pago: ');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, $existe_documento->id_periodo == 1 ? 'PUE' : 'PPD');

        $pdf->Ln(20);

        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(20, 5, "CANTIDAD", "T");
        $pdf->Cell(25, 5, "UNIDAD / SAT", "T");
        $pdf->Cell(25, 5, "CODIGO / SAT", "T");
        $pdf->Cell(80, 5, "DESCRIPCION", "T");
        $pdf->Cell(20, 5, "PRECIO", "T");
        $pdf->Cell(20, 5, "TOTAL", "T");
        $pdf->Ln();

        foreach ($productos as $producto) {
            $pdf->Cell(20, 5, $producto->cantidad, "T");
            $pdf->Cell(25, 5, $producto->unidad, "T");
            $pdf->Cell(25, 5, $producto->sku, "T");
            $pdf->Cell(80, 5, substr($producto->descripcion, 0, 40), "T");
            $pdf->Cell(20, 5, "$ " . $producto->precio, "T");
            $pdf->Cell(20, 5, "$ " . round((float) $producto->precio * (float) $producto->cantidad, 2), "T");
            $pdf->Ln();

            $pdf->Cell(20, 5, "");
            $pdf->Cell(25, 5, $producto->claveunidad);
            $pdf->Cell(25, 5, $producto->codigo);

            if (strlen($producto->descripcion) > 40) {
                $pdf->Cell(80, 5, substr($producto->descripcion, 40, 40));
                $pdf->Cell(20, 5, "");
                $pdf->Cell(20, 5, "");
            }

            $pdf->Ln();

            if (strlen($producto->descripcion) > 80) {
                $pdf->Cell(25, 5, "");
                $pdf->Cell(25, 5, "");
                $pdf->Cell(80, 5, substr($producto->descripcion, 80, 40));
                $pdf->Cell(20, 5, "");
                $pdf->Cell(20, 5, "");
                $pdf->Ln();
            }
        }

        $pdf->Ln(10);
        $pdf->Cell(120, 10, '');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 10, 'Subtotal: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, "$ " . round($total / 1.16), 2);

        $pdf->Ln(5);
        $pdf->Cell(120, 10, '');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 10, 'Iva (16%): ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, "$ " . round($total - ($total / 1.16)), 2);

        $pdf->Ln(5);
        $pdf->Cell(120, 10, '');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 10, 'Total: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(10, 10, "$ " . round($total), 2);

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "NOTA_" . $data->documento . "_" . $cliente->cliente . "_" . uniqid() . ".pdf";

        if (!empty($data->correo)) {
            $vendedor = DB::select("SELECT nombre FROM usuario WHERE id = " . $auth->id . "")[0]->nombre;
            $html = view('email.notificacion_reporte_diario')->with([
                'anio' => date('Y'),
                'vendedor' => $vendedor,
                'documento' => $data->documento
            ]);

            $file = fopen($pdf_name, 'w');
            fwrite($file, $pdf_data);
            fclose($file);

            $mg = Mailgun::create('key-ff8657eb0bb864245bfff77c95c21bef');
            $domain = "omg.com.mx";
            $mg->messages()->send(
                $domain,
                array(
                    'from'  => 'Reportes OMG International <crm@omg.com.mx>',
                    'to'      => $data->correo,
                    'subject' => 'Nota de venta para el pedido ' . $data->documento,
                    'html'    => $html->render()
                ),
                array(
                    'attachment' => array(
                        $pdf_name
                    )
                )
            );

            unlink($pdf_name);

            return response()->json([
                'code'  => 200,
                'message' => "La nota de venta fue enviada correctamente al correo electronico proporcionado."
            ]);
        }

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    /* Venta > Venta > Importacion */
    public function venta_venta_importacion_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        //!! RELEASE T1 quitar tests
        $marketplaces = DB::select("SELECT
                                        marketplace_area.id,
                                        area.area,
                                        marketplace.marketplace
                                    FROM marketplace_area
                                    INNER JOIN usuario_marketplace_area ON marketplace_area.id = usuario_marketplace_area.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN area ON marketplace_area.id_area = area.id
                                    WHERE usuario_marketplace_area.id_usuario = " . $auth->id . "
                                    AND marketplace.marketplace IN('AMAZON', 'CLAROSHOP','SEARS','SANBORNS')");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        almacen.id AS id_almacen,
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ASC");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code'  => 200,
            'marketplaces'  => $marketplaces,
            'empresas'  => $empresas
        ]);
    }

    public function venta_venta_importacion(Request $request)
    {
        $marketplace = json_decode($request->input('marketplace'));
        $auth = json_decode($request->auth);

        switch ($marketplace->marketplace) {
            case 'mercadolibre':
                $response = MercadolibreService::importarVentas($marketplace->id, null);
                break;

            case 'amazon':
                $response = AmazonService::importarVentas($marketplace->id, $auth->id, $marketplace->ventas);
                break;

            case 'linio':
                $response = LinioService::importarVentas($marketplace->id, $auth->id, $marketplace->ventas, $marketplace->almacen);
                break;

                // case 'claroshop':
                // case 'sears':
                //     $response = ClaroshopService::importarVentasMasiva($marketplace->id, $auth->id, $marketplace->almacen, $marketplace->empresa);
                //     break;
                //!! RELEASE T1 reempalzar

            case 'claroshop':
            case 'sears':
            case 'sanborns':
                $response = ClaroshopServiceV2::importarVentasMasiva($marketplace->id, $auth->id, $marketplace->almacen, $marketplace->empresa);
                break;

            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado para la importación de ventas masivamente.<br/> Error: BVC2488";

                break;
        }

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => $response->mensaje,
            'excel' => $marketplace->marketplace == 'amazon' ? $response->excel : '',
            'archivo' => $marketplace->marketplace == 'amazon' ? $response->archivo : '',

        ]);
    }

    /* Venta > Venta > Mensaje */
    public function venta_venta_mensaje_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $marketplaces = DB::select("SELECT
                                        marketplace_area.id,
                                        area.area,
                                        marketplace.marketplace
                                    FROM marketplace_area
                                    INNER JOIN usuario_marketplace_area ON marketplace_area.id = usuario_marketplace_area.id_marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN area ON marketplace_area.id_area = area.id
                                    WHERE usuario_marketplace_area.id_usuario = " . $auth->id . "
                                    AND marketplace.marketplace IN('MERCADOLIBRE')");

        return response()->json([
            'code'  => 200,
            'marketplaces'  => $marketplaces
        ]);
    }

    public function venta_venta_mensaje(Request $request)
    {
        $marketplace = json_decode($request->input('marketplace'));
        $responses = array();

        // foreach ($marketplace->ventas as $venta) {
        $response = MercadolibreService::enviarMensaje($marketplace->id, $marketplace->data);
        // $response = MercadolibreService::enviarMensaje($marketplace->id, $venta->venta, $venta->mensaje);


        array_push($responses, $response);
        // }

        return response()->json([
            'code' => 200,
            'message' => "Mensajes enviados correctamente.",
            'data'  => $responses
        ]);
    }

    /* Venta > Venta > Pedido de venta */
    public function venta_venta_pedido_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $modificacion   = 0;
        $pedidos_creados = "";

        $existe_venta = DB::select("SELECT 
                                        documento.id,
                                        marketplace.id AS id_marketplace
                                    FROM documento 
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE documento.no_venta = '" . trim($data->documento->venta) . "' 
                                    AND documento.no_venta != '.' 
                                    AND documento.id_marketplace_area = " . $data->documento->marketplace . " AND documento.status = 1");

        if (!empty($existe_venta)) {
            if ($existe_venta[0]->id_marketplace != 19) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "El número de venta ya se encuentra registrada<br>Documento CRM: " . $existe_venta[0]->id . ""
                ]);
            }
        }

        if (strpos(TRIM($data->cliente->rfc), 'XAXX0101010') === false) {
            $cliente = $data->cliente->select;
        } else {
            $cliente = DB::table('documento_entidad')->insertGetId([
                'id_erp'        => trim(mb_strtoupper($data->cliente->select, 'UTF-8')),
                'razon_social'  => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                'rfc'           => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                'telefono'      => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                'telefono_alt'  => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                'correo'        => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
            ]);
        }

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa'  => $data->documento->almacen,
            'id_periodo'                    => $data->documento->periodo,
            'id_cfdi'                       => $data->documento->uso_venta,
            'id_marketplace_area'           => $data->documento->marketplace,
            'id_usuario'                    => $auth->id,
            'id_moneda'                     => $data->documento->moneda,
            'id_paqueteria'                 => $data->documento->paqueteria,
            'id_fase'                       => 1,
            'no_venta'                      => TRIM($data->documento->venta),
            'tipo_cambio'                   => $data->documento->tipo_cambio,
            'referencia'                    => TRIM($data->documento->referencia),
            'observacion'                   => TRIM($data->documento->observacion),
            'info_extra'                    => $data->documento->info_extra,
            'fulfillment'                   => $data->documento->fulfillment,
            'series_factura'                => $data->documento->series_factura,
            'autorizado'                    => ($data->documento->baja_utilidad) ? 0 : 1,
            'anticipada'                    => 0,
            'id_entidad'                    => $cliente,
            'addenda_orden_compra'          => $data->addenda->orden_compra,
            'addenda_solicitud_pago'        => $data->addenda->solicitud_pago,
            'addenda_tipo_documento'        => $data->addenda->tipo_documento,
            'addenda_factura_asociada'      => $data->addenda->factura_asociada,
            'mkt_fee'                       => $data->documento->mkt_fee,
            'mkt_shipping_total'            => $data->documento->costo_envio,
            'mkt_shipping_total_cost'       => $data->documento->costo_envio_total,
            'mkt_shipping_id'               => $data->documento->mkt_shipping,
            'mkt_user_total'                => $data->documento->total_user,
            'mkt_total'                     => $data->documento->total,
            'mkt_publicacion'               => $data->documento->mkt_publicacion,
            'mkt_created_at'                => $data->documento->mkt_created_at,
            'started_at'                    => $data->documento->fecha_inicio
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->documento->seguimiento
        ]);

        foreach ($data->documento->productos as $producto) {
            DB::table('movimiento')->insertGetId([
                'id_documento'  => $documento,
                'id_modelo'     => $producto->id,
                'cantidad'      => $producto->cantidad,
                'precio'        => $producto->precio,
                'garantia'      => $producto->garantia,
                'modificacion'  => $producto->modificacion,
                'comentario'    => $producto->comentario,
                'addenda'       => $producto->addenda,
                'regalo'        => $producto->regalo
            ]);
        }

        DB::table('documento_direccion')->insert([
            'id_documento'      => $documento,
            'id_direccion_pro'  => $data->documento->direccion_envio->colonia,
            'contacto'          => $data->documento->direccion_envio->contacto,
            'calle'             => $data->documento->direccion_envio->calle,
            'numero'            => $data->documento->direccion_envio->numero,
            'numero_int'        => $data->documento->direccion_envio->numero_int,
            'colonia'           => $data->documento->direccion_envio->colonia_text,
            'ciudad'            => $data->documento->direccion_envio->ciudad,
            'estado'            => $data->documento->direccion_envio->estado,
            'codigo_postal'     => $data->documento->direccion_envio->codigo_postal,
            'referencia'        => $data->documento->direccion_envio->referencia
        ]);

        try {
            foreach ($data->documento->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                        ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento'  =>  $documento,
                        'id_usuario'    =>  $auth->id,
                        'nombre'        =>  $archivo->nombre,
                        'dropbox'       =>  $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            DB::table('documento')->where(['id' => $documento])->delete();

            return response()->json([
                'code'  => 500,
                'message'   => "No fue posible subir los archivos a dropbox, pedido cancelado, favor de contactar a un administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }

        $message = "Venta creada correctamente. " . $documento;

        return response()->json([
            'code'  => 200,
            'message'   => $message
        ]);
    }

    public function venta_venta_pedido_pendiente_data(Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);
        $marketplaces = array();

        $marketplaces_usuario = DB::select("SELECT id_marketplace_area FROM usuario_marketplace_area WHERE id_usuario = " . $auth->id . "");

        foreach ($marketplaces_usuario as $marketplace) {
            array_push($marketplaces, $marketplace->id_marketplace_area);
        }

        $documentos = $this->obtener_ventas("AND documento.id_fase = 1 AND marketplace_area.id IN (" . implode(",", $marketplaces) . ")");

        return response()->json([
            'code'  => 200,
            'ventas'    => $documentos
        ]);
    }

    public function venta_venta_pedido_pendiente_convertir($documento, Request $request)
    {
        $auth = json_decode($request->auth);
        $message = "";

        $informacion_documento = DB::select("SELECT
                                                documento.id_almacen_principal_empresa,
                                                documento.id_marketplace_area,
                                                documento.id_paqueteria,
                                                documento.id_fase,
                                                documento.no_venta,
                                                documento.referencia,
                                                documento.autorizado,
                                                documento.fulfillment,
                                                documento.modificacion,
                                                marketplace.marketplace,
                                                paqueteria.paqueteria
                                            FROM documento
                                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                            WHERE documento.id = " . $documento . "");

        if (empty($informacion_documento)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del documento, favor de verificar e intentar de nuevo."
            ]);
        }

        $informacion_documento = $informacion_documento[0];

        $productos = DB::select("SELECT
                                    modelo.sku,
                                    modelo.id_tipo,
                                    movimiento.cantidad
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        if (empty($productos)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información de los productos del documento, favor de contactar a un administrador."
            ]);
        }

        foreach ($productos as $producto) {
            if ($producto->id_tipo == 1) {
                $total = InventarioService::existenciaProducto($producto->sku, $informacion_documento->id_almacen_principal_empresa);

                if ($total->error) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => $total->mensaje
                    ]);
                }

                if ($total->tipo == 1) { /* Sí es producto se toman en cuenta la existencia, si es servicio, no */
                    if ($total->existencia < $producto->cantidad) {
                        return response()->json([
                            'code'  => 500,
                            'message'   => "Producto " . $producto->sku . " sin suficiente existencias.<br><br>Requerida: " . $producto->cantidad . "<br>Disponible: " . $total->existencia
                        ]);
                    }
                }
            }
        }

        if ($informacion_documento->id_fase > 2) {
            return response()->json([
                'code'  => 500,
                'message'   => "El pedido seleccionado ya fue convertido."
            ]);
        }

        if (!$informacion_documento->autorizado) {
            $message .= "El documento no alcanza la ultilidad del 5% respecto al costo de los productos seleccionados por lo cual, quedará pendiente de autorización.<br><br>";

            try {
                $administradores = DB::select("SELECT 
                                                usuario.id
                                            FROM usuario 
                                            INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario 
                                            WHERE id_subnivel_nivel = 3 AND status = 1");

                if (!empty($administradores)) {
                    $usuarios = array();

                    $notificacion['titulo']     = "Autorización requerida";
                    $notificacion['message']    = "El pedido de venta " . $documento . " requiere una autorización por no alcanzar el 5% de utilidad en el total de la venta.";
                    $notificacion['tipo']       = "warning"; // success, warning, danger
                    $notificacion['link']       = "/venta/venta/autorizar/" . $documento;

                    $notificacion_id = DB::table('notificacion')->insertGetId([
                        'data'  => json_encode($notificacion)
                    ]);

                    $notificacion['id']         = $notificacion_id;

                    foreach ($administradores as $usuario) {
                        DB::table('notificacion_usuario')->insert([
                            'id_usuario'        => $usuario->id,
                            'id_notificacion'   => $notificacion_id
                        ]);

                        array_push($usuarios, $usuario->id);
                    }

                    if (!empty($usuarios)) {
                        $notificacion['usuario']    = $usuarios;

                        event(new PusherEvent(json_encode($notificacion)));
                    }
                }
            } catch (Exception $e) {
                $message .= "No fue posible notificar a los administradores para la autorización del pedido por baja utilidad.<br><br>";
            }
        }

        if ($informacion_documento->modificacion) {
            try {
                $tecnicos = DB::select("SELECT 
                                        usuario.id
                                    FROM usuario 
                                    INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario 
                                    WHERE id_subnivel_nivel = 12 AND status = 1");

                if (!empty($tecnicos)) {
                    $usuarios = array();

                    $notificacion['titulo']     = "Modificación para el pedido " . $documento . "";
                    $notificacion['message']    = "El pedido de venta " . $documento . " requiere una modificación el alguno de sus productos, favor de revisar en la sección de pendientes de revisión.";
                    $notificacion['tipo']       = "success"; // success, warning, danger
                    $notificacion['link']       = "/soporte/revision/" . $documento;

                    $notificacion_id = DB::table('notificacion')->insertGetId([
                        'data'  => json_encode($notificacion)
                    ]);

                    $notificacion['id']         = $notificacion_id;

                    foreach ($tecnicos as $usuario) {
                        DB::table('notificacion_usuario')->insert([
                            'id_usuario'        => $usuario->id,
                            'id_notificacion'   => $notificacion_id
                        ]);

                        array_push($usuarios, $usuario->id);
                    }

                    if (!empty($usuarios)) {
                        $notificacion['usuario']    = $usuarios;

                        event(new PusherEvent(json_encode($notificacion)));
                    }
                }
            } catch (Exception $e) {
                $message .= "No fue posible notificar a los tenicos de soporte de la modificación del pedido.<br><br>";
            }
        }

        if ($informacion_documento->fulfillment) {
            //Aqui ta
            $response = InventarioService::aplicarMovimiento($documento);

            if ($response->error) {
                return response()->json([
                    'code'  => 500,
                    'message'   => $response->mensaje
                ]);
            }
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'id_usuario' => $auth->id,
            'id_fase' => $informacion_documento->fulfillment ? 6 : ($informacion_documento->modificacion ? 2 : 3),
            'validated_at' => date("Y-m-d H:i:s")
        ]);

        # Si la venta es de ClaroShop, Laptop México ó Linio y es enviada de Amazon, se tiene que enviar una carta (por eso se manda a logistica)
        if ($informacion_documento->id_almacen_principal_empresa == 5 && in_array($informacion_documento->id_marketplace_area, [11, 14, 21]) && !$informacion_documento->fulfillment) {
            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase'   => 4
            ]);
        }

        $message .= "Documento actualizado correctamente.<br><br>";

        return response()->json([
            'code'  => 200,
            'message'   => $message
        ]);
    }

    /* Venta > Publicación */
    public function venta_publicacion_data(Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);

        $marketplaces = $this->publicaciones_raw_data($auth->id);
        $proveedores = DB::select("SELECT id, razon_social FROM modelo_proveedor WHERE status = 1");
        $marketplaces_precios = array();
        $marketplaces_oferta = array();

        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        almacen.id AS id_almacen,
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ASC");

            $empresa->almacenes = $almacenes;
        }

        foreach ($marketplaces as $marketplace) {
            $publicaciones_precios = array();
            $publicaciones_oferta = array();

            foreach ($marketplace->publicaciones as $publicacion) {
                if (!empty($publicacion->competencias)) {
                    array_push($publicaciones_precios, $publicacion);
                }

                foreach ($publicacion->ofertas as $oferta) {
                    if (date("Y-m-d H:i:s") >= date("Y-m-d H:i:s", strtotime($oferta->inicio)) && date("Y-m-d H:i:s") <= date("Y-m-d H:i:s", strtotime($oferta->final))) {
                        foreach ($publicaciones_oferta as $publicacion_oferta) {
                            if ($publicacion->id == $publicacion_oferta->id) {
                                continue 2;
                            }
                        }

                        array_push($publicaciones_oferta, $publicacion);
                    }
                }
            }

            $marketplace_data_precio = new \stdClass();
            $marketplace_data_precio->id = $marketplace->id;
            $marketplace_data_precio->marketplace = $marketplace->marketplace;
            $marketplace_data_precio->extra_2 = $marketplace->extra_2;
            $marketplace_data_precio->publicaciones = $publicaciones_precios;

            array_push($marketplaces_precios, $marketplace_data_precio);

            $marketplace_data_oferta = new \stdClass();
            $marketplace_data_oferta->id = $marketplace->id;
            $marketplace_data_oferta->marketplace = $marketplace->marketplace;
            $marketplace_data_oferta->extra_2 = $marketplace->extra_2;
            $marketplace_data_oferta->publicaciones = $publicaciones_oferta;

            array_push($marketplaces_oferta, $marketplace_data_oferta);
        }

        return response()->json([
            'code'  => 200,
            'marketplaces' => $marketplaces,
            'empresas'  => $empresas,
            'proveedores' => $proveedores,
            'marketplaces_oferta' => $marketplaces_oferta,
            'marketplaces_precios' => $marketplaces_precios,
            'marketplaces_fulfillment' => $this->publicaciones_raw_data($auth->id, " AND marketplace_publicacion.logistic_type = 'fulfillment'")
        ]);
    }

    public function venta_publicacion_competencia($competencia_id)
    {
        DB::table('marketplace_publicacion_competencia')->where(['id' => $competencia_id])->delete();

        return response()->json([
            'code' => 200
        ]);
    }

    public function venta_publicacion_oferta($oferta)
    {
        DB::table('marketplace_publicacion_oferta')->where(['id' => $oferta])->delete();

        return response()->json([
            'code' => 200
        ]);
    }

    public function venta_publicacion_15_dias($publicacion_id)
    {
        $surtido_sugerido = 0;
        $ventas_15_dias = 0;

        $publicacion = DB::table("marketplace_publicacion")
            ->select("cantidad_disponible")
            ->where("publicacion_id", $publicacion_id)->first();

        $ventas_15_dias = DB::table("documento")
            ->select(DB::raw("COUNT(*) AS total"))
            ->where("id_tipo", 2)
            ->where("mkt_publicacion", $publicacion_id)
            ->whereBetween("created_at", [date("Y-m-d 00:00:00", strtotime("-15 days")), date("Y-m-d 23:59:59")])
            ->first()->total;

        $surtido_sugerido = !$ventas_15_dias ? 0 : (($ventas_15_dias / 15) * 30 - (int) $publicacion->cantidad_disponible) < 1 ? 0 : (($ventas_15_dias / 15) * 30 - (int) $publicacion->cantidad_disponible);

        return response()->json([
            "code" => 200,
            "ventas" => $ventas_15_dias,
            "sugerido" => $surtido_sugerido < 1 ? 0 : $surtido_sugerido
        ]);
    }

    public function venta_publicacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        foreach ($data->ofertas as $oferta) {
            if ($oferta->id == 0) {
                if (date('Y-m-d H:i:s', strtotime($oferta->final)) < date('Y-m-d H:i:s', strtotime($oferta->inicio))) {
                    return response()->json([
                        "code" => 500,
                        "message" => "La fecha de terminación en la oferta relampago no puede ser menor a la fecha de inicio, puñetas, favor de revisarlo."
                    ]);
                }

                if (date('Y-m-d H:i:s', strtotime($oferta->inicio)) < date('Y-m-d H:i:s')) {
                    return response()->json([
                        "code" => 500,
                        "message" => "La fecha de inicio de la oferta no puede ser menor a la fecha de hoy."
                    ]);
                }
            }
        }

        $publicacion_data_old = DB::table("marketplace_publicacion")
            ->where("id", $data->id)
            ->first();

        $publicacion_data_old->productos = DB::table('marketplace_publicacion_producto')->where(['id_publicacion' => $data->id])->get()->toArray();

        DB::table('marketplace_publicacion_producto')->where(['id_publicacion' => $data->id])->delete();

        foreach ($data->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . trim($producto->sku) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'sku'           => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                    'descripcion'   => mb_strtoupper(trim($producto->descripcion), 'UTF-8'),
                    'costo'         => mb_strtoupper(trim($producto->costo), 'UTF-8'),
                    'alto'          => mb_strtoupper(trim($producto->alto), 'UTF-8'),
                    'ancho'         => mb_strtoupper(trim($producto->ancho), 'UTF-8'),
                    'largo'         => mb_strtoupper(trim($producto->largo), 'UTF-8'),
                    'peso'          => mb_strtoupper(trim($producto->peso), 'UTF-8'),
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            DB::table('marketplace_publicacion_producto')->insert([
                'id_publicacion'    => $data->id,
                'id_modelo'         => $modelo,
                'garantia'          => $producto->garantia,
                'cantidad'          => $producto->cantidad,
                'regalo'            => $producto->regalo,
                'etiqueta'          => $producto->etiqueta
            ]);
        }

        DB::table('marketplace_publicacion')->where(['id' => $data->id])->update([
            'total' => $data->total,
            'id_proveedor' => $data->proveedor,
            'id_almacen_empresa' => $data->almacen,
            'id_almacen_empresa_fulfillment' => $data->almacen_fulfillment,
            'publicacion_sku' => $data->sku,
            #'competencia_condicion' => $data->condicion,
            'competencia_precio_minimo' => $data->precio_minimo,
            'tee' => $data->tee,
            'status' => $data->status ? 'active' : 'inactive'
        ]);

        $publicacion_data_new = DB::table("marketplace_publicacion")
            ->where("id", $data->id)
            ->first();

        $publicacion_data_new->productos = DB::table('marketplace_publicacion_producto')->where(['id_publicacion' => $data->id])->get()->toArray();

        DB::table("marketplace_publicacion_updates")->insert([
            "id_publicacion" => $data->id,
            "id_usuario" => $auth->id,
            "old_data" => json_encode($publicacion_data_old),
            "new_data" => json_encode($publicacion_data_new)
        ]);

        foreach ($data->ofertas as $oferta) {
            if ($oferta->id == 0) {
                DB::table('marketplace_publicacion_oferta')->insert([
                    'id_publicacion' => $data->id,
                    'precio' => (float) $oferta->precio,
                    'inicio' => date('Y-m-d H:i:s', strtotime($oferta->inicio)),
                    'final' => date('Y-m-d H:i:s', strtotime($oferta->final)),
                    'promocion' => $oferta->promocion
                ]);
            }
        }

        foreach ($data->competencias as $competencia) {
            if ($competencia->id == 0) {
                $publicacion_competencia_id = DB::table('marketplace_publicacion_competencia')->insertGetId([
                    'id_publicacion' => $data->id,
                    'publicacion_id' => $competencia->publicacion_id
                ]);

                $response = MercadolibreService::buscarPublicacionCompetencia($publicacion_competencia_id);

                if ($response->error) {
                    return response()->json([
                        'code' => 500,
                        'message' => $response->mensaje
                    ]);
                }

                DB::table('marketplace_publicacion_competencia')->where(['id' => $publicacion_competencia_id])->update([
                    'precio' => (float) $response->data->price - 1,
                    'precio_cambiado' => (float) $response->data->price
                ]);

                DB::table('marketplace_publicacion_competencia_bitacora')->insert([
                    'id_competencia' => $publicacion_competencia_id,
                    'precio' => (float) $response->data->price
                ]);
            }
        }

        $marketplace_publicacion = DB::select("SELECT
                                                marketplace.marketplace
                                            FROM marketplace_publicacion
                                            INNER JOIN marketplace_area ON marketplace_publicacion.id_marketplace_area = marketplace_area.id
                                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                            WHERE marketplace_publicacion.id = " . $data->id . "")[0]->marketplace;

        $publicacion_data = DB::select("SELECT
                                        marketplace_publicacion.id,
                                        marketplace_publicacion.publicacion_id,
                                        marketplace_publicacion.publicacion,
                                        marketplace_publicacion.total,
                                        IF(marketplace_publicacion.status = 'active', 1, 0) AS status,
                                        marketplace_publicacion.tee,
                                        marketplace_publicacion.tipo,
                                        marketplace_publicacion.logistic_type,
                                        marketplace_publicacion.tienda,
                                        marketplace_publicacion.id_almacen_empresa AS almacen,
                                        marketplace_publicacion.id_almacen_empresa_fulfillment AS almacen_fulfillment,
                                        marketplace_publicacion.url
                                        empresa.bd AS empresa
                                    FROM marketplace_publicacion
                                    INNER JOIN empresa_almacen ON marketplace_publicacion.id_almacen_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE marketplace_publicacion.id = " . $data->id . "");

        if (!empty($publicacion_data)) {
            $publicacion_data[0]->productos = DB::select("SELECT
                                                    modelo.sku,
                                                    modelo.descripcion,
                                                    marketplace_publicacion_producto.id,
                                                    marketplace_publicacion_producto.garantia,
                                                    marketplace_publicacion_producto.cantidad,
                                                    marketplace_publicacion_producto.regalo,
                                                    marketplace_publicacion_producto.etiqueta
                                                FROM marketplace_publicacion_producto
                                                INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                                WHERE marketplace_publicacion_producto.id_publicacion = " . $publicacion_data[0]->id . "");

            $publicacion_data[0]->variaciones = DB::select("SELECT id, id_etiqueta, valor, cantidad FROM marketplace_publicacion_etiqueta WHERE id_publicacion = " . $publicacion_data[0]->id . "");
            $publicacion_data[0]->competencias = DB::select("SELECT * FROM marketplace_publicacion_competencia WHERE id_publicacion = " . $publicacion_data[0]->id . " ORDER BY precio ASC");
            $publicacion_data[0]->competencia = empty($publicacion->competencias) ? "Sín competencia" : "$ " . $publicacion_data[0]->competencias[0]->precio_cambiado;
            $publicacion_data[0]->ofertas = DB::select("SELECT id, precio, inicio, final, promocion FROM marketplace_publicacion_oferta WHERE id_publicacion = " . $publicacion_data[0]->id . "");

            foreach ($publicacion_data[0]->ofertas as $oferta) {
                $fecha_inicio = strtotime(date("Y-m-d H:i:s"));
                $fecha_final = strtotime($oferta->final);

                $oferta->dias = round(($fecha_final - $fecha_inicio) / (60 * 60 * 24));
            }
        }
        /*
        if (strpos($marketplace_publicacion, 'MERCADOLIBRE') !== false) {
            $es_admin_ventas = DB::table("usuario_subnivel_nivel")
                                ->select("usuario_subnivel_nivel.id")
                                ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
                                ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
                                ->where("subnivel_nivel.id_nivel", 8)
                                ->where("subnivel_nivel.id_subnivel", 1)
                                ->first();

            if (!empty($es_admin_ventas)) {
                $response = MercadolibreService::actualizarPublicacion($data->id);

                if ($response->error) {
                    return response()->json([
                        'code'  => 200,
                        'message'   => "Publicacion actualizada correctamente en CRM pero no fue posible actualizar la publicación en el mercadolibre, mensaje de error: " . $response->mensaje,
                        'publicacion' => empty($publicacion_data) ? 0 : $publicacion_data[0],
                        'raw' => property_exists($response, "raw") ? $response->raw : 0
                    ]);
                }
            }
        }
        */

        return response()->json([
            'code'  => 200,
            'message'   => "Publicación actualizada correctamente.",
            'publicacion' => empty($publicacion_data) ? 0 : $publicacion_data[0]
        ]);
    }

    public function venta_publicacion_actualizar($marketplace_id, Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);

        $marketplace = DB::select("SELECT
                                        marketplace_area.id,
                                        marketplace.marketplace
                                    FROM marketplace_area
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE marketplace_area.id = " . $marketplace_id . "");

        if (empty($marketplace)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información del marketplace seleccionado."
            ]);
        }

        $marketplace = $marketplace[0];

        switch (strtolower($marketplace->marketplace)) {
            case 'mercadolibre':
                $response = MercadolibreService::actualizarPublicaciones($marketplace->id);
                break;

            case 'linio':
                $response = LinioService::actualizarPublicaciones($marketplace->id);
                break;

            case 'amazon':
                $response = AmazonService::actualizarPublicaciones($marketplace->id);

                break;


            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador. <br/> Error: BVC3621";

                break;
        }

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje
            ]);
        }

        $marketplaces = $this->publicaciones_raw_data($auth->id);

        return response()->json([
            'code'  => 200,
            'marketplaces'  => $marketplaces
        ]);
    }

    public function venta_publicacion_pretransferencia(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        foreach ($data->publicaciones as $publicacion) {
            foreach ($publicacion->productos_enviados as $producto) {
                $response = InventarioService::existenciaProducto($producto->sku, $data->almacen_secundario);

                if ($response->error) {
                    return response()->json([
                        "code" => 500,
                        "message" => "Error al buscar la existencia del producto " . $producto->sku . ", mensaje de error: " . $response->mensaje
                    ]);
                }

                if ($response->existencia < $producto->cantidad) {
                    return response()->json([
                        "code" => 500,
                        "message" => "No hay suficiente existencia del codigo " . $producto->sku . "<br><br><b>Cantidad solicitada: " . $producto->cantidad . "</b><br><b>Cantidad disponible</b>: " . $response->existencia . ""
                    ]);
                }
            }
        }

        $existe_entidad = DB::select("SELECT id FROM documento_entidad WHERE RFC = 'SISTEMAOMG'");

        if (empty($existe_entidad)) {
            $entidad = DB::table('documento_entidad')->insertGetId([
                'tipo' => 2,
                'razon_social' => 'SISTEMA OMG',
                'rfc' => 'SISTEMAOMG'
            ]);
        } else {
            $entidad = $existe_entidad[0]->id;
        }

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => $data->almacen_principal,
            'id_almacen_secundario_empresa' => $data->almacen_secundario,
            'id_tipo' => 9,
            'id_periodo' => 1,
            'id_cfdi' => 1,
            'id_marketplace_area' => $data->publicaciones[0]->id_marketplace_area,
            'id_usuario' => $auth->id,
            'id_moneda' => 3,
            'id_paqueteria' => 6,
            'id_fase' => 401,
            'id_entidad' => $entidad,
            'factura_folio' => 'N/A',
            'tipo_cambio' => 1,
            'referencia' => 'N/A',
            'info_extra' => json_encode(new \stdClass()),
            'observacion' => $data->observacion
        ]);

        DB::table('documento_direccion')->insert([
            'id_documento' => $documento,
            'id_direccion_pro' => $data->direccion_envio->colonia,
            'contacto' => $data->direccion_envio->contacto,
            'calle' => $data->direccion_envio->calle,
            'numero' => $data->direccion_envio->numero,
            'numero_int' => $data->direccion_envio->numero_int,
            'colonia' => $data->direccion_envio->colonia_text,
            'ciudad' => $data->direccion_envio->ciudad,
            'estado' => $data->direccion_envio->estado,
            'codigo_postal' => $data->direccion_envio->codigo_postal,
            'referencia' => $data->direccion_envio->referencia
        ]);

        foreach ($data->publicaciones as $publicacion) {
            foreach ($publicacion->productos_enviados as $producto) {
                $modelo = DB::table("modelo")
                    ->select("id")
                    ->where("sku", trim($producto->sku))
                    ->first();

                $movimiento = DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $modelo->id,
                    'cantidad' => $producto->cantidad,
                    'precio' => 0.862068,
                    'garantia' => $producto->garantia,
                    'modificacion' => 'N/A',
                    'comentario' => 'N/A',
                    'regalo' => $producto->regalo
                ]);

                if (!$producto->regalo && count($publicacion->variaciones) == 0) {
                    DB::table("marketplace_publicacion_etiqueta_envio")->insert([
                        "id_documento" => $documento,
                        "id_publicacion" => $publicacion->id,
                        "cantidad" => $producto->cantidad,
                        "etiqueta" => "N/A"
                    ]);
                }
            }

            if (count($publicacion->variaciones) > 0) {
                foreach ($publicacion->variaciones as $variacion) {
                    if ($variacion->cantidad_envio > 0) {
                        DB::table("marketplace_publicacion_etiqueta_envio")->insert([
                            "id_documento" => $documento,
                            "id_publicacion" => $publicacion->id,
                            "cantidad" => $variacion->cantidad_envio,
                            "etiqueta" => $variacion->id_etiqueta
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'code'  => 200,
            'message' => "Solicitud creada correctamente con el ID " . $documento . "."
        ]);
    }

    /* Nota de credito */
    public function venta_nota_credito_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $usos_factura = DB::table("documento_uso_cfdi")
            ->select("id", "codigo", "descripcion")
            ->get()
            ->toArray();

        $monedas = DB::table("moneda")->get()->toArray();

        $periodos = DB::table("documento_periodo")
            ->select("id", "periodo")
            ->where("status", 1)
            ->get()
            ->toArray();

        $metodos = DB::table("metodo_pago")
            ->select("id", "metodo_pago", "codigo")
            ->get()
            ->toArray();

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.empresa", "empresa.bd")
            ->join("usuario_empresa", "empresa.id", "=", "usuario_empresa.id_empresa")
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->get()
            ->toArray();

        foreach ($empresas as $empresa) {
            $empresa->almacenes = DB::table("empresa_almacen")
                ->select("empresa_almacen.id", "almacen.almacen", "empresa_almacen.id_erp")
                ->join("almacen", "empresa_almacen.id_almacen", "=", "almacen.id")
                ->where("empresa_almacen.id_empresa", $empresa->id)
                ->where("almacen.id", "<>", 0)
                ->get()
                ->toArray();
        }

        return response()->json([
            "code" => 200,
            "empresas" => $empresas,
            "usos_factura" => $usos_factura,
            "monedas" => $monedas,
            "periodos" => $periodos,
            "metodos" => $metodos
        ]);
    }

    /* Mercadolibre */
    public function venta_mercadolibre_pregunta_respuesta_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        return response()->json([
            "data" => self::areas_marketplaces($auth->id, "MERCADOLIBRE")
        ]);
    }

    public function venta_mercadolibre_pregunta_respuesta_get_preguntas($marketplace_id)
    {
        $data = MercadolibreService::buscarPreguntas($marketplace_id);

        return response()->json([
            "data" => $data
        ]);
    }

    public function venta_mercadolibre_pregunta_respuesta_post_responder(Request $request)
    {
        $data = json_decode($request->input("data"));

        $response = MercadolibreService::responderPregunta($data->marketplace, $data->id, $data->respuesta);

        return response()->json([
            "message" => $response->error ? $response->mensaje : "Respuesta enviada correctamente",
            "raw" => property_exists($response, "raw") ? $response->raw : 0
        ], $response->error ? 500 : 200);
    }

    public function venta_mercadolibre_pregunta_respuesta_post_borrar(Request $request)
    {
        $data = json_decode($request->input("data"));

        $response = MercadolibreService::borrarPregunta($data->marketplace, $data->id);

        return response()->json([
            "message" => $response->error ? $response->mensaje : "Pregunta eliminada correctamente",
            "raw" => property_exists($response, "raw") ? $response->raw : 0
        ], $response->error ? 500 : 200);
    }

    public function venta_mercadolibre_pregunta_respuesta_post_bloquear_usuario(Request $request)
    {
        $data = json_decode($request->input("data"));

        $response = MercadolibreService::bloquearUsuarioParaPreguntar($data->marketplace, $data->id);

        return response()->json([
            "message" => $response->error ? $response->mensaje : "Usuario bloqueado correctamente",
            "raw" => property_exists($response, "raw") ? $response->raw : 0
        ], $response->error ? 500 : 200);
    }

    public function venta_mercadolibre_nueva_publicacion(Request $request)
    {
        $data = json_decode($request->input("data"));

        $response = MercadolibreService::crearPublicacion($data->marketplace, $data->item);

        return response()->json([
            "message" => $response->mensaje
        ], $response->error ? 500 : 200);
    }

    public function venta_mercadolibre_publicaciones_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $areas = self::areas_marketplaces($auth->id, "MERCADOLIBRE");

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.empresa", "empresa.bd")
            ->join("usuario_empresa", "empresa.id", "=", "usuario_empresa.id_empresa")
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->where("empresa.id", "<>", 0)
            ->get()
            ->toArray();

        foreach ($empresas as $empresa) {
            $empresa->almacenes = DB::table("empresa_almacen")
                ->select("empresa_almacen.id", "almacen.almacen")
                ->join("almacen", "empresa_almacen.id_almacen", "=", "almacen.id")
                ->where("empresa_almacen.id_empresa", $empresa->id)
                ->where("almacen.id", "<>", 0)
                ->get()
                ->toArray();
        }

        $proveedores = DB::table("modelo_proveedor")
            ->select("id", "razon_social")
            ->where("status", 1)
            ->get()
            ->toArray();

        $tipos_logistica = DB::table("marketplace_publicacion")
            ->select("logistic_type")
            ->where("logistic_type", "<>", "N/A")
            ->groupBy("logistic_type")
            ->get()
            ->toArray();

        return response()->json([
            "areas" => $areas,
            "proveedores" => $proveedores,
            "logistica" => $tipos_logistica,
            "empresas" => $empresas
        ]);
    }

    public function venta_mercadolibre_publicaciones_busqueda(Request $request)
    {
        set_time_limit(0);
        
        $data = json_decode($request->input("data"));

        $publicaciones = DB::table("marketplace_publicacion")
            ->where(
                "id_marketplace_area",
                $data->marketplace
            )
            ->when($data->provider, function ($query, $provider) {
                return $query->where("id_proveedor", $provider);
            })
            ->when($data->brand, function ($query, $brand) {
                return $query->where("tienda", $brand);
            })
            ->when($data->status, function ($query, $status) {
                return $query->where("status", $status);
            })
            ->when($data->logistic, function ($query, $logistic) {
                return $query->where("logistic_type", $logistic);
            })
            ->get();

        $publicacionIds = $publicaciones->pluck('id')->toArray();

        $productosCounts = DB::table("marketplace_publicacion_producto")
            ->whereIn("id_publicacion", $publicacionIds)
            ->select("id_publicacion", DB::raw("COUNT(*) as count"))
            ->groupBy("id_publicacion")
            ->pluck('count', 'id_publicacion');

        $almacenIds = $publicaciones->pluck('id_almacen_empresa')->merge($publicaciones->pluck('id_almacen_empresa_fulfillment'))->unique()->toArray();

        $almacenes = DB::table('empresa_almacen')
            ->select(
                'empresa_almacen.id',
                'almacen.almacen'
            )
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->whereIn('empresa_almacen.id', $almacenIds)
            ->pluck('almacen', 'empresa_almacen.id');

        foreach ($publicaciones as $publicacion) {
            $publicacion->productos = isset($productosCounts[$publicacion->id]) ? true : false;
            $publicacion->almacendrop = $almacenes[$publicacion->id_almacen_empresa] ?? '';
            $publicacion->almacenfull = $almacenes[$publicacion->id_almacen_empresa_fulfillment] ?? '';
        }

        return response()->json([
            "data" => $publicaciones
        ]);
    }


    public function venta_mercadolibre_publicaciones_publicacion_data($publicacion_id)
    {
        $publicacion = DB::table("marketplace_publicacion")->find($publicacion_id);

        $productos = DB::table("marketplace_publicacion_producto")
            ->select("modelo.sku", "modelo.descripcion AS description", "marketplace_publicacion_producto.etiqueta AS variation", "marketplace_publicacion_producto.cantidad AS quantity", "marketplace_publicacion_producto.garantia AS warranty", "marketplace_publicacion_producto.porcentaje AS percentage")
            ->join("modelo", "marketplace_publicacion_producto.id_modelo", "=", "modelo.id")
            ->where("marketplace_publicacion_producto.id_publicacion", $publicacion_id)
            ->get()
            ->toArray();
        /*
        $ventas_15_dias = DB::table("documento")
                            ->select(DB::raw("COUNT(*) AS total"))
                            ->where("id_tipo", 2)
                            ->where("mkt_publicacion", $publicacion_id)
                            ->whereBetween("created_at", [date("Y-m-d 00:00:00", strtotime("-15 days")), date("Y-m-d 23:59:59")])
                            ->first()->total;
        
         $surtido_sugerido = !$ventas_15_dias ? 0 : (($ventas_15_dias / 15) * 30 - (int) $publicacion->cantidad_disponible) < 1 ? 0 : (($ventas_15_dias / 15) * 30 - (int) $publicacion->cantidad_disponible);
        */
        return response()->json([
            "productos" => $productos,
            # "ventas" => $ventas_15_dias
            # "surtido_sugerido" => $surtido_sugerido < 0 ? 0 : $surtido_sugerido
        ]);
    }

    public function venta_mercadolibre_publicaciones_actualizar(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input("data"));

        $marketplace_name = DB::table("marketplace_area")
            ->select("marketplace.marketplace")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_area.id", $data->marketplace)
            ->first();

        if (!$marketplace_name) {
            return response()->json([
                "message" => "No se encontró información del marketplace seleccionado"
            ], 500);
        }

        switch (strtolower($marketplace_name->marketplace)) {
            case 'mercadolibre':
                $response = MercadolibreService::actualizarPublicaciones($data->marketplace);
                break;

            case 'linio':
                $response = LinioService::actualizarPublicaciones($data->marketplace);
                break;

            case 'amazon':
                $response = AmazonService::actualizarPublicaciones($data->marketplace);

                break;

            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador.<br/> Error: BVC4207";

                break;
        }

        if ($response->error) {
            return response()->json([
                'message' => $response->mensaje
            ], 500);
        }

        $publicaciones = DB::table("marketplace_publicacion")
            ->where("id_marketplace_area", $data->marketplace)
            ->when($data->provider, function ($query, $provider) {
                return $query->where("id_proveedor", $provider);
            })
            ->when($data->brand, function ($query, $brand) {
                return $query->where("tienda", $brand);
            })
            ->when($data->status, function ($query, $status) {
                return $query->where("status", $status);
            })
            ->when($data->logistic, function ($query, $logistic) {
                return $query->where("logistic_type", $logistic);
            })
            ->get()
            ->toArray();

        return response()->json([
            "data" => $publicaciones
        ]);
    }

    public function venta_mercadolibre_publicaciones_guardar(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $products_before_updating = DB::table("marketplace_publicacion_producto")
            ->where("id_publicacion", $data->id)
            ->get()
            ->toArray();

        # Se actualizan productos de la publicación
        DB::table('marketplace_publicacion_producto')->where(['id_publicacion' => $data->id])->delete();

        foreach ($data->products as $producto) {
            $existe = DB::table("modelo")
                ->where("sku", $producto->sku)
                ->first();

            if (!$existe) {
                $modelo = DB::table("modelo")->firstOrUpdate([
                    "sku" => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                    "descripcion" => mb_strtoupper(trim($producto->description), 'UTF-8'),
                ]);
            }

            $modelo = !$existe ? DB::table("modelo")->insertGetId([
                "sku" => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                "descripcion" => mb_strtoupper(trim($producto->description), 'UTF-8'),
            ]) : $existe;

            DB::table('marketplace_publicacion_producto')->insert([
                'id_publicacion' => $data->id,
                'id_modelo' => $modelo->id,
                'garantia' => $producto->warranty,
                'cantidad' => $producto->quantity,
                'etiqueta' => $producto->variation,
                'porcentaje' => $producto->percentage
            ]);
        }

        $products_after_updating = DB::table("marketplace_publicacion_producto")
            ->where("id_publicacion", $data->id)
            ->get()
            ->toArray();

        $item_data_before_update = DB::table("marketplace_publicacion")
            ->where("id", $data->id)
            ->first();

        $item_data_before_update->products = $products_before_updating;

        DB::table("marketplace_publicacion")->where("id", $data->id)->update([
            "id_proveedor" => $data->provider,
            "id_almacen_empresa" => $data->principal_warehouse,
            "id_almacen_empresa_fulfillment" => $data->secondary_warehouse
        ]);

        $item_data_after_update = DB::table("marketplace_publicacion")
            ->where("id", $data->id)
            ->first();

        $item_data_after_update->products = $products_after_updating;

        DB::table("marketplace_publicacion_updates")->insert([
            "id_usuario" => $auth->id,
            "id_publicacion" => $data->id,
            "old_data" => json_encode($item_data_before_update),
            "new_data" => json_encode($item_data_after_update)
        ]);

        return response()->json([
            "message" => "Publicación actualizada correctamente!"
        ]);
    }

    public function venta_mercadolibre_publicaciones_guardar_marketplace(Request $request)
    {

        $data = json_decode($request->input("data"));

        $validate_authy = DocumentoService::authy($auth->id, $data->authy_code);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje
            ], 500);
        }

        $publicacion = DB::table("marketplace_publicacion")
            ->select("marketplace.marketplace")
            ->join("marketplace_area", "marketplace_publicacion.id_marketplace_area", "marketplace_area.id")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("marketplace_publicacion.id", $data->id)
            ->first();

        if (!$publicacion) {
            return response()->json([
                "message" => "No se encontró el marketplace de la publicación, favor de contactar a un administrador."
            ], 404);
        }

        switch (strtolower($publicacion->marketplace)) {
            case 'mercadolibre':
                $data_to_update = new \stdClass();
                $data_to_update->id = $data->id;
                $data_to_update->title = $data->title;
                $data_to_update->variations = $data->variations;
                $data_to_update->attributes = $data->attributes;
                $data_to_update->pictures_data = $data->pictures_data;
                $data_to_update->description = $data->description;
                $data_to_update->quantity = $data->quantity;
                $data_to_update->price = $data->price;
                $data_to_update->video = $data->video;
                $data_to_update->listing_type = $data->listing_type;
                $data_to_update->warranty = $data->warranty;

                return (array) $data_to_update;

                $response = MercadolibreService::actualizarPublicacion($data_to_update);

                break;

            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador.<br/> Error: BVC4364";

                break;
        }

        return response()->json([
            "message" => $response->mensaje,
            "data" => property_exists($response, "data") ? $response->data : 0
        ], $response->error ? 500 : 200);
    }

    public function venta_mercadolibre_validar_ventas_data(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input("data"));

        $ventas = DB::table("documento")
            ->select(
                "documento.id",
                "documento.no_venta",
                "documento.no_venta_btob",
                "documento_fase.fase",
                "documento.id_modelo_proveedor",
                "documento.fulfillment",
                "documento.id_marketplace_area",
                "documento_entidad.rfc",
                "documento_entidad.razon_social",
                "documento.mkt_publicacion",
                "documento.created_at"
            )
            ->join("documento_entidad", "documento.id_entidad", "=", "documento_entidad.id")
            ->join("documento_fase", "documento_fase.id", "=", "documento.id_fase")
            ->whereIn("documento.id_fase", [1, 2, 7])
            ->where("documento.status", 1)
            ->where("documento.id_marketplace_area", $data->marketplace)
            ->get();

        foreach ($ventas as $venta) {
            $productos = DB::table('movimiento')
                ->select(
                    'movimiento.id',
                    'movimiento.cantidad',
                    'modelo.sku',
                    'modelo.descripcion',
                    'modelo.serie',
                    DB::raw('ROUND((movimiento.precio * 1.16), 2) AS precio')
                )
                ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                ->where('id_documento', $venta->id)
                ->get();

            $venta->productos   = $productos;
        }

        return response()->json([
            "message" => empty($ventas) ? "No se encontraron ventas pendientes" : "Ventas obtenidas correctamente",
            "ventas" => $ventas
        ]);
    }

    public function venta_mercadolibre_valida_venta(Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);
        $documento = $request->input("venta");

        $hayError = false;

        $venta = DB::table("documento")
            ->select(
                "documento.id",
                "documento.no_venta",
                "documento.no_venta_btob",
                "documento.id_modelo_proveedor",
                "documento.fulfillment",
                "documento.id_marketplace_area",
                "documento_entidad.rfc",
                "documento_entidad.razon_social",
                "documento.mkt_publicacion",
                "documento.created_at"
            )
            ->join("documento_entidad", "documento.id_entidad", "=", "documento_entidad.id")
            ->where("documento.id", $documento)
            ->first();

        BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Se inicia el proceso de validar la venta");

        $response = MercadolibreService::validarVenta($documento);

        if ($response->error) {
            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Error al validar la venta en ML. Error: " . $response->mensaje);

            DB::table("seguimiento")->insert([
                'id_documento' => $documento,
                'id_usuario' => 1,
                'seguimiento' => "Mensaje al validar la venta -> " . $response->mensaje
            ]);

            return response()->json([
                'code' => 500,
                'mensaje' => "Error al validar la venta en ML: " . $response->mensaje
            ]);
        }

        DB::table('documento')->where('id', $documento)->update([
            'id_almacen_principal_empresa' => $response->almacen,
            'id_paqueteria' => $response->paqueteria,
            'comentario' => $response->id,
        ]);

        DB::table('movimiento')->where('id_documento', $documento)->delete();

        $total_pago = 0;

        BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
        "Se actualiza el almacen a " . $response->almacen . " y se actualiza el paqueteria a " . $response->paqueteria . " con el id " . $response->id);

        BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Se borran los productos que tenia el pedido.");

        foreach ($response->productos as $producto) {
            $codigo = DB::table('modelo')->where('id', $producto->id_modelo)->value('sku');

            if ($venta->id_modelo_proveedor != 0) {
                $existe_relacion_btob = DB::table("modelo_proveedor_producto")
                    ->where("id_modelo_proveedor", $venta->id_modelo_proveedor)
                    ->where("id_modelo", $producto->id_modelo)
                    ->first();

                if (!$existe_relacion_btob) {
                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                        "No existe del relación del codigo " . $codigo . " con el proveedor B2B " . $documento);

                    DB::table("seguimiento")->insert([
                        'id_documento' => $venta->id,
                        'id_usuario' => 1,
                        'seguimiento' => "Mensaje al validar la venta -> No existe del relación del codigo " . $codigo . " con el proveedor B2B " . $venta->id
                    ]);

                    $hayError = true;
                }
            } else {
                $existencia = InventarioService::existenciaProducto($codigo, $response->almacen);

                if ($existencia->error) {
                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                    "Error al consultar la existencia. Error: " . $existencia->mensaje);

                    DB::table("seguimiento")->insert([
                        'id_documento' => $venta->id,
                        'id_usuario' => 1,
                        'seguimiento' => "Mensaje al validar la venta -> " . $existencia->mensaje . ", error en el pedido " . $venta->id
                    ]);

                    $hayError = true;
                }

                if ($existencia->existencia < $producto->cantidad) {
                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                        "No hay suficiente existencia del producto " . $codigo . " para procesar el pedido " . $venta->id);

                    DB::table("seguimiento")->insert([
                        'id_documento' => $venta->id,
                        'id_usuario' => 1,
                        'seguimiento' => "Mensaje al validar la venta -> " . "No hay suficiente existencia del producto " . $codigo . " para procesar el pedido " . $venta->id
                    ]);

                    $hayError = true;
                }
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $venta->id,
                'id_modelo' => $producto->id_modelo,
                'cantidad' => $producto->cantidad,
                'precio' => (float) ($producto->precio) / 1.16,
                'garantia' => $producto->garantia,
                'modificacion' => '',
                'regalo' => $producto->regalo
            ]);

            $total_pago += $producto->cantidad * $producto->precio;

            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                "Se agrega el producto con el id " . $producto->id_modelo . " con la cantidad de " . $producto->cantidad .
                " y el precio de " . $producto->precio . " al pedido " . $venta->id . ". El id del movimiento es " . $movimiento);
        }

        $existe_pago = DB::table('documento_pago_re')->where('id_documento', $venta->id)->first();

        if (empty($existe_pago)) {
            $pago = DB::table('documento_pago')->insertGetId([
                'id_usuario' => 1,
                'id_metodopago' => 31,
                'id_vertical' => 0,
                'id_categoria' => 0,
                'id_clasificacion' => 0,
                'tipo' => 1,
                'origen_importe' => 0,
                'destino_importe' => $total_pago,
                'folio' => "",
                'entidad_origen' => 1,
                'origen_entidad' => $venta->rfc,
                'entidad_destino' => '',
                'destino_entidad' => '',
                'referencia' => '',
                'clave_rastreo' => '',
                'autorizacion' => '',
                'destino_fecha_operacion' => date('Y-m-d'),
                'destino_fecha_afectacion' => '',
                'cuenta_cliente' => ''
            ]);

            DB::table('documento_pago_re')->insert([
                'id_documento' => $venta->id,
                'id_pago' => $pago
            ]);

            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                "Se crea el pago en crm de " . $total_pago . " a la entidad " . $venta->rfc . ". El id del pago es " . $pago);
        } else {
            DB::table('documento_pago')->where('id', $existe_pago->id_pago)->update([
                'id_usuario' => 1,
                'id_metodopago' => 31,
                'id_vertical' => 0,
                'id_categoria' => 0,
                'id_clasificacion' => 0,
                'tipo' => 1,
                'origen_importe' => 0,
                'destino_importe' => $total_pago,
                'folio' => "",
                'entidad_origen' => 1,
                'origen_entidad' => $venta->rfc,
                'entidad_destino' => '',
                'destino_entidad' => '',
                'referencia' => '',
                'clave_rastreo' => '',
                'autorizacion' => '',
                'destino_fecha_operacion' => date('Y-m-d'),
                'destino_fecha_afectacion' => '',
                'cuenta_cliente' => ''
            ]);

            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                "Se actualiza el pago en crm de " . $total_pago . " a la entidad " . $venta->rfc . ". El id del pago es " . $existe_pago->id_pago);
        }

        if ($venta->id_modelo_proveedor != 0) {
            if ($venta->no_venta_btob == 'N/A') {
                $tiene_archivos = DB::table('documento_archivo')->where('id_documento', $venta->id)->where('tipo', 2)
                    ->pluck('id');

                if (empty($tiene_archivos)) {
                    $marketplace_info = DB::table('marketplace_area')
                        ->select(
                            'marketplace_area.id',
                            'marketplace_api.extra_1',
                            'marketplace_api.extra_2',
                            'marketplace_api.app_id',
                            'marketplace_api.secret'
                        )
                        ->join('marketplace_api', 'marketplace_area.id', '=', 'marketplace_api.id_marketplace_area')
                        ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
                        ->where('marketplace_area.id', $venta->id_marketplace_area)
                        ->first();

                    $guia = MercadolibreService::documento(trim($venta->no_venta), $marketplace_info);

                    if ($guia->error) {
                        BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Error al obtener la guia en ML. Error: " . $guia->mensaje);

                        DB::table("seguimiento")->insert([
                            'id_documento' => $venta->id,
                            'id_usuario' => 1,
                            'seguimiento' => "Mensaje al validar la venta -> " . $guia->mensaje . ", en el pedido " . $venta->id
                        ]);

                        $hayError = true;
                    } else {
                        try {
                            $nombre = "etiqueta_" . $venta->id . ".pdf";

                            $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                                ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                                ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $nombre . '" , "mode": "add", "autorename": true}')
                                ->addHeader('Content-Type', 'application/octet-stream')
                                ->body(base64_decode($guia->file))
                                ->send();

                            $guia_archivo = DB::table('documento_archivo')->insertGetId([
                                'id_documento' => $venta->id,
                                'id_usuario' => 1,
                                'id_impresora' => 36,
                                'nombre' => $nombre,
                                'dropbox' => $response->body->id,
                                'tipo' => 2
                            ]);

                            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Se agrega el archivo de la guia con el id " . $guia_archivo);
                        } catch (Exception $e) {
                            BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "Error al guardar la guia en dropbox. Error: " . $e->getMessage());

                            DB::table("seguimiento")->insert([
                                'id_documento' => $venta->id,
                                'id_usuario' => 1,
                                'seguimiento' => "Mensaje al validar la venta -> " . $e->getMessage() . ", en el pedido " . $venta->id
                            ]);

                            $hayError = true;
                        }
                    }
                }

                switch ($venta->id_modelo_proveedor) {
                    case '4':
                        $crear_pedido_btob = ExelDelNorteService::crearPedido($venta->id);
                        break;

                    case '5':
                        $crear_pedido_btob = CTService::crearPedido($venta->id);
                        break;

                    default:
                        $crear_pedido_btob = new \stdClass();

                        $crear_pedido_btob->error = 1;
                        $crear_pedido_btob->mensaje = "El proveedor no ha sido configurado";

                        break;
                }

                if ($crear_pedido_btob->error) {
                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "No fue posible crear la venta en el sistema del proveedor B2B, mensaje de error: " . $crear_pedido_btob->mensaje);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "<p>No fue posible crear la venta en el sistema del proveedor B2B, mensaje de error: " . $crear_pedido_btob->mensaje . "</p>"
                    ]);

                    $hayError = true;
                }
            }
        }

        $validar_buffered = MercadolibreService::validarPendingBuffered($venta->id);

        if ($validar_buffered->error) {
            if ($validar_buffered->substatus == "cancelled") {
                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => "El pedido esta CANCELADO en MERCADOLIBRE"
                ]);

                BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "El pedido esta CANCELADO en MERCADOLIBRE. Se cancela el pedido.");

                return response()->json([
                    'code' => 500,
                    'mensaje' => "El pedido esta CANCELADO en MERCADOLIBRE. Revisar con MercadoLibre."
                ]);
            }

            if ($validar_buffered->substatus == "delivered") {
                DB::table('documento')->where('id', $documento)->update([
                    'id_fase' => $venta->fulfillment ? $hayError ? 1 : 5 : 1
                ]);

                BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, $venta->fulfillment ? "El pedido esta ENTREGADO en MERCADOLIBRE. Se cambia la fase a Factura."
                    : "El pedido esta ENTREGADO en MERCADOLIBRE. Se cambia la fase a Pedido.");

                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => $venta->fulfillment ? $hayError ? "El pedido esta ENTREGADO en MERCADOLIBRE. No se puede crear la factura. Favor de revisar." : "El pedido esta ENTREGADO en MERCADOLIBRE."
                        : "El pedido esta ENTREGADO en MERCADOLIBRE. Se cambia la fase a Pedido."
                ]);

                return response()->json([
                    'code' => 500,
                    'mensaje' => $venta->fulfillment ? $hayError ? "El pedido esta ENTREGADO en MERCADOLIBRE. No se puede crear la factura. Favor de revisar." : "El pedido esta ENTREGADO en MERCADOLIBRE."
                        : "El pedido esta ENTREGADO en MERCADOLIBRE. Se cambia la fase a Pedido."
                ]);
            }
        }

        if ($validar_buffered->estatus) {
            if ($validar_buffered->substatus == "buffered") {
                BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "El pedido se encuentra en pending buffered, se actualiza la fase a 7");

                DB::table('documento')->where('id', $documento)->update([
                    'id_fase' => 7,
                    'validated_at' => date("Y-m-d H:i:s")
                ]);

                DB::table("seguimiento")->insert([
                    'id_documento' => $venta->id,
                    'id_usuario' => 1,
                    'seguimiento' => "Mensaje al validar la venta -> " . $validar_buffered->mensaje
                ]);

                return response()->json([
                    'code' => 500,
                    'mensaje' => "El pedido aun no tiene la guia de embarque se actualiza la fase del pedido."
                ]);
            } else {
                DB::table('documento')->where('id',$venta->id)->update([
                    'id_fase' => 1,
                    'validated_at' => date("Y-m-d H:i:s")
                ]);

                BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "El pedido se actualiza la fase a 1. Mensaje: " . $validar_buffered->mensaje);

                DB::table("seguimiento")->insert([
                    'id_documento' => $venta->id,
                    'id_usuario' => 1,
                    'seguimiento' => "Mensaje al validar la venta -> " . $response->mensaje
                ]);

                CorreoService::cambioDeFase($venta->id, $response->mensaje);

                return response()->json([
                    'code' => 500,
                    'mensaje' => "El pedido se actualiza a la fase de Pedido. Mensaje: " . $validar_buffered->mensaje
                ]);
            }
        }

        if ($venta->fulfillment) {
            //Aqui ta
            if(!$hayError) {
//                $factura = DocumentoService::crearFactura($venta->id, 0, 0);
                $factura =InventarioService::aplicarMovimiento($venta->id);
                if (!$factura->error) {
                    DB::table('documento')->where(['id' => $venta->id])->update([
                        'id_fase' => 6,
                        'validated_at' => date("Y-m-d H:i:s")
                    ]);

                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id,
                        "Se actualiza la fase a 6 y se crea la factura con el id " . $factura->id);

                    return response()->json([
                        'code' => 200,
                        'mensaje' => "Documento actualizado correctamente"
                    ]);
                } else {
                    DB::table('documento')->where('id',$venta->id)->update([
                        'id_fase' => 6,
                        'validated_at' => date("Y-m-d H:i:s")
                    ]);
                    BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, "No fue posible crear la factura, mensaje de error: " . $factura->mensaje);

                    return response()->json([
                        'code' => 500,
                        'mensaje' => "Documento actualizado correctamente, sin embargo hubo un error al crear la factura. Mensaje de error: " . $factura->mensaje
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 500,
                    'mensaje' => "Documento actualizado correctamente, sin embargo, hay un problema en el pedido que no deja crear la Factura, Favor de revisar el pedido."
                ]);
            }
        }

        DB::table('documento')->where('id', $venta->id)->update([
            'id_fase' => $hayError ? 1 : 3,
            'validated_at' => date("Y-m-d H:i:s")
        ]);

        BitacoraService::insertarBitacoraValidarVenta($documento, $auth->id, $hayError ?
            "Se actualiza el pedido a fase 1 ya que hubo errores que hacen que el pedido no este completo"
            : "Se actualiza la fase a 3 y se termina el proceso de validar la venta.");

        DB::table('seguimiento')->insertGetId([
            "id_documento" => $venta->id,
            "id_usuario" => 1,
            "seguimiento" => $hayError ?
                "Se actualiza el pedido a fase de PEDIDO ya que hubo errores que hacen que el pedido no este completo"
                : "Se actualiza la fase a PENDIENTE DE REMISION y se termina el proceso de validar la venta."
        ]);

        return response()->json([
            'code' => 200,
            'mensaje' => $hayError ? "Documento actualizado sin embargo hubo algunes errores, favor de ver los seguimientos en el documento"
                : "Documento actualizado correctamente"
        ]);
    }

    /* Lino */
    public function venta_shopify_importar_ventas(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $invalido = 0;

        if (intval($data->marketplace) != 35 && $invalido == 0) {
            $invalido = 1;
        }
        if (intval($data->marketplace) != 60 && $invalido == 1) {
            $invalido = 1;
        } else {
            $invalido = 0;
        }

        if ($invalido == 1) {
            return response()->json([
                "code" => 500,
                "message" => "Marketplace Invalido",
            ]);
        }

        $ventas = ShopifyService::importarVentasMasiva($data->marketplace);

        return response()->json([
            "code" => $ventas->code,
            "message" => $ventas->message,
            "data" => $ventas->ventas,
        ]);
    }

    public function venta_shopify_cotizar_guia(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);
        $response = new \stdClass();
        $response->error = 1;

        $documento = $data->documento;
        $usuario = $auth->id;

        $direccion = DB::select("SELECT * FROM documento_direccion WHERE id_documento = " . $documento . "");

        if (empty($direccion)) {
            $log = self::logVariableLocation();

            $response->mensaje = "El documento no contiene dirección para generar la guía, favor de contactar a un administrador." . $log;

            return $response;
        }

        $direccion = $direccion[0];

        # Información del usuario que está cerrando el pedido
        $usuario_data = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $usuario . " AND status = 1");

        if (empty($usuario_data)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información del usuario, favor de contactar a un administrador." . $log;

            return $response;
        }

        $usuario_data = $usuario_data[0];

        # Información del cliente
        $informacion_cliente = DB::select("SELECT
                                        documento_entidad.*
                                    FROM documento
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento . "");

        if (empty($informacion_cliente)) {
            $log = self::logVariableLocation();

            $response->mensaje = "No se encontró información sobre el cliente del documento, favor de contactar a un administrador." . $log;

            return $response;
        }

        $informacion_cliente = $informacion_cliente[0];

        $informacion_empresa = DB::table('documento')
            ->select('empresa.*')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->where('documento.id', $documento)
            ->first();

        $guias = ShopifyService::cotizarGuia($documento, $direccion, $usuario_data, $informacion_cliente, $informacion_empresa);

        $paqueterias =
            DB::table('paqueteria_tipo')
            ->select('*')
            ->where('id_paqueteria', '>', 100)
            ->get()
            ->toArray();

        return response()->json([
            "code" => 200,
            "message" => "Proceso terminado",
            "guias" => $guias,
            "paqueterias" => $paqueterias
        ]);
    }

    public function venta_walmart_importar_ventas(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);
        $ventas = [];

        $marketplace_area =
            DB::table('marketplace_area')
            ->select('id')
            ->where('id_area', $data->area)
            ->where('id_marketplace', $data->marketplace)
            ->first();

        if($data->excel && $data->fulfillment) {
            $ventas = $data->data;
            foreach ($ventas as $venta) {
                $existe = DB::select("SELECT * FROM documento WHERE no_venta = " . $venta->orden);

                if ($existe) {
                    $venta->Error = 1;
                    $venta->purchaseOrderId = "N/A";
                    $venta->customerOrderId = $venta->orden;
                    $venta->ErrorMessage = "Ya existe la venta en CRM.";
                    $venta->Documentos = implode(',', array_column($existe, 'id'));
                } else {
                    $info_venta = WalmartService::venta($venta->orden, 64, 1);

                    $importar = WalmartService::importarVentaIndividual($info_venta->data, $marketplace_area->id, 1);
                    if ($importar->error) {
                        $venta->Error = $importar->error;
                        $venta->purchaseOrderId = "N/A";
                        $venta->customerOrderId = $venta->orden;
                        $venta->ErrorMessage = $importar->mensaje;
                        $venta->Documentos = "";
                    } else {
                        $venta->Error = $importar->error;
                        $venta->purchaseOrderId = "N/A";
                        $venta->customerOrderId = $venta->orden;
                        $venta->ErrorMessage = $importar->mensaje;
                        $venta->Documentos = $importar->documentos;
                    }
                }
            }
        } else if ($data->excel && !$data->fulfillment) {
            $ventas = $data->data;
            foreach ($ventas as $venta) {
                $existe = DB::select("SELECT * FROM documento WHERE no_venta = " . $venta->orden);

                if ($existe) {
                    $venta->Error = 1;
                    $venta->purchaseOrderId = "N/A";
                    $venta->customerOrderId = $venta->orden;
                    $venta->ErrorMessage = "Ya existe la venta en CRM.";
                    $venta->Documentos = implode(',', array_column($existe, 'id'));
                } else {
                    $info_venta = WalmartService::venta($venta->orden, $marketplace_area->id);

                    $importar = WalmartService::importarVentaIndividual($info_venta->data, $marketplace_area->id, 0);
                    $venta->Error = $importar->error;
                    $venta->purchaseOrderId = "N/A";
                    $venta->customerOrderId = $venta->orden;
                    $venta->ErrorMessage = $importar->mensaje;
                    if ($importar->error) {
                        $venta->Documentos = "";
                    } else {
                        $venta->Documentos = $importar->documentos;
                    }
                }
            }
        } else {
            $ventas = WalmartService::importarVentas($marketplace_area->id, $data);
        }

        return response()->json([
            "code" => 200,
            "message" => "Proceso terminado",
            "data" => $ventas->data ?? $ventas,
        ]);
    }

    public function venta_liverpool_importar_ventas(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $marketplace_area =
            DB::table('marketplace_area')
                ->select('id')
                ->where('id_area', $data->area)
                ->where('id_marketplace', $data->marketplace)
                ->first();

        $importar = LiverpoolService::importar_ventas($data->data, $marketplace_area->id, $data->almacen);

         
        return response()->json([
            "code" => 200,
            "message" => "Proceso terminado",
            "data" => $importar,
        ]);
    }

    public function venta_liverpool_getData()
    {
        $empresas = DB::table("empresa")->where("status", 1)->whereIn("id", [1, 2])->get();

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        almacen.id AS id_almacen,
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ASC");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code'  => 200,
            'empresas'  => $empresas,
            'almacenes' => $almacenes,
        ]);
    }

    /* Rawinfo */
    private function publicaciones_raw_data($usuario, $query_extra = "")
    {
        $marketplaces = DB::select("SELECT
                                        marketplace_area.id,
                                        CONCAT(area.area, ' / ', marketplace.marketplace) AS marketplace,
                                        marketplace_api.extra_1,
                                        marketplace_api.extra_2
                                    FROM usuario_marketplace_area 
                                    INNER JOIN marketplace_area ON usuario_marketplace_area.id_marketplace_area = marketplace_area.id
                                    LEFT JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                    INNER JOIN area ON marketplace_area.id_area = area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE usuario_marketplace_area.id_usuario = " . $usuario . "
                                    AND marketplace.marketplace IN ('MERCADOLIBRE', 'AMAZON')");

        foreach ($marketplaces as $marketplace) {
            $publicaciones = DB::select("SELECT
                                            marketplace_publicacion.id,
                                            marketplace_publicacion.publicacion_id,
                                            marketplace_publicacion.publicacion_sku AS sku,
                                            marketplace_publicacion.publicacion,
                                            marketplace_publicacion.total,
                                            IF(marketplace_publicacion.status = 'active', 1, 0) AS status,
                                            marketplace_publicacion.tee,
                                            marketplace_publicacion.tipo,
                                            marketplace_publicacion.logistic_type,
                                            marketplace_publicacion.tienda,
                                            marketplace_publicacion.id_almacen_empresa AS almacen,
                                            marketplace_publicacion.id_almacen_empresa_fulfillment AS almacen_fulfillment,
                                            marketplace_publicacion.url,
                                            marketplace_publicacion.id_proveedor AS proveedor,
                                            marketplace_publicacion.id_marketplace_area,
                                            (
                                                SELECT
                                                    empresa.bd
                                                FROM empresa_almacen
                                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                WHERE empresa_almacen.id = almacen
                                            ) AS empresa_almacen_principal,
                                            (
                                                SELECT
                                                    empresa.bd
                                                FROM empresa_almacen
                                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                WHERE empresa_almacen.id = almacen_fulfillment
                                            ) AS empresa_almacen_secundario
                                        FROM marketplace_publicacion
                                        WHERE marketplace_publicacion.id_marketplace_area = " . $marketplace->id . "
                                        " . $query_extra . "");

            foreach ($publicaciones as $publicacion) {
                $publicacion->productos = DB::select("SELECT
                                            modelo.sku,
                                            modelo.descripcion,
                                            marketplace_publicacion_producto.id,
                                            marketplace_publicacion_producto.garantia,
                                            marketplace_publicacion_producto.cantidad,
                                            marketplace_publicacion_producto.cantidad AS cantidad_envio,
                                            marketplace_publicacion_producto.regalo,
                                            marketplace_publicacion_producto.etiqueta
                                        FROM marketplace_publicacion_producto
                                        INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                        WHERE marketplace_publicacion_producto.id_publicacion = " . $publicacion->id . "");

                $publicacion->variaciones = DB::select("SELECT id, id_etiqueta, valor, cantidad FROM marketplace_publicacion_etiqueta WHERE id_publicacion = " . $publicacion->id . "");
                $publicacion->competencias = DB::select("SELECT * FROM marketplace_publicacion_competencia WHERE id_publicacion = " . $publicacion->id . " ORDER BY precio ASC");
                $publicacion->competencia = empty($publicacion->competencias) ? "Sín competencia" : "$ " . $publicacion->competencias[0]->precio_cambiado;
                $publicacion->ofertas = DB::select("SELECT id, precio, inicio, final, promocion FROM marketplace_publicacion_oferta WHERE id_publicacion = " . $publicacion->id . "");

                foreach ($publicacion->ofertas as $oferta) {
                    $fecha_inicio = strtotime(date("Y-m-d H:i:s"));
                    $fecha_final = strtotime($oferta->final);

                    $oferta->dias = round(($fecha_final - $fecha_inicio) / (60 * 60 * 24));
                }
            }

            $marketplace->publicaciones = $publicaciones;
        }

        return $marketplaces;
    }

    private function obtener_ventas($query_extra)
    {
        $ventas = DB::select("SELECT 
                                documento.id, 
                                documento.id_fase,
                                documento.id_periodo,
                                documento.id_marketplace_area,
                                documento.documento_extra,
                                documento.no_venta,
                                documento.pagado,
                                documento.modificacion,
                                documento.problema,
                                documento.status,
                                documento.fulfillment,
                                documento.mkt_created_at,
                                documento.mkt_publicacion,
                                documento.created_at, 
                                marketplace.marketplace, 
                                marketplace_area.publico,
                                area.area, 
                                paqueteria.paqueteria, 
                                usuario.nombre AS usuario,
                                documento_entidad.razon_social AS cliente,
                                documento_entidad.rfc,
                                documento_entidad.correo,
                                documento_fase.fase,
                                almacen.almacen
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            LEFT JOIN documento_guia ON documento.id = documento_guia.id_documento
                            WHERE documento.id_tipo = 2
                            AND documento.status = 1
                            " . $query_extra . "");

        foreach ($ventas as $venta) {
            $total = 0;
            $tiene_publicacion = DB::select("SELECT tee FROM marketplace_publicacion WHERE publicacion_id = '" . $venta->mkt_publicacion . "'");
            $venta->dias_restantes = 0;

            if (!empty($tiene_publicacion)) {
                $fecha_entrega = date('Y-m-d', strtotime($venta->mkt_created_at . ' + ' . $tiene_publicacion[0]->tee . ' days'));

                $fecha_actual = time();
                $fecha_entrega = strtotime($fecha_entrega);
                $diferencia = $fecha_entrega - $fecha_actual;

                $venta->dias_restantes = floor($diferencia / (60 * 60 * 24));
            }

            $direccion = DB::select("SELECT
                                        *
                                    FROM documento_direccion
                                    WHERE id_documento = " . $venta->id . "");

            $productos = DB::select("SELECT 
                                    movimiento.id,
                                    movimiento.cantidad, 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    modelo.serie,
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

            foreach ($productos as $producto) {
                $total += round((int) $producto->cantidad * (float) $producto->precio, 2);
            }

            $archivos = DB::select("SELECT
                                        usuario.id,
                                        usuario.nombre AS usuario,
                                        documento_archivo.nombre AS archivo,
                                        documento_archivo.dropbox
                                    FROM documento_archivo
                                    INNER JOIN usuario ON documento_archivo.id_usuario = usuario.id
                                    WHERE documento_archivo.id_documento = " . $venta->id . " AND documento_archivo.status = 1");

            $seguimiento = DB::select("SELECT seguimiento.*, usuario.nombre FROM seguimiento INNER JOIN usuario ON seguimiento.id_usuario = usuario.id WHERE id_documento = " . $venta->id . "");

            $venta->seguimiento = $seguimiento;
            $venta->productos   = $productos;
            $venta->direccion   = (empty($direccion)) ? 0 : $direccion[0];
            $venta->archivos    = $archivos;
            $venta->total       = $total;
        }

        return $ventas;
    }

    private function cotizar_paqueteria($paqueteria, $array)
    {
        $message = "";

        $cotizar = \Httpful\Request::post('http://apipaqueterias.crmomg.mx/api/' . $paqueteria . '/Cotizar')
            ->addHeader('authorization', 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjA4Njk2YjQxODczZTM4YTRkM2E1NjBjNTI2YTY3NmRhZmQ0YWNmOWUzNTFmNTkxZWM2NTIyOGRiOTJmODBlMmE0ZjNhNWJlZjcyNjg1ODQ3In0.eyJhdWQiOiIxIiwianRpIjoiMDg2OTZiNDE4NzNlMzhhNGQzYTU2MGM1MjZhNjc2ZGFmZDRhY2Y5ZTM1MWY1OTFlYzY1MjI4ZGI5MmY4MGUyYTRmM2E1YmVmNzI2ODU4NDciLCJpYXQiOjE1NTIwNzEzNzksIm5iZiI6MTU1MjA3MTM3OSwiZXhwIjoxNTgzNjkzNzc5LCJzdWIiOiIxIiwic2NvcGVzIjpbXX0.h6nCD2mqyp-QkXBd73BEM3kiwzry9JZJ5I35VJAcYesJc_kup3zVKrRIKz0wSsFt5zIGzg91LCnxgyr78GyIBpBkH-TVzzGSjTVNf5uFZhWGUVINM7SsKtbCHHyrUhOdOI2zgfO4_BIvkafnw_mYIgULMcwQU1w5G7XXnWeYaIxIooLEkw0TmtT0pD4aAYh9J15ak5cvVhTcoe-UO6XF63CkbAQmmeYpAjkxyLG_y5WIZIBXe5lhMYABjnKiBDlM6Ig8tcjXQ8egaLrAAsrRzFDwwzn23O3qTUo7pwxyHNn5guBXfaLpG7Pr5an-GslArwHApA6Ipdg4n5Qt4M42EHfO0KwrwAFMFHo1yDr3vcK7S05K_p9d9cAuZTZyYGo-39PUXcWoluR9W6KVA3X7tlZr3R8d7XLjDJ3Gp--tTmZpDsXKGLCh_uiFIHdmBXuN-eKbvrsxMnbgyK9EPY4PNbG35Ii6O6vZDVvfKia0tkpXof03uAs2OIcmlc5belFSpl7OHEO6HDXKfCmPjOMQFyxyAPrdEsJNJgz55tbdFPNZzpQSsWILVUgDq3pjpy503uyTS0NCvicIByBVwmOOXLm8l40dKhX-aaFOMiTzqxO0m9NJjoBwRsscUUYSXFOexYXGSiHXT4dqq_E4dAhEewKLSo1-RwwUtP0VGgmkiUo')
            ->body($array, \Httpful\Mime::FORM)
            ->send();

        $cotizar_res = @json_decode($cotizar->raw_body);

        if (empty($cotizar_res)) {
            return "No fue posible cotizar el envio, error desconocido.";
        }

        if ($cotizar_res->error == 1) {
            return "Ocurrió un error, favor de contactar al adminsitrador. Mensaje de error: " . $cotizar_res->mensaje;
        }

        $costo_total = (float) $cotizar_res->base + (float) $cotizar_res->extra;

        return "$ " . $costo_total;
    }

    private function areas_marketplaces($usuario, $marketplace)
    {
        $areas_cast = array();

        $areas = DB::table("area")
            ->where("status", 1)
            ->get()
            ->toArray();

        foreach ($areas as $i => $area) {
            $area->marketplaces = DB::table("marketplace_area")
                ->select("marketplace_area.id", "marketplace.marketplace", "marketplace_api.extra_2 AS pseudonimo", "marketplace_api.token AS marketplace_token")
                ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
                ->join("usuario_marketplace_area", "marketplace_area.id", "=", "usuario_marketplace_area.id_marketplace_area")
                ->leftJoin("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
                ->where("marketplace_area.id_area", $area->id)
                ->where("marketplace.marketplace", "LIKE", "%" . $marketplace . "%")
                ->where("marketplace_area.status", 1)
                ->where("usuario_marketplace_area.id_usuario", $usuario)
                ->get()
                ->toArray();

            if (!empty($area->marketplaces)) {
                array_push($areas_cast, $area);
            }
        }

        return $areas_cast;
    }

    public function venta_nota_autorizar_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $pendientes =
            DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'documento.no_venta')
            ->leftJoin('usuario as a', 'documento_nota_autorizacion.id_usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'documento_nota_autorizacion.id_autoriza', '=', 'b.id')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('estado', 1)
            ->where('modulo', 'Ventas')
            ->get()
            ->toArray();

        $terminados =
            DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'c.nombre as denegente', 'documento.no_venta')
            ->leftJoin('usuario as a', 'documento_nota_autorizacion.id_usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'documento_nota_autorizacion.id_autoriza', '=', 'b.id')
            ->leftJoin('usuario as c', 'documento_nota_autorizacion.id_rechaza', '=', 'c.id')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('estado', '!=', 1)
            ->where('modulo', 'Ventas')
            ->get()
            ->toArray();

        $personales = DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'documento.no_venta')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('documento_nota_autorizacion.estado', 1)
            ->where('modulo', 'Ventas')
            ->where('documento_nota_autorizacion.id_usuario', $auth->id)
            ->get()
            ->toArray();

        return response()->json([
            "code" => 200,
            "pendientes" => $pendientes,
            "terminados" => $terminados,
            "personales" => $personales
        ]);
    }

    public function venta_sin_venta_nota_autorizar_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $pendientes =
            DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'documento.no_venta')
            ->leftJoin('usuario as a', 'documento_nota_autorizacion.id_usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'documento_nota_autorizacion.id_autoriza', '=', 'b.id')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('estado', 1)
            ->where('modulo', 'Sin Venta')
            ->get()
            ->toArray();

        $terminados =
            DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'c.nombre as denegente', 'documento.no_venta')
            ->leftJoin('usuario as a', 'documento_nota_autorizacion.id_usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'documento_nota_autorizacion.id_autoriza', '=', 'b.id')
            ->leftJoin('usuario as c', 'documento_nota_autorizacion.id_rechaza', '=', 'c.id')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('estado', '!=', 1)
            ->where('modulo', 'Sin Venta')
            ->get()
            ->toArray();

        $personales = DB::table('documento_nota_autorizacion')
            ->select('documento_nota_autorizacion.*', 'documento.no_venta')
            ->leftJoin('documento', 'documento_nota_autorizacion.id_documento', '=', 'documento.id')
            ->where('documento_nota_autorizacion.estado', 1)
            ->where('modulo', 'Sin Venta')
            ->where('documento_nota_autorizacion.id_usuario', $auth->id)
            ->get()
            ->toArray();

        foreach ($pendientes as $key) {
            $key->data = Crypt::decrypt($key->data);
            $key->info = DB::table('documento')
                ->select('documento_entidad.id_erp', 'documento_entidad.rfc')
                ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
                ->where('documento.id', $key->id_documento)
                ->where('documento_entidad.tipo', 1)
                ->get()
                ->first();
        }
        foreach ($terminados as $key) {
            $key->data = Crypt::decrypt($key->data);
            $key->info = DB::table('documento')
                ->select('documento_entidad.id_erp', 'documento_entidad.rfc')
                ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
                ->where('documento.id', $key->id_documento)
                ->where('documento_entidad.tipo', 1)
                ->get()
                ->first();
        }
        foreach ($personales as $key) {
            $key->data = Crypt::decrypt($key->data);
            $key->info = DB::table('documento')
                ->select('documento_entidad.id_erp', 'documento_entidad.rfc')
                ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
                ->where('documento.id', $key->id_documento)
                ->where('documento_entidad.tipo', 1)
                ->get()
                ->first();
        }

        return response()->json([
            "code" => 200,
            "pendientes" => $pendientes,
            "terminados" => $terminados,
            "personales" => $personales
        ]);
    }

    public function venta_nota_autorizar_soporte_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $pendientes = DB::table('garantia_nota_autorizacion')
            ->select('garantia_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante')
            ->leftJoin('usuario as a', 'garantia_nota_autorizacion.usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'garantia_nota_autorizacion.autoriza', '=', 'b.id')
            ->where('estado', 1)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->toArray();

        $terminados =
            DB::table('garantia_nota_autorizacion')
            ->select('garantia_nota_autorizacion.*', 'a.nombre as solicitante', 'b.nombre as autorizante', 'c.nombre as denegente')
            ->leftJoin('usuario as a', 'garantia_nota_autorizacion.usuario', '=', 'a.id')
            ->leftJoin('usuario as b', 'garantia_nota_autorizacion.autoriza', '=', 'b.id')
            ->leftJoin('usuario as c', 'garantia_nota_autorizacion.rechaza', '=', 'c.id')
            ->where('estado', '!=', 1)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->toArray();

        $personales =
            DB::table('garantia_nota_autorizacion')
            ->select('*')
            ->where('usuario', $auth->id)
            ->where('estado', '!=', 1)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->toArray();


        foreach ($pendientes as $key) {
            $key->json = json_decode($key->json);
            $key->data = json_decode($key->data);
        }
        foreach ($terminados as $key) {
            $key->json = json_decode($key->json);
            $key->data = json_decode($key->data);
        }

        $tecnicos = Usuario::whereHas("subnivelesbynivel", function ($query) {
            return $query->where("id_nivel", UsuarioNivel::SOPORTE);
        })
            ->where("usuario.status", 1)
            ->get();

        $paqueterias = Paqueteria::get();
        $causas = DocumentoGarantiaCausa::get();

        return response()->json([
            "code" => 200,
            "pendientes" => $pendientes,
            "terminados" => $terminados,
            "personales" => $personales,
            "paqueterias" => $paqueterias,
            "causas" => $causas,
            "tecnicos" => $tecnicos
        ]);
    }

    public function venta_nota_autorizar_autorizado(Request $request)
    {

        $id = json_decode($request->input('id'));
        $documento = json_decode($request->input('documento'));
        $auth = json_decode($request->auth);

        DB::table('documento_nota_autorizacion')->where(['id' => $id])->update([
            'estado' => 2,
            'id_autoriza' => $auth->id,
            'authorized_at' => date("Y-m-d H:i:s")
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "<p>Se autoriza la creación de la nota de crédito</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Autorizado para continuar'
        ]);
    }
    public function venta_nota_autorizar_garantia_autorizado(Request $request)
    {

        $documento = json_decode($request->input('documento'));
        $garantia = json_decode($request->input('garantia'));
        $auth = json_decode($request->auth);

        DB::table('garantia_nota_autorizacion')->where(['documento' => $documento])->where(['documento_garantia' => $garantia])->where(['estado' => 1])->update([
            'estado' => 2,
            'autoriza' => $auth->id,
            'authorized_at' => date("Y-m-d H:i:s")
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "<p>Se autoriza la creación de la nota de crédito</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Autorizado, se coninua con el proceso. Nota de crédito se creará.'
        ]);
    }
    public function venta_nota_sin_venta_autorizar_autorizado(Request $request)
    {
        $id = json_decode($request->input('id'));
        $auth = json_decode($request->auth);

        DB::table('documento_nota_autorizacion')->where(['id' => $id])->update([
            'estado' => 2,
            'id_autoriza' => $auth->id,
            'authorized_at' => date("Y-m-d H:i:s")
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Autorizado para continuar'
        ]);
    }
    public function venta_nota_autorizar_rechazado(Request $request)
    {

        $id = json_decode($request->input('id'));
        $documento = json_decode($request->input('documento'));
        $auth = json_decode($request->auth);
        $motivo = json_decode($request->input('motivo'));

        DB::table('documento_nota_autorizacion')->where(['id' => $id])->update([
            'estado' => 3,
            'id_rechaza' => $auth->id,
            'denied_at' => date("Y-m-d H:i:s")
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "<p>Se rechaza la creación de la nota de crédito</p><p>Motivo de rechazo: " . $motivo . "</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Solicitud rechazada correctamente'
        ]);
    }
    public function venta_nota_autorizar_garantia_rechazado(Request $request)
    {
        $documento = json_decode($request->input('documento'));
        $garantia = json_decode($request->input('garantia'));
        $auth = json_decode($request->auth);
        $motivo = json_decode($request->input('motivo'));


        DB::table('garantia_nota_autorizacion')->where(['documento' => $documento])->where(['documento_garantia' => $garantia])->where(['estado' => 1])->update([
            'estado' => 3,
            'rechaza' => $auth->id,
            'denied_at' => date("Y-m-d H:i:s")
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "<p>Se rechaza la creación de la nota de crédito</p><p>Motivo de rechazo: " . $motivo . "</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Solicitud rechazada correctamente'
        ]);
    }
    public function venta_nota_sin_venta_autorizar_rechazado(Request $request)
    {
        $id = json_decode($request->input('id'));
        $auth = json_decode($request->auth);


        DB::table('documento_nota_autorizacion')->where(['id' => $id])->where(['estado' => 1])->update([
            'estado' => 3,
            'id_rechaza' => $auth->id,
            'denied_at' => date("Y-m-d H:i:s")
        ]);

        return response()->json([
            "code" => 200,
            "message" => 'Solicitud rechazada correctamente',
            'log' => $id
        ]);
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'VC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'NTA'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }

    public function venta_publicaciones_data(Request $request)
    {
        $auth = json_decode($request->auth);

        return response()->json([
            "data" => self::areas_publicaciones($auth->id)
        ]);
    }

    public function venta_publicaciones_crear(Request $request)
    {
        $data = json_decode($request->input("data"));
        $productos = json_decode($request->input("productos"));
        $auth = json_decode($request->auth);
        $response = new \stdClass();

        $validate_authy = DocumentoService::authy($auth->id, $data->authy_code);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje
            ], 500);
        }

        $existe_publicacion = DB::table('marketplace_publicacion')->where('publicacion_id', $data->item->asin)->get();

        if (!empty($existe_publicacion->first())) {
            return response()->json([
                "message" => 'Ya existe la publicación' . self::logVariableLocation()
            ], 500);
        }

        try {
            DB::beginTransaction();

            //insertar la publicación
            $id_publicacion = DB::table('marketplace_publicacion')->insertGetId([
                'id_marketplace_area' => $data->marketplace,
                'publicacion_id' => $data->item->asin,
                'publicacion' => $data->item->title,
                'logistic_type' => $data->item->shipping,
                'total' => $data->item->price,
                'status' => 'active',
            ]);

            foreach ($productos->products as $producto) {
                $existe = DB::table("modelo")
                    ->where("sku", $producto->sku)
                    ->first();

                if (!$existe) {
                    $modelo = DB::table("modelo")->firstOrUpdate([
                        "sku" => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                        "descripcion" => mb_strtoupper(trim($producto->description), 'UTF-8'),
                    ]);
                }

                $modelo = !$existe ? DB::table("modelo")->insertGetId([
                    "sku" => mb_strtoupper(trim($producto->sku), 'UTF-8'),
                    "descripcion" => mb_strtoupper(trim($producto->description), 'UTF-8'),
                ]) : $existe;

                DB::table('marketplace_publicacion_producto')->insert([
                    'id_publicacion' => $id_publicacion,
                    'id_modelo' => $modelo->id,
                    'garantia' => $producto->warranty,
                    'cantidad' => $producto->quantity,
                    'etiqueta' => $producto->variation,
                    'porcentaje' => $producto->percentage
                ]);
            }

            $item_data_before_update = [];

            $item_data_after_update = DB::table("marketplace_publicacion")
                ->where("id", $id_publicacion)
                ->first();

            $products_after_updating = DB::table("marketplace_publicacion_producto")
                ->where("id_publicacion", $id_publicacion)
                ->get()
                ->toArray();

            $item_data_after_update->products = $products_after_updating;

            DB::table("marketplace_publicacion")->where("id", $id_publicacion)->update([
                "id_proveedor" => $productos->provider,
                "id_almacen_empresa" => $productos->principal_warehouse,
                "id_almacen_empresa_fulfillment" => $productos->secondary_warehouse
            ]);

            DB::table("marketplace_publicacion_updates")->insert([
                "id_usuario" => $auth->id,
                "id_publicacion" => $id_publicacion,
                "old_data" => json_encode($item_data_before_update),
                "new_data" => json_encode($item_data_after_update)
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            $response->error = 1;
            $response->mensaje = "Hubo un problema en la transacción" . self::logVariableLocation() . ' ' . $e->getMessage();

            return response()->json([
                "message" => $response->mensaje
            ], 500);
        }

        $response->error = 0;
        $response->mensaje = "Publicación creada correctamente";

        return response()->json([
            "message" => $response->mensaje
        ], $response->error ? 500 : 200);
    }

    public function venta_marketplaces_autorizados_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $data =
            DB::table('marketplace_publicacion_marketplaces')
            ->select('marketplace_publicacion_marketplaces.*', 'marketplace.marketplace', 'area.area')
            ->join('marketplace_area', 'marketplace_publicacion_marketplaces.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->join('area', 'marketplace_area.id_area', '=', 'area.id')
            ->where('marketplace_publicacion_marketplaces.estatus', 1)
            ->get()->toArray();

        return response()->json([
            'code'  => 200,
            "data" => self::areas_marketplaces_publicaciones($auth->id),
            'marketplaces' => $data
        ]);
    }

    public function venta_marketplaces_autorizados_gestion(Request $request)
    {
        $data = json_decode($request->data);

        $marketplace = DB::table('marketplace')->where('marketplace', $data->marketplace)->pluck('id')->first();

        $id_marketplace_area = DB::table('marketplace_area')
            ->where('id_area', $data->area)
            ->where('id_marketplace', $marketplace)
            ->where('status', 1)
            ->pluck('id')
            ->first();

        switch ($data->option) {
            case 1:

                $existe = DB::table('marketplace_publicacion_marketplaces')->where('id_marketplace_area', $id_marketplace_area)->first();

                if (empty($existe)) {
                    DB::table('marketplace_publicacion_marketplaces')->insert([
                        'id_marketplace_area' => $id_marketplace_area,
                    ]);

                    return response()->json([
                        'code' => 200,
                        'message' => 'Se creó el registro correctamente'
                    ]);
                }

                if ($existe->estatus) {
                    return response()->json([
                        'code' => 300,
                        'message' => 'El Marketplace ya se encuentra activo',
                        'data' => $existe
                    ]);
                }

                DB::table('marketplace_publicacion_marketplaces')->where('id', $existe->id)->update([
                    'estatus' => 1,
                ]);

                return response()->json([
                    'code' => 200,
                    'message' => 'Se actualizó el registro correctamente'
                ]);

                break;

            case 2:
                $existe = DB::table('marketplace_publicacion_marketplaces')->where('id_marketplace_area', $data->extra)->first();

                if (empty($existe)) {

                    return response()->json([
                        'code' => 500,
                        'message' => 'No existe el registro'
                    ]);
                }

                if (!$existe->estatus) {
                    return response()->json([
                        'code' => 300,
                        'message' => 'El Marketplace ya se encuentra inactivo',
                        'data' => $existe
                    ]);
                }

                DB::table('marketplace_publicacion_marketplaces')->where('id', $existe->id)->update([
                    'estatus' => 0,
                ]);

                return response()->json([
                    'code' => 200,
                    'message' => 'Se actualizó el registro correctamente'
                ]);
                break;

            default:
                return response()->json([
                    'code'  => 500,
                    'message'   => 'hubo un error al procesar ' . self::logVariableLocation()
                ]);
                break;
        }
    }

    private function areas_publicaciones($usuario)
    {
        $areas_cast = array();

        $areas =
            DB::table('marketplace_publicacion_marketplaces')
            ->select('area.*')
            ->join('marketplace_area', 'marketplace_publicacion_marketplaces.id_marketplace_area',  'marketplace_area.id')
            ->join('area', 'area.id',  'marketplace_area.id_area')
            ->groupBy('area.area')
            ->where('marketplace_publicacion_marketplaces.estatus', 1)
            ->get()
            ->toArray();

        foreach ($areas as $i => $area) {
            $area->marketplaces = DB::table('marketplace_publicacion_marketplaces')
                ->select('marketplace_area.id', 'marketplace.marketplace', 'marketplace_api.extra_2')
                ->join('marketplace_area', 'marketplace_publicacion_marketplaces.id_marketplace_area',  'marketplace_area.id')
                ->join('marketplace', 'marketplace_area.id_marketplace',  'marketplace.id')
                ->leftJoin('marketplace_api', 'marketplace_area.id',  'marketplace_api.id_marketplace_area')
                ->join('usuario_marketplace_area', 'marketplace_area.id',  'usuario_marketplace_area.id_marketplace_area')
                ->where('usuario_marketplace_area.id_usuario',  $usuario)
                ->where('marketplace_area.status',  1)
                ->where('marketplace_area.id_area',  $area->id)
                ->where('marketplace_publicacion_marketplaces.estatus',  1)
                ->get()
                ->toArray();

            if (!empty($area->marketplaces)) {
                array_push($areas_cast, $area);
            }
        }

        return $areas_cast;
    }

    private function areas_marketplaces_publicaciones($usuario)
    {
        $areas_cast = array();

        $areas = DB::table("area")
            ->where("status", 1)
            ->get()
            ->toArray();

        foreach ($areas as $i => $area) {
            $area->marketplaces = DB::table("marketplace_area")
                ->select("marketplace_area.id", "marketplace.marketplace", "marketplace_api.extra_2 AS pseudonimo")
                ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
                ->join("usuario_marketplace_area", "marketplace_area.id", "=", "usuario_marketplace_area.id_marketplace_area")
                ->leftJoin("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
                ->where("marketplace_area.id_area", $area->id)
                ->where("marketplace_area.status", 1)
                ->where("usuario_marketplace_area.id_usuario", $usuario)
                ->get()
                ->toArray();

            if (!empty($area->marketplaces)) {
                array_push($areas_cast, $area);
            }
        }

        return $areas_cast;
    }

    public function venta_amazon_publicaciones_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $areas = self::areas_marketplaces($auth->id, "AMAZON");

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.empresa", "empresa.bd")
            ->join(
                "usuario_empresa",
                "empresa.id",
                "=",
                "usuario_empresa.id_empresa"
            )
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->where("empresa.id", "<>", 0)
            ->get()
            ->toArray();

        foreach ($empresas as $empresa) {
            $empresa->almacenes = DB::table("empresa_almacen")
                ->select("empresa_almacen.id", "almacen.almacen")
                ->join("almacen", "empresa_almacen.id_almacen", "=", "almacen.id")
                ->where("empresa_almacen.id_empresa", $empresa->id)
                ->where(
                    "almacen.id",
                    "<>",
                    0
                )
                ->get()
                ->toArray();
        }

        $proveedores = DB::table("modelo_proveedor")
            ->select("id", "razon_social")
            ->where("status", 1)
            ->get()
            ->toArray();

        return response()->json([
            "areas" => $areas,
            "proveedores" => $proveedores,
            "empresas" => $empresas
        ]);
    }

    public function venta_claroshop_publicaciones_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $areas = self::areas_marketplaces($auth->id, "CLAROSHOP");

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.empresa", "empresa.bd")
            ->join(
                "usuario_empresa",
                "empresa.id",
                "=",
                "usuario_empresa.id_empresa"
            )
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->where("empresa.id", "<>", 0)
            ->get()
            ->toArray();

        foreach ($empresas as $empresa) {
            $empresa->almacenes = DB::table("empresa_almacen")
                ->select("empresa_almacen.id", "almacen.almacen")
                ->join("almacen", "empresa_almacen.id_almacen", "=", "almacen.id")
                ->where("empresa_almacen.id_empresa", $empresa->id)
                ->where(
                    "almacen.id",
                    "<>",
                    0
                )
                ->get()
                ->toArray();
        }

        $proveedores = DB::table("modelo_proveedor")
            ->select("id", "razon_social")
            ->where("status", 1)
            ->get()
            ->toArray();

        return response()->json([
            "areas" => $areas,
            "proveedores" => $proveedores,
            "empresas" => $empresas
        ]);
    }
    private function ordenes_generar_pdf($documento, $auth)
    {
        $response = new \stdClass();

        $informacion_documento = DB::select("SELECT
                                                documento.id,
                                                documento.info_extra,
                                                documento.tipo_cambio,
                                                documento.created_at,
                                                documento_periodo.periodo_en,
                                                documento_entidad.rfc,
                                                documento_entidad.razon_social,
                                                moneda.moneda,
                                                usuario.firma,
                                                empresa.logo_odc
                                            FROM documento
                                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                            INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                                            INNER JOIN moneda ON documento.id_moneda = moneda.id
                                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                                            WHERE documento.id = " . $documento . "");

        if (empty($informacion_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento para generar el PDF.";

            return $response;
        }

        $productos = DB::table("movimiento")
            ->select("modelo.sku AS codigo", "modelo.np", "modelo.descripcion", "movimiento.comentario", "movimiento.cantidad", "movimiento.precio AS costo", "movimiento.cantidad", "movimiento.descuento")
            ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
            ->where("movimiento.id_documento", $documento)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron productos de la ODC.";

            return $response;
        }

        $informacion_documento = $informacion_documento[0];
        $informacion_documento->info_extra = json_decode($informacion_documento->info_extra);
        $impuesto = "1." . $informacion_documento->info_extra->impuesto;

        $pdf = app('FPDF');

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetTextColor(69, 90, 100);
        $pdf->Cell(110, 35, "PURCHASE ORDER");

        if ($informacion_documento->logo_odc != 'N/A') {
            $pdf->Image($informacion_documento->logo_odc, 5, 0, 70, 25, 'png');
        }

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 5, "Date", 1, false, 'C');
        $pdf->Cell(40, 5, "P.O #", 1, false, 'C');
        $pdf->Ln();

        $pdf->Cell(110, 8, "");
        $pdf->Cell(40, 8, date('d/m/Y', strtotime($informacion_documento->created_at)), 1, false, 'C');
        $pdf->Cell(40, 8, $informacion_documento->id, 1, false, 'C');
        $pdf->Ln(20);

        $pdf->Cell(25, 8, "Supplier", 1, false, 'L');
        $pdf->Cell(95, 8, $informacion_documento->razon_social, 1, false, 'L');
        $pdf->Ln();
        $pdf->Cell(95, 8, "Bill To", 1, false, 'L');
        $pdf->Cell(95, 8, "Ship To", 1, false, 'L');
        $pdf->Ln(8);

        $billto_breaks = explode("\n", $informacion_documento->info_extra->billto);
        $shipto_breaks = explode("\n", $informacion_documento->info_extra->shipto);

        $current_heigth_ship_bill = 0;
        $current_height_product = 38;

        if (count($billto_breaks) > count($shipto_breaks)) {
            foreach ($billto_breaks as $index => $billto) {
                $pdf->Cell(95, 5, substr($billto, 0, 50), 'LR', false, 'L');
                $pdf->Cell(95, 5, isset($shipto_breaks[$index]) ? substr($shipto_breaks[$index], 0, 50) : '', 'LR', false, 'L');
                $pdf->Ln();

                $current_heigth_ship_bill += 5;
                $current_height_product += 5;
            }
        } else {
            foreach ($shipto_breaks as $index => $shipto) {
                $pdf->Cell(95, 5, isset($billto_breaks[$index]) ? substr($billto_breaks[$index], 0, 50) : '', 'LR', false, 'L');
                $pdf->Cell(95, 5, substr($shipto, 0, 50), 'LR', false, 'L');
                $pdf->Ln();

                $current_heigth_ship_bill += 5;
                $current_height_product += 5;
            }
        }

        $pdf->Cell(95, 30 - $current_heigth_ship_bill, '', 'LBR', false, 'L');
        $pdf->Cell(95, 30 - $current_heigth_ship_bill, '', 'LBR', false, 'L');
        $pdf->Ln(5);

        $pdf->Cell(38, 7, "Sales Order #", 1, false, 'C');
        $pdf->Cell(38, 7, "Terms", 1, false, 'C');
        $pdf->Cell(38, 7, "Rep", 1, false, 'C');
        $pdf->Cell(38, 7, "Currency / Rate", 1, false, 'C');
        $pdf->Cell(38, 7, "Incoterm", 1, false, 'C');
        $pdf->Ln();

        $nombre_exploded = explode(" ", $auth->nombre);

        $pdf->Cell(38, 12, $informacion_documento->info_extra->invoice, 1, false, 'C');
        $pdf->Cell(38, 12, substr($informacion_documento->periodo_en, 0, 10), 1, false, 'C');
        $pdf->Cell(38, 12, substr($nombre_exploded[0], 0, 1) . (count($nombre_exploded) > 1 ? substr($nombre_exploded[1], 0, 1) : ""), 1, false, 'C');
        $pdf->Cell(38, 12, $informacion_documento->moneda . " / " . $informacion_documento->tipo_cambio, 1, false, 'C');
        $pdf->Cell(38, 12, $informacion_documento->info_extra->fob, 1, false, 'C');
        $pdf->Ln(15);

        $product_qty_height = 10;
        $product_code_height = 25;
        $product_description_height = 81;
        $product_cost_height = 24;
        $product_discount_height = 25;
        $product_total_height = 25;

        $pdf->Cell($product_qty_height, 7, "Qty", 1, false, 'C');
        $pdf->Cell($product_code_height, 7, "Item Code", 1, false, 'C');
        $pdf->Cell($product_description_height, 7, "Description", 1, false, 'C');
        $pdf->Cell($product_cost_height, 7, "Price Each", 1, false, 'C');
        $pdf->Cell($product_discount_height, 7, "Discount", 1, false, 'C');
        $pdf->Cell($product_total_height, 7, "Amount", 1, false, 'C');
        $pdf->Ln();

        $current_height_product += 35;

        $total = 0;
        $total_discount = 0;

        $pdf->SetFont('Arial', '', 6);

        foreach ($productos as $producto) {
            $producto->descripcion = $producto->comentario;
            $largo_descripcion = strlen($producto->descripcion);

            $pdf->Cell($product_qty_height, 5, $producto->cantidad, 'LR', false, 'C');
            $pdf->Cell($product_code_height, 5, substr($producto->codigo, 0, 20), 'LR', false, 'C');
            $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 0, 60), 'LR', false, 'C');
            $pdf->Cell($product_cost_height, 5, "$ " . number_format($producto->costo, 2, '.', ','), 'LR', false, 'C');
            $pdf->Cell($product_discount_height, 5, "$ " . number_format($producto->descuento > 0 ? ($producto->descuento * $producto->costo) / 100 : 0, 2, '.', ','), 'LR', false, 'C');
            $pdf->Cell($product_total_height, 5, "$ " . number_format((float) $producto->cantidad * (float) $producto->costo, 2, '.', ','), 'LR', false, 'C');
            $pdf->Ln();

            $current_height_product += 5;

            if ($largo_descripcion > 60) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, substr($producto->np, 0, 10), 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 60, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 120) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 120, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 180) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 180, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 240) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 240, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 300) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 300, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 360) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 360, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 420) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 420, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 480) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 480, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 540) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 540, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 600) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 600, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 660) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 660, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 720) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 720, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 780) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 780, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 840) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 840, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 900) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 900, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 960) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 960, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1020) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1020, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1080) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1080, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1140) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1140, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1200) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1200, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1260) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1260, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1380) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1380, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            //            if ($producto->np != 'N/A') {
            //                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_description_height, 5, $producto->np, 'LR', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
            //                $pdf->Ln();
            //
            //                $current_height_product += 5;
            //            }

            //            if ($current_height_product >= 260) {
            //                $pdf->Cell($product_qty_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_description_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Ln();
            //
            //                # $pdf->addPage();
            //
            //                $pdf->Cell($product_qty_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_description_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Ln();
            //
            //                # $current_height_product = 5;
            //            }

            $total += (float) $producto->cantidad * (float) $producto->costo;
            $total_discount += $producto->descuento > 0 ? (($producto->cantidad * (float) $producto->costo) * $producto->descuento / 100) : 0;
        }

        $total = $total * (float) $impuesto;

        $pdf->Cell($product_qty_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_code_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_description_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_cost_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_discount_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_total_height, 10, "", 'LBR', false, 'C');
        $pdf->Ln(15);

        $current_height_product += 15;

        $pdf->SetFont('Arial', '', 10);

        $pdf->Cell(130, 7, "Thanks you for your business", 1, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Subtotal", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total / (float) $impuesto, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        $pdf->Cell(130, 7, "Special Comments ", 1, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Tax", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total - ($total / (float) $impuesto), 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 0, 90), 0, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Total", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        if (strlen($informacion_documento->info_extra->comentarios) > 90) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 90, 90), 0, false, 'L');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, "Discount", "TLB", false, 'R');
            $pdf->Cell(30, 7, "$ " . number_format($total_discount, 2, '.', ','), "TRB", false, 'L');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Ln();
            $current_height_product += 5;
        } else {
            $pdf->Cell(130, 7, "", 0, false, 'L');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, "Discount", "TLB", false, 'R');
            $pdf->Cell(30, 7, "$ " . number_format($total_discount, 2, '.', ','), "TRB", false, 'L');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 180) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 180, 90), 0, false, 'L');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, "Total w discount", "TLB", false, 'R');
            $pdf->Cell(30, 7, "$ " . number_format($total - $total_discount, 2, '.', ','), "TRB", false, 'L');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Ln();
            $current_height_product += 5;
        } else {
            $pdf->Cell(130, 7, "", 0, false, 'L');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, "Total w discount", "TLB", false, 'R');
            $pdf->Cell(30, 7, "$ " . number_format($total - $total_discount, 2, '.', ','), "TRB", false, 'L');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 270) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 270, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 360) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 360, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 450) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 450, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 540) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 540, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 630) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 630, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 720) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 720, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 810) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 810, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 900) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 900, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 990) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 990, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 1080) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 1080, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 1170) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 1170, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if ($informacion_documento->firma != 'N/A') {
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY(90, 250); // position of text3
            $pdf->Write(0, 'Authorized By');

            $pdf->Image($informacion_documento->firma, 50, 250, 100, 40, 'png');
        }

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "INVOICE_" . $informacion_documento->info_extra->invoice . "_" . $informacion_documento->id . ".pdf";

        $response->error = 0;
        $response->data = base64_encode($pdf_data);
        $response->name = $file_name;

        return $response;
    }
}
