<?php

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\ComodinService;
use App\Http\Services\CorreoService;
use App\Http\Services\DocumentoService;
use App\Http\Services\GeneralService;
use App\Http\Services\InventarioService;
use App\Http\Services\MercadolibreService;
use App\Http\Services\WhatsAppService;
use App\Models\Area;
use App\Models\Documento;
use App\Models\DocumentoEntidad;
use App\Models\DocumentoEntidadRelacion;
use App\Models\DocumentoTipo;
use App\Models\Empresa;
use App\Models\EmpresaAlmacen;
use App\Models\Enums\DocumentoTipo as EnumDocumentoTipo;
use App\Models\Movimiento;
use App\Models\MovimientoProducto;
use App\Models\Paqueteria;
use App\Models\Producto;
use Exception;
use Httpful\Mime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mailgun\Mailgun;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Picqer\Barcode\BarcodeGeneratorJPG;
use stdClass;
use Throwable;

class AlmacenController extends Controller
{
    /* Almacen > Packing */
    public function almacen_packing_data(Request $request)
    {
        set_time_limit(0);
        $auth = json_decode($request->auth);

        $ventas = $this->picking_packing_raw_data(" AND usuario_empresa.id_usuario = " . $auth->id);

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas
        ]);
    }
    public function almacen_packing_empresa_almacen($usuario)
    {
        set_time_limit(0);

        $res = DB::table('usuario_empresa_almacen')
            ->select('id_empresa_almacen')
            ->where('id_usuario', $usuario)
            ->pluck('id_empresa_almacen')
            ->toArray();

        return response()->json([
            'code'  => 200,
            'res'    => $res
        ]);
    }

    public function almacen_packing_confirmar(Request $request)
    {
        $series = json_decode($request->input('series'));
        $producto = $request->input('producto');

        $validar_series = ComodinService::validar_series($series, $producto);

        return response()->json([
            'code'  => $validar_series->error ? 500 : 200,
            'mensaje' => $validar_series->mensaje,
            'series'    => $validar_series->series
        ]);
    }

    public function almacen_packing_confirmar_authy(Request $request)
    {
        $data = json_decode($request->input("data"));
            return response()->json([
                "code" => 404
            ]);

    }

    public function almacen_packing_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $errores = array();
        $series_cambiadas = array();

        $info_documento = DB::table('documento')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('paqueteria', 'documento.id_paqueteria', '=', 'paqueteria.id')
            ->join('marketplace_area', 'documento.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('area', 'marketplace_area.id_area', '=', 'area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->where('documento.id', $data->documento)
            ->where('documento.id_tipo', 2)
            ->where('documento.status', 1)
            ->select(
                'area.area',
                'documento.id_fase',
                'documento.id_almacen_principal_empresa',
                'almacen.id AS id_almacen',
                'marketplace.marketplace',
                'paqueteria.paqueteria'
            )
            ->get();

        if (empty($info_documento)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del documento, es posible que haya sido cancelado, favor de verificar e intentar de nuevo." . " " . self::logVariableLocation()
            ]);
        }

        $info_documento = $info_documento[0];

        if ($info_documento->id_fase > 3) {
            return response()->json([
                'code'  => 500,
                'message'   => "El pedido ya ha sido remisionado." . " " . self::logVariableLocation()
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        if (!$data->problema && !$data->terminar) {
            return response()->json([
                'code'  => 200,
                'message'   => "Seguimiento guardado correctamente."
            ]);
        }

        if ($data->problema) {
            try {
                $usuario_documento = DB::table('documento')
                    ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
                    ->where('documento.id', $data->documento)
                    ->select(
                        'usuario.id AS id_usuario',
                        'usuario.email',
                        'usuario.nombre'
                    )
                    ->first();

                if ($usuario_documento->id_usuario > 1) {
                    $notificacion['titulo']     = "Pedido en problemas.";
                    $notificacion['message']    = "Tú pedido " . $data->documento . " ah sido agregado a problemas.";
                    $notificacion['tipo']       = "warning"; // success, warning, danger
                    $notificacion['link']       = "/venta/venta/problema/" . $data->documento;

                    $notificacion_id = DB::table('notificacion')->insertGetId([
                        'data'  => json_encode($notificacion)
                    ]);

                    $notificacion['id']         = $notificacion_id;

                    DB::table('notificacion_usuario')->insert([
                        'id_usuario'        => $usuario_documento->id_usuario,
                        'id_notificacion'   => $notificacion_id
                    ]);

                    $notificacion['usuario']    = $usuario_documento->id_usuario;

                    event(new PusherEvent(json_encode($notificacion)));

                    $view = view('email.notificacion_problema')->with([
                        'vendedor' => $usuario_documento->nombre,
                        'usuario' => $usuario_documento->nombre,
                        'anio' => date('Y'),
                        'documento' => $data->documento,
                        'comentario' => $data->seguimiento
                    ]);

                    $mg     = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
                    $domain = "omg.com.mx";
                    $mg->messages()->send($domain, array(
                        'from'  => 'CRM OMG International <crm@omg.com.mx>',
                        'to'            => $usuario_documento->email,
                        'subject'       => 'Pedido ' . $data->documento . ' en problemas.',
                        'html'          => $view->render()
                    ));
                }

                DB::table('documento')->where(['id' => $data->documento])->update([
                    'problema' => 1,
                    'picking_date' => '0000-00-00 00:00:00'
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
                ]);
            } catch (Throwable $e) {
                return response()->json([
                    'code'  => 500,
                    'message'   => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
                ]);
            }

            return response()->json([
                'code'  => 200,
                'message'   => "Seguimiento guardado correctamente."
            ]);
        }

        foreach ($data->productos as $producto) {
            $movimiento = DB::table('movimiento')
                ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                ->where('modelo.sku', trim($producto->producto))
                ->where('id_documento', $data->documento)
                ->select(
                    'movimiento.id',
                    'modelo.serie'
                )
                ->get();

            if (!empty($movimiento)) {
                if ($movimiento[0]->serie) {
                    $validar_series = ComodinService::validar_series($producto->series, trim($producto->producto));

                    if($validar_series->error){
                        return response()->json([
                            'code'  => 500,
                            'message'   => $validar_series->message,
                            'errores' => $validar_series->errores
                        ]);
                    }

                    foreach ($producto->series as $serie) {
                        $serie = str_replace(["'", '\\'], '', $serie);
                        $existe = DB::table('documento')
                            ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
                            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                            ->join('movimiento_producto', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
                            ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
                            ->where('producto.serie', $serie)
                            ->select(
                                'producto.id',
                                'modelo.sku'
                            )
                            ->get();

                        array_push($series_cambiadas, $existe[0]->id);

                        DB::table('producto')->where(['id' => $existe[0]->id])->update([
                            'id_almacen' => $info_documento->id_almacen,
                            'status' => 0
                        ]);

                        DB::table('movimiento_producto')->insert(
                            [
                                'id_movimiento' => $movimiento[0]->id,
                                'id_producto' => $existe[0]->id
                            ]
                        );
                    }
                }
            } else {
                if (!file_exists("logs")) {
                    mkdir("logs", 0777, true);
                }

                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Error : Documento CRM: " . $data->documento . " ; Mensaje: No se encontró el movimento relacionado con el producto " . $producto->producto . ", por lo tanto no se generó la relación de las series." . PHP_EOL, FILE_APPEND);

                array_push($errores, "Mensaje: No se encontró el movimento relacionado con el producto " . $producto->producto . ", por lo tanto no se generó la relación de las series." . " " . self::logVariableLocation());
            }
        }

        if (!empty($errores)) {
            $movimientos = DB::table('documento')
                ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
                ->where('documento.id', $data->documento)
                ->select('movimiento.id')
                ->get();

            foreach ($movimientos as $movimiento) {
                DB::table('movimiento_producto')->where(['id_movimiento' => $movimiento->id])->delete();
            }

            foreach ($series_cambiadas as $serie) {
                DB::table('producto')->where(['id' => $serie])->update(['status' => 1]);
            }

            return response()->json([
                'code'  => 500,
                'errores'   => $errores
            ]);
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_fase' => 4,
            'packing_date' => date('Y-m-d H:i:s'),
            'problema' => $data->problema
        ]);

        try {
            $zpl =  "^XA~TA000~JSN^LT0^MNW^MTT^PON^PMN^LH0,0^JMA^PR6,6~SD15^JUS^LRN^CI0^XZ" .
                "^XA" .
                "^MMT" .
                "^PW406" .
                "^LL0203" .
                "^LS0" .
                "^BY5,3,60^FT369,139^BCI,,Y,N" .
                "^FD>;" . $data->documento . "^FS" .
                "^FT368,68^A0I,28,28^FH\^FD" . $info_documento->marketplace . " / " . $info_documento->area . "^FS" .
                "^FT368,22^A0I,45,45^FH\^FD" . $info_documento->paqueteria . "^FS" .
                "^PQ1,0,1,Y^XZ";

            $array = array(
                'printer'   => "Zebra_Packing",
                'zpl'       => $zpl
            );

            \Httpful\Request::post('http://wimtech.ddns.net:9180')
                ->body($array, Mime::FORM)
                ->send();
        } catch (Exception $e) {
            return response()->json([
                'code'  => 200,
                'message'   => "Documento guardado correctamente pero hubo un problema al imprimir la etiqueta. Error: " . $e->getMessage()
            ]);
        }

        $ventas = $this->picking_packing_raw_data(" AND usuario_empresa.id_usuario = " . $auth->id . "");

        return response()->json([
            'code'  => 200,
            'message'   => "Documento guardado correctamente.",
            'ventas'    => $ventas
        ]);
    }

    public function almacen_busqueda_serie_vs_sku(Request $request)
    {
        $serie = $request->input('serie');

        $es_sku = DB::table('modelo')->where('sku', $serie)->first();

        if (empty($es_sku)) {
            $es_sinonimo = DB::table('modelo_sinonimo')->where('codigo', $serie)->first();

            if (empty($es_sinonimo)) {
                return response()->json([
                    'code' => 200,
                    'valido' => true
                ]);
            } else {
                return response()->json([
                    'code' => 500,
                    'valido' => false
                ]);
            }
        } else {
            return response()->json([
                'code' => 500,
                'valido' => false
            ]);
        }
    }

    public function almacen_busqueda_serie_vs_almacen(Request $request)
    {
        $serie = $request->input('serie');
        $almacen = $request->input('almacen');

        $existe = DB::table('producto')->where('serie', $serie)->where('id_almacen', $almacen)->first();

        if (empty($existe)) {
            return response()->json([
                'code' => 500,
                'existe_almacen' => false
            ]);
        } else {
            return response()->json([
                'code' => 200,
                'existe_almacen' => true
            ]);
        }
    }

    public function almacen_packing_documento($documento, $usuario)
    {
        set_time_limit(0);

        $res = DB::table('usuario_empresa_almacen')
            ->select('id_empresa_almacen')
            ->where('id_usuario', $usuario)
            ->pluck('id_empresa_almacen')
            ->toArray();

        $documento_info = DB::table('documento')->where('id', $documento)->first();

        if($documento_info->id_tipo != 2) {
            return response()->json([
                'code' => 500,
                'message' => "El documento no es una venta"
            ]);
        }

        $tiene_series = DB::table('movimiento_producto')
            ->join('movimiento', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
            ->join('producto', 'producto.id', '=', 'movimiento_producto.id_producto')
            ->where('movimiento.id_documento', $documento)
            ->select('producto.*')
            ->get();

        //El pedido ya fue surtido por alguien
        if ($documento_info->packing_by != 0) {
            //El pedido ya fue surtido y tiene series asignadas
            if ($tiene_series->isNotEmpty()) {
                //El pedido ya tiene documento en comercial
                if ($documento_info->documento_extra == "N/A") {
                    //Se manda el pedido a fase factura para solo crear la factura
                    DB::table('documento')->where('id', $documento)->update(['id_fase' => 5]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "Pedido mandado a fase factura porque ya fue surtido y no se ha creado la factura."
                    ]);

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " ya fue remisionado. Pedido mandado a fase factura porque ya fue surtido y no se ha creado la factura." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                } else {
                    //El pedido se manda a fase de Terminado porque ya fue surtido, ya tiene series y documento en comercial
                    DB::table('documento')->where('id', $documento)->update(['id_fase' => 6]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "Pedido mandado a fase terminado porque ya fue surtido y tiene series asignadas."
                    ]);

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " ya fue remisionado. Pedido mandado a fase terminado porque ya fue surtido y tiene series asignadas." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }
            } else {
                //El pedido ya fue surtido por alguien pero no se guardaron la series
                //Validar primero si el pedido debe tener series
                $info_productos = DB::table('movimiento')
                    ->join('modelo', 'modelo.id', '=', 'movimiento.id_modelo')
                    ->where('movimiento.id_documento', $documento)
                    ->select('modelo.*') // Selecciona todos los campos de modelo
                    ->get();

                $debe_tener_serie = 1;

                foreach ($info_productos as $producto) {
                    if ($producto->serie == 0) {
                        $debe_tener_serie = 0;
                    } else {
                        $debe_tener_serie = 1;
                    }
                }

                if ($debe_tener_serie == 0) {
                    DB::table('documento')->where('id', $documento)->update([
                        'id_fase' => 6,
                    ]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "El pedido ya fue surtido. Se manda a fase de Terminado."
                    ]);

//                    if ($documento_info->documento_extra == "N/A") {
//                        $crear_factura = InventarioService::aplicarMovimiento($documento);
//
//                        if ($crear_factura->error) {
//                            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Ocurrió un error al crear la factura " . $documento . " en packing v2." . PHP_EOL, FILE_APPEND);
//
//
//                            DB::table('documento')->where(['id' => $documento])->update([
//                                'id_fase' => 5,
//                            ]);
//
//                            DB::table('seguimiento')->insert([
//                                'id_documento' => $documento,
//                                'id_usuario' => 1,
//                                'seguimiento' => "Ocurrio un error al generar la factura y se manda a fase Factura." . $crear_factura->mensaje
//                            ]);
//                        }
//                    }

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " ya fue surtido. Se manda a fase de Terminado." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                } else {
                    $data_usuario = DB::table('usuario')->where('id', $usuario)->first();
                    DB::table('documento')->where('id', $documento)->update([
                        'id_fase' => 3,
                        'packing_by' => 0
                    ]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "El pedido ya fue surtido por " . $data_usuario->nombre . ", sin embargo, el pedido no tiene series asigadas. Se manda a pendiente de remisión para que vuelvan a surtir."
                    ]);

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " ya fue surtido por " . $data_usuario->nombre . ", sin embargo, el pedido no tiene series asigadas. Se manda a pendiente de remisión para que vuelvan a surtir." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }
            }
        } else {
            if ($tiene_series->isNotEmpty()) {
                $this->eliminarSeries($documento);
                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => "El pedido ya tiene series asignadas y no ha sido surtido, se borraron las series para volver a surtir."
                ]);
                return response()->json([
                    "code" => 500,
                    "message" => "El documento " . $documento . " ya tiene series asignadas, borrando series, POR FAVOR VOLVER A INTENTAR" . " " . self::logVariableLocation(),
                    "color" => "red-border-top"
                ]);
            }
        }

        $informacion = DB::table('documento')
            ->join('marketplace_area', 'documento.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->join('area', 'marketplace_area.id_area', '=', 'area.id')
            ->join('paqueteria', 'documento.id_paqueteria', '=', 'paqueteria.id')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->where('documento.id', $documento)
            ->select(
                'documento.id',
                'documento.status',
                'documento.id_fase',
                'documento.id_usuario',
                'documento.picking',
                'documento.pagado',
                'documento.shipping_null',
                'documento.id_marketplace_area',
                'documento.id_periodo',
                'documento.documento_extra',
                'paqueteria.paqueteria',
                'paqueteria.guia',
                'empresa.empresa',
                'almacen.almacen',
                'empresa_almacen.id AS almacen_id',
                'marketplace_area.publico',
                'area.area',
                'marketplace.marketplace'
            )
            ->get();

        if (empty($informacion)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró el documento " . $documento . "." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }

        $informacion = $informacion[0];

        if (!$informacion->status) {
            return response()->json([
                "code" => 500,
                "message" => "El documento " . $documento . " se encuentra cancelado." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }
        if (!in_array($informacion->almacen_id, $res)) {
            return response()->json([
                "code" => 500,
                "message" => "El documento " . $documento . " no corresponde a su(s) almacen(es) asignado(s)." . " " . self::logVariableLocation(),
                "color" => "yellow-border-top"
            ]);
        }

        if ($informacion->id_fase > 3) {
            return response()->json([
                "code" => 500,
                "message" => "El documento " . $documento . " ya fue surtido." . " " . self::logVariableLocation(),
                "color" => "blue-border-top"
            ]);
        }

        if ($informacion->id_fase < 3) {
            return response()->json([
                "code" => 500,
                "message" => "El documento " . $documento . " no está disponible para ser surtido." . " " . self::logVariableLocation(),
                "color" => "orange-border-top"
            ]);
        }

        if ($informacion->id_periodo == 1 && !$informacion->pagado && !$informacion->publico) {
            return response()->json([
                "code" => 500,
                "message" => "El documento no ha sido pagado." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }

        if (in_array($informacion->id_marketplace_area, [1]) && $informacion->shipping_null != 1) {
            $validar_buffered = MercadolibreService::validarPendingBuffered($informacion->id);

            if ($validar_buffered->error) {
                if ($validar_buffered->substatus == "cancelled") {

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "El pedido esta CANCELADO en MERCADOLIBRE"
                    ]);

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " se encuentra cancelado, NO REMISIONAR, DESTRUIR PICKING." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }

                if ($validar_buffered->substatus == "delivered") {
                    DB::table('documento')->where('id', $documento)->update([
                        'id_fase' => $informacion->documento_extra != "N/A" ? 6 : 5
                    ]);

                    DB::table('seguimiento')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => 1,
                        'seguimiento' => "El pedido esta ENTREGADO en MERCADOLIBRE, se cambia de fase."
                    ]);

                    return response()->json([
                        "code" => 500,
                        "message" => "El documento " . $documento . " ya esta entregado en MERCADOLIBRE, NO REMISIONAR, DESTRUIR PICKING." . " " . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }
            }

            if ($validar_buffered->estatus) {
                DB::table('documento')->where('id', $documento)->update([
                    'id_fase' => 1,
                    'picking' => 0,
                    'picking_by' => 0
                ]);

                CorreoService::cambioDeFase($documento, "El pedido no tiene guia asignada en MERCADOLIBRE, se manda a fase pedido.");

                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 0,
                    'seguimiento' => "El pedido no tiene guia asignada en MERCADOLIBRE, se manda a fase pedido."
                ]);

                return response()->json([
                    "code" => 500,
                    "message" => "El documento " . $documento . " no tiene guia asignada, NO REMISIONAR, DESTRUIR PICKING." . " " . self::logVariableLocation(),
                    "color" => "red-border-top"
                ]);
            }
        }

        $contiene_servicios = DB::table('movimiento')
            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
            ->where('movimiento.id_documento', $documento)
            ->whereIn('modelo.id_tipo', [2, 4])
            ->select(
                'modelo.id',
                'modelo.sku',
                'modelo.descripcion',
                'modelo.serie',
                'movimiento.cantidad'
            )
            ->get();

        $informacion->con_servicios = empty($contiene_servicios) ? 0 : 1;

        $informacion->productos = DB::table('movimiento')
            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
            ->where('movimiento.id_documento', $documento)
            ->whereNotIn('modelo.id_tipo', [2, 4])
            ->select(
                'modelo.id',
                'modelo.sku',
                'modelo.descripcion',
                'modelo.serie',
                'movimiento.cantidad'
            )->get();

        if (empty($informacion->productos) && !$informacion->con_servicios) {
            $vendedor = DB::table('usuario')
                ->where('id', $informacion->id_usuario)
                ->value('nombre');

            return response()->json([
                "code" => 500,
                "message" => "El documento " . $documento . " no cuenta con productos, favor de contactar al vendedor " . $vendedor . "." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }

        foreach ($informacion->productos as $producto) {
            $producto->sinonimos = DB::table("modelo_sinonimo")
                ->select("codigo")
                ->where("id_modelo", $producto->id)
                ->pluck("codigo");
        }

        $informacion->seguimiento = DB::table('seguimiento')
            ->join('usuario', 'seguimiento.id_usuario', '=', 'usuario.id')
            ->where('id_documento', $documento)
            ->select(
                'seguimiento.*',
                'usuario.nombre'
            )
            ->get();

        return response()->json([
            "code" => 200,
            "informacion" => $informacion
        ]);
    }

    public function almacen_packing_guardar_guia(Request $request)
    {
        $data = json_decode($request->input("data"));

        $esta_remisionado = DB::select("SELECT id, id_fase, status, no_venta, id_paqueteria, id_marketplace_area FROM documento WHERE id = " . $data->documento . "");

        if (empty($esta_remisionado)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró el pedido, favor de contactar a un administrador." . " " . self::logVariableLocation()
            ]);
        }

        $esta_remisionado = $esta_remisionado[0];

        if (!$esta_remisionado->status) {
            return response()->json([
                "code" => 500,
                "message" => "El pedido se encuentra cancelado." . " " . self::logVariableLocation()
            ]);
        }

        if ($esta_remisionado->id_fase < 4) {
            return response()->json([
                "code" => 500,
                "message" => "El pedido no ha sido remisionado." . " " . self::logVariableLocation()
            ]);
        }

        $existe_guia = DB::select("SELECT
                                    documento_guia.id,
                                    documento.id AS documento_id
                                FROM documento_guia
                                INNER JOIN documento ON documento_guia.id_documento = documento.id
                                WHERE documento_guia.guia = '" . $data->guia . "'
                                AND documento.status = 1");

        if (!empty($existe_guia)) {
            return response()->json([
                "code" => 500,
                "message" => "La guía ya está relacionada a un pedido, " . $existe_guia[0]->documento_id . "" . " " . self::logVariableLocation()
            ]);
        }

        $existe_guia_paqueteria = DB::select("SELECT costo FROM paqueteria_guia WHERE guia = '" . $data->guia . "'");

        DB::table('documento_guia')->insertGetId([
            'id_documento' => $data->documento,
            'guia' => trim($data->guia),
            'costo' => !empty($existe_guia_paqueteria) ? $existe_guia_paqueteria[0]->costo : 0
        ]);

        $existe_guia_manifiesto = DB::select("SELECT id FROM manifiesto WHERE guia = '" . $data->guia . "'");

        if (empty($existe_guia_manifiesto)) {
            $impresora_documento = DB::table("documento")
                ->select("empresa_almacen.id_impresora_manifiesto")
                ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
                ->where("documento.id", $data->documento)
                ->first();

            $shiping = DB::table("documento")->select("id_paqueteria", "id_marketplace_area")->where("id", $data->documento)->first();

            DB::table('manifiesto')->insert([
                'id_impresora' => $impresora_documento->id_impresora_manifiesto,
                'manifiesto' => date('dmY'),
                'guia' => trim($data->guia),
                'id_paqueteria' => trim($shiping->id_paqueteria),
                'id_marketplace_area' => $shiping->id_marketplace_area == 64 ? $shiping->id_marketplace_area : null,
                'notificado' => $shiping->id_marketplace_area == 64 ? 0 : null
            ]);
        }

        return response()->json([
            "code" => 200,
            "message" => "Guía guardada correctamente."
        ]);
    }

    /* V2 */
    public function almacen_packing_v2_data()
    {

        $problemas = DB::select("SELECT codigo, problema FROM problema_surtido WHERE status = 1");

        $usuarios = DB::select("SELECT
                                    usuario.authy,
                                    usuario.id,
                                    usuario.nombre,
                                    nivel.nivel
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                INNER JOIN subnivel ON subnivel_nivel.id_subnivel = subnivel.id
                                WHERE nivel.nivel IN ('ALMACEN', 'ADMINISTRADOR')
                                AND subnivel.subnivel = 'ADMINISTRADOR'
                                AND usuario.id != 1
                                AND usuario.status = 1
                                GROUP BY usuario.id");

        $impresoras = DB::table("impresora")
            ->where("tamanio", "4x8")
            ->where("status", 1)
            ->select("id", "nombre")
            ->get();

        return response()->json([
            "code" => 200,
            "problemas" => $problemas,
            "usuarios" => $usuarios,
            "impresoras" => $impresoras
        ]);
    }

    /**
     * @throws Throwable
     */
    public function almacen_packing_guardar_v2(Request $request)
    {
        $backup = 0;

        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $errores = array();
        $series_cambiadas = array();
        $solicitar_guia = 1;

        $info_documento = DB::select("SELECT
                                        area.area,
                                        almacen.id AS id_almacen,
                                        documento.id_fase,
                                        documento.id_almacen_principal_empresa,
                                        documento.id_marketplace_area,
                                        documento.guia_impresa,
                                        documento.shipping_null,
                                        documento.anticipada,
                                        documento.referencia,
                                        documento.documento_extra,
                                        almacen.id AS id_almacen,
                                        marketplace.marketplace,
                                        paqueteria.id as paqueteria_id,
                                        paqueteria.paqueteria,
                                        paqueteria.guia,
                                        paqueteria.api
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN area ON marketplace_area.id_area = area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    WHERE documento.id = " . $data->documento . " 
                                    AND documento.id_tipo = 2
                                    AND documento.status = 1");

        if (empty($info_documento)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del documento, es posible que haya sido cancelado, favor de verificar e intentar de nuevo." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }

        $info_documento = $info_documento[0];

        if ($info_documento->id_fase > 3) {
            return response()->json([
                'code'  => 500,
                'message'   => "El pedido ya ha sido remisionado." . " " . self::logVariableLocation(),
                "color" => "red-border-top"
            ]);
        }

        if (property_exists($data, "seguimiento")) {
            if (!empty($data->seguimiento)) {
                DB::table('seguimiento')->insert([
                    'id_documento' => $data->documento,
                    'id_usuario' => $auth->id,
                    'seguimiento' => $data->seguimiento
                ]);
            }
        }

        if ($data->problema) {
            try {
                $usuario_documento = DB::select("SELECT
                                                    usuario.id AS id_usuario,
                                                    usuario.email,
                                                    usuario.nombre
                                                FROM documento 
                                                INNER JOIN usuario ON documento.id_usuario = usuario.id 
                                                WHERE documento.id = " . $data->documento . "")[0];

                if ($usuario_documento->id_usuario > 1) {
                    $notificacion['titulo'] = "Pedido en problemas.";
                    $notificacion['message'] = "Tú pedido " . $data->documento . " ah sido agregado a problemas.";
                    $notificacion['tipo'] = "warning"; // success, warning, danger
                    $notificacion['link'] = "/venta/venta/problema/" . $data->documento;

                    $notificacion_id = DB::table('notificacion')->insertGetId([
                        'data' => json_encode($notificacion)
                    ]);

                    $notificacion['id'] = $notificacion_id;

                    DB::table('notificacion_usuario')->insert([
                        'id_usuario' => $usuario_documento->id_usuario,
                        'id_notificacion' => $notificacion_id
                    ]);

                    $notificacion['usuario'] = $usuario_documento->id_usuario;

                    event(new PusherEvent(json_encode($notificacion)));

                    $view = view('email.notificacion_problema')->with([
                        'vendedor' => $usuario_documento->nombre,
                        'problema' => 0,
                        'usuario' => $usuario_documento->nombre,
                        'anio' => date('Y'),
                        'documento' => $data->documento,
                        'comentario' => $data->seguimiento
                    ]);

                    $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
                    $domain = "omg.com.mx";
                    $mg->messages()->send($domain, array(
                        'from' => 'CRM OMG International <crm@omg.com.mx>',
                        'to' => $usuario_documento->email,
                        'subject' => 'Pedido ' . $data->documento . ' en problemas.',
                        'html' => $view->render()
                    ));
                }

                DB::table('documento')->where(['id' => $data->documento])->update([
                    'problema' => 1,
                    'picking_date' => '0000-00-00 00:00:00'
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'code'  => 500,
                    "color" => "red-border-top",
                    'message'   => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
                ]);
            } catch (Throwable $e) {
                return response()->json([
                    'code'  => 500,
                    "color" => "red-border-top",
                    'message'   => "Ocurrió un error al enviar el correo de notificación, favor de contactar al administrador. Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
                ]);
            }

            return response()->json([
                'code'  => 200,
                "color" => "green-border-top",
                'message'   => "Documento enviado a problemas correctamente."
            ]);
        }

        foreach ($data->productos as $producto) {
            // Consulta el movimiento relacionado al SKU y documento
            $movimiento = DB::select("SELECT
                                    movimiento.id,
                                    modelo.serie
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE modelo.sku = '" . trim($producto->sku) . "' 
                                AND movimiento.id_documento = " . $data->documento);

            if (!empty($movimiento)) {
                if ($movimiento[0]->serie) {
                    // Llamamos a la función de validación de series
                    $validacion = ComodinService::validar_series($producto->series, trim($producto->sku));

                    // Si hay errores en la validación, se retorna la respuesta de error
                    if ($validacion->error == 1) {
                        return response()->json([
                            'code'  => 500,
                            "color" => "red-border-top",
                            'message'   => $validacion->mensaje . " " . self::logVariableLocation(),
                            'errores'   => $validacion->errores
                        ]);
                    }

                    // Verificar si hay series repetidas
                    $series_count = array_count_values($producto->series);
                    $series_repetidas = array_filter($series_count, function ($count) {
                        return $count > 1;
                    });

                    if (!empty($series_repetidas)) {
                        return response()->json([
                            'code'  => 500,
                            "color" => "red-border-top",
                            'message'   => "Hay series repetidas: " . implode(', ', array_keys($series_repetidas)) . " " . self::logVariableLocation()
                        ]);
                    }

                    // Procesar cada serie validada
                    foreach ($producto->series as $serie) {
                        // Se limpian caracteres potencialmente problemáticos
                        $serie = str_replace(["'", '\\'], '', $serie);

                        $existe = DB::select("SELECT
                                            producto.id,
                                            modelo.sku
                                        FROM documento
                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                        INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                        WHERE producto.serie = '" . $serie . "'
                                        AND modelo.sku = '" . $producto->sku . "'");

                        if (!empty($existe)) {
                            array_push($series_cambiadas, $existe[0]->id);

                            DB::table('producto')->where(['id' => $existe[0]->id])->update([
                                'id_almacen' => $info_documento->id_almacen,
                                'status' => 0
                            ]);

                            DB::table('movimiento_producto')->insert([
                                'id_movimiento' => $movimiento[0]->id,
                                'id_producto' => $existe[0]->id
                            ]);
                        } else {
                            array_push($errores, "La serie " . $serie . " no existe en el producto " . trim($producto->sku));
                        }
                    }
                }
            } else {
                array_push($errores, "Mensaje: No se encontró el movimiento relacionado con el producto " . $producto->sku . ", por lo tanto no se generó la relación de las series. " . self::logVariableLocation());
            }
        }

        if (!empty($errores)) {
            $this->eliminarSeries($data->documento);
            DB::table('seguimiento')->insert([
                'id_documento' => $data->documento,
                'id_usuario' => 1,
                'seguimiento' => "Ocurrió un error al finalizar el pedido " . $data->documento . " en packing v2, se borraran las series y se manda a pendiente de remision." . json_encode($errores)
            ]);
            CorreoService::cambioDeFase($data->documento, "Ocurrió un error al finalizar el pedido " . $data->documento . " en packing v2, se borraran las series y se manda a pendiente de remision." . json_encode($errores));

            file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Ocurrió un error al finalizar el pedido " . $data->documento . " en packing v2, errores: " . json_encode($errores) . "." . PHP_EOL, FILE_APPEND);

            return response()->json([
                'code' => 500,
                'message' => json_encode($errores),
                "color" => "red-border-top",
                'LOG' => self::logVariableLocation()
            ]);
        }

        $marketplace_area = DB::table("documento")
            ->join("marketplace_area", "documento.id_marketplace_area", "=", "marketplace_area.id")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->where("documento.id", $data->documento)
            ->select("marketplace.marketplace", "documento.id_marketplace_area", "documento.no_venta")
            ->first();

        if ($info_documento->guia && $info_documento->shipping_null == 0) {
            # Verificamos que la guía no se haya imprimido
            if (!$info_documento->guia_impresa) {
                # Sacamos la impresora asignada al usuario

                $fase_documento = DB::table("documento")
                    ->select("id_fase")
                    ->where("id", $data->documento)
                    ->first();

                //PREGUNTAR
//                if (in_array($marketplace_area->id_marketplace_area, [14, 53, 4, 5])) {
//                    if ($fase_documento->id_fase < 3) {
//                        $this->eliminarSeries($data->documento);
//                        DB::table('seguimiento')->insert([
//                            'id_documento' => $data->documento,
//                            'id_usuario' => 1,
//                            'seguimiento' => "El pedido " . $data->documento . " no ha sido finalizado, se borraran las series y se manda a pendiente de remision."
//                        ]);
//                        return response()->json([
//                            "code" => 500,
//                            "message" => "El documento no ha sido finalizado." . self::logVariableLocation(),
//                            "color" => "red-border-top"
//                        ]);
//                    }
//                } else {
                if ($fase_documento->id_fase < 3) {
                    $this->eliminarSeries($data->documento);
                    DB::table('seguimiento')->insert([
                        'id_documento' => $data->documento,
                        'id_usuario' => 1,
                        'seguimiento' => "El pedido " . $data->documento . " no ha sido finalizado, se borraran las series y se manda a pendiente de remision."
                    ]);
                    return response()->json([
                        "code" => 500,
                        "message" => "El documento no ha sido finalizado." . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }
//                }

                $impresora = DB::table("usuario")
                    ->join("impresora", "usuario.id_impresora_packing", "=", "impresora.id")
                    ->select("impresora.cups", "impresora.servidor")
                    ->where("usuario.id", $auth->id)
                    ->first();

                if (empty($impresora)) {
                    $this->eliminarSeries($data->documento);
                    DB::table('seguimiento')->insert([
                        'id_documento' => $data->documento,
                        'id_usuario' => 1,
                        'seguimiento' => "No se encontro la impresora del usuario, se borraran las series y se manda a pendiente de remision."
                    ]);
                    return response()->json([
                        "code" => 500,
                        "message" => "No se encontró la impresora del usuario." . self::logVariableLocation(),
                        "color" => "red-border-top"
                    ]);
                }

                # Verificamos si el marketplace del documento, nos proporciona la guía
                $informacion_marketplace = DB::select("SELECT
                                                            marketplace_api.id
                                                        FROM documento
                                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                                        AND documento.id = " . $data->documento . "
                                                        AND marketplace_api.guia = 1");

                # Sí el marketplace no nos proporciona la guía, la debemos generar en caso de que la paquetería tenga api
                if (empty($informacion_marketplace)) {
                    # Sí la paquetería no contiene api, debemos verificar que haya archivos adjuntos que son guías
                    $archivos_embarque = DB::select("SELECT
                                                        documento_archivo.id
                                                    FROM documento_archivo
                                                    WHERE documento_archivo.id_documento = " . $data->documento . "
                                                    AND documento_archivo.tipo = 2");

                    if (empty($archivos_embarque)) {
                        if (!$info_documento->api) {
                            $this->eliminarSeries($data->documento);
                            DB::table('seguimiento')->insert([
                                'id_documento' => $data->documento,
                                'id_usuario' => 1,
                                'seguimiento' => "El documento no contiene archivos de embarque adjuntos, el marketplace no proporciona la guía y tampoco la paquetería contiene api, se borraran las series y se manda a pendiente de remision."
                            ]);
                            return response()->json([
                                "code" => 500,
                                "message" => "El documento no contiene archivos de embarque adjuntos, el marketplace no proporciona la guía y tampoco la paquetería contiene api, favor de contactar a un administrador." . self::logVariableLocation(),
                                "color" => "red-border-top"
                            ]);
                        } else {
                            $crear_guia = GeneralService::generarGuiaDocumento($data->documento, $auth->id);

                            if ($crear_guia->error) {
                                $this->eliminarSeries($data->documento);
                                DB::table('seguimiento')->insert([
                                    'id_documento' => $data->documento,
                                    'id_usuario' => 1,
                                    'seguimiento' => "Error al generar la guía, se borraran las series y se manda a pendiente de remision."
                                ]);
                                return response()->json([
                                    "code" => 500,
                                    "message" => $crear_guia->mensaje . " " . self::logVariableLocation(),
                                    "color" => "blue-border-top",
                                    "raw" => property_exists($crear_guia, "raw") ? $crear_guia->raw : 0
                                ]);
                            }

                            $solicitar_guia = 0;
                        }
                    }
                }

                $file = "";
                $pdf = "";

                if($auth->id != 9999) {
                    $impresion_raw = json_decode(file_get_contents($impresora->servidor . "/raspberry-print-server/public/print/" . $data->documento . "/" . $impresora->cups . "?token=" . $request->get("token") . ""));
                    $impresion = @$impresion_raw;

                    if (empty($impresion)) {
                        $this->eliminarSeries($data->documento);
                        DB::table('seguimiento')->insert([
                            'id_documento' => $data->documento,
                            'id_usuario' => $auth->id,
                            'seguimiento' => "Hubo un problema al imprimir la guia, se eliminaron la series y se regreso a pendiente de remisión.."
                        ]);

                        return response()->json([
                            "code" => 500,
                            "message" => "No fue posible imprimir la guía de embarque, No se encontro la guia, error desconocido." . self::logVariableLocation(),
                            "color" => property_exists($impresion, "key") ? "orange-border-top" : "blue-border-top",
                            "raw" => $impresion_raw
                        ]);
                    }

                    if ($impresion->code != 200) {
                        $this->eliminarSeries($data->documento);
                        DB::table('seguimiento')->insert([
                            'id_documento' => $data->documento,
                            'id_usuario' => $auth->id,
                            'seguimiento' => "Hubo un problema al imprimir la guia, la series se eliminaron y se regresa a pendiente de remisión. " . $impresion->message
                        ]);

                        return response()->json([
                            "code" => 500,
                            "message" => "Hubo un problema al imprimir la guia, la series se eliminaron y se regresa a pendiente de remisión, error: " . $impresion->message . "." . self::logVariableLocation(),
                            "color" => property_exists($impresion, "key") ? "orange-border-top" : "blue-border-top",
                            "raw" => property_exists($impresion, "raw") ? $impresion->raw : 0
                        ]);
                    }
                }
                else {
                    $archivo_guia = ComodinService::logistica_envio_pendiente_documento($data->documento,$marketplace_area->id_marketplace_area);
                    $respuesta = json_decode($archivo_guia->getContent());

                    if($respuesta->code == 200){
                        $file = $respuesta->file;
                        $pdf = $respuesta->pdf;
                        $backup = 1;
                    }
                }

                DB::table("documento")->where(["id" => $data->documento])->update([
                    "guia_impresa" => 1
                ]);
            }
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_fase' => 6,
            'packing_by' => $auth->id,
            'packing_date' => date('Y-m-d H:i:s'),
            'shipping_date' => date('Y-m-d H:i:s'),
            'problema' => $data->problema
        ]);

        if (!$info_documento->anticipada && ($info_documento->documento_extra == "N/A" || $info_documento->documento_extra == "")) {
            $crear_factura = InventarioService::aplicarMovimiento($data->documento);

            if ($crear_factura->error) {
                file_put_contents("logs/documentos.log", date("d/m/Y H:i:s") . " Ocurrió un error al crear la factura " . $data->documento . " en packing v2." . PHP_EOL, FILE_APPEND);

                DB::table('documento')->where(['id' => $data->documento])->update([
                    'id_fase' => 5,
                ]);

                DB::table('seguimiento')->insert([
                    'id_documento' => $data->documento,
                    'id_usuario' => 1,
                    'seguimiento' => "Ocurrió un error al generar la factura y se manda a fase Factura."
                ]);

                $emails = "";
                $correos = DB::select("SELECT
                                usuario.email
                            FROM usuario
                            INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                            INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                            INNER JOIN subnivel on subnivel_nivel.id_subnivel = subnivel.id
                            WHERE subnivel.subnivel in ('CXC','CXP') and usuario.email like '%@omg%'
                            GROUP BY usuario.email");

                foreach ($correos as $correo) {
                    $emails .= $correo->email . ";";
                }

                $emails .= "sistemas@omg.com.mx";

                $vista = view('email.notificacion_factura')->with([
                    'mensaje' => $crear_factura->mensaje,
                    'anio' => date('Y'),
                    'documento' => $data->documento
                ]);

                $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
                $domain = "omg.com.mx";
                $mg->messages()->send($domain, array(
                    'from' => 'CRM OMG International <crm@omg.com.mx>',
                    'to' => $emails,
                    'subject' => 'Error al generar factura',
                    'html' => $vista->render()
                ));

                return response()->json([
                    "code" => 200,
                    "message" => "No fue posible generar la factura del documento, mensaje de error: " . $crear_factura->mensaje . ", favor de contactar a un administrador." . self::logVariableLocation(),
                    "raw" => property_exists($crear_factura, "raw") ? $crear_factura->raw : 0,
                    "color" => "pink-border-top",
                    "data" => property_exists($crear_factura, "data") ? $crear_factura->data : 0,
                    'solicitar_guia' => $solicitar_guia,
                    'file' => $file ?? null,
                    'pdf' => $pdf ?? null,
                    'backup' => $backup
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente.",
            "color" => "green-border-top",
            'solicitar_guia' => $solicitar_guia,
            'file' => $file ?? null,
            'pdf' => $pdf ?? null,
            'backup' => $backup
        ]);
    }

    public function eliminarSeries($documento)
    {
        $info = DB::table('documento')->where('id', $documento)->first();

        if (!empty($info)) {
            if ($info->id_fase != 3) {
                DB::table('documento')->where('id', $documento)->update(['id_fase' => 3]);
            }
        }

        $movimientos = DB::table('movimiento')->where('id_documento', $documento)->get();

        if (!empty($movimientos)) {
            foreach ($movimientos as $movimiento) {
                $mov_produ = DB::table('movimiento_producto')->where('id_movimiento', $movimiento->id)->get();

                if (!empty($mov_produ)) {
                    foreach ($mov_produ as $mov) {
                        DB::table('producto')->where('id', $mov->id_producto)->update(['status' => 1]);

                        DB::table('movimiento_producto')->where('id', $mov->id)->delete();
                    }
                }
            }
        }
    }

    public function almacen_packing_v2_reimprimir(Request $request)
    {
        $data = json_decode($request->input("data"));

        $informacion_documento = DB::table("documento")
            ->where("id", $data->documento)
            ->select("id_fase")
            ->first();

        if (empty($informacion_documento)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró información del documento proporcionado" . self::logVariableLocation()
            ]);
        }

        if ($informacion_documento->id_fase != 6 && $informacion_documento->id_fase != 5) {
            return response()->json([
                "code" => 500,
                "message" => "El pedido tiene que estar finalizado para poder reimprimir la guía" . self::logVariableLocation()
            ]);
        }

        $impresora = DB::table("impresora")
            ->select("servidor", "cups")
            ->where("id", $data->impresora)
            ->first();

        $impresion_raw = json_decode(file_get_contents($impresora->servidor . "/raspberry-print-server/public/print/" . $data->documento . "/" . $impresora->cups . "?token=" . $request->get("token") . ""));
        $impresion = @$impresion_raw;

        if (empty($impresion)) {
            return response()->json([
                "code" => 500,
                "message" => "No fue posible imprimir la guía de embarque, favor de contactar a un administrador, error desconocido." . self::logVariableLocation(),
                "raw" => $impresion_raw,
                "a" => $impresora->servidor . "/raspberry-print-server/public/print/" . $data->documento . "/" . $impresora->cups . "?token=" . $request->get("token")
            ]);
        }

        if ($impresion->code != 200) {
            return response()->json([
                "code" => 500,
                "message" => "No fue posible imprimir la guía de embarque, favor de contactar a un administrador, error: " . $impresion->message . ".",
                "raw" => property_exists($impresion, "raw") ? $impresion->raw : 0
            ]);
        }

        DB::table("documento")->where(["id" => $data->documento])->update([
            "guia_impresa" => 1
        ]);

        $guia_escaneada = DB::table("documento_guia")
            ->where("id_documento", $data->documento)
            ->first();

        $paqueteria = DB::table("documento")
            ->join("paqueteria", "documento.id_paqueteria", "=", "paqueteria.id")
            ->where("documento.id", $data->documento)
            ->select("paqueteria.guia")
            ->first();

        return response()->json([
            "code" => 200,
            "message" => "Guia impresa correctamente",
            "solicitar_guia" => empty($guia_escaneada) && $paqueteria->guia ? 1 : 0,
            "raw" => $impresion_raw
        ]);
    }

    /* Almacen > Movimiento */
    public function almacen_movimiento_crear_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $empresas = Empresa::whereHas("usuarios", function ($query) use ($auth) {
            return $query->where("id_usuario", $auth->id);
        })
            ->with("almacenes.almacen")
            ->get();

        $tipos = DocumentoTipo::whereIn("id", [
            EnumDocumentoTipo::ENTRADA,
            EnumDocumentoTipo::SALIDA,
            EnumDocumentoTipo::TRASPASO,
            EnumDocumentoTipo::USO_INTERNO
        ])
            ->get();

        return response()->json([
            "empresas" => $empresas,
            "tipos" => $tipos
        ]);
    }

    public function almacen_movimiento_data_producto($producto)
    {
        $productos = DB::table('modelo')
            ->where('descripcion', 'like', '%' . $producto . '%')
            ->orWhere('sku', $producto)
            ->get();


        return response()->json([
            'code' => 200,
            'productos' => $productos
        ]);
    }

    public function almacen_movimiento_crear_producto($producto)
    {
        $producto  = DB::select("SELECT serie FROM modelo WHERE sku = '" . TRIM($producto) . "'");

        if (empty($producto)) {
            $producto = new stdClass();
            $producto->serie = 0;
        } else {
            $producto = $producto[0];
        }

        return response()->json([
            'code' => 200,
            'producto' => $producto
        ]);
    }


//    public function almacen_movimiento_crear_crear(Request $request)
//    {
//        set_time_limit(0);
//        DB::beginTransaction();
//
//        try {
//            $data = json_decode($request->input('data'));
//            $auth = json_decode($request->auth);
//            $series_afectadas = array();
//
//            // Se obtienen los almacenes, según si son de entrada o salida
//            if (!empty($data->almacen_entrada)) {
//                $id_almacen_entrada = EmpresaAlmacen::find($data->almacen_entrada);
//            }
//            if (!empty($data->almacen_salida)) {
//                $id_almacen_salida = EmpresaAlmacen::find($data->almacen_salida);
//            }
//
//            // Creación del documento
//            $documento = Documento::create([
//                'id_almacen_principal_empresa' => in_array($data->tipo, [EnumDocumentoTipo::ENTRADA, EnumDocumentoTipo::TRASPASO]) ? $data->almacen_entrada : $data->almacen_salida,
//                'id_almacen_secundario_empresa' => in_array($data->tipo, [EnumDocumentoTipo::SALIDA, EnumDocumentoTipo::TRASPASO, EnumDocumentoTipo::USO_INTERNO]) ? $data->almacen_salida : 0,
//                'id_tipo' => $data->tipo,
//                'id_usuario' => $auth->id,
//                'id_fase' => 100,
//                'autorizado' => $data->tipo == EnumDocumentoTipo::ENTRADA ? 1 : 0,
//                'referencia' => 'N/A',
//                'info_extra' => 'N/A',
//                'observacion' => $data->observacion
//            ])->id;
//
//            // Verificar existencia de la entidad; si no existe, crearla
//            $existe_entidad = DocumentoEntidad::where("RFC", "SISTEMAOMG")->first();
//            if (!$existe_entidad) {
//                $entidad_id = DocumentoEntidad::create([
//                    'tipo' => 2,
//                    'razon_social' => 'SISTEMA OMG',
//                    'rfc' => 'SISTEMAOMG'
//                ])->id;
//            } else {
//                $entidad_id = $existe_entidad->id;
//            }
//            DocumentoEntidadRelacion::create([
//                'id_documento' => $documento,
//                'id_entidad' => $entidad_id
//            ]);
//
//            // Procesar cada producto contenido en el documento
//            foreach ($data->productos as $producto) {
//                $existe_modelo = Modelo::where("sku", trim($producto->sku))->first();
//                if (!$existe_modelo) {
//                    $modelo = Modelo::create([
//                        'id_tipo' => EnumModeloTipo::PRODUCTO,
//                        'sku' => trim($producto->sku),
//                        'descripcion' => $producto->descripcion,
//                        'costo' => $producto->costo,
//                        'alto' => $producto->alto,
//                        'ancho' => $producto->ancho,
//                        'largo' => $producto->largo,
//                        'peso' => $producto->peso,
//                        'serie' => $producto->serie
//                    ])->id;
//                } else {
//                    $modelo = $existe_modelo->id;
//                }
//
//                $movimiento = Movimiento::create([
//                    'id_documento' => $documento,
//                    'id_modelo' => $modelo,
//                    'cantidad' => $producto->serie ? count($producto->series) : $producto->cantidad,
//                    'precio' => $producto->costo,
//                    'garantia' => 0,
//                    'modificacion' => 'N/A',
//                    'comentario' => $producto->comentarios,
//                    'regalo' => 0
//                ])->id;
//
//                // Si el producto se gestiona por series
//                if ($producto->serie) {
//                    // Solo se valida si el documento NO es de tipo ENTRADA
//                    if ($data->tipo != EnumDocumentoTipo::ENTRADA) {
//                        // Se reutiliza la función de validación de series de ComodinService
//                        $validacion = ComodinService::validar_series($producto->series, trim($producto->sku));
//                        if ($validacion->error == 1) {
//                            return response()->json([
//                                'code'  => 500,
//                                "color" => "red-border-top",
//                                'message'   => $validacion->mensaje . " " . self::logVariableLocation(),
//                                'errores'   => $validacion->errores
//                            ], 500);
//                        }
//                    }
//
//                    // Procesar cada serie individualmente
//                    foreach ($producto->series as $serie) {
//                        // Se eliminan caracteres conflictivos
//                        $serie = str_replace(["'", '\\'], '', $serie);
//                        $existe_serie = Producto::where("serie", trim($serie))->first();
//
//                        if (!$existe_serie) {
//                            if($data->tipo != EnumDocumentoTipo::ENTRADA){
//                                $productoId = Producto::create([
//                                    'id_almacen' => in_array($data->tipo, [EnumDocumentoTipo::SALIDA, EnumDocumentoTipo::USO_INTERNO])
//                                        ? $id_almacen_salida->id_almacen
//                                        : $id_almacen_entrada->id_almacen,
//                                    'serie' => trim($serie),
//                                    'status' => $data->tipo == EnumDocumentoTipo::ENTRADA ? 1 : 0
//                                ])->id;
//
//                                $serie_afectada = new \stdClass();
//                                $serie_afectada->id = $productoId;
//                                $serie_afectada->almacen_previo = in_array($data->tipo, [EnumDocumentoTipo::SALIDA, EnumDocumentoTipo::USO_INTERNO])
//                                    ? $id_almacen_salida->id_almacen
//                                    : $id_almacen_entrada->id_almacen;
//                                $serie_afectada->status_previo = $data->tipo == "5" ? 1 : 0;
//
//                                array_push($series_afectadas, $serie_afectada);
//                            }
//                        } else {
//                            Producto::where("id", $existe_serie->id)->update([
//                                'id_almacen' => in_array($data->tipo, [EnumDocumentoTipo::SALIDA, EnumDocumentoTipo::USO_INTERNO])
//                                    ? $id_almacen_salida->id_almacen
//                                    : $id_almacen_entrada->id_almacen,
//                                'status' => $data->tipo == EnumDocumentoTipo::ENTRADA ? 1 : 0
//                            ]);
//
//                            $productoId = $existe_serie->id;
//
//                            $serie_afectada = new \stdClass();
//                            $serie_afectada->id = $existe_serie->id;
//                            $serie_afectada->almacen_previo = $existe_serie->id_almacen;
//                            $serie_afectada->status_previo = $existe_serie->status;
//
//                            array_push($series_afectadas, $serie_afectada);
//                        }
//
//                        MovimientoProducto::create([
//                            'id_movimiento' => $movimiento,
//                            'id_producto' => $productoId
//                        ]);
//                    }
//                }
//            }
//
//            $mensaje = "Documento creado correctamente con el ID " . $documento;
//
//            if (in_array($data->tipo, [EnumDocumentoTipo::ENTRADA, EnumDocumentoTipo::TRASPASO, EnumDocumentoTipo::USO_INTERNO])) {
//                $response = DocumentoService::crearMovimiento($documento);
//
//                if ($response->error) {
//                    // Si ocurre error en el proceso de creación del movimiento, se revierten los cambios de las series afectadas
//                    foreach ($series_afectadas as $serie) {
//                        if ($data->tipo == EnumDocumentoTipo::ENTRADA) {
//                            DB::table('producto')->where(['id' => $serie->id])->delete();
//                        } else {
//                            DB::table('producto')->where(['id' => $serie->id])->update([
//                                'id_almacen' => $serie->almacen_previo,
//                                'status' => $serie->status_previo
//                            ]);
//                        }
//                    }
//
//                    DB::rollBack();
//                    return response()->json([
//                        'message' => $response->mensaje . " " . self::logVariableLocation(),
//                        'raw' => property_exists($response, "raw") ? $response->raw : 0
//                    ], 500);
//                }
//
//                $mensaje = $response->mensaje . " ID " . $documento;
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => $mensaje,
//                'documento' => $documento
//            ]);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Error al crear el documento: ' . $e->getMessage(),
//            ], 500);
//        }
//    }

    public function almacen_movimiento_crear_crear(Request $request)
    {
        set_time_limit(0);

        DB::beginTransaction();

        try {
            $data = json_decode($request->input('data'));
            $auth = json_decode($request->auth);
            $series_afectadas = array();

            // Se obtienen los almacenes según si son de entrada o salida
            if (!empty($data->almacen_entrada)) {
                $id_almacen_entrada = EmpresaAlmacen::find($data->almacen_entrada);
            }
            if (!empty($data->almacen_salida)) {
                $id_almacen_salida = EmpresaAlmacen::find($data->almacen_salida);
            }

            // Creación del documento
            $documento = Documento::create([
                'id_almacen_principal_empresa' => in_array($data->tipo, [EnumDocumentoTipo::ENTRADA, EnumDocumentoTipo::TRASPASO]) ? $data->almacen_entrada : $data->almacen_salida,
                'id_almacen_secundario_empresa' => in_array($data->tipo, [EnumDocumentoTipo::SALIDA, EnumDocumentoTipo::TRASPASO, EnumDocumentoTipo::USO_INTERNO]) ? $data->almacen_salida : 0,
                'id_tipo' => $data->tipo,
                'id_usuario' => $auth->id,
                'id_fase' => 100,
                'autorizado' => $data->tipo == EnumDocumentoTipo::ENTRADA ? 1 : 0,
                'referencia' => 'N/A',
                'info_extra' => 'N/A',
                'observacion' => $data->observacion
            ])->id;

            // Verificar existencia de la entidad; si no existe, crearla
            $existe_entidad = DocumentoEntidad::where("RFC", "SISTEMAOMG")->first();
            if (!$existe_entidad) {
                $entidad_id = DocumentoEntidad::create([
                    'tipo' => 2,
                    'razon_social' => 'SISTEMA OMG',
                    'rfc' => 'SISTEMAOMG'
                ])->id;
            } else {
                $entidad_id = $existe_entidad->id;
            }
            DocumentoEntidadRelacion::create([
                'id_documento' => $documento,
                'id_entidad' => $entidad_id
            ]);

            // Procesar cada producto contenido en el documento
            foreach ($data->productos as $producto) {

                // Verificar existencia del modelo; si no existe se crea
                $existe_modelo = DB::table('modelo')->where('sku', $producto->sku)->first();

                if(empty($existe_modelo)){
                    DB::rollBack();
                    return response()->json([
                        'message' => "No existe el producto " . trim($producto->sku)
                    ], 500);
                }

                // Creación del movimiento
                $movimiento = Movimiento::create([
                    'id_documento' => $documento,
                    'id_modelo' => $existe_modelo->id,
                    'cantidad' => $producto->serie ? count($producto->series) : $producto->cantidad,
                    'precio' => (float)str_replace(',', '', $producto->costo),
                    'garantia' => 0,
                    'modificacion' => 'N/A',
                    'comentario' => $producto->comentarios,
                    'regalo' => 0
                ])->id;

                // Validar existencias para documentos que NO sean de entrada.
                // Ahora, para SALIDA, TRASPASO y USO_INTERNO, se utiliza el almacén de salida para verificar el stock.
                if ($data->tipo != EnumDocumentoTipo::ENTRADA) {
                    $sourceAlmacenId = $id_almacen_salida->id_almacen;
                    $stock = InventarioService::stockDisponible(trim($producto->sku), $sourceAlmacenId);
                    $cantidadRequerida = $producto->serie ? count($producto->series) : $producto->cantidad;
                    if ($stock->error || $stock->disponible < $cantidadRequerida) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Stock insuficiente para el producto " . trim($producto->sku) . ". Disponible: " . $stock->disponible . ", requerido: " . $cantidadRequerida
                        ], 500);
                    }
                }

                // Si el producto se gestiona por series
                if ($producto->serie) {
                    if ($data->tipo == EnumDocumentoTipo::ENTRADA) {
                        // Validar que las series NO existan en la BD usando la función de validación para entrada.
                        $validacion = ComodinService::validar_series_entrada($producto->series, trim($producto->sku));
                        if ($validacion->error == 1) {
                            DB::rollBack();
                            return response()->json([
                                'message' => $validacion->mensaje . " " . self::logVariableLocation(),
                                'errores' => $validacion->errores
                            ]);
                        }

                        // Procesar cada serie: al no existir, se crean nuevos registros
                        foreach ($producto->series as $serie) {
                            // Eliminar caracteres conflictivos
                            $serie = str_replace(["'", '\\'], '', $serie);
                            $existe_serie = Producto::where("serie", trim($serie))->first();
                            if ($existe_serie) {
                                // Si por alguna inconsistencia la serie ya existe, se aborta
                                DB::rollBack();
                                return response()->json([
                                    'message' => "La serie " . $serie . " ya existe en la Base de Datos."
                                ], 500);
                            } else {
                                $productoId = Producto::create([
                                    'id_almacen' => $id_almacen_entrada->id_almacen,
                                    'serie' => trim($serie),
                                    'status' => 1,
                                    'id_modelo' => $existe_modelo->id
                                ])->id;

                                MovimientoProducto::create([
                                    'id_movimiento' => $movimiento,
                                    'id_producto' => $productoId
                                ]);
                            }
                        }
                    } else {
                        // Para SALIDA, TRASPASO y USO_INTERNO se utiliza la función existente de validación de series
                        $validacion = ComodinService::validar_series($producto->series, trim($producto->sku));
                        if ($validacion->error == 1) {
                            DB::rollBack();
                            return response()->json([
                                'code'  => 500,
                                "color" => "red-border-top",
                                'message'   => $validacion->mensaje . " " . self::logVariableLocation(),
                                'errores'   => $validacion->errores
                            ]);
                        }
                        // Procesar cada serie: si existe se actualiza, si no se crea
                        foreach ($producto->series as $serie) {
                            $serie = str_replace(["'", '\\'], '', $serie);
                            $existe_serie = Producto::where("serie", trim($serie))->first();

                            if (!$existe_serie) {
                                return response()->json([
                                    'code'  => 500,
                                    'message' => "La serie no existe en la Base de Datos."
                                ]);
                            } else {
                                Producto::where("id", $existe_serie->id)->update([
                                    'id_almacen' => $id_almacen_salida->id_almacen,
                                    'status' => 0
                                ]);
                                $productoId = $existe_serie->id;
                                $serie_afectada = new stdClass();
                                $serie_afectada->id = $existe_serie->id;
                                $serie_afectada->almacen_previo = $existe_serie->id_almacen;
                                $serie_afectada->status_previo = $existe_serie->status;
                                array_push($series_afectadas, $serie_afectada);
                            }

                            MovimientoProducto::create([
                                'id_movimiento' => $movimiento,
                                'id_producto' => $productoId
                            ]);
                        }
                    }
                }
            }

            $mensaje = "Documento creado correctamente con el ID " . $documento;

            if (!in_array($data->tipo, [EnumDocumentoTipo::TRASPASO])) {
                $response = DocumentoService::afectarMovimiento($documento);

                if ($response->error) {
                    // En caso de error en la creación del movimiento, se revierten los cambios realizados en las series
                    foreach ($series_afectadas as $serie) {
                        if ($data->tipo == EnumDocumentoTipo::ENTRADA) {
                            DB::table('producto')->where(['id' => $serie->id])->delete();
                        } else {
                            DB::table('producto')->where(['id' => $serie->id])->update([
                                'id_almacen' => $serie->almacen_previo,
                                'status' => $serie->status_previo
                            ]);
                        }
                    }

                    DB::rollBack();
                    return response()->json([
                        'message' => $response->mensaje . " " . self::logVariableLocation(),
                        'raw' => property_exists($response, "raw") ? $response->raw : 0
                    ], 500);
                }

                $mensaje = $response->mensaje . " ID " . $documento;
            }

            DB::commit();

            return response()->json([
                'message' => $mensaje,
                'documento' => $documento
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear el documento: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function almacen_movimiento_crear_confirmar_authy(Request $request)
    {
        $auth = json_decode($request->auth);
        $authy_code = json_decode($request->input("authy_code"));

        $validate_authy = DocumentoService::authy($auth->id, $authy_code);

        if ($validate_authy->error) {
            return response()->json([
                "message" => $validate_authy->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        return response()->json();
    }

    public function almacen_movimiento_historial()
    {
        $tipos_documento = DB::table("documento_tipo")
            ->select("id", "tipo")
            ->whereIn("id", [3, 4, 5, 11])
            ->get()
            ->toArray();

        return response()->json([
            'tipos_documento' => $tipos_documento
        ]);
    }

    public function almacen_movimiento_historial_data(Request $request)
    {
        set_time_limit(0);

        $auth = json_decode($request->auth);
        $data = json_decode($request->input("data"));

        $afectador_es_almacen_o_admin = DB::table("usuario_subnivel_nivel")
            ->select("nivel.id")
            ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
            ->join("nivel", "subnivel_nivel.id_nivel", "=", "nivel.id")
            ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
            ->where("nivel.nivel", "ALMACEN")
            ->orWhere("nivel.nivel", "ADMINISTRADOR")
            ->first();

        $query_filter = empty($data->document) ? " AND documento.id_tipo = " . $data->type . " AND documento.created_at BETWEEN '" . $data->initial_date . " 00:00:00' AND '" . $data->final_date . " 23:59:59'"
            : ($data->su ? " AND (documento.id = " . $data->document . " OR documento.documento_extra = " . $data->document . ") AND documento.id_tipo IN (3, 4, 5, 11)"
                : " AND (documento.id = " . $data->document . " OR documento.documento_extra = " . $data->document . ") AND documento.id_tipo IN (3, 4, 5, 11)");



        $documentos = DB::select("SELECT
                                    usuario.id AS id_usuario,
                                    usuario.nombre,
                                    documento.id,
                                    documento.factura_folio,
                                    documento.id_tipo,
                                    documento_tipo.tipo,
                                    documento.id_almacen_principal_empresa AS id_almacen_principal,
                                    documento.id_almacen_secundario_empresa AS id_almacen_secundario,
                                    IF (documento.id_tipo = 4, (
                                        SELECT
                                            empresa.bd
                                        FROM empresa_almacen
                                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                        WHERE empresa_almacen.id = id_almacen_secundario
                                    ), (
                                        SELECT
                                            empresa.bd
                                        FROM empresa_almacen
                                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                        WHERE empresa_almacen.id = id_almacen_principal
                                    )) AS empresa,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_principal
                                    ) AS almacen_principal,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_secundario
                                    ) AS almacen_secundario,
                                    documento.created_at,
                                    documento.observacion,
                                    documento.autorizado,
                                    documento.autorizado_by,
                                    documento.importado,
                                    IF(documento.autorizado_by = 0, 'SIN REGISTRO', (SELECT nombre FROM usuario WHERE id = documento.autorizado_by)) AS autorizado_por
                                FROM documento
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                INNER JOIN documento_tipo ON documento.id_tipo = documento_tipo.id
                                WHERE documento.id_usuario != 1
                                AND documento.status = 1
                                " . $query_filter . "");

        foreach ($documentos as $documento) {
            $productos = DB::select("SELECT
                                        movimiento.id,
                                        movimiento.cantidad,
                                        movimiento.precio,
                                        modelo.sku,
                                        modelo.descripcion,
                                        modelo.serie,
                                        0 AS almacen
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $documento->id . "");

            foreach ($productos as $k => $producto) {
                if ($producto->serie) {
                    $series = DB::select("SELECT
                                        producto.id,
                                        producto.serie
                                    FROM movimiento_producto
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento_producto.id_movimiento = " . $producto->id . "");

                    $producto->series = $series;
                }
            }

            $creador_es_soporte = DB::table("usuario_subnivel_nivel")
                ->select("nivel.id")
                ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
                ->join("nivel", "subnivel_nivel.id_nivel", "=", "nivel.id")
                ->where("usuario_subnivel_nivel.id_usuario", $documento->id_usuario)
                ->where("nivel.nivel", "SOPORTE")
                ->first();

            $afectador_de_traspasos =
                DB::table('usuario_subnivel_nivel')
                ->select('id_usuario')
                ->where('id_subnivel_nivel', 63)
                ->where('id_usuario', $auth->id)
                ->first();

            $documento->puede_afectar = $creador_es_soporte ? ($afectador_es_almacen_o_admin ? 1 : 0) : 1;
            $documento->afectador_de_traspasos = $afectador_de_traspasos ? 1 : 0;
            $documento->productos = $productos;
        }

        return response()->json([
            'code'  => 200,
            'documentos'    => $documentos,
            'ss' => $data->su
        ]);
    }

    public function almacen_movimiento_historial_afectar(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $puede_afectar = DB::table("usuario_subnivel_nivel")
            ->select("nivel.id")
            ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
            ->join("nivel", "subnivel_nivel.id_nivel", "=", "nivel.id")
            ->join("subnivel", "subnivel_nivel.id_subnivel", "=", "subnivel.id")
            ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
            ->whereIn("nivel.nivel", ["ALMACEN", "SOPORTE", "ADMINISTRADOR"])
            ->where("subnivel.subnivel", "ADMINISTRADOR")
            ->first();

        $afectador_de_traspasos =
            DB::table('usuario_subnivel_nivel')
            ->select('id_usuario')
            ->where('id_subnivel_nivel', 63)
            ->where('id_usuario', $auth->id)
            ->first();

        if (!$puede_afectar  && !$afectador_de_traspasos) {
            return response()->json([
                "message" => "No tienes permisos para afectar traspasos" . self::logVariableLocation()
            ], 401);
        }

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        try {
            $tipo_documento = DB::select("SELECT id_tipo FROM documento WHERE id = " . $data->document)[0]->id_tipo;

            if ($tipo_documento == 4) {
                $response = DocumentoService::crearMovimiento($data->document);

                if ($response->error) {
                    return response()->json([
                        'message' => $response->mensaje . " " . self::logVariableLocation()
                    ], 500);
                }

                $series = DB::select("SELECT
                                    producto.id
                                FROM movimiento
                                INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                WHERE movimiento.id_documento = " . $data->document);

                foreach ($series as $serie) {
                    DB::table('producto')->where('id', $serie->id)->update([
                        'status' => 0
                    ]);
                }
            } else {
                if ($tipo_documento == 5) {
                    $response = DocumentoService::afectarMovimiento($data->document);

                    if ($response->error) {
                        return response()->json([
                            'message' => $response->mensaje . " " . self::logVariableLocation()
                        ], 500);
                    }
                }

                $series = DB::select("SELECT
                                    producto.id
                                FROM movimiento
                                INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                WHERE movimiento.id_documento = " . $data->document . "");

                foreach ($series as $serie) {
                    DB::table('producto')->where('id', $serie->id)->update([
                        'status' => 1
                    ]);
                }
            }

            DB::table('documento')->where('id', $data->document)->update([
                'autorizado' => 1,
                'autorizado_by' => $auth->id
            ]);

            return response()->json([
                'message' => "Inventario afectado correctamente."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => "Ocurrió un error al afectar el inventario, mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
            ], 500);
        }
    }

    public function almacen_movimiento_historial_interno(Request $request)
    {
        $data = json_decode($request->input('data'));

        $almacen_documento = DB::select("SELECT id_almacen FROM empresa_almacen WHERE id = " . $data->id_almacen_secundario . "");

        if (empty($almacen_documento)) {
            return response()->json([
                'code'  => 404,
                'message'   => "No se encontró el almacén del documento." . self::logVariableLocation()
            ]);
        }

        $almacen_documento = $almacen_documento[0];

        if ($almacen_documento->id_almacen == 0) {
            return response()->json([
                'code'  => 404,
                'message'   => "No se encontró el almacén del documento." . self::logVariableLocation()
            ]);
        }

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                if (count($producto->series_afectar) != $producto->cantidad) {
                    return response()->json([
                        'code'  => 500,
                        'message'   => "El total de series capturadas no concuerda con la cantidad del producto " . $producto->sku . ", favor de verificar e intentar de nuevo." . self::logVariableLocation()
                    ]);
                }

                $validar = ComodinService::validar_series($producto->series_afectar, $producto->sku);

                if($validar->error){
                    return response()->json([
                        'code'  => 500,
                        'message'   => $validar->mensaje . " " . self::logVariableLocation()
                    ]);
                }

                foreach ($producto->series_afectar as $serie) {
                    //                    $apos = `'`;
                    //                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                    //                    if (str_contains($serie, $apos)) {
                    //                        $serie = addslashes($serie);
                    //                    }
                    $serie = str_replace(["'", '\\'], '', $serie);
                    $existe_serie = DB::select("SELECT
                                                    producto.id,
                                                    producto.status,
                                                    producto.id_almacen,
                                                    almacen.almacen,
                                                    modelo.sku
                                                FROM producto
                                                INNER JOIN almacen ON producto.id_almacen = almacen.id
                                                INNER JOIN movimiento_producto ON producto.id = movimiento_producto.id_producto
                                                INNER JOIN movimiento ON movimiento_producto.id_movimiento = movimiento.id
                                                INNER JOIN documento ON movimiento.id_documento = documento.id
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE producto.serie = '" . trim($serie) . "'
                                                AND documento.status = 1
                                                ORDER BY documento.created_at DESC");

                    if (empty($existe_serie)) {
                        return response()->json([
                            'code'  => 404,
                            'message'   => "La serie " . trim($serie) . " del producto " . $producto->sku . " no existe registrada en el sistema." . self::logVariableLocation()
                        ]);
                    }

                    $existe_serie = $existe_serie[0];

                    if ($existe_serie->sku != $producto->sku) {
                        return response()->json([
                            'code'  => 404,
                            'message'   => "La serie " . trim($serie) . " del producto " . $producto->sku . " no pertenece al producto ingresado.<br><br>SKU de la serie: " . $existe_serie->sku . "<br>SKU de la partida: " . $producto->sku . "" . self::logVariableLocation()
                        ]);
                    }

                    if ($existe_serie->id_almacen != $almacen_documento->id_almacen) {
                        return response()->json([
                            'code'  => 404,
                            'message'   => "La serie " . trim($serie) . " del producto " . $producto->sku . " no se encuentra en el almacén seleccionado para su salida.<br><br>Almacén de la serie: " . $existe_serie->almacen . "<br>Almacén del documento: " . $data->almacen_secundario . "" . self::logVariableLocation()
                        ]);
                    }
                }
            }
        }

        $response = DocumentoService::crearMovimiento($data->id);

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje . " " . self::logVariableLocation()
            ]);
        }

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                foreach ($producto->series_afectar as $serie) {
                    //                    $apos = `'`;
                    //                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                    //                    if (str_contains($serie, $apos)) {
                    //                        $serie = addslashes($serie);
                    //                    }
                    $serie = str_replace(["'", '\\'], '', $serie);
                    $id_serie = DB::select("SELECT id FROM producto WHERE serie = '" . trim($serie) . "'")[0]->id;

                    DB::table('movimiento_producto')->insert([
                        'id_movimiento' => $producto->id,
                        'id_producto'   => $id_serie
                    ]);

                    DB::table('producto')->where(['id' => $id_serie])->update([
                        'status' => 0
                    ]);
                }

                $producto->series = DB::select("SELECT
                                                    producto.id,
                                                    producto.serie
                                                FROM producto
                                                INNER JOIN movimiento_producto ON movimiento_producto.id_producto = producto.id
                                                WHERE movimiento_producto.id_movimiento = " . $producto->id . "");
            }
        }

        DB::table('documento')->where(['id' => $data->id])->update([
            'importado' => 1
        ]);

        return response()->json([
            'code'  => 200,
            'message'   => "Documento terminado correctamente.",
            'productos' => $data->productos
        ]);
    }

    public function almacen_movimiento_documento($documento)
    {
        $total      = 0;

        $informacion_documento = DB::select("SELECT
                                                usuario.nombre,
                                                usuario.email,
                                                documento_fase.fase,
                                                documento.factura_folio,
                                                documento_tipo.tipo,
                                                documento.id_tipo,
                                                documento.observacion,
                                                documento.id_almacen_principal_empresa AS id_almacen_principal,
                                                documento.id_almacen_secundario_empresa AS id_almacen_secundario,
                                                (
                                                    SELECT
                                                        almacen
                                                    FROM empresa_almacen
                                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                                    WHERE empresa_almacen.id = id_almacen_principal
                                                ) AS almacen_entrada,
                                                (
                                                    SELECT
                                                        almacen
                                                    FROM empresa_almacen
                                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                                    WHERE empresa_almacen.id = id_almacen_secundario
                                                ) AS almacen_salida,
                                                documento.created_at
                                            FROM documento
                                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                                            INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                            INNER JOIN documento_tipo ON documento.id_tipo = documento_tipo.id
                                            WHERE documento.id = " . $documento . "");

        if (empty($informacion_documento)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre el documento." . self::logVariableLocation()
            ]);
        }

        $informacion_documento = $informacion_documento[0];

        $productos = DB::select("SELECT 
                                modelo.sku, 
                                modelo.descripcion, 
                                movimiento.cantidad,
                                movimiento.garantia,
                                movimiento.comentario,
                                movimiento.precio as costo
                            FROM movimiento 
                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                            WHERE id_documento = " . $documento . "");


        # Formato de celda: X -> Tamaño de la celda, Y -> Margen de arriba, Z -> Texto

        if ($informacion_documento->id_tipo === 4 || $informacion_documento->id_tipo === 11) {
            $informacion_empresa = DB::select("SELECT
                                        empresa.logo,
                                        empresa.empresa
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_secundario_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "")[0];
        } else {
            $informacion_empresa = DB::select("SELECT
                                        empresa.logo,
                                        empresa.empresa
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . "")[0];
        }

        $barcode = new BarcodeGeneratorJPG();
        $barcode_image = "data://text/plain;base64," . base64_encode($barcode->getBarcode(substr($informacion_documento->tipo, 0, 4) . "&"  . $documento, $barcode::TYPE_CODE_128));
        $fontsize = 8;
        $pageline = 3;
        $anchotexto = 60;
        $alturatexto = 5;

        $pdf = app('FPDF');

        $pdf->AddPage('L', 'Letter');
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->SetTextColor(69, 90, 100);

        setlocale(LC_ALL, "es_MX");

        # Informacion de la empresa
        # OMG Logo y codigo de barra para el acceso rápido
        if ($informacion_empresa->logo != "N/A") {
            $pdf->Image($informacion_empresa->logo, 5, 5, 60, 20, 'png');
        }

        $pdf->Image($barcode_image, 210, 5, 60, 20, 'jpg');

        $pdf->Ln(20);
        $pdf->Cell(260, 0, '', 'T');
        $pdf->Ln();

        $pdf->Cell($anchotexto, 10, $informacion_empresa->empresa);
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell($anchotexto, 10, 'INFORMACION DEL MOVIMIENTO');
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Ln($pageline);
        $pdf->Cell($anchotexto, 10, $informacion_documento->nombre);

        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(50, 10, 'TIPO DE MOVIMIENTO: ');

        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(50, 10, $informacion_documento->tipo);

        $pdf->Ln($pageline);

        if ($informacion_documento->id_tipo == 5) {
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell($anchotexto, 10, '');
            $pdf->SetFont('Arial', 'B', $fontsize);
            $pdf->Cell(50, 10, "ALMACEN DE ENTRADA: ");
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell(50, 10, $informacion_documento->almacen_entrada);

            $pdf->Ln($pageline);

            $pdf->Cell($anchotexto, 10, '');
            $pdf->SetFont('Arial', 'B', $fontsize);
            $pdf->Cell(50, 10, "ALMACEN DE SALIDA: ");
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell(50, 10, $informacion_documento->almacen_salida);
        } else if ($informacion_documento->id_tipo == 3) {
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell($anchotexto, 10, "");
            $pdf->SetFont('Arial', 'B', $fontsize);
            $pdf->Cell(50, 10, "ALMACEN DE ENTRADA: ");
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell(50, 10, $informacion_documento->almacen_entrada);
        } else {
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell($anchotexto, 10, "");
            $pdf->SetFont('Arial', 'B', $fontsize);
            $pdf->Cell(50, 10, "ALMACEN DE SALIDA: ");
            $pdf->SetFont('Arial', '', $fontsize);
            $pdf->Cell(50, 10, $informacion_documento->almacen_salida);
        }

        $pdf->Ln($pageline);

        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell($anchotexto, 10, "");
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(50, 10, 'DOCUMENTO: ');

        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(30, 10, $documento . " - " . $informacion_documento->factura_folio);

        $pdf->Ln($pageline);
        $pdf->Cell($anchotexto, 10, '');
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(50, 10, 'FECHA: ');

        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(10, 10, utf8_decode(mb_strtoupper(strftime("%A %d de %B del %Y", strtotime($informacion_documento->created_at)), 'UTF-8')));

        $pdf->Ln(15);
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(200, 0, "OBSERVACIONES:");
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Ln(1);
        $pdf->MultiCell(260, 5, utf8_decode(mb_strtoupper($informacion_documento->observacion, 'UTF-8')));

        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Ln(10);

        $pdf->Cell(35, 5, "CODIGO", "T");
        $pdf->Cell(150, 5, "DESCRIPCION", "T");
        $pdf->Cell(20, 5, "CANTIDAD", "T");
        $pdf->Cell(30, 5, "COSTO U.", "T");
        $pdf->Cell(25, 5, "TOTAL", "T");
        $pdf->Ln();

        foreach ($productos as $producto) {
            $producto->descripcion = $producto->descripcion . " - " . $producto->comentario;

            $pdf->Cell(35, 5, $producto->sku, "T");
            $pdf->Cell(150, 5, utf8_decode(substr($producto->descripcion, 0, 90)), "T");
            $pdf->Cell(20, 5, $producto->cantidad, "T");
            $pdf->Cell(30, 5, "$ " . number_format($producto->costo, 2, '.', ''), "T");
            $pdf->Cell(25, 5, "$ " . number_format((float) $producto->costo * (float) $producto->cantidad, 2, '.', ''), "T");
            $pdf->Ln();

            if (strlen($producto->descripcion) > 90) {
                $pdf->Cell(35, 5, "");
                $pdf->Cell(150, 5, utf8_decode(substr($producto->descripcion, 90, 90)), "T");
                $pdf->Cell(20, 5, "");
                $pdf->Cell(30, 5, "");
                $pdf->Cell(25, 5, "");
                $pdf->Ln();
            }

            $total += $producto->costo * $producto->cantidad * 1.16;
        }

        $pdf->Ln(10);
        $pdf->Cell(215, 10, '');
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(20, 10, 'Subtotal: ');
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(10, 10, "$ " . number_format($total / 1.16), 2, '.', '');

        $pdf->Ln($pageline);
        $pdf->Cell(215, 10, '');
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(20, 10, 'Iva (16%): ');
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(10, 10, "$ " . number_format($total - ($total / 1.16)), 2, '.', '');

        $pdf->Ln($pageline);
        $pdf->Cell(215, 10, '');
        $pdf->SetFont('Arial', 'B', $fontsize);
        $pdf->Cell(20, 10, 'Total: ');
        $pdf->SetFont('Arial', '', $fontsize);
        $pdf->Cell(10, 10, "$ " . number_format($total), 2, '.', '');

        # FIrma de recibido

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');
        $file_name  = "MOVIMIENTO_" . $documento . "_" . uniqid() . ".pdf";

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    /* Almacen > Pretransferencias */
    public function almacen_pretransferencia_solicitud_get_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $paqueterias = Paqueteria::get();

        $empresas = Empresa::with("almacenes.almacen")
            ->where("id", "<>", 0)
            ->whereHas("almacenes", function ($query) {
                $query->where("empresa_almacen.id_almacen", "<>", 0);
            })
            ->whereHas("usuarios", function ($query) use ($auth) {
                $query->where("id_usuario", $auth->id);
            })
            ->get();

        $areas = Area::with("marketplaces")
            ->where('area', '!=', 'N/A')
            ->get();

        return response()->json([
            'empresas' => $empresas,
            'areas' => $areas,
            'paqueterias' => $paqueterias
        ]);
    }

    public function almacen_pretransferencia_solicitud_crear(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!empty($data->cliente->rfc)) {
            if (strpos(TRIM($data->cliente->rfc), 'XAXX0101010') === false) {
                $existe_cliente = DB::table('documento_entidad')
                    ->where('rfc', trim($data->cliente->rfc))
                    ->where('tipo', 1)
                    ->value('id');

                if (empty($existe_cliente)) {
                    return response()->json([
                        'code'  => 404,
                        'message'   => "El RFC ingresado no existe en la base de datos." . self::logVariableLocation()
                    ]);
                } else {
                    $entidad = $existe_cliente[0]->id;

                    DB::table('documento_entidad')->where(['id' => $entidad])->update([
                        'razon_social'  => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                        'rfc'           => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8'))
                    ]);
                }
            } else {
                //Preguntar
                $entidad = DB::table('documento_entidad')->insertGetId([
                    'razon_social' => trim(mb_strtoupper($data->cliente->razon_social, 'UTF-8')),
                    'rfc' => trim(mb_strtoupper($data->cliente->rfc, 'UTF-8')),
                    'telefono' => trim(mb_strtoupper($data->cliente->telefono, 'UTF-8')),
                    'telefono_alt' => trim(mb_strtoupper($data->cliente->telefono_alt, 'UTF-8')),
                    'correo' => trim(mb_strtoupper($data->cliente->correo, 'UTF-8'))
                ]);
            }
        } else {
            $existe_entidad = DB::table('documento_entidad')
                ->where('RFC', 'SISTEMAOMG')
                ->value('id');

            if (empty($existe_entidad)) {
                $entidad = DB::table('documento_entidad')->insertGetId([
                    'tipo'  => 2,
                    'razon_social'  => 'SISTEMA OMG',
                    'rfc'           => 'SISTEMAOMG'
                ]);
            } else {
                $entidad = $existe_entidad;
            }
        }

        foreach ($data->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

            if (empty($existe_modelo)) {
                return response()->json([
                    'code'  => 400,
                    'message' => "No existe el producto en la base de datos"
                ]);
            }
        }

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa'  => $data->almacen_entrada,
            'id_almacen_secundario_empresa' => $data->almacen_salida,
            'id_tipo' => 9,
            'id_periodo' => 1,
            'id_entidad' => $entidad,
            'id_cfdi' => 1,
            'id_marketplace_area' => $data->marketplace,
            'id_usuario' => $auth->id,
            'id_moneda' => 3,
            'id_paqueteria' => $data->paqueteria,
            'id_fase' => 401,
            'factura_folio' => 'N/A',
            'tipo_cambio' => 1,
            'referencia' => 'N/A',
            'info_extra' => json_encode($data->informacion_adicional),
            'observacion' => $data->observacion
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        foreach ($data->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . TRIM($producto->sku) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'id_tipo' => 1,
                    'sku' => $producto->sku,
                    'descripcion' => $producto->descripcion,
                    'costo' => $producto->costo,
                    'alto' => $producto->alto,
                    'ancho' => $producto->ancho,
                    'largo' => $producto->largo,
                    'peso' => $producto->peso,
                    'serie' => $producto->serie
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $modelo,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo,
                'garantia' => 0,
                'modificacion' => 'N/A',
                'comentario' => 'N/A',
                'regalo' => 0
            ]);
        }

        DB::table('documento_direccion')->insert([
            'id_documento'      => $documento,
            'id_direccion_pro'  => $data->direccion_envio->colonia,
            'contacto'          => $data->direccion_envio->contacto,
            'calle'             => $data->direccion_envio->calle,
            'numero'            => $data->direccion_envio->numero,
            'numero_int'        => $data->direccion_envio->numero_int,
            'colonia'           => $data->direccion_envio->colonia_text,
            'ciudad'            => $data->direccion_envio->ciudad,
            'estado'            => $data->direccion_envio->estado,
            'codigo_postal'     => $data->direccion_envio->codigo_postal,
            'referencia'        => $data->direccion_envio->referencia
        ]);

        foreach ($data->archivos as $archivo) {
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

        DB::table("marketplace_publicacion_etiqueta_envio")->where(["id_documento" => $documento])->delete();

        foreach ($data->publicaciones as $publicacion) {
            $publicacion_id = DB::table("marketplace_publicacion")
                ->select("id")
                ->where("publicacion_id", $publicacion->id)
                ->first();

            DB::table("marketplace_publicacion_etiqueta_envio")->insert([
                "id_documento" => $documento,
                "id_publicacion" => $publicacion_id->id,
                "cantidad" => $publicacion->cantidad,
                "etiqueta" => empty($publicacion->etiqueta) ? "N/A" : $publicacion->etiqueta
            ]);
        }

        if (property_exists($data, "saltar")) {
            if ($data->saltar) {
                DB::table("documento")->where("id", $documento)->update([
                    "id_fase" => 403
                ]);
            }
        }

        if (property_exists($data, "pendiente")) {
            if ($data->pendiente) {
                DB::table("documento")->where("id", $documento)->update([
                    "id_fase" => 400
                ]);
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Solicitud creada correctamente con el ID " . $documento . "."
        ]);
    }

    public function almacen_pretransferencia_solicitud_get_publicacion($marketplace, $publicacion)
    {
        $publicacion = str_replace("%20", " ", $publicacion);

        $publicaciones = DB::table("marketplace_publicacion")
            ->select("marketplace_publicacion.id", "marketplace_publicacion.publicacion_id", "marketplace_publicacion.publicacion")
            ->where("marketplace_publicacion.publicacion_id", $publicacion)
            ->where("marketplace_publicacion.id_marketplace_area", $marketplace)
            ->get()
            ->toArray();

        if (empty($publicaciones)) {
            $publicaciones = DB::table("marketplace_publicacion")
                ->select("marketplace_publicacion.id", "marketplace_publicacion.publicacion_id", "marketplace_publicacion.publicacion")
                ->where("marketplace_publicacion.publicacion", "like", "" . $publicacion . "%")
                ->where("marketplace_publicacion.id_marketplace_area", $marketplace)
                ->get()
                ->toArray();
        }

        foreach ($publicaciones as $publicacion) {
            $publicacion->variaciones = DB::table("marketplace_publicacion_etiqueta")
                ->select("id_etiqueta", "valor")
                ->where("id_publicacion", $publicacion->id)
                ->get()
                ->toArray();
        }

        return response()->json([
            "publicaciones" => $publicaciones
        ]);
    }

    public function almacen_pretransferencia_solicitud_get_publicacion_productos(Request $request)
    {
        $id_etiqueta = $request->input('etiqueta');
        $publicacion_text = $request->input('publicacion');

        if ($id_etiqueta != "" && !empty($id_etiqueta)) {
            $productos = DB::table("marketplace_publicacion_producto")
                ->select("modelo.*", "marketplace_publicacion_producto.etiqueta", "marketplace_publicacion_producto.cantidad as cantidad")
                ->join('modelo', 'modelo.id', '=', 'marketplace_publicacion_producto.id_modelo')
                ->where("marketplace_publicacion_producto.etiqueta", $id_etiqueta)
                ->get()->toArray();

            if(empty($productos)) {
                $publicacion = DB::table("marketplace_publicacion")->where("marketplace_publicacion.publicacion_id", $publicacion_text)->first();

                $productos = DB::table("marketplace_publicacion_producto")
                    ->select("modelo.*", "marketplace_publicacion_producto.etiqueta", "marketplace_publicacion_producto.cantidad as cantidad")
                    ->join('modelo', 'modelo.id', '=', 'marketplace_publicacion_producto.id_modelo')
                    ->where("marketplace_publicacion_producto.id_publicacion", $publicacion->id)
                    ->get()->toArray();
            }
        } else {
            $publicacion = DB::table("marketplace_publicacion")->where("marketplace_publicacion.publicacion_id", $publicacion_text)->first();

            $productos = DB::table("marketplace_publicacion_producto")
                ->select("modelo.*", "marketplace_publicacion_producto.etiqueta", "marketplace_publicacion_producto.cantidad as cantidad")
                ->join('modelo', 'modelo.id', '=', 'marketplace_publicacion_producto.id_modelo')
                ->where("marketplace_publicacion_producto.id_publicacion", $publicacion->id)
                ->get()->toArray();
        }

        return response()->json([
            "productos" => $productos
        ]);
    }

    public function almacen_pretransferencia_pendiente_guardar(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        if (!empty($data->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento' => $data->id,
                'id_usuario' => $auth->id,
                'seguimiento' => $data->seguimiento
            ]);
        }

        if (!empty($data->archivos)) {
            try {
                foreach ($data->archivos as $archivo) {
                    if ($archivo->nombre != "" && $archivo->data != "") {
                        $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                        $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                            ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                            ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                            ->addHeader('Content-Type', 'application/octet-stream')
                            ->body($archivo_data)
                            ->send();

                        DB::table('documento_archivo')->insert([
                            'id_documento' => $data->id,
                            'id_usuario' => $auth->id,
                            'tipo' => 1,
                            'id_impresora' => 0,
                            'nombre' => $archivo->nombre,
                            'dropbox' => $response->body->id
                        ]);
                    }
                }
            } catch (Exception $e) {
                return response()->json([
                    'message' => "No fue posible subir los archivos a dropbox, favor de contactar a un administrador. <br>Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
                ], 500);
            }
        }

        # Se borran los movimentos anteriores para ingresar los actualizados
        DB::table("movimiento")->where("id_documento", $data->id)->delete();

        foreach ($data->productos as $producto) {
            $existe_producto = DB::table("modelo")
                ->where("sku", trim($producto->sku))
                ->first();

            if (!$existe_producto) {
                $modelo_id = DB::table("modelo")->insertGetId([
                    "sku" => trim($producto->sku),
                    "np" => trim($producto->sku),
                    "descripcion" => trim($producto->descripcion),
                    "costo" => $producto->costo
                ]);
            }

            DB::table('movimiento')->insert([
                'id_documento' => $data->id,
                'id_modelo' => !$existe_producto ? $modelo_id : $existe_producto->id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo,
                'modificacion' => 'N/A',
                'comentario' => 'N/A',
                'addenda' => 'N/A'
            ]);
        }

        DB::table("documento")->where("id", $data->id)->update([
            "id_fase" => 401
        ]);

        return response()->json([
            "message" => "Pretransferencia actualizada correctamente"
        ]);
    }

    public function almacen_pretransferencia_pendiente_eliminar(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        DB::table("documento")->where("id", $data->id)->update([
            "status" => 0
        ]);

        return response()->json([
            "message" => "Pretransferencia eliminada correctamente"
        ]);
    }

    public function almacen_pretransferencia_confirmacion_data()
    {
        $solicitudes = $this->prestransferencias_raw_data(401);

        return response()->json([
            'code'  => 200,
            'solicitudes'   => $solicitudes
        ]);
    }

    public function almacen_pretransferencia_confirmacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('documento')->where(['id' => $data->id])->update([
            'id_fase'   => 402
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->id,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        foreach ($data->productos as $producto) {
            DB::table('movimiento')->where(['id' => $producto->id])->update([
                'cantidad_aceptada' => $producto->disponible
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Documento guardado correctamente."
        ]);
    }

    public function almacen_pretransferencia_autorizacion_data(Request $request)
    {
        $solicitudes = $this->prestransferencias_raw_data(402);

        return response()->json([
            'code'  => 200,
            'solicitudes'   => $solicitudes
        ]);
    }

    public function almacen_pretransferencia_autorizacion_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $regresar = 0;
        $excel = "";

        foreach ($data->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'id_tipo'       => 1,
                    'sku'           => $producto->sku,
                    'descripcion'   => $producto->descripcion,
                    'costo'         => $producto->costo,
                    'alto'          => $producto->alto,
                    'ancho'         => $producto->ancho,
                    'largo'         => $producto->largo,
                    'peso'          => $producto->peso,
                    'serie'         => $producto->serie
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }
        }

        $productos_ingresados = DB::select("SELECT id FROM movimiento WHERE id_documento = " . $data->id . "");

        if (COUNT($productos_ingresados) != COUNT($data->productos)) {
            $regresar = 1;
        }

        DB::table('movimiento')->where(['id_documento' => $data->id])->delete();

        foreach ($data->productos as $producto) {
            if ($producto->modificar) {
                $regresar = 1;
            }

            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . TRIM($producto->sku) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'id_tipo' => 1,
                    'sku' => $producto->sku,
                    'descripcion' => $producto->descripcion,
                    'costo' => $producto->costo,
                    'alto' => $producto->alto,
                    'ancho' => $producto->ancho,
                    'largo' => $producto->largo,
                    'peso' => $producto->peso,
                    'serie' => $producto->serie
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $data->id,
                'id_modelo' => $modelo,
                'cantidad' => $producto->cantidad,
                'cantidad_aceptada' => $producto->cantidad_aceptada,
                'precio' => $producto->costo,
                'garantia' => 0,
                'modificacion' => 'N/A',
                'comentario' => '',
                'regalo' => 0
            ]);
        }

        DB::table("marketplace_publicacion_etiqueta_envio")->where(["id_documento" => $data->id])->delete();

        foreach ($data->publicaciones as $publicacion) {
            $publicacion_id = DB::table("marketplace_publicacion")
                ->select("id")
                ->where("publicacion_id", $publicacion->id)
                ->first();

            DB::table("marketplace_publicacion_etiqueta_envio")->insert([
                "id_documento" => $data->id,
                "id_publicacion" => $publicacion_id->id,
                "cantidad" => $publicacion->cantidad,
                "etiqueta" => empty($publicacion->etiqueta) ? "N/A" : $publicacion->etiqueta
            ]);
        }

        DB::table('documento')->where(['id' => $data->id])->update([
            'id_fase'   => ($regresar) ? 401 : 403
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->id,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        $marketplace_envio = DB::table("documento")
            ->join("marketplace_area", "documento.id_marketplace_area", "=", "marketplace_area.id")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->select("marketplace.marketplace", "marketplace_area.id")
            ->where("documento.id", $data->id)
            ->first();

        if (!$regresar && $marketplace_envio->marketplace === "MERCADOLIBRE") {
            $reader = IOFactory::createReader("Xlsx");
            $spreadsheet = $reader->load("archivos/templates/mercadolibre/Envio-Fulfillment.xlsx");

            $sheet = $spreadsheet->setActiveSheetIndex(1);

            $contador_fila = 6;

            $productos = DB::table("marketplace_publicacion_etiqueta_envio")
                ->join("marketplace_publicacion", "marketplace_publicacion_etiqueta_envio.id_publicacion", "marketplace_publicacion.id")
                ->select("marketplace_publicacion.publicacion_id", "marketplace_publicacion.id_marketplace_area", "marketplace_publicacion_etiqueta_envio.etiqueta", "marketplace_publicacion_etiqueta_envio.cantidad")
                ->where("marketplace_publicacion_etiqueta_envio.id_documento", "=", $data->id)
                ->get()
                ->toArray();

            foreach ($productos as $producto) {
                $response = MercadolibreService::buscarPublicacion($producto->publicacion_id, $marketplace_envio->id);

                if ($response->error) {
                    return response()->json([
                        "code" => 500,
                        "message" => "Ocurrió un error al buscar información de la publicacion " . $publicacion->id . " en mercadolibre, mensaje de error: " . $response->mensaje . "." . self::logVariableLocation()
                    ], 500);
                }

                $producto->seller_sku = "";
                $producto->codigo_universal = "";

                if ($producto->etiqueta != 'N/A') {
                    foreach ($response->data->variations as $variation) {
                        if ($variation->id == $producto->etiqueta) {
                            $producto->inventory_id = $variation->inventory_id;

                            if (property_exists($variation, "attributes")) {
                                foreach ($variation->attributes as $attribute) {
                                    if ($attribute->id === "GTIN") {
                                        $producto->codigo_universal = $attribute->value_name;
                                    }

                                    if ($attribute->id === "SELLER_SKU") {
                                        $producto->seller_sku = $attribute->value_name;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if (property_exists($response->data, "attributes")) {
                        foreach ($response->data->attributes as $attribute) {
                            if ($attribute->id === "GTIN") {
                                $producto->codigo_universal = $attribute->value_name;
                            }

                            if ($attribute->id === "SELLER_SKU") {
                                $producto->seller_sku = $attribute->value_name;
                            }
                        }
                    }

                    $producto->inventory_id = $response->data->inventory_id;
                }

                $sheet->setCellValue('A' . $contador_fila, !is_null($producto->seller_sku) ? $producto->seller_sku : "");
                $sheet->setCellValue('B' . $contador_fila, !is_null($producto->codigo_universal) ? $producto->codigo_universal : "");
                $sheet->setCellValue('C' . $contador_fila, !is_null($producto->inventory_id) ? $producto->inventory_id : "");
                $sheet->setCellValue('D' . $contador_fila, $producto->publicacion_id);
                $sheet->setCellValue('E' . $contador_fila, $producto->etiqueta !== 'N/A' ? $producto->etiqueta : "");
                $sheet->setCellValue('F' . $contador_fila, (int) $producto->cantidad);

                $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit(!is_null($producto->seller_sku) ? $producto->seller_sku : "", DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit(!is_null($producto->codigo_universal) ? $producto->codigo_universal : "", DataType::TYPE_STRING);

                $contador_fila++;
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('Envio-Fulfillment-Copy.xlsx');

            $excel = base64_encode(file_get_contents('Envio-Fulfillment-Copy.xlsx'));

            unlink('Envio-Fulfillment-Copy.xlsx');
        }

        return response()->json([
            'code' => 200,
            'message' => "Documento guardado correctamente.",
            'excel' => $excel
        ]);
    }

    public function almacen_pretransferencia_envio_data()
    {
        $solicitudes = $this->prestransferencias_raw_data(403);

        return response()->json([
            'code'  => 200,
            'solicitudes'   => $solicitudes
        ]);
    }

    public function almacen_pretransferencia_envio_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $series_afectadas = array();
        $regresar = $request->input('regresar');

        if ($regresar) {
            DB::table('documento')->where(['id' => $data->id])->update([
                'id_fase'   => 402
            ]);

            return response()->json([
                'code'  => 200,
                'message'   => "Documento regresado correctamente."
            ]);
        }

        $id_almacen_entrada = DB::select("SELECT id_almacen FROM empresa_almacen WHERE id = " . $data->id_almacen_principal . "")[0];
        $id_almacen_salida  = DB::select("SELECT id_almacen FROM empresa_almacen WHERE id = " . $data->id_almacen_secundario . "")[0];

        $bd = DB::select("SELECT
                            empresa.bd
                        FROM empresa_almacen
                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                        WHERE empresa_almacen.id = " . $data->id_almacen_principal . "");

        if (empty($bd)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información sobre la BD de la empresa, favor de contactar con un administrador." . self::logVariableLocation()
            ]);
        }

        $bd = $bd[0]->bd;

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa'  => $data->id_almacen_principal,
            'id_almacen_secundario_empresa' => $data->id_almacen_secundario,
            'id_tipo' => 5,
            'id_periodo' => 1,
            'id_cfdi' => 1,
            'id_marketplace_area' => 1,
            'id_usuario' => $auth->id,
            'id_moneda' => 3,
            'id_paqueteria' => 6,
            'id_fase' => 404,
            'tipo_cambio' => 1,
            'referencia' => 'N/A',
            'info_extra' => 'N/A',
            'observacion' => $data->observacion . " - Documento creado a partir de la solicitud de transferencia No. " . $data->id,
        ]);

        $existe_entidad = DB::table('documento_entidad')
            ->where('RFC', '=', 'SISTEMAOMG')
            ->value('id');

        if (empty($existe_entidad)) {
            $entidad = DB::table('documento_entidad')->insertGetId([
                'tipo' => 2,
                'razon_social' => 'SISTEMA OMG',
                'rfc' => 'SISTEMAOMG'
            ]);
        } else {
            $entidad = $existe_entidad;
        }

        DB::table('documento')->where('id', $documento)->update([
            'id_entidad' => $entidad
        ]);

        foreach ($data->productos as $producto) {
            $existe_modelo = DB::select("SELECT id FROM modelo WHERE sku = '" . TRIM($producto->sku) . "'");

            if (empty($existe_modelo)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'id_tipo'       => 1,
                    'sku'           => $producto->sku,
                    'descripcion'   => $producto->descripcion,
                    'costo'         => 0,
                    'alto'          => 0,
                    'ancho'         => 0,
                    'largo'         => 0,
                    'peso'          => 0,
                    'serie'         => $producto->serie
                ]);
            } else {
                $modelo = $existe_modelo[0]->id;
            }

            DB::table("movimiento")->where("id", $producto->id)->update([
                "comentario" => $producto->comentario
            ]);

            $movimiento = DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $modelo,
                'cantidad' => $producto->cantidad,
                'precio' => 0,
                'garantia' => 0,
                'modificacion' => 'N/A',
                'comentario' => $producto->comentario,
                'regalo' => 0
            ]);
            # Se debe validar que la serie no éxista cuando es una entrada
            if ($producto->serie) {
                foreach ($producto->series as $serie) {
                    //Aqui se quita
                    //                    $apos = `'`;
                    //                    //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                    //                    if (str_contains($serie, $apos)) {
                    //                        $serie = addslashes($serie);
                    //                    }
                    $serie = str_replace(["'", '\\'], '', $serie);
                    $existe_serie = DB::select("SELECT id, id_almacen FROM producto WHERE serie = '" . TRIM($serie) . "'");

                    if (empty($existe_serie)) {
                        $producto_id = DB::table('producto')->insertGetId([
                            'id_modelo' => $modelo,
                            'id_almacen' => $id_almacen_salida->id_almacen,
                            'serie' => TRIM($serie),
                            'status' => 0
                        ]);
                    } else {
                        DB::table('producto')->where(['id' => $existe_serie[0]->id])->update([
                            'id_modelo' => $modelo,
                            'id_almacen' => $id_almacen_entrada->id_almacen,
                            'status' => 0
                        ]);

                        $producto_id = $existe_serie[0]->id;
                    }
                    # Se inserta la relación de la solicitud de transferencia con la serie
                    DB::table('movimiento_producto')->insert([
                        'id_movimiento' => $producto->id,
                        'id_producto' => $producto_id
                    ]);

                    # Se inserta la relación del traspaso con la serie
                    DB::table('movimiento_producto')->insert([
                        'id_movimiento' => $movimiento,
                        'id_producto' => $producto_id
                    ]);

                    $serie_afectada = new stdClass();
                    $serie_afectada->id = $producto_id;
                    $serie_afectada->almacen_previo = empty($existe_serie) ? $id_almacen_salida->id_almacen : $existe_serie[0]->id_almacen;

                    array_push($series_afectadas, $serie_afectada);
                }
            }
        }

        DB::table("documento")->where("id", $data->id)->update([
            "no_venta" => $documento
        ]);

        if (property_exists($data, "informacion_adicional")) {
            DB::table('documento')->where("id", $data->id)->update([
                'comentario' => json_encode($data->informacion_adicional)
            ]);
        }

        DB::table('documento')->where(['id' => $data->id])->update([
            'id_fase' => 404,
            'shipping_date' => date("Y-m-d H:i:s")
        ]);

        $traspaso_message = "Documento finalizado correctamente";

        return response()->json([
            'code'  => 200,
            'message'   => "Traspaso creado correctamente." . $traspaso_message,
            'documento' => $documento
        ]);
    }

    public function almacen_pretransferencia_envio_etiqueta($documento, $publicacion, $etiqueta, Request $request)
    {
        $etiquetas = array();

        $marketplace_envio = DB::table("documento")
            ->join("marketplace_area", "documento.id_marketplace_area", "=", "marketplace_area.id")
            ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
            ->join("empresa_almacen", "documento.id_almacen_secundario_empresa", "=", "empresa_almacen.id")
            ->join("impresora", "empresa_almacen.id_impresora_etiqueta_envio", "=", "impresora.id")
            ->select("marketplace.marketplace", "marketplace_area.id", "documento.info_extra", "impresora.servidor", "impresora.id AS id_impresora")
            ->where("documento.id", $documento)
            ->first();

        switch ($marketplace_envio->marketplace) {
            case 'MERCADOLIBRE':
                $informacion_publicacion = DB::table("marketplace_publicacion")
                    ->join("marketplace_publicacion_etiqueta_envio", "marketplace_publicacion.id", "=", "marketplace_publicacion_etiqueta_envio.id_publicacion")
                    ->select("marketplace_publicacion_etiqueta_envio.cantidad")
                    ->where("marketplace_publicacion.publicacion_id", $publicacion)
                    ->where("marketplace_publicacion_etiqueta_envio.id_documento", $documento)
                    ->where("marketplace_publicacion_etiqueta_envio.etiqueta", $etiqueta == 'na' ? 'N/A' : $etiqueta)
                    ->first();

                $response = MercadolibreService::buscarPublicacion($publicacion, $marketplace_envio->id);

                if ($response->error) {
                    return response()->json([
                        "code" => 500,
                        "message" => "Ocurrió un error al buscar información de la publicacion " . $marketplace_envio->id . " en mercadolibre, mensaje de error: " . $response->mensaje . "." . self::logVariableLocation()
                    ], 500);
                }

                if (empty($informacion_publicacion)) {
                    return response()->json([
                        "code" => 500,
                        "message" => "No se encontró información de la variación registrada en el sistema, favor de revisar en intentar nuevamente." . self::logVariableLocation()
                    ], 500);
                }

                $color = "";
                $codigo = $response->data->inventory_id;

                foreach ($response->data->variations as $variation) {
                    if ($variation->id == $etiqueta) {
                        $codigo = $variation->inventory_id;

                        foreach ($variation->attribute_combinations as $attribute) {
                            $color .= " " . $attribute->value_name . " /";
                        }

                        $color = trim(substr($color, 0, -1));
                    }
                }

                if (is_null($codigo)) {
                    return response()->json([
                        "code" => 500,
                        "message" => "No se encontró el codigo de inventario para la publicacion " . $marketplace_envio->id . " en mercadolibre." . self::logVariableLocation()
                    ], 500);
                }

                $etiqueta_data = new stdClass();
                $etiqueta_data->codigo = $codigo;
                $etiqueta_data->descripcion = $response->data->title;
                $etiqueta_data->cantidad = $informacion_publicacion->cantidad;
                $etiqueta_data->extra = $color;

                array_push($etiquetas, $etiqueta_data);

                break;

            case 'AMAZON':
                $etiquetas_amazon = DB::table('movimiento')
                    ->join('modelo_amazon', 'modelo_amazon.id_modelo', '=', 'movimiento.id_modelo')
                    ->where('movimiento.id_documento', 1186900)
                    ->select('modelo_amazon.*') // Selecciona todos los campos de modelo_amazon
                    ->get();

                $movimientos = DB::table('movimiento')
                    ->join('modelo', 'modelo.id', '=', 'movimiento.id_modelo')
                    ->where('movimiento.id_documento', 1186900)
                    ->select('movimiento.*', 'modelo.descripcion') // Selecciona todos los campos de modelo_amazon
                    ->get();

                foreach ($etiquetas_amazon as $etiqueta) {
                    $etiqueta_data = new stdClass();
                    $etiqueta_data->codigo = $etiqueta->codigo;
                    foreach ($movimientos as $mov) {
                        if ($mov->id_modelo == $etiqueta->id_modelo) {
                            $etiqueta_data->descripcion = $mov->descripcion;
                            $etiqueta_data->cantidad = $mov->cantidad;
                        }
                    }
                    $etiqueta_data->extra = "";

                    array_push($etiquetas, $etiqueta_data);
                }

                break;

            default:

                return response()->json([
                    "code" => 500,
                    "message" => "El marketplace no está configurado para generar etiquetas." . self::logVariableLocation()
                ]);
        }

        $auth = json_decode($request->auth);
        if ($auth->id == 134){
            $data = array(
                "etiquetas" => $etiquetas,
                "impresora" => 37
            );
        }
        else{
            $data = array(
                "etiquetas" => $etiquetas,
                "impresora" => $marketplace_envio->id_impresora
            );
        }

        $token = $request->get("token");

        $impresion = \Httpful\Request::post($marketplace_envio->servidor . "/raspberry-print-server/public/label/sku-and-description?token=" . $token)
            ->body($data, Mime::FORM)
            ->send();

        $impresion_raw = $impresion->raw_body;
        $impresion = @json_decode($impresion_raw);

        return (array) $impresion_raw;
        /*
        if (empty($impresion)) {
            GeneralService::sendEmailToAdmins($url, "No fue posible imprimir el picking", $impresion_raw);
        }

        if ($impresion->code != 200) {
            GeneralService::sendEmailToAdmins($url, "No fue posible imprimir el picking del documento, mensaje de error: " . $impresion->mensaje . "", 0);
        }
        */
    }

    public function almacen_pretransferencia_historial_data(Request $request)
    {
        $data = json_decode($request->input("data"));

        if (!empty($data->criterio)) {
            $solicitudes = $this->prestransferencias_raw_data(0, "AND documento.id = '" . $data->criterio . "'");

            if (empty($solicitudes)) {
                $solicitudes = $this->prestransferencias_raw_data(0, "AND documento.info_extra LIKE '%" . $data->criterio . "%'");
            }

            if (empty($solicitudes)) {
                $solicitudes = $this->prestransferencias_raw_data(0, "AND documento.observacion LIKE '%" . $data->criterio . "%'");
            }
        } else {
            $solicitudes = $this->prestransferencias_raw_data(0, "AND documento.created_at BETWEEN '" . $data->inicial . " 00:00:00' AND '" . $data->final . " 23:59:59'");
        }

        return response()->json([
            'code'  => 200,
            'solicitudes'   => $solicitudes
        ]);
    }

    public function almacen_pretransferencia_historial_factura($documento, Request $request)
    {
        $auth = json_decode($request->auth);
        $total = 0;

        $informacion_documento = DB::table('documento')
            ->join('documento_direccion', 'documento.id', '=', 'documento_direccion.id_documento')
            ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->where('documento.id', $documento)
            ->select(
                'documento.id_almacen_principal_empresa',
                'documento.id_almacen_secundario_empresa',
                'documento.id_usuario',
                'documento.observacion',
                'documento.created_at',
                'empresa.rfc',
                'empresa.bd',
                'empresa.empresa',
                'empresa.logo',
                'documento_entidad.razon_social',
                'documento_direccion.*'
            )
            ->get();

        if (empty($informacion_documento)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del documento para generar la factura." . self::logVariableLocation()
            ]);
        }

        $informacion_documento = $informacion_documento[0];

        $productos = DB::select("SELECT
                                    modelo.sku,
                                    modelo.descripcion,
                                    movimiento.id,
                                    movimiento.id_modelo,
                                    movimiento.cantidad,
                                    movimiento.precio,
                                    movimiento.garantia,
                                    movimiento.comentario,
                                    movimiento.modificacion,
                                    movimiento.addenda,
                                    movimiento.regalo
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $documento . "");

        if (empty($productos)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información de los productos del documento para generar la factura." . self::logVariableLocation()
            ]);
        }

        foreach ($productos as $producto) {
            $total += (float) $producto->precio * (float) $producto->cantidad;

            $informacion_producto = @json_decode(file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $informacion_documento->bd . '/' . rawurlencode(trim($producto->sku))));

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

        # Logo de la empresa
        if ($informacion_documento->logo != 'N/A') {
            $pdf->Image($informacion_documento->logo, 5, 10, 60, 20, 'png');
        }

        # EL jimmy puso mal la parte de "adminpro" y puso "dminpro", por eso se pone la URL completa
        $informacion_empresa = @json_decode(file_get_contents("http://201.7.208.53:11903/api/dminpro/Empresas/Informacion/BD/" . $informacion_documento->bd . "/RFC/" . $informacion_documento->rfc . ""));

        if (empty($informacion_empresa)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró información de la empresa del documento." . self::logVariableLocation()
            ]);
        }

        if (empty($informacion_empresa->direccion)) {
            return response()->json([
                "code" => 500,
                "message" => "La empresa del documento no contiene direcciones." . self::logVariableLocation()
            ]);
        }

        $direccion_fiscal = new stdClass();

        foreach ($informacion_empresa->direccion as $direccion) {
            if ($direccion->nombre === "Dirección fiscal") {
                $direccion_fiscal = $direccion;
            }
        }

        if (!property_exists($direccion_fiscal, "nombre")) {
            return response()->json([
                "code" => 500,
                "message" => "La empresa del documento no contiene dirección fiscal." . self::logVariableLocation()
            ]);
        }

        $pdf->SetFont('Arial', 'B', 45);
        $pdf->Cell(120, 10, "");
        $pdf->Cell(50, 10, $documento);
        $pdf->SetFont('Arial', '', 10);

        $pdf->Ln(10);

        setlocale(LC_ALL, "es_MX");

        $pdf->Cell(120, 10, "");
        $pdf->Cell(50, 10, strftime("%A %d de %B del %Y", strtotime($informacion_documento->created_at)));

        $pdf->Ln(30);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(100, 10, "- ORIGEN");
        $pdf->Cell(100, 10, "- DESTINO");
        $pdf->Ln(5);
        $pdf->Cell(100, 10, $informacion_documento->empresa);
        $pdf->Cell(100, 10, $informacion_documento->razon_social);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln(5);
        $pdf->Cell(100, 10, $direccion_fiscal->calle . " " . $direccion_fiscal->ext . " " . $direccion_fiscal->int);
        $pdf->Cell(100, 10, $informacion_documento->calle . " " . $informacion_documento->numero . " " . $informacion_documento->numero_int);
        $pdf->Ln(5);
        $pdf->Cell(100, 10, $direccion_fiscal->colonia);
        $pdf->Cell(100, 10, $informacion_documento->colonia);
        $pdf->Ln(5);
        $pdf->Cell(100, 10, $direccion_fiscal->ciudad . ", " . $direccion_fiscal->estado . ", " . $direccion_fiscal->cp);
        $pdf->Cell(100, 10, $informacion_documento->ciudad . ", " . $informacion_documento->estado . ", " . $informacion_documento->codigo_postal);

        /* Productos */

        $pdf->Ln(20);

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(20, 5, "CANTIDAD", "T");
        $pdf->Cell(25, 5, "UNIDAD / SAT", "T");
        $pdf->Cell(25, 5, "CODIGO / SAT", "T");
        $pdf->Cell(80, 5, "DESCRIPCION", "T");
        $pdf->Cell(20, 5, "PRECIO", "T");
        $pdf->Cell(20, 5, "TOTAL", "T");
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 8);

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
                $pdf->Cell(20, 5, "");
                $pdf->Cell(25, 5, "");
                $pdf->Cell(25, 5, "");
                $pdf->Cell(80, 5, substr($producto->descripcion, 80, 40));
                $pdf->Cell(20, 5, "");
                $pdf->Cell(20, 5, "");
                $pdf->Ln();
            }
        }

        $total = round($total, 4);

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
        $file_name  = "NOTA_" . $documento . "_" . uniqid() . ".pdf";

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    public function almacen_pretransferencia_historial_nc($documento)
    {
        $existe_factura = DB::select("SELECT factura_folio FROM documento WHERE id = " . $documento . "");

        if (empty($existe_factura)) {
            return response()->json([
                'code'  => 500,
                'message'   => "No se encontró información del documento para generar la NC." . self::logVariableLocation()
            ]);
        }

        $existe_factura = $existe_factura[0];

        if ($existe_factura->factura_folio == 'N/A') {
            return response()->json([
                'code'  => 500,
                'message'   => "El folio de la factura no es valido." . self::logVariableLocation()
            ]);
        }

        $crear_nota_credito = DocumentoService::crearNotaCredito($existe_factura->factura_folio, 1);

        if ($crear_nota_credito->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $crear_nota_credito->mensaje . " " . self::logVariableLocation()
            ]);
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'pagado'    => 1,
            'referencia'    => $crear_nota_credito->id
        ]);

        $saldar_factura = DocumentoService::saldarFactura($existe_factura->factura_folio, $crear_nota_credito->id, 0);

        if ($saldar_factura->error) {
            return response()->json([
                'code'  => 200,
                'referencia'    => $crear_nota_credito->id,
                'message'   => "Nota de credito creada correctamente con el ID " . $crear_nota_credito->id . ", pero no fue posible saldar la factura por el siguiente error: " . $saldar_factura->mensaje . ", favor de aplicar manualmente." . self::logVariableLocation()
            ]);
        }

        return response()->json([
            'code'  => 200,
            'referencia'    => $crear_nota_credito->id,
            'message'   => "Nota de credito creada correctamente con el ID " . $crear_nota_credito->id . ", factura saldada correctamente."
        ]);
    }

    public function almacen_pretransferencia_historial_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!empty($data->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento'  => $data->id,
                'id_usuario'    => $auth->id,
                'seguimiento'   => $data->seguimiento
            ]);
        }

        DB::table("documento")->where("id", $data->id)->update([
            'comentario' => json_encode($data->informacion_adicional)
        ]);

        try {
            foreach ($data->informacion_adicional->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento'  => $data->id,
                        'id_usuario'    => $auth->id,
                        'tipo'          => 1,
                        'id_impresora'  => 0,
                        'nombre'        => $archivo->nombre,
                        'dropbox'       => $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'code'  => 500,
                'message'   => "No fue posible subir los archivos a dropbox, favor de contactar a un administrador. <br>Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Seguimiento guardado correctamente."
        ]);
    }

    public function almacen_pretransferencia_finalizar_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        if (!empty($data->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento' => $data->id,
                'id_usuario' => $auth->id,
                'seguimiento' => $data->seguimiento
            ]);
        }

        try {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->id,
                        'id_usuario' => $auth->id,
                        'tipo' => 1,
                        'id_impresora' => 0,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'message' => "No fue posible subir los archivos a dropbox, favor de contactar a un administrador. <br>Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
            ], 500);
        }

        $con_diferencias = false;

        foreach ($data->productos as $producto) {
            DB::table("movimiento")->where("id", $producto->id)->update([
                "cantidad_recepcionada" => $producto->cantidad_recepcionada
            ]);

            if ($producto->cantidad != $producto->cantidad_recepcionada) $con_diferencias = true;
        }

        $documento_traspaso = DB::table("documento")
            ->select("no_venta", "documento_extra")
            ->where("id", $data->id)
            ->first();

        if ($documento_traspaso) {
            if (!$documento_traspaso->no_venta != 'N/A') {
                $documento_traspaso_data = DB::table("documento")
                    ->select("id", "documento_extra")
                    ->where("id", $documento_traspaso->no_venta)
                    ->first();

                if (!$documento_traspaso_data) {
                    return response()->json([
                        "message" => "No se encontró el traspaso de la pretransferencia" . " " . self::logVariableLocation()
                    ]);
                }

                if ($documento_traspaso_data->documento_extra == 'N/A') {
                    $response = DocumentoService::crearMovimiento($documento_traspaso_data->id);

                    if ($response->error) {
                        return response()->json([
                            "code" => 500,
                            "message" => "Ocurrió un error al generar el traspaso de la pretransferencia, mensaje de error: " . $response->mensaje . " " . self::logVariableLocation(),
                            "raw" => property_exists($response, "raw") ? $response->raw : 0
                        ]);
                    }
                }

                $response_afectar = DocumentoService::afectarMovimiento($documento_traspaso_data->id);

                if ($response_afectar->error) {
                    return response()->json([
                        "code" => 500,
                        "message" => "Ocurrió un error al afectar el traspaso, favor de contactar con un administrador, mensaje de error: " . $response_afectar->mensaje . " " . self::logVariableLocation(),
                        "raw" => property_exists($response_afectar, "raw") ? $response_afectar->raw : 0
                    ]);
                }

                DB::table("documento")->where("id", $documento_traspaso_data->id)->update([
                    "id_fase" => 100
                ]);
            }
        }

        DB::table("documento")->where("id", $data->id)->update([
            "id_fase" => $con_diferencias ? 405 : 100
        ]);

        return response()->json([
            "message" => "Pretransferencia finalizada correctamente"
        ]);
    }

    public function almacen_pretransferencia_con_diferencias_guardar(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $validate_wa = WhatsAppService::validateCode($auth->id, $data->code);

        if ($validate_wa->error) {
            return response()->json([
                "message" => $validate_wa->mensaje . " " . self::logVariableLocation()
            ], 500);
        }

        if (!empty($data->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento' => $data->id,
                'id_usuario' => $auth->id,
                'seguimiento' => $data->seguimiento
            ]);
        }

        try {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->id,
                        'id_usuario' => $auth->id,
                        'tipo' => 1,
                        'id_impresora' => 0,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'message' => "No fue posible subir los archivos a dropbox, favor de contactar a un administrador. <br>Mensaje de error: " . $e->getMessage() . " " . self::logVariableLocation()
            ], 500);
        }

        DB::table("documento")->where("id", $data->id)->update([
            "id_fase" => 100
        ]);

        return response()->json([
            "message" => "Pretransferencia finalizada correctamente"
        ]);
    }

    /* Almacen > Common Routes */
    public function almacen_pretransferencia_get_documentos($fase)
    {
        set_time_limit(0);
        $documentos = $this->prestransferencias_raw_data(0, "AND documento.id_fase = '" . $fase . "'");

        return response()->json([
            "documentos" => $documentos
        ]);
    }

    /* Etiqueta */
    public function almacen_etiqueta(Request $request)
    {
        $tipo = $request->input("tipo");
        $data = json_decode($request->input("data"));

        $impresora = DB::table("impresora")
            ->select("servidor")
            ->where("id", $data->impresora)
            ->first();

        if (empty($impresora)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró la impresora proporcionada" . " " . self::logVariableLocation()
            ]);
        }

        if ($tipo == "1") {
            if (empty($data->etiquetas)) {
                $etiquetas = array(
                    "impresora" => $data->impresora,
                    "etiquetas" => [$data]
                );
            } else {
                $etiquetas = array(
                    "impresora" => $data->impresora,
                    "etiquetas" => $data->etiquetas
                );
            }

            $url = "label/sku-and-description";
        } else {
            $zpl_data = DB::table("impresora_zpl")->insertGetId([
                "zpl" => $data->archivo
            ]);

            $etiquetas = array(
                "zpl" => $zpl_data,
                "impresora" => $data->impresora
            );

            $url = "label/raw";
        }

        $token = $request->get("token");

        $impresion = \Httpful\Request::post($impresora->servidor . "/raspberry-print-server/public/" . $url . "?token=" . $token)
            ->body($etiquetas, Mime::FORM)
            ->send();

        $impresion_raw = $impresion->raw_body;
        $impresion = @json_decode($impresion_raw);

        return (array) json_decode($impresion_raw);
    }

    public function almacen_etiqueta_get_data(Request $request)
    {
        $impresoras = DB::table("impresora")
            ->where("status", 1)
            ->get()
            ->toArray();

        $empresas = DB::table("empresa")
            ->select("empresa", "bd")
            ->where("id", "<>", '')
            ->get()
            ->toArray();

        return response()->json([
            "code" => 200,
            "impresoras" => $impresoras,
            "empresas" => $empresas
        ]);
    }

    public function almacen_etiqueta_serie(Request $request)
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);
        $etiquetas = array();
        $series = array();

        $cantidad = explode(".", $data->cantidad);

        $impresora = DB::table("impresora")
            ->select("servidor")
            ->where("id", $data->impresora)
            ->first();

        if (empty($impresora)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró la impresora proporcionada" . " " . self::logVariableLocation()
            ]);
        }

        $existe_codigo = DB::table("modelo")
            ->select("id", "consecutivo")
            ->where("sku", $data->codigo)
            ->first();

        if (empty($existe_codigo)) {
            $existe_sinonimo = DB::table("modelo_sinonimo")
                ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                ->select("modelo.id", "modelo.consecutivo")
                ->where("modelo_sinonimo.codigo", trim($data->codigo))
                ->first();

            if (empty($existe_sinonimo)) {
                return response()->json([
                    "code" => 500,
                    "message" => "El codigo proporcionado no existe en la base de datos, favor de contactar a un administrador" . " " . self::logVariableLocation()
                ]);
            }

            $existe_codigo = $existe_sinonimo;
        }

        $prefijo = "";
        $cantidad_id = substr($existe_codigo->id, -5);
        $fecha = date("mY");

        for ($y = 0; $y < (5 - strlen($cantidad_id)); $y++) {
            $prefijo .= "0";
        }

        $prefijo .= $cantidad_id;

        for ($i = 0; $i < (int) $cantidad[0]; $i++) {
            $consecutivo = (int) $existe_codigo->consecutivo + $i + 1;
            $cantidad_consecutivo = strlen((string) $consecutivo);
            $sufijo = "";

            for ($y = 0; $y < (6 - $cantidad_consecutivo); $y++) {
                $sufijo .= "0";
            }

            $sufijo .= $consecutivo;

            $etiqueta_data = new stdClass();
            $etiqueta_data->serie = (string) $prefijo . $fecha . $sufijo;
            $etiqueta_data->codigo = $data->codigo;
            $etiqueta_data->descripcion = $data->descripcion;
            $etiqueta_data->cantidad = 1;
            $etiqueta_data->extra = property_exists($data, "extra") ? $data->extra : "";

            array_push($etiquetas, $etiqueta_data);
            array_push($series, $etiqueta_data->serie);
        }

        $consecutivo = (((int) $existe_codigo->consecutivo + (int) $cantidad[0]) >= 800000) ? 1 : ((int) $existe_codigo->consecutivo + (int) $cantidad[0]);

        DB::table("modelo")->where("id", $existe_codigo->id)->update([
            "consecutivo" => $consecutivo
        ]);

        if (count($cantidad) > 1) {
            $es_admin = DB::table("usuario_subnivel_nivel")
                ->select("usuario_subnivel_nivel.id")
                ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
                ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
                ->where("subnivel_nivel.id_nivel", 6)
                ->where("subnivel_nivel.id_subnivel", 1)
                ->first();

            if (!empty($es_admin)) {
                $data_qr = array(
                    "series" => $series,
                    "impresora" => '7'
                );

                $token = $request->get("token");

                $impresion = \Httpful\Request::post($impresora->servidor . "/raspberry-print-server/public/label/qr-serie?token=" . $token)
                    ->body($data_qr, Mime::FORM)
                    ->send();

                $impresion_raw = $impresion->raw_body;
                $impresion = @json_decode($impresion_raw);

                return (array) $impresion_raw;
            }
        } else {
            $data_etiqueta = array(
                "etiquetas" => $etiquetas,
                "impresora" => $data->impresora
            );

            $token = $request->get("token");

            $impresion = \Httpful\Request::post($impresora->servidor . "/raspberry-print-server/public/label/sku-and-description-and-serie?token=" . $token)
                ->body($data_etiqueta, Mime::FORM)
                ->send();

            $impresion_raw = $impresion->raw_body;
            $impresion = @json_decode($impresion_raw);

            return (array) $impresion_raw;
        }
    }

    public function almacen_etiqueta_serie_qr(Request $request)
    {
        $data = json_decode($request->input("data"));

        $impresora = DB::table("impresora")
            ->select("servidor")
            ->where("id", $data->impresora)
            ->first();

        if (empty($impresora)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró la impresora proporcionada" . " " . self::logVariableLocation()
            ]);
        }

        $data = array(
            "series" => $data->series,
            "impresora" => $data->impresora
        );

        $token = $request->get("token");

        $impresion = \Httpful\Request::post($impresora->servidor . "/raspberry-print-server/public/label/qr-serie?token=" . $token)
            ->body($data, Mime::FORM)
            ->send();

        $impresion_raw = $impresion->raw_body;
        $impresion = @json_decode($impresion_raw);

        return (array) $impresion_raw;
    }

    /* Raw */
    public function rawinfo_almacen_picking(Request $request)
    {
        set_time_limit(0);

        $responses = array();
        $url = "";
//
        $servidores = DB::select("SELECT
                                impresora.servidor
                            FROM documento
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN impresora ON empresa_almacen.id_impresora_picking = impresora.id
                            WHERE documento.id_fase = 3
                            AND documento.status = 1
                            AND documento.id_tipo = 2
                            AND documento.autorizado = 1
                            AND documento.problema = 0
                            AND documento.picking = 0
                            AND documento.created_at LIKE '%" . date("Y") . "%'
                            GROUP BY impresora.servidor
                            ORDER BY impresora.servidor ASC");

        if (!empty($servidores)) {
            foreach ($servidores as $servidor) {
                $ventas = DB::select("SELECT
                                documento.id,
                                documento.pagado,
                                documento.id_periodo,
                                documento.id_marketplace_area,
                                documento.documento_extra,
                                marketplace_area.publico,
                                impresora.ip
                            FROM documento
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN impresora ON empresa_almacen.id_impresora_picking = impresora.id
                            WHERE documento.id_fase = 3
                            AND documento.status = 1
                            AND documento.id_tipo = 2
                            AND documento.autorizado = 1
                            AND documento.problema = 0
                            AND documento.picking = 0
                            AND documento.packing_by = 0
                            AND documento.created_at LIKE '%" . date("Y") . "%'
                            AND impresora.servidor = '" . $servidor->servidor . "'
                            AND NOT (marketplace_area.publico = 0 AND documento.pagado = 0 AND documento.id_periodo = 1)
                            GROUP BY documento.id
                            ORDER BY documento.created_at ASC
                            LIMIT 30");

                if (!empty($ventas)) {
                    //Borrar for si no salen pickings
                    foreach ($ventas as $index => $venta) {
                        $movimientos = DB::table('movimiento')->where('id_documento', $venta->id)->first();

                        if (empty($movimientos)) {
                            DB::table('seguimiento')->insert([
                                'id_documento' => $venta->id,
                                'id_usuario' => 1,
                                'seguimiento' => "PICKING: El pedido ha sido mandado a fase PEDIDO debido a que actualmente no contiene productos.
                                Por favor, añada artículos para proceder con la siguiente fase del proceso."
                            ]);

                            DB::table('documento')->where('id', $venta->id)->update([
                                'id_fase' => 1,
                            ]);

                            CorreoService::cambioDeFase($venta->id, "PICKING: El pedido ha sido mandado a fase PEDIDO debido a que actualmente no contiene productos.
                                Por favor, añada artículos para proceder con la siguiente fase del proceso.");

                            unset($ventas[$index]);

                            $tiene_series = DB::select('
                                        SELECT producto.*
                                        FROM movimiento_producto
                                        INNER JOIN movimiento ON movimiento.id = movimiento_producto.id_movimiento
                                        INNER JOIN producto ON producto.id = movimiento_producto.id_producto
                                        WHERE movimiento.id_documento =' . $venta->id);

                            if (!empty($tiene_series)) {
                                $this->eliminarSeries($venta->id);
                            }
                        }
                    }

                    if (empty($ventas)) continue;
                    $data = array(
                        "documentos" => $ventas
                    );

                    try {
                        $impresion = \Httpful\Request::post($servidor->servidor . "/raspberry-print-server/public/picking")
                            ->body($data, Mime::FORM)
                            ->send();

                        $impresion_raw = $impresion->raw_body;
                        $impresion = @json_decode($impresion_raw);

                        array_push($responses, $impresion_raw);

                        if (empty($impresion)) {
                            GeneralService::sendEmailToAdmins($url, "No fue posible imprimir el picking, servidor: " . $servidor->servidor . " " . self::logVariableLocation(), $impresion_raw);
                        }

                        if ($impresion->code != 200) {
                            GeneralService::sendEmailToAdmins($url, "No fue posible imprimir el picking del documento, servidor: " . $servidor->servidor . " " . self::logVariableLocation(), json_encode($impresion->data));
                        }
                    } catch (Exception $e) {
                        GeneralService::sendEmailToAdmins($url, "No fue posible imprimir los picking del servidor " . $servidor->servidor . ", error: " . $e->getMessage() . "" . " " . self::logVariableLocation(), "");
                    }
                }

                //            foreach ($ventas as $index => $venta) {
                //                if ($venta->publico == 0 && $venta->pagado == 0 && $venta->id_periodo == 1) {
                //                    unset($ventas[$index]);
                //                    continue;
                //                }
                //                array_push($ventas_filtered, $venta);
                //            }
                //            if (empty($ventas_filtered)) continue;
            }
        }
        return $responses;
    }


    private function picking_packing_raw_data($extra_data = "")
    {
        $ventas_re = [];

        $ventas = DB::table('documento')
            ->select(
                'area.area',
                'almacen.almacen',
                'documento.id',
                'documento.no_venta',
                'documento.id_marketplace_area',
                'documento.id_almacen_principal_empresa AS almacen_empresa',
                'documento.mkt_total',
                'documento.documento_extra',
                'documento.referencia',
                'documento.problema',
                'documento.pagado',
                'documento.id_periodo',
                'documento.created_at',
                'documento_entidad.razon_social AS cliente',
                'empresa_almacen.id AS id_almacen',
                'paqueteria.paqueteria',
                'paqueteria.guia AS contiene_guia',
                'marketplace.marketplace',
                'marketplace_area.publico',
                'usuario.nombre AS usuario'
            )
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('usuario_empresa', 'empresa_almacen.id_empresa', '=', 'usuario_empresa.id_empresa')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('paqueteria', 'documento.id_paqueteria', '=', 'paqueteria.id')
            ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
            ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
            ->join('marketplace_area', 'documento.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('area', 'marketplace_area.id_area', '=', 'area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->where('documento.id_fase', 3)
            ->where('documento.status', 1)
            ->where('documento.id_tipo', 2)
            ->where('documento.autorizado', 1)
            ->orderBy('documento.created_at', 'ASC');

        // Si existe extra_data, agregar partes RAW adicionales a la consulta
        if (!empty($extra_data)) {
            $ventas->whereRaw($extra_data);
        }

        $ventas = $ventas->get();

        foreach ($ventas as $index => $venta) {
            if ($venta->publico == 0 && $venta->pagado == 0 && $venta->id_periodo == 1) {
                unset($ventas[$index]);
                continue;
            }

            $venta->productos = DB::table('movimiento')
                ->select(
                    'modelo.serie',
                    'modelo.sku',
                    'modelo.descripcion',
                    'movimiento.cantidad',
                    DB::raw('ROUND((movimiento.precio * 1.16), 2) AS total')
                )
                ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                ->where('movimiento.id_documento', $venta->id)
                ->get();

            $venta->seguimiento = DB::table('seguimiento')
                ->select('seguimiento.*', 'usuario.nombre')
                ->join('usuario', 'seguimiento.id_usuario', '=', 'usuario.id')
                ->where('seguimiento.id_documento', $venta->id)
                ->get();

            $venta->archivos = DB::table('documento_archivo')
                ->where('id_documento', $venta->id)
                ->where('tipo', 2)
                ->where('status', 1)
                ->get();

            array_push($ventas_re, $venta);
        }

        return $ventas_re;
    }

    private function prestransferencias_raw_data($fase, $fecha = "")
    {
        set_time_limit(0);
        $fase = ($fase == 0) ? '' : 'AND documento.id_fase = ' . $fase;
        $fecha = ($fecha == '') ? '' : $fecha;

        $solicitudes = DB::select("SELECT
                                    usuario.nombre,
                                    area.area,
                                    marketplace.marketplace,
                                    documento.id,
                                    documento.id AS documento_id,
                                    documento.id_almacen_principal_empresa AS id_almacen_principal,
                                    documento.id_almacen_secundario_empresa AS id_almacen_secundario,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_principal
                                    ) AS almacen_entrada,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_secundario
                                    ) AS almacen_salida,
                                    documento.factura_folio,
                                    documento.referencia,
                                    documento.observacion,
                                    documento.id_fase,
                                    documento.importado,
                                    documento.info_extra,
                                    documento.created_at,
                                    documento.shipping_date,
                                    documento_fase.fase,
                                    documento.comentario,
                                    documento.id_marketplace_area,
                                    empresa.bd AS empresa,
                                    (
                                        SELECT
                                            marketplace_area.serie
                                        FROM documento
                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                        WHERE documento.id = documento_id
                                    ) AS factura_serie
                                FROM documento
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                INNER JOIN documento_tipo ON documento.id_tipo = documento_tipo.id
                                AND documento.id_tipo = 9
                                AND documento.id_usuario != 1
                                AND documento.status = 1
                                " . $fase . "
                                " . $fecha . "");

        foreach ($solicitudes as $solicitud) {
            $solicitud->productos = DB::select("SELECT
                                        movimiento.id,
                                        modelo.id AS modelo_id,
                                        modelo.sku,
                                        movimiento.precio AS costo,
                                        modelo.serie,
                                        modelo.descripcion,
                                        movimiento.cantidad,
                                        movimiento.cantidad AS disponible,
                                        movimiento.cantidad_aceptada,
                                        movimiento.cantidad_recepcionada,
                                        movimiento.comentario,
                                        0 AS modificar
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $solicitud->id . "");

            $solicitud->publicaciones = DB::table("marketplace_publicacion_etiqueta_envio")
                ->select("marketplace_publicacion.id AS publicacion_id", "marketplace_publicacion.publicacion_id AS id", "marketplace_publicacion.publicacion AS titulo", "marketplace_publicacion_etiqueta_envio.cantidad", "marketplace_publicacion_etiqueta_envio.etiqueta", "marketplace_publicacion_etiqueta.valor AS etiqueta_text")
                ->join("marketplace_publicacion", "marketplace_publicacion_etiqueta_envio.id_publicacion", "=", "marketplace_publicacion.id")
                ->leftJoin("marketplace_publicacion_etiqueta", "marketplace_publicacion_etiqueta_envio.etiqueta", "=", "marketplace_publicacion_etiqueta.id_etiqueta")
                ->where("marketplace_publicacion_etiqueta_envio.id_documento", $solicitud->id)
                ->get()
                ->toArray();

            foreach ($solicitud->publicaciones as $publicacion) {
                $publicacion->productos = DB::table("marketplace_publicacion_producto")
                    ->select("modelo.sku", "modelo.descripcion")
                    ->selectRaw('marketplace_publicacion_producto.cantidad * ? as cantidad', [(int) $publicacion->cantidad])
                    ->join("modelo", "marketplace_publicacion_producto.id_modelo", "=", "modelo.id")
                    ->where("marketplace_publicacion_producto.id_publicacion", $publicacion->publicacion_id)
                    ->where("marketplace_publicacion_producto.etiqueta", $publicacion->etiqueta)
                    ->get()
                    ->toArray();
            }

            $solicitud->seguimiento_anterior = DB::select("SELECT
                                                                seguimiento.*, 
                                                                usuario.nombre 
                                                            FROM seguimiento 
                                                            INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                                            WHERE id_documento = " . $solicitud->id . "");

            $solicitud->archivos = DB::select("SELECT * FROM documento_archivo WHERE id_documento = " . $solicitud->id . " AND status = 1");

            $solicitud->seguimiento = "";
            $solicitud->info_extra = json_decode($solicitud->info_extra);

            foreach ($solicitud->productos as $producto) {
                $producto->sinonimos = DB::table("modelo_sinonimo")
                    ->select("codigo")
                    ->where("id_modelo", $producto->modelo_id)
                    ->pluck("codigo");

                if ($producto->serie) {
                    $producto->series = DB::select("SELECT
                                            producto.id,
                                            producto.serie
                                        FROM movimiento_producto
                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                        WHERE movimiento_producto.id_movimiento = " . $producto->id . "");
                }
            }
        }

        return $solicitudes;
    }

    private function prestamos_raw_data($fase, $fecha = "")
    {
        $fase = ($fase == 0) ? '' : 'AND documento.id_fase = ' . $fase;
        $fecha = ($fecha == '') ? '' : $fecha;

        $solicitudes = DB::select("SELECT
                                    usuario.nombre,
                                    documento.id,
                                    documento.factura_folio,
                                    documento.autorizado_by,
                                    documento.id_almacen_principal_empresa AS id_almacen_principal,
                                    documento.id_almacen_secundario_empresa AS id_almacen_secundario,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_principal
                                    ) AS almacen_entrada,
                                    (
                                        SELECT
                                            almacen
                                        FROM empresa_almacen
                                        INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                        WHERE empresa_almacen.id = id_almacen_secundario
                                    ) AS almacen_salida,
                                    documento.created_at,
                                    documento.observacion,
                                    documento_fase.fase
                                FROM documento
                                INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                INNER JOIN documento_tipo ON documento.id_tipo = documento_tipo.id
                                AND documento.id_tipo = 10
                                AND documento.id_usuario != 1
                                AND documento.status = 1
                                " . $fase . "
                                " . $fecha . "");

        foreach ($solicitudes as $solicitud) {
            $productos = DB::select("SELECT
                                        movimiento.id,
                                        modelo.serie,
                                        modelo.costo,
                                        modelo.sku,
                                        modelo.descripcion,
                                        movimiento.cantidad,
                                        movimiento.comentario AS comentarios
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $solicitud->id . "");

            foreach ($productos as $producto) {
                if ($producto->serie) {
                    $series = DB::select("SELECT 
                                                producto.serie
                                            FROM movimiento
                                            INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                            INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                            WHERE movimiento.id = " . $producto->id . "");

                    $arreglo_series = array();

                    foreach ($series as $serie) {
                        $apos = `'`;
                        //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
                        if (str_contains($serie->serie, $apos)) {
                            $serie->serie = addslashes($serie->serie);
                        }
                        array_push($arreglo_series, $serie->serie);
                    }

                    $producto->series_anteriores    = $arreglo_series;
                    $producto->series               = array();
                }
            }

            $seguimiento = DB::select("SELECT
                                        seguimiento.*, 
                                        usuario.nombre 
                                    FROM seguimiento 
                                    INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                    WHERE id_documento = " . $solicitud->id . "");

            $archivos = DB::select("SELECT * FROM documento_archivo WHERE id_documento = " . $solicitud->id . " AND status = 1");

            $solicitud->archivos                = $archivos;
            $solicitud->productos               = $productos;
            $solicitud->seguimiento_anterior    = $seguimiento;
            $solicitud->seguimiento             = "";
        }

        return $solicitudes;
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'AC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'CEN'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        $text = ('<br>' . $sis . $ini . $trace['line'] . $fin);

        return $text;
    }
}
