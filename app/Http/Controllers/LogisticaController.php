<?php

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\ClaroshopServiceV2;
use App\Http\Services\CoppelService;
use App\Http\Services\CorreoService;
use App\Http\Services\DropboxService;
use App\Http\Services\ElektraService;
use App\Http\Services\EnviaService;
use App\Http\Services\InventarioService;
use App\Http\Services\LinioService;
use App\Http\Services\MercadolibreService;
use App\Http\Services\ShopifyService;
use App\Http\Services\WalmartService;
use App\Models\Paqueteria;
use App\Models\Usuario;
use Crabbly\Fpdf\Fpdf;
use Exception;
use Httpful\Mime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mailgun\Mailgun;
use stdClass;
use Throwable;


class LogisticaController extends Controller
{
    /* Logistica > Envio */
    public function logistica_envio_pendiente_data(Request $request)
    {
        $auth = json_decode($request->auth);
        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

        $ventas = DB::select("SELECT 
                                documento.id, 
                                documento.documento_extra,
                                documento.no_venta,
                                documento.id_marketplace_area,
                                documento.id_paqueteria AS paqueteria,
                                documento.created_at, 
                                marketplace.marketplace, 
                                area.area, 
                                paqueteria.paqueteria AS paqueteria_text, 
                                usuario.nombre AS usuario,
                                documento_entidad.razon_social AS cliente
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN usuario_empresa ON empresa_almacen.id_empresa = usuario_empresa.id_empresa
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE documento.id_fase = 4
                            AND documento.problema = 0
                            AND documento.status = 1
                            AND usuario_empresa.id_usuario = " . $auth->id . "");

        foreach ($ventas as $venta) {
            $direccion = DB::select("SELECT
                                        *
                                    FROM documento_direccion
                                    WHERE id_documento = " . $venta->id . "");

            $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    movimiento.cantidad, 
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

            $archivos = DB::select("SELECT * FROM documento_archivo WHERE id_documento = " . $venta->id . " AND status = 1");

            $seguimiento = DB::select("SELECT
                                        seguimiento.*, 
                                        usuario.nombre 
                                    FROM seguimiento 
                                    INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                    WHERE id_documento = " . $venta->id . "");

            $venta->seguimiento = $seguimiento;
            $venta->productos   = $productos;
            $venta->archivos    = $archivos;
            $venta->direccion   = (empty($direccion)) ? 0 : $direccion[0];
        }

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas,
            'paqueterias'   => $paqueterias
        ]);
    }

    public function logistica_envio_pendiente_paqueteria($documento, $paqueteria)
    {
        DB::table('documento')->where(['id' => $documento])->update([
            'id_paqueteria' => $paqueteria
        ]);

        return response()->json([
            'code'  => 200
        ]);
    }

    public function logistica_envio_pendiente_regresar($documento, Request $request)
    {
        $auth = json_decode($request->auth);
        $informacion = DB::select("SELECT id_fase, status FROM documento WHERE id = " . $documento . "");

        if (empty($informacion)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró el documento seleccionado."
            ]);
        }

        $informacion = $informacion[0];

        if ($informacion->id_fase > 4) {
            return response()->json([
                "code" => 500,
                "message" => "El documento seleccionado ya fue finalizado, por lo cual no se puede regresar a la fase de Packing, favor de revisar en la búsqueda general."
            ]);
        }

        if (!$informacion->status) {
            return response()->json([
                "code" => 500,
                "message" => "El documento seleccionado se encuentra cancelado."
            ]);
        }

        $productos = DB::select("SELECT
                                    movimiento.id,
                                    modelo.serie
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        foreach ($productos as $producto) {
            if ($producto->serie) {
                $series = DB::select("SELECT
                                        movimiento_producto.id,
                                        movimiento_producto.id_producto
                                    FROM movimiento
                                    INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento.id = " . $producto->id . "");

                foreach ($series as $serie) {
                    DB::table('producto')->where(['id' => $serie->id_producto])->update(['status' => 1]);
                    DB::table('producto')->where(['id' => $serie->id])->delete();
                }
            }
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'id_fase' => 3
        ]);

        DB::table("seguimiento")->insert([
            'id_documento' => $documento,
            'id_usuario' => $auth->id,
            'seguimiento' => "Venta regresada a la fase de Packing"
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Documento regresado a Packing correctamente."
        ]);
    }

