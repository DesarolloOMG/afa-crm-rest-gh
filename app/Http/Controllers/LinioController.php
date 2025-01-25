<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Crypt;
use App\Http\Services\LinioService;

class LinioController extends Controller
{
    public function rawinfo_linio_importar_ventas($marketplace_id, $tipo_importacion)
    {
        set_time_limit(0);

        $usuario_id = 1;

        if ($tipo_importacion != "dropoff" && $tipo_importacion != "fulfillment") {
            return response()->json("Importación incorrecta");
        }

        return (array) LinioService::importarVentas($marketplace_id, $tipo_importacion, $usuario_id);
    }

    public function rawinfo_linio_validar_ventas()
    {
        set_time_limit(0);

        $errores = array();

        $ventas = DB::select("SELECT
                                documento.id,
                                documento.no_venta,
                                documento.no_venta_btob,
                                documento.id_modelo_proveedor,
                                documento.fulfillment,
                                documento_entidad.rfc,
                                documento.id_marketplace_area
                            FROM documento
                            INNER JOiN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                            INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE documento.id_fase = 1
                            AND documento.status = 1
                            AND marketplace.marketplace = 'LINIO'");

        foreach ($ventas as $venta) {
            $response = LinioService::validarVenta($venta->id);

            if ($response->error) {
                array_push($errores, $response->mensaje);

                DB::table("seguimiento")->insert([
                    'id_documento' => $venta->id,
                    'id_usuario' => 1,
                    'seguimiento' => "Mensaje al validar la venta -> " . $response->mensaje
                ]);

                continue;
            }

            DB::table('documento')->where(['id' => $venta->id])->update([
                'id_almacen_principal_empresa' => $response->venta->almacen,
                'id_paqueteria' => $response->venta->paqueteria_id,
            ]);

            DB::table('movimiento')->where(['id_documento' => $venta->id])->delete();

            $total_pago = 0;

            $response->productos_documento = array();

            foreach ($response->productos_publicacion as $producto) {
                $existe_en_arreglo = false;

                foreach ($response->productos_documento as $producto_documento) {
                    if ($producto_documento->id_modelo == $producto->id_modelo) {
                        $existe_en_arreglo = true;

                        $producto_documento->cantidad += $producto->cantidad;

                        break;
                    }
                }

                if (!$existe_en_arreglo) array_push($response->productos_documento, $producto);
            }

            foreach ($response->productos_documento as $producto) {
                $codigo = DB::select("SELECT sku FROM modelo WHERE id = " . $producto->id_modelo . "")[0]->sku;

                if ($venta->id_modelo_proveedor != 0) {
                    $existe_relacion_btob = DB::table("modelo_proveedor_producto")
                        ->where("id_modelo_proveedor", $venta->id_modelo_proveedor)
                        ->where("id_modelo", $producto->id_modelo)
                        ->first();

                    if (!$existe_relacion_btob) {
                        array_push($errores, "No existe del relación del codigo " . $codigo . " con el proveedor B2B " . $venta->id);

                        DB::table("seguimiento")->insert([
                            'id_documento' => $venta->id,
                            'id_usuario' => 1,
                            'seguimiento' => "Mensaje al validar la venta -> No existe del relación del codigo " . $codigo . " con el proveedor B2B " . $venta->id
                        ]);

                        continue 2;
                    }
                } else {
                    $existencia = DocumentoService::existenciaProducto($codigo, $response->almacen); # Solo almacén de vidriera y emperesa OMG

                    if ($existencia->error) {
                        array_push($errores, $existencia->mensaje . ", error en el pedido " . $venta->id);

                        DB::table("seguimiento")->insert([
                            'id_documento' => $venta->id,
                            'id_usuario' => 1,
                            'seguimiento' => "Mensaje al validar la venta -> " . $existencia->mensaje . ", error en el pedido " . $venta->id
                        ]);

                        continue 2;
                    }

                    if ($existencia->existencia < $producto->cantidad) {
                        array_push($errores, "No hay suficiente existencia del producto " . $codigo . " para procesar el pedido " . $venta->id);

                        DB::table("seguimiento")->insert([
                            'id_documento' => $venta->id,
                            'id_usuario' => 1,
                            'seguimiento' => "Mensaje al validar la venta -> " . "No hay suficiente existencia del producto " . $codigo . " para procesar el pedido " . $venta->id
                        ]);

                        continue 2;
                    }
                }

                DB::table('movimiento')->insertGetId([
                    'id_documento' => $venta->id,
                    'id_modelo' => $producto->id_modelo,
                    'cantidad' => $producto->cantidad,
                    'precio' => (float) ($producto->precio) / 1.16,
                    'garantia' => $producto->garantia,
                    'modificacion' => '',
                    'regalo' => $producto->regalo
                ]);

                $total_pago += $producto->cantidad * $producto->precio;
            }

            $existe_pago = DB::select("SELECT id_pago FROM documento_pago_re WHERE id_documento = " . $venta->id . "");

            if (empty($existe_pago)) {
                $pago = DB::table('documento_pago')->insertGetId([
                    'id_usuario' => 1,
                    'id_metodopago' => 31,
                    'id_vertical' => 0,
                    'id_categoria' => 0,
                    'id_clasificacion' => 1,
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
            } else {
                DB::table('documento_pago')->where(['id' => $existe_pago[0]->id_pago])->update([
                    'id_usuario' => 1,
                    'id_metodopago' => 31,
                    'id_vertical' => 0,
                    'id_categoria' => 0,
                    'id_clasificacion' => 1,
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
            }

            if ($venta->id_modelo_proveedor != 0) {
                if ($venta->no_venta_btob == 'N/A') {
                    $tiene_archivos = DB::select("SELECT id FROM documento_archivo WHERE id_documento = " . $venta->id . " AND tipo = 2");

                    if (empty($tiene_archivos)) {
                        $marketplace_info = DB::select("SELECT
                                                            marketplace_area.id,
                                                            marketplace_api.extra_1,
                                                            marketplace_api.extra_2,
                                                            marketplace_api.app_id,
                                                            marketplace_api.secret
                                                        FROM marketplace_area
                                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                                        WHERE marketplace_area.id = " . $venta->id_marketplace_area . "");

                        try {
                            $marketplace_info->secret = Crypt::decrypt($marketplace_info->secret);
                        } catch (DecryptException $e) {
                            $marketplace_info->secret = "";
                        }

                        $guia = LinioService::documento(trim($venta->no_venta), $marketplace_info[0]);

                        if ($guia->error) {
                            array_push($errores, $guia->mensaje . ", en el pedido " . $venta->id);

                            DB::table("seguimiento")->insert([
                                'id_documento' => $venta->id,
                                'id_usuario' => 1,
                                'seguimiento' => "Mensaje al validar la venta -> " . $guia->mensaje . ", en el pedido " . $venta->id
                            ]);

                            continue;
                        }

                        try {
                            foreach ($guia->file as $index => $file) {
                                $nombre = "etiqueta_" . $venta->id . "_" . $index . ".pdf";

                                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $nombre . '" , "mode": "add", "autorename": true}')
                                    ->addHeader('Content-Type', 'application/octet-stream')
                                    ->body(base64_decode($file))
                                    ->send();

                                DB::table('documento_archivo')->insert([
                                    'id_documento' => $venta->id,
                                    'id_usuario' => 1,
                                    'id_impresora' => 1,
                                    'nombre' => $nombre,
                                    'dropbox' => $response->body->id,
                                    'tipo' => 2
                                ]);
                            }
                        } catch (Exception $e) {
                            array_push($errores, $e->getMessage() . ", en el pedido " . $venta->id);

                            DB::table("seguimiento")->insert([
                                'id_documento' => $venta->id,
                                'id_usuario' => 1,
                                'seguimiento' => "Mensaje al validar la venta -> " . $e->getMessage() . ", en el pedido " . $venta->id
                            ]);

                            continue;
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
                        array_push($errores, $crear_pedido_btob->mensaje . ", en el pedido " . $venta->id);

                        DB::table('seguimiento')->insert([
                            'id_documento' => $documento,
                            'id_usuario' => 1,
                            'seguimiento' => "<p>No fue posible crear la venta en el sistema del proveedor B2B, mensaje de error: " . $crear_pedido_btob->mensaje . "</p>"
                        ]);

                        continue;
                    }
                }
            }

            DB::table('documento')->where(['id' => $venta->id])->update([
                'id_fase' => $venta->fulfillment ? 6 : 3,
                'validated_at' => date("Y-m-d H:i:s")
            ]);

            DB::table('seguimiento')->insertGetId([
                "id_documento" => $venta->id,
                "id_usuario" => 1,
                "seguimiento" => "Documento actuali<ado automatico y masivamente"
            ]);
        }

        return $errores;
    }

    public function rawinfo_linio_validar_ventas_canceladas($marketplace_id)
    {
        set_time_limit(0);

        return (array) LinioService::validarVentasCanceladas($marketplace_id);
    }

    public function rawinfo_linio_informacion_venta($venta, $marketplace_id, $status)
    {
        set_time_limit(0);

        return (array) LinioService::informacionVenta($venta, $marketplace_id, $status);
    }

    public function rawinfo_linio_informacion_venta_id($venta, $marketplace_id)
    {
        set_time_limit(0);

        return (array) LinioService::informacionVenta($venta, $marketplace_id);
    }
}