    public function logistica_envio_pendiente_documento($documento, $marketplace, $zpl = 0)
    {
        $marketplace_data = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_api.extra_2,
                                            marketplace_api.extra_1,
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = " . $marketplace . "")[0];

        $informacion = DB::select("SELECT
                                documento.no_venta,
                                paqueteria.paqueteria
                            FROM documento
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            WHERE documento.id = " . $documento . "")[0];

        switch (strtolower($marketplace_data->marketplace)) {
            case 'mercadolibre externo':
            case 'mercadolibre':
            $response = $zpl
                ? MercadolibreService::documentoZPL($informacion->no_venta, $marketplace_data)
                : MercadolibreService::documento($informacion->no_venta, $marketplace_data);

                break;

            case 'linio 2':
            case 'linio':
                try {
                    $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
                } catch (DecryptException $e) {
                    $marketplace_data->secret = "";
                }

                $response = LinioService::documento($informacion->no_venta, $marketplace_data);
                break;

                //!! RELEASE T1 reempalzar

            case 'claroshop':
            case 'sears':
            case 'sanborns':
                $response = ClaroshopServiceV2::documento($informacion->no_venta, $marketplace_data);
                break;

                // case 'claroshop':
                // case 'sears':
                //     try {
                //         $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
                //     } catch (DecryptException $e) {
                //         $marketplace_data->secret = "";
                //     }

                //     $response = ClaroshopService::documento($informacion->no_venta, $marketplace_data, strtolower($informacion->paqueteria));
                //     break;

            case 'walmart':
                $response = WalmartService::documento($documento, $marketplace_data->id);
                break;

            case 'coppel':
                $response = CoppelService::documento($documento, $marketplace_data->id);
                break;

            default:
                $response = new stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador.<br/> Error: LC249";

                break;
        }

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje,
                'raw'   => property_exists($response, 'raw') ? $response->raw : ''
            ]);
        }

        return response()->json([
            'code' => 200,
            'file' => $response->file,
            'pdf' => property_exists($response, 'pdf') ? $response->pdf : 1
        ]);
    }

    public function logistica_envio_pendiente_guia($guia)
    {
        $existe = DB::select("SELECT
                                documento_guia.id 
                            FROM documento_guia
                            INNER JOIN documento ON documento_guia.id_documento = documento.id
                            WHERE documento_guia.guia = '" . $guia . "'
                            AND documento.status = 1");

        return response()->json([
            'code'  => 200,
            'existe'    => empty($existe) ? 0 : 1
        ]);
    }

    public function logistica_envio_pendiente_guardar(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

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
                'message'   => "Seguimiento guardado correctamente"
            ]);
        }

        $info_documento = DB::select("SELECT id_fase, id_marketplace_area, status FROM documento where id = " . $data->documento . "")[0];

        if ($info_documento->id_fase > 4) {
            return response()->json([
                'code'  => 500,
                'message'   => "El pedido ya ha sido enviado."
            ]);
        }

        if (!$info_documento->status) {
            return response()->json([
                'code'  => 500,
                'message'   => "El documento está cancelado."
            ]);
        }

        foreach ($data->guias as $guia) {
            $existe_guia_paqueteria = DB::select("SELECT costo FROM paqueteria_guia WHERE guia = '" . $guia->guia . "'");

            $documento_guia = DB::table('documento_guia')->insertGetId([
                'id_documento'  => $data->documento,
                'guia'          => trim($guia->guia),
                'costo'         => (!empty($existe_guia_paqueteria)) ? $existe_guia_paqueteria[0]->costo : 0
            ]);

            if ($data->seguro) {
                if (!empty($guia[0]) && !empty($guia[1])) {
                    DB::table('documento_guia_seguro')->insert([
                        'id_documento_guia' => $documento_guia,
                        'contenido'         => $guia->contenido,
                        'total'             => $guia->total
                    ]);
                }
            }

            if ($data->manifiesto) {
                $existe_guia = DB::select("SELECT id FROM manifiesto WHERE guia = '" . $guia->guia . "'");

                if (empty($existe_guia)) {
                    $impresora_documento = DB::table("documento")
                        ->select("empresa_almacen.id_impresora_manifiesto")
                        ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
                        ->where("documento.id", $data->documento)
                        ->first();
                    $shiping = DB::table("documento")->select("id_paqueteria", "id_marketplace_area")->where("id", $data->documento)->first();

                    DB::table('manifiesto')->insert([
                        'id_impresora' => $impresora_documento->id_impresora_manifiesto,
                        'manifiesto' => date('dmY'),
                        'guia' => trim($guia->guia),
                        'id_paqueteria' => trim($shiping->id_paqueteria),
                        'id_marketplace_area' => $shiping->id_marketplace_area == 64 ? $shiping->id_marketplace_area : null,
                        'notificado' => $shiping->id_marketplace_area == 64 ? 0 : null
                    ]);
                }
            }
        }
        //Aqui ta
        $response = InventarioService::aplicarMovimiento($data->documento);

        if ($response->error) {
            DB::table('documento_guia')->where(['id_documento' => $data->documento])->delete();

            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje,
                'raw'       => property_exists($response, 'raw') ? $response->raw : 0,
                'data'      => property_exists($response, 'data') ? json_encode($response->data) : 0
            ]);
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_fase' => in_array($info_documento->id_marketplace_area, [14, 53, 4, 5]) ? 5 : 6, # ahora todas las ventas se pasan a facturas
            'shipping_date' => date('Y-m-d H:i:s')
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        $message = $response->mensaje;

        $marketplace_info = DB::select("SELECT
                                            documento.no_venta,
                                            marketplace_area.id,
                                            marketplace_api.extra_1,
                                            marketplace_api.extra_2,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace.marketplace
                                        FROM documento
                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE documento.id = " . $data->documento . "");

        if (!empty($marketplace_info)) {
            $marketplace_info = $marketplace_info[0];

            if (strpos($marketplace_info->marketplace, 'ELEKTRA') !== false) {
                $estado = ElektraService::cambiarEstado(trim($marketplace_info->no_venta), $marketplace_info, 2);

                if ($estado->error) {
                    $message .= " No fue posible cambiar el estado de la venta, favor de cambiar manual.";
                }
            }
        }

        return response()->json([
            'code'  => $response->error ? 500 : 200,
            'message'   => $message
        ]);
    }

    public function logistica_envio_firma_detalle($documento)
    {
        $informacion = DB::select("SELECT
                                    documento_entidad.razon_social AS cliente,
                                    documento.id_fase
                                FROM documento
                                INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                WHERE documento.id = " . $documento . "
                                AND documento.status = 1");

        if (empty($informacion)) {
            return response()->json([
                'code'  => 404,
                'message'   => "Documento no encontrado, favor de verificar que no esté cancelado"
            ]);
        }

        $informacion = $informacion[0];

        if ($informacion->id_fase < 4) {
            return response()->json([
                "code" => 500,
                "message" => "El pedido no ha sido surtido."
            ]);
        }

        $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    movimiento.cantidad, 
                                    ROUND((movimiento.precio * 1.16), 2) AS precio,
                                    movimiento.garantia
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $documento . "");

        $informacion->productos = $productos;

        return response()->json([
            'code'  => 200,
            'venta' => $informacion
        ]);
    }

    public function logistica_envio_firma_guardar(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $auth = json_decode($request->auth);
        $firma = $request->input('firma');
        $documento = $request->input('documento');
        $recoge = $request->input('recoge');
        $total = 0;

        try {
            $documento_fase = DB::table("documento")->where("id", $documento)->select("id_fase")->first();

            if (empty($documento_fase)) {
                return response()->json([
                    "code" => 200,
                    "message" => "No se encontró informacion del documento"
                ]);
            }

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
                                    WHERE documento.id = " . $documento . "");

            if (empty($cliente)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró información del cliente, favor de contactar a un administrador."
                ]);
            }

            $direccion = DB::select("SELECT
                                        *
                                    FROM documento_direccion 
                                    WHERE id_documento = " . $documento . "");

            if (empty($direccion)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontró la dirección del documento, favor de contactar a un administrador."
                ]);
            }

            $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    movimiento.cantidad, 
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $documento . "");

            if (empty($productos)) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "No se encontraron productos del documento, favor de contactar a un administrador."
                ]);
            }

            if ($documento_fase->id_fase < 6) {
                //Aqui ta
//                $response = DocumentoService::crearFactura($documento, 0, 0);

                $response = InventarioService::aplicarMovimiento($documento);

                if ($response->error) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => $response->mensaje
                    ]);
                }

                DB::table('documento')->where(['id' => $documento])->update([
                    'id_fase' => 6,
                    'shipping_date' => date('Y-m-d H:i:s')
                ]);
            }

            $cliente = $cliente[0];
            $direccion = $direccion[0];

            $firma_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $firma));
            $firma_name = uniqid() . ".png";
            file_put_contents($firma_name, $firma_data);

            foreach ($productos as $producto) {
                $total += (float) $producto->precio * (float) $producto->cantidad;
            }

            # Formato de celda: X -> margen izquierdo, Y -> Margen de arriba, Z -> Texto

            $pdf = new Fpdf();

            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(69, 90, 100);

            # Informacion de la empresa
            # OMG Logo
            $pdf->Image("img/omg.png", 5, 10, 60, 20, 'png');

            $pdf->Ln(30);
            $pdf->Cell(20, 10, 'OMG INTERNATIONAL SA DE CV');
            $pdf->Ln(5);
            $pdf->Cell(20, 10, 'Industria Maderera #226, Fracc. Industrial Zapopan Norte');
            $pdf->Ln(5);
            $pdf->Cell(20, 10, iconv('UTF-8', 'windows-1252', $cliente->nombre));
            $pdf->Ln(5);
            $pdf->Cell(20, 10, $cliente->email);

            # Información del cliente
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Ln(20);
            $pdf->Cell(100, 10, 'INFORMACION DEL CLIENTE');
            $pdf->Cell(10, 10, 'INFORMACION DE LA VENTA');

            setlocale(LC_ALL, "es_MX");

            $pdf->Ln(5);
            $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', mb_strtoupper($cliente->cliente, 'UTF-8')));
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 10, 'Fecha: ');

            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(10, 10, strftime("%A %d de %B del %Y"));

            $pdf->Ln(5);
            $pdf->Cell(100, 10, iconv('UTF-8', 'windows-1252', ($direccion->calle == "" && $direccion->numero == "") ? "-" : $direccion->calle . " " . $direccion->numero . ", " . $direccion->colonia . ", " . $direccion->ciudad . " " . $direccion->estado));

            $pdf->Ln(5);
            $pdf->Cell(100, 10, ($cliente->telefono == "" && $cliente->telefono_alt) ? "-" : ($cliente->telefono));

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 10, 'Documento: ');

            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(10, 10, $documento);

            $pdf->Ln(5);
            $pdf->Cell(100, 10, $cliente->correo);

            $pdf->Ln(20);

            $pdf->Cell(100, 10, "Descripcion", "T");
            $pdf->Cell(20, 10, "Cantidad", "T");
            $pdf->Cell(40, 10, "Precio unitario", "T");
            $pdf->Cell(30, 10, "Total", "T");
            $pdf->Ln();

            foreach ($productos as $producto) {
                $pdf->Cell(100, 10, substr($producto->descripcion, 0, 40), "T");
                $pdf->Cell(20, 10, $producto->cantidad, "T");
                $pdf->Cell(40, 10, "$ " . $producto->precio, "T");
                $pdf->Cell(30, 10, "$ " . round((float) $producto->precio * (float) $producto->cantidad, 2), "T");
                $pdf->Ln();

                if (strlen($producto->descripcion) > 40) {
                    $pdf->Cell(100, 10, substr($producto->descripcion, 40, 40), "T");
                    $pdf->Cell(20, 10, "");
                    $pdf->Cell(40, 10, "");
                    $pdf->Cell(30, 10, "");
                    $pdf->Ln();
                }

                $total += (float) $producto->precio * (float) $producto->cantidad;
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

            # FIrma de recibido

            $pdf->Ln(20);
            $pdf->Cell(80, 10, '');
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(10, 10, 'Firma de recibido');
            $pdf->SetFont('Arial', '', 10);

            $pdf->Ln();
            $pdf->Image($firma_name, 40, 190, 130, 40, 'png');

            $pdf->Ln(40);
            $pdf->Cell(190, 10, '', "T");

            $pdf->Ln(5);
            $pdf->Cell(190, 10, $recoge, "C");

            $pdf_name = uniqid() . ".pdf";
            $pdf_data = $pdf->Output($pdf_name, 'S');
            $file_name = "FIRMA_" . $cliente->cliente . "_" . uniqid() . ".pdf";

            $dropboxService = new DropboxService();
            $response = $dropboxService->uploadFile('/' . $file_name, $pdf_data, false);

            DB::table('documento_archivo')->insert([
                'id_documento' => $documento,
                'id_usuario'   => $auth->id,
                'nombre'       => $file_name,
                'dropbox'      => $response['id']
            ]);

            unlink($firma_name);


            return response()->json([
                'code'  => 200,
                'message'   => "Documento guardado correctamente."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code'  => 500,
                'message'   => "No fue posible generar el documento de firma del pedido, factura eliminada.<br><br>Favor de no intentar cerrar el pedido hasta que un administrador le indique, mensaje de error: " . $e->getMessage() . "."
            ]);
        }
    }

    /* Logistica > Manifiesto */
    public function logistica_manifiesto_manifiesto_data(Request $request)
    {
        $shipment = DB::table('paqueteria')
            ->select("id", "paqueteria")
            ->get()
            ->toArray();

        $printers = DB::table("impresora")
            ->select("id", "nombre")
            ->where("tamanio", "Continuo")
            ->get()
            ->toArray();

        $labels = self::manifiesto_guias_raw_data("salida = 0 AND impreso = 0");

        return response()->json([
            'labels' => $labels,
            'printers' => $printers,
            'shipment' => $shipment
        ]);
    }

    public function logistica_manifiesto_manifiesto_agregar(Request $request)
    {

        $data = json_decode($request->input("data"));

        $exits = DB::table("manifiesto")
            ->where("guia", $data->label)
            ->first();

        if ($exits) {
            if ($exits->manifiesto == date("dmY")) {
                return response()->json([
                    "message" => "La guía ya se encuentra en el manifiesto"
                ], 500);
            }

            DB::table('manifiesto')->where("guia", $data->label)->update([
                'id_impresora' => $data->printer,
                'manifiesto' => date('dmY'),
                'salida' => 0,
                'impreso' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'id_paqueteria' => $data->shipment
            ]);
        } else {
            DB::table('manifiesto')->insert([
                'id_impresora' => $data->printer,
                'manifiesto' => date('dmY'),
                'guia' => trim($data->label),
                'id_paqueteria' => $data->shipment
            ]);
        }

        $label_data = self::manifiesto_guias_raw_data("manifiesto.salida = 0 AND manifiesto.impreso = 0 AND manifiesto.guia = '" . $data->label . "'");

        return response()->json([
            "label" => $label_data
        ]);
    }

    public function logistica_manifiesto_manifiesto_eliminar(Request $request)
    {
        $data = $request->input("data");

        DB::table('manifiesto')->where(['guia' => trim($data)])->delete();

        return response()->json([
            'message' => "Guía eliminada correctamente."
        ]);
    }

    public function logistica_manifiesto_manifiesto_salida_data()
    {
        $printers = DB::table("impresora")
            ->select("id", "nombre", "servidor", "ip")
            ->where("tamanio", "continua")
            ->get()
            ->toArray();

        $labels = self::manifiesto_guias_raw_data("salida = 1 AND impreso = 0");

        $shipping_providers = DB::table("paqueteria")
            ->select("id", "paqueteria")
            ->where("manifiesto", 1)
            ->get()
            ->toArray();

        return response()->json([
            'labels' => $labels,
            'printers' => $printers,
            'shipping_providers' => $shipping_providers,

        ]);
    }

    public function logistica_manifiesto_manifiesto_salida_agregar(Request $request)
    {
        $data = $request->input("data");
        $paqueteria = $request->input("id_paqueteria");

        $exits = DB::table("manifiesto")
            ->where("guia", $data)
            ->first();

        if (empty($exits)) {
            return response()->json([
                'message' => "La guía no fue encontrada en el manifiesto, favor de agregarla y luego agregar su salida."
            ], 400);
        }

        if ($exits->manifiesto != date('dmY')) {
            DB::table('manifiesto')->where("guia", $data)->update([
                'manifiesto' => date('dmY'),
                'salida' => 0,
                'impreso' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $documento_guia = DB::table('documento_guia')->where('guia', $data)->first();
        if (!empty($documento_guia)) {
            $documento = DB::table('documento')->where('id', $documento_guia->id_documento)->first();
            if (!empty($documento)) {
                if (in_array($documento->id_marketplace_area, [1])) {
                    $informacion = MercadolibreService::venta($documento->no_venta, $documento->id_marketplace_area);
                    if ($informacion->error) {
                        return response()->json([
                            'message' => "No se encontro la guia en el Marketplace."
                        ], 500);
                    }

                    if (empty($informacion->data)) {
                        return response()->json([
                            'message' => "No hay informacion de la venta en el Marketplace."
                        ], 500);
                    }

                    $informacion->data = $informacion->data[0];

                    if ($informacion->data->status == "cancelled") {
                        DB::table('manifiesto')->where('guia', $data)->delete();

                        return response()->json([
                            'message' => "La guía no se encuentra activa, NO SURTIR, Guia quitada del manifiesto, Favor de cancelar el Pedido " . $documento->id
                        ], 500);
                    }
                }
            }
        }

        if ($exits->salida == 1) {
            return response()->json([
                'message' => "La guía ya está marcada para su salida."
            ], 500);
        }

        DB::table('manifiesto')->where(['guia' => $data])->update([
            'salida' => 1,
            'id_paqueteria' => $paqueteria
        ]);

        $label_data = self::manifiesto_guias_raw_data("manifiesto.salida = 1 AND manifiesto.impreso = 0 AND manifiesto.guia = '" . $data . "'");

        return response()->json([
            'label' => $label_data
        ]);
    }

    public function logistica_manifiesto_manifiesto_salida_imprimir(Request $request)
    {
        $data = json_decode($request->input("data"));

        $guias_paqueteria = array();

        $impresora_data = DB::table("impresora")
            ->where("ip", $data->printer)
            ->first();

        if ($data->type == '1' || $data->type == 1) {
            $guias = DB::select("SELECT
                                manifiesto.id,
                                manifiesto.guia,
                                paqueteria.paqueteria
                                FROM manifiesto
                            INNER JOIN paqueteria ON manifiesto.id_paqueteria = paqueteria.id
                            WHERE manifiesto.manifiesto = '" . date('dmY') . "'
                            AND manifiesto.salida = 1
                            AND manifiesto.impreso = 0
                            AND manifiesto.id_paqueteria = '" . $data->shipping_provider->id . "'
                            AND manifiesto.id_impresora = " . $impresora_data->id . "");
        }
        if ($data->type == '2' || $data->type == 2) {
            $guias = DB::select("SELECT
                                manifiesto.id,
                                manifiesto.guia,
                                paqueteria.paqueteria
                                FROM manifiesto
                            INNER JOIN paqueteria ON manifiesto.id_paqueteria = paqueteria.id
                            WHERE manifiesto.manifiesto = '" . date('dmY') . "'
                            AND manifiesto.salida = 1
                            AND manifiesto.impreso = 1
                            AND manifiesto.id_paqueteria = '" . $data->shipping_provider->id . "'
                            AND manifiesto.id_impresora = " . $impresora_data->id . "");
        }

        if (empty($guias)) {
            return response()->json([
                "code" => 500,
                "message" => "No hay guias agregadas al manifiesto de salida."
            ]);
        }

        foreach ($guias as $guia) {
            array_push($guias_paqueteria, $guia->guia);
        }

        CorreoService::enviarManifiesto($guias_paqueteria, 1, $data->shipping_provider->paqueteria, $data->server);

        return response()->json([
            "guias" => $guias_paqueteria
        ]);
    }

    /* Logistica > Control paqueteria */
    public function logistica_control_paqueteria_crear_data()
    {
        $usuarios = DB::select("SELECT id, nombre FROM usuario WHERE status = 1");
        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

        return response()->json([
            'code' => 200,
            'usuarios' => $usuarios,
            'paqueterias' => $paqueterias
        ]);
    }

    public function logistica_control_paqueteria_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $usuarios = [];

        if (!empty($data->notificados)) {
            foreach ($data->notificados as $notificado) {
                try {
                    $usuario = Usuario::find($notificado->id);

                    if ($usuario) {
                        if (filter_var($usuario->email, FILTER_VALIDATE_EMAIL)) {
                            $html = view('email.notificacion_control_paqueteria')->with(['data' => $data, 'anio' => date('Y')]);

                            $mg = Mailgun::create(config("mailgun.token"));
                            $mg->messages()->send(config("mailgun.domain"), array(
                                'from'  => config("mailgun.email_from"),
                                'to' => $usuario->email,
                                'subject' => '¡Te llegó un paquete!',
                                'html' => $html->render()
                            ));
                        }
                    }

                    array_push($usuarios, $notificado->id);
                } catch (Exception $e) {
                    return response()->json([
                        'code' => 500,
                        'message' => $e->getMessage()
                    ]);
                } catch (Throwable $e) {
                    return response()->json([
                        'code' => 500,
                        'message' => $e->getMessage()
                    ]);
                }
            }
        }

        DB::table('paqueteria_control')->insert([
            'id_paqueteria' => $data->paqueteria,
            'id_usuario' => $auth->id,
            'guia' => $data->guia,
            'cliente' => $data->cliente,
            'contenido' => $data->contenido,
            'observacione' => $data->observaciones,
            'notificados' => json_encode($data->notificados)
        ]);

        $notificacion['titulo'] = "Control paquetería";
        $notificacion['message'] = "Llegó un paquete para tí, puedes pasar por el al área de logistica.";
        $notificacion['tipo'] = "success"; // success, warning, danger

        $notificacion_id = DB::table('notificacion')->insertGetId([
            'data' => json_encode($notificacion)
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

    public function logistica_control_paqueteria_historial_data($fecha_inicial, $fecha_final)
    {
        $documentos = DB::select("SELECT
                                    paqueteria.paqueteria,
                                    usuario.nombre,
                                    paqueteria_control.guia,
                                    paqueteria_control.cliente,
                                    paqueteria_control.contenido,
                                    paqueteria_control.observacione,
                                    paqueteria_control.notificados,
                                    paqueteria_control.created_at
                                FROM paqueteria_control
                                INNER JOIN paqueteria ON paqueteria_control.id_paqueteria = paqueteria.id
                                INNER JOIN usuario ON paqueteria_control.id_usuario = usuario.id
                                AND paqueteria_control.created_at BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'");

        foreach ($documentos as $documento) {
            $documento->notificados = json_decode($documento->notificados);
        }
        $estados = DB::select("SELECT * FROM estados");


        return response()->json([
            'code'  => 200,
            'documentos'    => $documentos
        ]);
    }

    /* Logistica > guía */
    public function logistica_guia_crear_data()
    {
        $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE api = 1 AND status = 1");
        $estados = DB::select("SELECT * FROM estados");

        foreach ($paqueterias as $paqueteria) {
            $paqueteria->tipos = DB::select("SELECT id, codigo, tipo FROM paqueteria_tipo WHERE id_paqueteria = " . $paqueteria->id . "");

            foreach ($paqueteria->tipos as $tipo) {
                $tipo->subtipos = DB::select("SELECT id, codigo, subtipo FROM paqueteria_subtipo WHERE id_tipo = " . $tipo->id . "");
            }
        }

        return response()->json([
            "code" => 200,
            "paqueterias" => $paqueterias,
            'estados' => $estados
        ]);
    }

    public function logistica_guia_crear_data_documento($documento)
    {
        $informacion = DB::select("SELECT
                                        documento_direccion.*,
                                        paqueteria.paqueteria,
                                        documento.id_paqueteria,
                                        documento_entidad.razon_social AS cliente,
                                        documento_entidad.telefono,
                                        documento_entidad.telefono_alt,
                                        documento_entidad.correo
                                    FROM documento
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                    INNER JOIN documento_direccion ON documento.id = documento_direccion.id_documento
                                    WHERE documento.id = " . $documento . "
                                    AND documento.status = 1");

        if (empty($informacion)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró la información del documento, favor de contactar al adminsitrador"
            ]);
        }

        return response()->json([
            'code'  => 200,
            'informacion'   => $informacion[0]
        ]);
    }

    public function logistica_guia_crear_cotizar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $cotizar_estafeta = [
            'paquete' => array(
                'peso' => $data->peso,
                'dimensiones' => array(
                    'largo' => $data->largo,
                    'ancho' => $data->ancho,
                    'alto' => $data->alto
                )
            ),
            'servicio' => ($data->tipo_envio_cotizar == 1) ? "70" : "D0",
            'origen' => $data->info_remitente->direccion->cp,
            'destino' => $data->info_destinatario->direccion->cp,
        ];

        $cotizar_fedex = [
            'tipo'                      => ($data->tipo_envio_cotizar == 1) ? "FEDEX_EXPRESS_SAVER" : "STANDARD_OVERNIGHT",
            'destino'                   => $data->info_destinatario->direccion->ciudad,
            'peso'                      => $data->peso,
            'cp_ini'                    => $data->info_remitente->direccion->cp,
            'cp_end'                    => $data->info_destinatario->direccion->cp,
            'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
            'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
            'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
            'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
            'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
            'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
        ];

        $cotizar_dhl = [
            'destino'                   => $data->info_destinatario->direccion->ciudad,
            'peso'                      => $data->peso,
            'tipo'                      => ($data->tipo_envio_cotizar == 1) ? "G" : "N",
            'largo'                     => $data->largo,
            'ancho'                     => $data->ancho,
            'alto'                      => $data->alto,
            'cp_ini'                    => $data->info_remitente->direccion->cp,
            'cp_end'                    => $data->info_destinatario->direccion->cp,
            'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
            'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
            'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
            'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
            'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
            'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
        ];

        $cotizar_ups = [
            'service_code'              => ($data->tipo_envio_cotizar == 1) ? "65" : "07",
            'service_description'       => '',
            'packagingtype_code'        => '02',
            'peso'                      => $data->peso,
            'largo'                     => $data->largo,
            'ancho'                     => $data->ancho,
            'alto'                      => $data->alto,
            'shipper_postalcode'        => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
            'shipto_name'               => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
            'shipto_addressline1'       => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
            'shipto_addressline2'       => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
            'shipto_addressline3'       => mb_strtoupper($data->info_destinatario->direccion->direccion_3, 'UTF-8'),
            'shipto_postalcode'         => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
            'shipfrom_name'             => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
            'shipfrom_addressline1'     => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
            'shipfrom_addressline2'     => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
            'shipfrom_addressline3'     => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
            'shipfrom_postalcode'       => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
            'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
            'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
            'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
            'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
            'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
            'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
        ];

        $cotizar_paquetexpress = array(
            "typeservice" => ($data->tipo_envio_cotizar == 1) ? "STD-T" : "SEG-DS",
            "from" => array(
                "neighborhood" => $data->info_remitente->direccion->colonia,
                "zip_code" => $data->info_remitente->direccion->cp
            ),
            "to" => array(
                "neighborhood" => $data->info_destinatario->direccion->colonia,
                "zip_code" => $data->info_destinatario->direccion->cp
            ),
            "insurance" => $data->seguro,
            "packets" => array(
                "0" => array(
                    "weight" => $data->peso,
                    "depth" => $data->largo,
                    "width" => $data->ancho,
                    "height" => $data->alto
                )
            )
        );

        return response()->json([
            'code'  => 200,
            'estafeta' => $this->cotizar_paqueteria_raw('Estafeta', $cotizar_estafeta),
            'fedex' => $this->cotizar_paqueteria_raw('Fedex', $cotizar_fedex),
            'dhl' => $this->cotizar_paqueteria_raw('DHL', $cotizar_dhl),
            'ups' =>  $this->cotizar_paqueteria_raw('UPS', $cotizar_ups),
            'paquetexpress' => $this->cotizar_paqueteria_raw('Paquetexpress', $cotizar_paquetexpress),
        ]);
    }

    public function logistica_guia_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $paqueteria = DB::select("SELECT paqueteria FROM paqueteria WHERE id = '" . $data->paqueteria . "'");

        if (empty($paqueteria)) {
            return response()->json([
                'code'  => 500,
                'message'   => "Ocurrió un error al encontrar la paquetería, favor de contactar al adminsitrador"
            ]);
        }

        $paqueteria = $paqueteria[0];
        $email_usuario = DB::select("SELECT email FROM usuario WHERE id = " . $auth->id . "")[0]->email;

        $esShopy = false;

        if ($data->paqueteria < 100) {
            $cotizar_paqueteria = $this->cotizar_paqueteria_raw_data($data, $auth->nombre, $email_usuario);

            if ($cotizar_paqueteria->error) {
                return response()->json([
                    'code'  => 500,
                    'message' => $cotizar_paqueteria->mensaje,
                    'raw' => property_exists($cotizar_paqueteria, 'raw') ? $cotizar_paqueteria->raw : 0,
                    'data' => property_exists($cotizar_paqueteria, 'data') ? $cotizar_paqueteria->data : $data,
                ]);
            }

            $crear_guia = \Httpful\Request::post('http://paqueterias.crmomg.mx/api/' . $paqueteria->paqueteria . '/CrearGuia')
                ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
                ->body($cotizar_paqueteria->data, Mime::FORM)
                ->send();
        } else {
            $esShopy = true;
            $crear_guia = ShopifyService::crearGuiaEnvia($data, $auth->nombre, $email_usuario);
        }

        $crear_guia_raw = $crear_guia->raw_body;
        $crear_guia = @json_decode($crear_guia->raw_body);

        if (empty($crear_guia)) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible crear la guia, error desconocido.",
                'raw' => $crear_guia_raw,
                'data' => $esShopy ? "Error al crear guia en Envia" : $cotizar_paqueteria->data
            ]);
        }

        if (!property_exists($crear_guia, "code")) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible crear la guia, error desconocido.",
                'raw' => $crear_guia_raw,
                'data' => $esShopy ? "Error al crear guia en Envia" : $cotizar_paqueteria->data
            ]);
        }

        if ($crear_guia->code != 200) {
            return response()->json([
                'code' => 500,
                'message' => $crear_guia->mensaje,
                'data' => $esShopy ? "Error al crear guia en Envia" : $cotizar_paqueteria->data
            ]);
        }

        DB::table('paqueteria_guia')->insert([
            'id_documento' => $data->documento,
            'id_paqueteria' => $data->paqueteria,
            'id_usuario' => $auth->id,
            'guia' => $crear_guia->guia,
            'binario' => $crear_guia->binario,
            'costo' => $esShopy ? 0 : $cotizar_paqueteria->total,
            'seguro' => $data->seguro > 0 ? 1 : 0,
            'monto_seguro' => $data->seguro,
            'contenido' => $data->contenido,
            'numero_guias' => 1,
            'peso' => $data->peso,
            'tipo_envio' => $data->tipo_envio,
            'tipo_paquete' => $data->tipo_paquete,
            'largo' => $data->largo,
            'alto' => $data->alto,
            'ancho' => $data->ancho,
            'ori_empresa' => $data->info_remitente->empresa,
            'ori_contacto' => $data->info_remitente->contacto,
            'ori_celular' => $data->info_remitente->celular,
            'ori_telefono' => $data->info_remitente->telefono,
            'ori_direccion_1' => $data->info_remitente->direccion->direccion_1,
            'ori_direccion_2' => $data->info_remitente->direccion->direccion_2,
            'ori_direccion_3' => $data->info_remitente->direccion->direccion_3,
            'ori_referencia' => $data->info_remitente->direccion->referencia,
            'ori_colonia' => $data->info_remitente->direccion->colonia,
            'ori_ciudad' => $data->info_remitente->direccion->ciudad,
            'ori_estado' => $data->info_remitente->direccion->estado,
            'ori_cp' => $data->info_remitente->direccion->cp,
            'des_empresa' => $data->info_destinatario->empresa,
            'des_contacto' => $data->info_destinatario->contacto,
            'des_celular' => $data->info_destinatario->celular,
            'des_telefono' => $data->info_destinatario->telefono,
            'des_direccion_1' => $data->info_destinatario->direccion->direccion_1,
            'des_direccion_2' => $data->info_destinatario->direccion->direccion_2,
            'des_direccion_3' => $data->info_destinatario->direccion->direccion_3,
            'des_referencia' => $data->info_destinatario->direccion->referencia,
            'des_colonia' => $data->info_destinatario->direccion->colonia,
            'des_ciudad' => $data->info_destinatario->direccion->ciudad,
            'des_estado' => $data->info_destinatario->direccion->estado,
            'des_cp' => $data->info_destinatario->direccion->cp
        ]);

        $binario = $crear_guia->binario;

        return response()->json([
            'code' => 200,
            'message' => "Guía creada correctamente.",
            'binario' => $binario
        ]);
    }

    /* Logistica > seguro */
    public function logistica_seguro_data(Request $request)
    {
        $guias = DB::select("SELECT
                                documento.id AS id_documento,
                                documento_guia.guia,
                                documento_guia_seguro.id,
                                documento_guia_seguro.contenido,
                                documento_guia_seguro.total,
                                documento_guia_seguro.date_added
                            FROM documento
                            INNER JOIN documento_guia ON documento.id = documento_guia.id_documento
                            INNER JOIN documento_guia_seguro ON documento_guia.id = documento_guia_seguro.id_documento_guia
                            WHERE documento_guia_seguro.date_added LIKE '%" . date('Y-m-d') . "%'");

        return response()->json([
            'code'  => 200,
            'guias' => $guias
        ]);
    }

    public function logistica_seguro_documento()
    {
        $guias = DB::select("SELECT
                                documento.id AS id_documento,
                                documento_guia.guia,
                                documento_guia_seguro.contenido,
                                documento_guia_seguro.total,
                                documento_guia_seguro.date_added
                            FROM documento
                            INNER JOIN documento_guia ON documento.id = documento_guia.id_documento
                            INNER JOIN documento_guia_seguro ON documento_guia.id = documento_guia_seguro.id_documento_guia
                            WHERE documento_guia_seguro.date_added LIKE '%" . date('Y-m-d') . "%'");

        $total  = 0;

        $pdf = new Fpdf();

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->AddPage('L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(69, 90, 100);

        # Informacion de la empresa
        # OMG Logo
        $pdf->Image("img/omg.png", 5, 10, 60, 20, 'png');

        $pdf->Cell(170, 10, '');
        $pdf->Cell(30, 10, '');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(10, 10, strftime("%A %d de %B del %Y"));

        $pdf->ln(10);

        $pdf->SetFont('Arial', 'B', 15);
        $pdf->Cell(190, 60, 'SOLICITUD DE SEGURO OPCIONAL');

        $pdf->Ln(50);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(100, 10, "Guia", "T");
        $pdf->Cell(100, 10, "Contenido", "T");
        $pdf->Cell(40, 10, "Valor declarado", "T");
        $pdf->Cell(30, 10, "Venta", "T");
        $pdf->Ln();

        foreach ($guias as $guia) {
            $pdf->Cell(100, 10, $guia->guia, "T");
            $pdf->Cell(100, 10, (strlen($guia->contenido) > 40) ? substr($guia->contenido, 0, 40) . " .." : $guia->contenido, "T");
            $pdf->Cell(40, 10, "$ " . $guia->total, "T");
            $pdf->Cell(30, 10, $guia->id_documento, "T");
            $pdf->Ln();

            $total += (float) $guia->total;
        }

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(100, 10, "", "T");
        $pdf->Cell(100, 10, "Total: ", "T", 0, 'R');
        $pdf->Cell(40, 10, "$ " . $total, "T");
        $pdf->Cell(30, 10, "", "T");
        $pdf->Ln();

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "SEGURO_ESTAFETA_" . date('d-m-Y') . "_" . uniqid() . ".pdf";

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    public function logistica_seguro_guia($guia, $total)
    {
        DB::table('documento_guia_seguro')->where(['id' => $guia])->update([
            'total' => $total
        ]);

        return response()->json([
            'code'  => 200
        ]);
    }

    private function cotizar_paqueteria_raw($paqueteria, $array)
    {
        $cotizar = \Httpful\Request::post('http://paqueterias.crmomg.mx/api/' . $paqueteria . '/Cotizar')
            ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
            ->body($array, Mime::FORM)
            ->send();

        $cotizar_raw = $cotizar->raw_body;
        $cotizar_res = @json_decode($cotizar->raw_body);

        if (empty($cotizar_res)) {
            return "No fue posible cotizar las paqueterías, error desconocido." . $cotizar_raw;
        }

        if (!property_exists($cotizar_res, "error")) {
            return "Response json: " . json_encode($cotizar_res);
        }

        if ($cotizar_res->error == 1) {
            return "No fue posible cotizar las paqueterías, error " . $cotizar_res->mensaje;
        }

        $total = (float) $cotizar_res->base;

        if (property_exists($cotizar_res, "extra")) {
            $total += +(float) $cotizar_res->extra;
        }

        return "$ " . $total;
    }

    private function cotizar_paqueteria_raw_data($data, $creador, $email)
    {
        $response = new stdClass();

        $paqueteria = Paqueteria::find($data->paqueteria);

        switch ($paqueteria->paqueteria) {
            case 'Estafeta':
                $array = [
                    'contenido' => mb_strtoupper($data->contenido, 'UTF-8'),
                    'numero_guias' => 1,
                    'peso' => $data->peso,
                    'tipo_paquete' => mb_strtoupper($data->tipo_paquete, 'UTF-8'),
                    'tipo_envio' => mb_strtoupper($data->tipo_envio, 'UTF-8'),
                    "height" => $data->alto,
                    "length" => $data->largo,
                    "width" => $data->ancho,
                    "ensure" => $data->seguro,
                    'referencia' => mb_strtoupper($data->info_destinatario->direccion->referencia, 'UTF-8'),
                    'informacion_adicional' => mb_strtoupper($data->info_remitente->direccion->referencia, 'UTF-8'),
                    'empresa' => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'usuario' => mb_strtoupper($creador, 'UTF-8'),
                    'contacto' => mb_strtoupper($data->info_remitente->contacto, 'UTF-8'),
                    'telefono' => mb_strtoupper($data->info_remitente->telefono, 'UTF-8'),
                    'celular' => mb_strtoupper($data->info_remitente->celular, 'UTF-8'),
                    'origin_domicilio1' => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'origin_domicilio2' => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'colonia' => mb_strtoupper($data->info_remitente->direccion->colonia, 'UTF-8'),
                    'ciudad' => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
                    'estado' => mb_strtoupper($data->info_remitente->direccion->estado, 'UTF-8'),
                    'cp' => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    "calle" => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    "direccion_referencia" => mb_strtoupper($data->info_remitente->direccion->referencia, 'UTF-8'),
                    "externalnum" => "S/N",
                    'destino_empresa' => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
                    'destino_contacto' => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
                    'destino_telefono' => mb_strtoupper($data->info_destinatario->telefono, 'UTF-8'),
                    'destino_cellphone' => mb_strtoupper($data->info_destinatario->celular, 'UTF-8'),
                    'destino_domicilio1' => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    'destino_domicilio2' => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
                    'destino_colonia' => mb_strtoupper($data->info_destinatario->direccion->colonia, 'UTF-8'),
                    'destino_ciudad' => mb_strtoupper($data->info_destinatario->direccion->ciudad, 'UTF-8'),
                    'destino_estado' => mb_strtoupper($data->info_destinatario->direccion->estado, 'UTF-8'),
                    'destino_cp' => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
                    "destino_calle" => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    "destino_direccion_referencia" => mb_strtoupper($data->info_destinatario->direccion->referencia, 'UTF-8'),
                    "destino_externalnum" => "S/N",
                    "destino_correo" => $email,
                    'from_cord_exists' => $data->info_remitente->direccion->cord_found,
                    'from_lng' => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat' => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists' => $data->info_destinatario->direccion->cord_found,
                    'to_lng' => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat' => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                $cotizar = [
                    'paquete' => array(
                        'peso' => $data->peso,
                        'dimensiones' => array(
                            'largo' => $data->largo,
                            'ancho' => $data->ancho,
                            'alto' => $data->alto
                        )
                    ),
                    'servicio' => $data->tipo_envio,
                    'origen' => $data->info_remitente->direccion->cp,
                    'destino' => $data->info_destinatario->direccion->cp,
                ];

                break;

            case 'Fedex':

                $array = [
                    'contenido'                 => mb_strtoupper($data->contenido, 'UTF-8'),
                    'peso'                      => mb_strtoupper($data->peso, 'UTF-8'),
                    'alto'                      => mb_strtoupper($data->alto, 'UTF-8'),
                    'ancho'                     => mb_strtoupper($data->ancho, 'UTF-8'),
                    'largo'                     => mb_strtoupper($data->largo, 'UTF-8'),
                    'tipo_envio'                => mb_strtoupper($data->tipo_envio, 'UTF-8'),
                    'asegurar'                  => $data->seguro > 0 ? 1 : 0,
                    'monto'                     => mb_strtoupper($data->seguro, 'UTF-8'),
                    'referencia'                => mb_strtoupper($data->info_destinatario->direccion->referencia, 'UTF-8'),
                    'origen_empresa'            => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'origen_contacto'           => mb_strtoupper($data->info_remitente->contacto, 'UTF-8'),
                    'origen_telefono'           => mb_strtoupper($data->info_remitente->telefono, 'UTF-8'),
                    'origen_celular'            => mb_strtoupper($data->info_remitente->celular, 'UTF-8'),
                    'origen_domicilio_1'        => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'origen_domicilio_2'        => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'origen_domicilio_3'        => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
                    'origen_colonia'            => mb_strtoupper($data->info_remitente->direccion->colonia, 'UTF-8'),
                    'origen_ciudad'             => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
                    'origen_estado'             => mb_strtoupper($data->info_remitente->direccion->estado, 'UTF-8'),
                    'origen_codigo_postal'      => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'destino_empresa'           => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
                    'destino_contacto'          => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
                    'destino_telefono'          => mb_strtoupper($data->info_destinatario->telefono, 'UTF-8'),
                    'destino_celular'           => mb_strtoupper($data->info_destinatario->celular, 'UTF-8'),
                    'destino_domicilio_1'       => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    'destino_domicilio_2'       => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
                    'destino_colonia'           => mb_strtoupper($data->info_destinatario->direccion->colonia, 'UTF-8'),
                    'destino_ciudad'            => mb_strtoupper($data->info_destinatario->direccion->ciudad, 'UTF-8'),
                    'destino_estado'            => mb_strtoupper($data->info_destinatario->direccion->estado, 'UTF-8'),
                    'destino_codigo_postal'     => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
                    'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
                    'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
                    'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                $cotizar = [
                    'tipo'                      => $data->tipo_envio,
                    'destino'                   => $data->info_destinatario->direccion->ciudad,
                    'peso'                      => $data->peso,
                    'cp_ini'                    => $data->info_remitente->direccion->cp,
                    'cp_end'                    => $data->info_destinatario->direccion->cp,
                    'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
                    'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
                    'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                break;

            case 'DHL':

                $cuenta = "";

                if (property_exists($data, "documento")) {
                    $informacion_documento = DB::table("documento")
                        ->select("documento.id_paqueteria", "marketplace_area.id_area")
                        ->join("marketplace_area", "documento.id_marketplace_area", "=", "marketplace_area.id")
                        ->where("documento.id", $data->documento)
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
                }

                $array = [
                    'user_email' => $email,
                    'contenido' => mb_strtoupper($data->contenido, 'UTF-8'),
                    'peso' => mb_strtoupper($data->peso, 'UTF-8'),
                    'height' => mb_strtoupper($data->alto, 'UTF-8'),
                    'width' => mb_strtoupper($data->ancho, 'UTF-8'),
                    'depth' => mb_strtoupper($data->largo, 'UTF-8'),
                    'asegurar' => $data->seguro > 0 ? 1 : 0,
                    'monto_seguro' => mb_strtoupper($data->seguro, 'UTF-8'),
                    'tipo_envio' => mb_strtoupper($data->tipo_envio, 'UTF-8'),
                    'ori_empresa' => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'ori_contacto' => mb_strtoupper($data->info_remitente->contacto, 'UTF-8'),
                    'ori_telefono' => mb_strtoupper($data->info_remitente->telefono, 'UTF-8'),
                    'ori_celular' => mb_strtoupper($data->info_remitente->celular, 'UTF-8'),
                    'ori_domi_1' => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'ori_domi_2' => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'ori_domi_3' => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
                    'ori_ciudad' => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
                    'ori_estado' => mb_strtoupper($data->info_remitente->direccion->estado, 'UTF-8'),
                    'ori_cp' => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'des_empresa' => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
                    'des_contacto' => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
                    'des_email' => mb_strtoupper($data->info_destinatario->email, 'UTF-8'),
                    'des_telefono' => mb_strtoupper($data->info_destinatario->telefono, 'UTF-8'),
                    'des_celular' => mb_strtoupper($data->info_destinatario->celular, 'UTF-8'),
                    'des_domi_1' => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    'des_domi_2' => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
                    'des_domi_3' => mb_strtoupper($data->info_destinatario->direccion->direccion_3, 'UTF-8'),
                    'des_colonia' => mb_strtoupper($data->info_destinatario->direccion->colonia, 'UTF-8'),
                    'des_ciudad' => mb_strtoupper($data->info_destinatario->direccion->ciudad, 'UTF-8'),
                    'des_estado' => mb_strtoupper($data->info_destinatario->direccion->estado, 'UTF-8'),
                    'des_cp' => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
                    'from_cord_exists' => $data->info_remitente->direccion->cord_found,
                    'from_lng' => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat' => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists' => $data->info_destinatario->direccion->cord_found,
                    'to_lng' => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat' => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found',
                    'referencia' => $data->info_destinatario->direccion->referencia
                ];

                if (!empty($cuenta)) {
                    $array["accountnumber"] = $cuenta;
                }

                $cotizar = [
                    'destino'                   => $data->info_destinatario->direccion->ciudad,
                    'peso'                      => $data->peso,
                    'tipo'                      => $data->tipo_envio,
                    'largo'                     => $data->largo,
                    'ancho'                     => $data->ancho,
                    'alto'                      => $data->alto,
                    'cp_ini'                    => $data->info_remitente->direccion->cp,
                    'cp_end'                    => $data->info_destinatario->direccion->cp,
                    'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
                    'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
                    'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                break;

            case 'UPS':

                $array = [
                    'service_code'                  => mb_strtoupper($data->tipo_envio, 'UTF-8'),
                    'service_name'                  => '',
                    'package_description'           => mb_strtoupper($data->contenido, 'UTF-8'),
                    'packaging_description'         => '',
                    'peso'                          => mb_strtoupper($data->peso, 'UTF-8'),
                    'width'                         => mb_strtoupper($data->ancho, 'UTF-8'),
                    'height'                        => mb_strtoupper($data->alto, 'UTF-8'),
                    'length'                        => mb_strtoupper($data->largo, 'UTF-8'),
                    'customercontext'               => '',
                    'shipper_description'           => '',
                    'shipper_name'                  => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'shipper_attentionname'         => mb_strtoupper($data->info_remitente->contacto, 'UTF-8'),
                    'shipper_telefono'              => mb_strtoupper($data->info_remitente->celular, 'UTF-8'),
                    'shipper_extension'             => '',
                    'shipper_address1'              => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'shipper_address2'              => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'shipper_address3'              => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
                    'shipper_city'                  => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
                    'shipper_estado_code'           => mb_strtoupper(substr($data->info_remitente->direccion->estado, 0, 3), 'UTF-8'),
                    'shipper_codigo_postal'         => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'shipto_name'                   => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
                    'shipto_attentionName'          => mb_strtoupper($data->info_destinatario->contacto, 'UTF-8'),
                    'shipto_email'                  => mb_strtoupper($data->info_destinatario->email, 'UTF-8'),
                    'shipto_phone'                  => mb_strtoupper($data->info_destinatario->celular, 'UTF-8'),
                    'shipto_addressline1'           => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    'shipto_addressline2'           => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
                    'shipto_addressline3'           => mb_strtoupper($data->info_destinatario->direccion->direccion_3, 'UTF-8'),
                    'shipto_city'                   => mb_strtoupper($data->info_destinatario->direccion->ciudad, 'UTF-8'),
                    'shipto_estado_code'            => mb_strtoupper(substr($data->info_destinatario->direccion->estado, 0, 3), 'UTF-8'),
                    'shipto_codigo_postal'          => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
                    'shipfrom_name'                 => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'shipfrom_attentionname'        => mb_strtoupper($data->info_remitente->contacto, 'UTF-8'),
                    'shipfrom_phone'                => mb_strtoupper($data->info_remitente->celular, 'UTF-8'),
                    'shipfrom_addressline1'         => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'shipfrom_addressline2'         => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'shipfrom_addressline3'         => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
                    'shipfrom_city'                 => mb_strtoupper($data->info_remitente->direccion->ciudad, 'UTF-8'),
                    'shipfrom_stateprovincecode'    => mb_strtoupper(substr($data->info_remitente->direccion->estado, 0, 3), 'UTF-8'),
                    'shipfrom_postalcode'           => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'from_cord_exists'              => $data->info_remitente->direccion->cord_found,
                    'from_lng'                      => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat'                      => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists'                => $data->info_destinatario->direccion->cord_found,
                    'to_lng'                        => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat'                        => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                $cotizar = [
                    'service_code'              => $data->tipo_envio,
                    'service_description'       => '',
                    'packagingtype_code'        => '02',
                    'peso'                      => $data->peso,
                    'largo'                     => $data->largo,
                    'ancho'                     => $data->ancho,
                    'alto'                      => $data->alto,
                    'shipper_postalcode'        => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'shipto_name'               => mb_strtoupper($data->info_destinatario->empresa, 'UTF-8'),
                    'shipto_addressline1'       => mb_strtoupper($data->info_destinatario->direccion->direccion_1, 'UTF-8'),
                    'shipto_addressline2'       => mb_strtoupper($data->info_destinatario->direccion->direccion_2, 'UTF-8'),
                    'shipto_addressline3'       => mb_strtoupper($data->info_destinatario->direccion->direccion_3, 'UTF-8'),
                    'shipto_postalcode'         => mb_strtoupper($data->info_destinatario->direccion->cp, 'UTF-8'),
                    'shipfrom_name'             => mb_strtoupper($data->info_remitente->empresa, 'UTF-8'),
                    'shipfrom_addressline1'     => mb_strtoupper($data->info_remitente->direccion->direccion_1, 'UTF-8'),
                    'shipfrom_addressline2'     => mb_strtoupper($data->info_remitente->direccion->direccion_2, 'UTF-8'),
                    'shipfrom_addressline3'     => mb_strtoupper($data->info_remitente->direccion->direccion_3, 'UTF-8'),
                    'shipfrom_postalcode'       => mb_strtoupper($data->info_remitente->direccion->cp, 'UTF-8'),
                    'from_cord_exists'          => $data->info_remitente->direccion->cord_found,
                    'from_lng'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lng : 'not_found',
                    'from_lat'                  => ($data->info_remitente->direccion->cord_found) ? $data->info_remitente->direccion->cord->lat : 'not_found',
                    'to_cord_exists'            => $data->info_destinatario->direccion->cord_found,
                    'to_lng'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lng : 'not_found',
                    'to_lat'                    => ($data->info_destinatario->direccion->cord_found) ? $data->info_destinatario->direccion->cord->lat : 'not_found'
                ];

                break;

            case 'Paquetexpress':
                $array = array(
                    "typeservice" => $data->tipo_envio,
                    "from" => array(
                        "company" => $data->info_remitente->empresa,
                        "contact" => $data->info_remitente->contacto,
                        "email" => $data->info_destinatario->email,
                        "phone" => $data->info_remitente->telefono,
                        "address1" => $data->info_remitente->direccion->direccion_1,
                        "neighborhood" => $data->info_remitente->direccion->colonia,
                        "city" => $data->info_remitente->direccion->ciudad,
                        "state" => $data->info_remitente->direccion->estado,
                        "zip_code" => $data->info_remitente->direccion->cp,
                        "number" => "S/N"
                    ),
                    "to" => array(
                        "company" => $data->info_destinatario->empresa,
                        "contact" => $data->info_destinatario->contacto,
                        "email" => $data->info_destinatario->email,
                        "phone" => $data->info_destinatario->telefono,
                        "address1" => $data->info_destinatario->direccion->direccion_1,
                        "neighborhood" => $data->info_destinatario->direccion->colonia,
                        "city" => $data->info_destinatario->direccion->ciudad,
                        "state" => $data->info_destinatario->direccion->estado,
                        "zip_code" => $data->info_destinatario->direccion->cp,
                        "number" => "S/N"
                    ),
                    "reference" => $data->info_destinatario->direccion->referencia,
                    "insurance" => $data->seguro,
                    "packets" => array(
                        "0" => array(
                            "weight" => $data->peso,
                            "depth" => $data->largo,
                            "width" => $data->ancho,
                            "height" => $data->alto
                        )
                    ),
                    "content" => $data->contenido,
                    "ocurr" => "EAD",
                    "observation" => ""
                );

                $cotizar = array(
                    "typeservice" => $data->tipo_envio,
                    "from" => array(
                        "neighborhood" => $data->info_remitente->direccion->colonia,
                        "zip_code" => $data->info_remitente->direccion->cp
                    ),
                    "to" => array(
                        "neighborhood" => $data->info_destinatario->direccion->colonia,
                        "zip_code" => $data->info_destinatario->direccion->cp
                    ),
                    "insurance" => $data->seguro,
                    "packets" => array(
                        "0" => array(
                            "weight" => $data->peso,
                            "depth" => $data->largo,
                            "width" => $data->ancho,
                            "height" => $data->alto
                        )
                    )
                );

                break;
        }

        $paqueteria = DB::select("SELECT paqueteria FROM paqueteria WHERE id = " . $data->paqueteria . "");

        if (empty($paqueteria)) {
            $response->error = 1;
            $response->mensaje = "No se encontró la paquetería, favor de contactar con un administrador.";

            return $response;
        }

        $paqueteria = $paqueteria[0];

        try {
            $cotizar_res = \Httpful\Request::post('http://paqueterias.crmomg.mx/api/' . $paqueteria->paqueteria . '/Cotizar')
                ->addHeader('authorization', 'Bearer ' . config("keys.paqueterias"))
                ->body($cotizar, Mime::FORM)
                ->send();

            $cotizar_raw = $cotizar_res->body;
            $cotizar_res = @json_decode($cotizar_res->raw_body);

            if (empty($cotizar_res)) {
                $response->error = 1;
                $response->mensaje = "No fue posible cotizar el envio, error desconocido";
                $response->raw = $cotizar_raw;

                return $response;
            }

            if (!property_exists($cotizar_res, "error")) {
                $response->error = 1;
                $response->mensaje = "No fue posible cotizar el envio, campo de error no encontrado";
                $response->raw = $cotizar_raw;
                $response->data = config("keys.paqueterias");

                return $response;
            }

            if ($cotizar_res->error == 1) {
                $response->error = 1;
                $response->mensaje = "No fue posible cotizar el envio, error " . $cotizar_res->mensaje;

                return $response;
            }

            $response->error = 0;
            $response->total = (float) $cotizar_res->base + (float) $cotizar_res->extra;
            $response->data = $array;

            return $response;
        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = "No fue posible cotizar el envio, error " . $e->getMessage();
            $response->raw = $cotizar_raw;

            return $response;
        }
    }

    private function manifiesto_guias_raw_data($extra_data)
    {

        // WHEN 8 THEN '99 minutos' 
        // WHEN 12 THEN 'Walmart'
        return DB::table("manifiesto")
            ->select("impresora.nombre", "manifiesto.guia", "documento_direccion.*", "paqueteria.paqueteria")
            ->join("paqueteria", "manifiesto.id_paqueteria", "=", "paqueteria.id")
            ->join("impresora", "manifiesto.id_impresora", "=", "impresora.id")
            ->leftJoin("documento_guia", "manifiesto.guia", "=", "documento_guia.guia")
            ->leftJoin("documento_direccion", "documento_guia.id_documento", "documento_direccion.id_documento")
            ->where("manifiesto.manifiesto", date("dmY"))
            ->when($extra_data, function ($query, $extra_query) {
                return $query->whereRaw($extra_query);
            })
            ->get()
            ->toArray();
    }

    public function rawinfo_logistica_envia_cotizar()
    {
        return (array) EnviaService::cotizar();
    }
}
