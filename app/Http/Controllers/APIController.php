<?php

namespace App\Http\Controllers;

use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use DOMDocument;
use Validator;
use Exception;
use DB;

class APIController extends Controller
{
    /* API > V1 */
    public function api_v1_access_token(Request $request)
    {
        try {
            $api_key = $request->input('api_key');

            str_replace(array("'", '"'), "", $api_key);

            $validator = Validator::make($request->all(), [
                'api_key' => "required|max:50"
            ]);

            if (!$validator->passes()) {
                return response()->json([
                    'code'  => 400,
                    'error' => implode("; ", $validator->errors()->all())
                ], 400);
            }

            $existe_api_key = DB::select("SELECT id, id_entidad FROM documento_entidad_ftp WHERE api_key = '" . trim($api_key) . "'");

            if (empty($existe_api_key)) {
                return response()->json([
                    'code'  => 404,
                    'error' => "Invalid api key."
                ], 404);
            }

            $access_token = $this->jwt($existe_api_key[0]->id_entidad);

            DB::table('documento_entidad_ftp')->where(['id' => $existe_api_key[0]->id])->update([
                'token' => $access_token
            ]);

            return response()->json([
                'code'  => 200,
                'token' => $access_token
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code'  => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_product_availability($producto, Request $request)
    {
        try {
            str_replace(array("'", '"'), "", $producto);

            $existe_cliente = DB::select("SELECT id FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($existe_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $producto_data  = @json_decode(file_get_contents("http://201.7.208.53:11903//api/WSCyberPuerta/Producto/SKU/" . trim(rawurlencode($producto))));

            if (empty($producto_data)) {
                return response()->json([
                    'code' => 404,
                    'error' => "The product with the sku provided was not found."
                ], 404);
            }

            $existencia_producto = DocumentoService::existenciaProducto($producto, 114);

            if ($existencia_producto->error) {
                return response()->json([
                    'code' => 404,
                    'error' => "There was an error looking for the product's availability. " . $existencia_producto->mensaje
                ], 404);
            }

            $existe_cliente = $existe_cliente[0];

            $producto_tiene_margen = DB::select("SELECT
                                                documento_entidad_modelo_margen.precio
                                            FROM documento_entidad_modelo_margen
                                            INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                            WHERE modelo.sku = '" . $producto . "'
                                            AND documento_entidad_modelo_margen.id_ftp = '" . $existe_cliente->id . "'");

            if (empty($producto_tiene_margen)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The product was not found in your price list.'
                ], 404);
            }

            $producto_data->disponibilidad = $existencia_producto->error ? 0 : $existencia_producto->existencia;

            $producto_data->precio = round($producto_tiene_margen[0]->precio, 2);

            $producto_object = new \stdClass();

            $producto_object->sku = $producto;
            $producto_object->nombre = $producto_data->producto;
            $producto_object->descripcion = $producto_data->descripcion;
            $producto_object->marca = $producto_data->marca;
            $producto_object->np = $producto_data->numero_parte;
            $producto_object->moneda = $producto_data->moneda;
            $producto_object->precio = $producto_data->precio / 1.16;
            $producto_object->refurbished = $producto_data->refurbished;
            $producto_object->disponibilidad = ($producto_data->disponibilidad < 0) ? 0 : $producto_data->disponibilidad;

            return response()->json([
                'code' => 200,
                'data' => $producto_object
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_product_list(Request $request)
    {
        set_time_limit(0);

        try {
            $productos_response = array();

            $existe_cliente = DB::select("SELECT id FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($existe_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $existe_cliente = $existe_cliente[0];

            $productos = @json_decode(file_get_contents("http://201.7.208.53:11903/api/WSCyberPuerta/Productos"));

            if (!is_array($productos)) {
                return response()->json([
                    "code" => 500,
                    "error" => "There was an error looking for the product list."
                ], 500);
            }

            foreach ($productos as $producto) {
                $producto_tiene_margen = DB::select("SELECT
                                                        documento_entidad_modelo_margen.precio
                                                    FROM documento_entidad_modelo_margen
                                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND documento_entidad_modelo_margen.id_ftp = '" . $existe_cliente->id . "'");

                if (empty($producto_tiene_margen)) {
                    continue;
                }

                $response = DocumentoService::existenciaProducto($producto->sku, 114);

                $producto->disponibilidad = $response->error ? 0 : $response->existencia;

                $producto->precio = round($producto_tiene_margen[0]->precio, 2);

                $producto_object = new \stdClass();

                $producto_object->sku = $producto->sku;
                $producto_object->nombre = $producto->producto;
                $producto_object->descripcion = $producto->descripcion;
                $producto_object->marca = $producto->marca;
                $producto_object->np = $producto->numero_parte;
                # $producto_object->moneda = $producto->moneda; El precio siempre es en pesos por que se pone en CRM
                $producto_object->precio = $producto->precio / 1.16;
                $producto_object->refurbished = $producto->refurbished;
                $producto_object->disponibilidad = ($producto->disponibilidad < 0) ? 0 : $producto->disponibilidad;

                array_push($productos_response, $producto_object);
            }

            return response()->json([
                "code" => 200,
                "data" => $productos_response
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_create(Request $request)
    {
        try {
            $observaciones = str_replace(array("'", '"'), "", $request->input('comments'));
            $referencia = str_replace(array("'", '"'), "", $request->input('reference'));
            $paqueteria = str_replace(array("'", '"'), "", $request->input('logistic'));
            $moneda = str_replace(array("'", '"'), "", $request->input('currency'));
            $archivos = json_decode($request->input('files'));
            $productos = json_decode($request->input('products'));
            $total = 0;
            $paqueteria_id = 13;

            $data_cliente = DB::select("SELECT id, id_periodo, id_cfdi, id_marketplace_area FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($data_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'reference' => "required|string|max:50",
                'comments' => "required|string|max:250",
                'currency' => "required|numeric|max:3",
                'files' => "required|json",
                'products' => "required|json",
                'products.*.sku' => "required",
                'products.*.quantity' => "required|numeric"
            ]);

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 400,
                    'error' => implode("; ", $validator->errors()->all())
                ], 400);
            }

            if (!empty($paqueteria)) {
                if (!is_numeric($paqueteria)) {
                    return response()->json([
                        'code' => 404,
                        'error' => 'Logistics provider ID must be integer.'
                    ], 404);
                }

                $existe_paqueteria = DB::select("SELECT id FROM paqueteria WHERE id = " . $paqueteria . "");

                if (empty($existe_paqueteria)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Logistics provider not found."
                    ], 404);
                }

                $paqueteria_id = $existe_paqueteria[0]->id;
            }

            $data_cliente = $data_cliente[0];

            $existe_moneda = DB::select("SELECT id FROM moneda WHERE id = " . $moneda . "");

            if (empty($existe_moneda)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Currency " . $moneda . " not found."
                ], 404);
            }

            $existe_moneda = $existe_moneda[0];

            $existe_paqueteria = DB::select("SELECT id FROM paqueteria WHERE id = " . $paqueteria_id . "");

            if (empty($existe_paqueteria)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Logistics provider not found."
                ], 404);
            }

            if (!is_array($productos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Field products must be an array."
                ], 404);
            }

            if (empty($productos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Array of products must not be empty."
                ], 404);
            }

            if (!is_array($archivos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Field files must be an array."
                ], 404);
            }

            foreach ($productos as $producto) {
                if (!property_exists($producto, 'sku') || !property_exists($producto, 'quantity')) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Single product must have properties sku and quantity."
                    ], 404);
                }

                if ($producto->quantity < 1) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Single product quantity must be greater than 0."
                    ], 404);
                }

                $producto->sku = str_replace(array("'", '"'), "", $producto->sku);

                $existe_producto = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

                if (empty($existe_producto)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Sku " . $producto->sku . " was not found."
                    ], 404);
                }

                $producto_data  = @json_decode(file_get_contents("http://201.7.208.53:11903/api/WSCyberPuerta/Producto/SKU/" . trim(rawurlencode($producto->sku))));

                if (empty($producto_data)) {
                    return response()->json([
                        'code' => 500,
                        'error' => "There was an error while looking for the sku " . $producto->sku . ", please try again in 1 minute."
                    ], 500);
                }

                $existencia_producto = DocumentoService::existenciaProducto($producto->sku, 114);

                if ($existencia_producto->error) {
                    return response()->json([
                        'code' => 404,
                        'error' => "There was an error looking for the product's availability. " . $existencia_producto->mensaje
                    ], 404);
                }

                $producto->existencia = ($existencia_producto->existencia < 0) ? 0 : $existencia_producto->existencia;

                if ($producto->existencia < $producto->quantity) {
                    return response()->json([
                        'code' => 404,
                        'error' => "There is no enought product for the sku " . $producto->sku . ", available: " . $producto->existencia . "."
                    ], 404);
                }

                $producto_tiene_margen = DB::select("SELECT
                                                        documento_entidad_modelo_margen.precio
                                                    FROM documento_entidad_modelo_margen
                                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND documento_entidad_modelo_margen.id_ftp = '" . $data_cliente->id . "'");

                if (empty($producto_tiene_margen)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Sku " . $producto->sku . " was not found in your price list."
                    ], 404);
                }

                $producto->precio = round($producto_tiene_margen[0]->precio, 2);
                $producto->id = $existe_producto[0]->id;
            }

            foreach ($archivos as $archivo) {
                if (!$this->is_valid_base64($archivo)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "File must be a valid base64 string."
                    ], 404);
                }

                $pdf_raw_data = base64_decode($archivo);
                $is_pdf_or_zpl = $this->is_string_pdf($pdf_raw_data);

                if ($is_pdf_or_zpl->error) {
                    return response()->json([
                        'code' => 400,
                        'error' => "Only PDFs and ZPL files are allowed."
                    ], 400);
                }
            }

            $venta = 'B2B_' . mb_strtoupper(uniqid(), 'UTF-8');

            $documento = DB::table('documento')->insertGetId([
                'id_almacen_principal_empresa' => 114, # Almacén del B2B
                'id_marketplace_area' => $data_cliente->id_marketplace_area, # Marketplace de cyberpuerta
                'id_periodo' => $data_cliente->id_periodo, # Previamente registrado junto con el cliente
                'id_cfdi' => $data_cliente->id_cfdi, # Previamente registrado junto con el cliente
                'id_usuario' => 1, # Sistema
                'id_moneda' => $moneda, # Pesos
                'id_paqueteria' => $paqueteria_id,
                'id_fase' => 1,
                'no_venta' => $venta, # Venta generar con el prefijo B2B y el uniqid
                'tipo_cambio' => $this->obtener_tipo_cambio($moneda),
                'referencia' => $referencia,
                'observacion' => $observaciones,
            ]);

            DB::table('documento_entidad_re')->insert([
                'id_entidad' => $request->auth,
                'id_documento' => $documento
            ]);

            DB::table('documento_direccion')->insert([
                'id_documento' => $documento,
                'id_direccion_pro' => 0,
                'contacto' => '',
                'calle' => '',
                'numero' => '',
                'numero_int' => '',
                'colonia' => '',
                'ciudad' => '',
                'estado' => '',
                'codigo_postal' => '',
                'referencia' => ''
            ]);

            if (!empty($observaciones)) {
                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => $observaciones
                ]);
            }

            foreach ($productos as $producto) {
                DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $producto->id,
                    'cantidad' => $producto->quantity,
                    'precio' => $producto->precio / 1.16,
                    'garantia' => 90,
                    'modificacion' => '',
                    'comentario' => '',
                    'addenda' => '',
                    'regalo' => 0
                ]);

                $total += $producto->precio / 1.16 * $producto->quantity;
            }

            foreach ($archivos as $archivo) {
                $is_pdf_or_zpl = $this->is_string_pdf(base64_decode($archivo));

                $archivo_nombre = "B2B_FILE_" . $documento . "_" . uniqid() . "." . $is_pdf_or_zpl->extension;

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo_nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body(base64_decode($archivo))
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento' =>  $documento,
                    'id_usuario' =>  1,
                    'tipo' =>  2,
                    'nombre' =>  $archivo_nombre,
                    'dropbox' =>  $response->body->id
                ]);
            }

            return response()->json([
                'code' => 200,
                'message' => "Order created succesfuly. You can track the progress of the order with the ID given",
                'order' => $documento,
                'total' => (float) $total
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_confirm(Request $request)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $request->input('order')));
            $paqueteria = str_replace(array("'", '"'), "", $request->input('logistic'));

            $validator = Validator::make($request->all(), [
                'order' => "required|numeric",
                'logistic' => "required|numeric|exists:paqueteria,id"
            ]);

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 400,
                    'error' => implode("; ", $validator->errors()->all())
                ], 400);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 1)
                ->where('documento.id_entidad', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The order with the given ID has been already confirmed.'
                ], 404);
            }

            $tiene_archivos = DB::select("SELECT id FROM documento_archivo WHERE id_documento = " . $documento . "");

            if (empty($tiene_archivos)) {
                return response()->json([
                    'code' => 406,
                    'error' => "Order can not be confirmed if there are not files attached."
                ], 406);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 3,
                'id_paqueteria' => $paqueteria
            ]);

            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => 1,
                'seguimiento' => 'El pedido ha sido confirmado por el cliente.'
            ]);

            return response()->json([
                'code' => 200,
                'message' => "The order has been confirmed and now is ready to be packed."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_cancel(Request $request, $documento)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $documento));

            if (!is_numeric($documento)) {
                return response()->json([
                    'code'  => 404,
                    'error' => 'Order ID must be numeric.'
                ]);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.id_entidad', $request->auth)
                ->select('documento.status', 'documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            if ($existe_documento[0]->status == 0) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The order with the given ID has been already canceled.'
                ], 404);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The order with the given ID can not be cancel.'
                ], 404);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'status' => 0,
                'status_erp' => 0
            ]);

            return response()->json([
                'code' => 200,
                'message' => "The order has been canceled correctly."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_files_add(Request $request)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $request->input('order')));
            $archivos = json_decode($request->input('files'));

            $validator = Validator::make($request->all(), [
                'order' => "required|numeric",
                'files' => "required|json",
            ]);

            if (!is_numeric($documento)) {
                return response()->json([
                    'code' => 400,
                    'error' => 'Order ID must be numeric.'
                ], 400);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 1)
                ->where('documento.id_entidad', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 400,
                    'error' => 'Order with the given ID not found.'
                ], 400);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 400,
                    'error' => "You can not add more files because the order has been confirmed already."
                ], 400);
            }

            if (empty($archivos)) {
                return response()->json([
                    'code' => 400,
                    'error' => "Files array must not be empty."
                ], 400);
            }

            if (!is_array($archivos)) {
                return response()->json([
                    'code' => 400,
                    'error' => "Field files must be an array."
                ], 400);
            }

            foreach ($archivos as $archivo) {
                if (!$this->is_valid_base64($archivo)) {
                    return response()->json([
                        'code' => 400,
                        'error' => "File must be a valid base64 string."
                    ], 400);
                }

                $pdf_raw_data = base64_decode($archivo);

                $is_pdf_or_zpl = $this->is_string_pdf($pdf_raw_data);

                if ($is_pdf_or_zpl->error) {
                    return response()->json([
                        'code' => 400,
                        'error' => "Only PDFs and ZPL files are allowed."
                    ], 400);
                }
            }

            foreach ($archivos as $archivo) {
                $is_pdf_or_zpl = $this->is_string_pdf(base64_decode($archivo));

                $archivo_nombre = "B2B_FILE_" . $documento . "_" . uniqid() . "." . $is_pdf_or_zpl->extension;

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo_nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body(base64_decode($archivo))
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento' =>  $documento,
                    'id_impresora' => 36,
                    'id_usuario' =>  1,
                    'tipo' =>  2,
                    'nombre' =>  $archivo_nombre,
                    'dropbox' =>  $response->body->id
                ]);

                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => 'Ha sido agregado un archivo por parte del cliente.'
                ]);
            }

            return response()->json([
                'code' => 200,
                'message' => "Files were added correctly."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_files_view(Request $request, $documento)
    {
        try {
            $documento = str_replace(array("'", '"'), "", $documento);

            if (!is_numeric($documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order ID must be numeric.'
                ], 404);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 1)
                ->where('documento.id_entidad', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            $archivos = DB::select("SELECT dropbox FROM documento_archivo WHERE id_documento = " . $documento . "");
            $archivos_data = array();

            foreach ($archivos as $archivo) {
                $file_data = new \stdClass();
                $file_data->path = $archivo->dropbox;

                $response = \Httpful\Request::post('https://api.dropboxapi.com/2/files/get_temporary_link')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Content-Type', 'application/json')
                    ->body(json_encode($file_data))
                    ->send();

                $response = @json_decode($response->raw_body);

                if (empty($response)) {
                    return response()->json([
                        'code' => 500,
                        'error' => "There was an error while looking for the file, please contact the server admin."
                    ], 500);
                }

                array_push($archivos_data, property_exists($response, 'error') ? $response->error_summary : base64_encode(file_get_contents($response->link)));
            }

            return response()->json([
                'code' => 200,
                'files' => $archivos_data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_status(Request $request, $documento)
    {
        try {
            $documento = str_replace(array("'", '"'), "", $documento);
            $uuid = "Not available.";
            $xml_data = "Not available.";

            if (!is_numeric($documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order ID must be numeric.'
                ], 404);
            }

            $existe_venta = DB::table('documento')
                ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
                ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->join('documento_fase', 'documento.id_fase', '=', 'documento_fase.id')
                ->where('documento.id', $documento)
                ->where('documento_entidad.id', $request->auth)
                ->select(
                    'documento_fase.id',
                    'documento.referencia',
                    'documento.status',
                    'empresa.bd'
                )
                ->get();

            if (empty($existe_venta)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ]);
            }

            if ($existe_venta[0]->status == 0) {
                return response()->json([
                    'code' => 200,
                    'message' => "The order has been canceled"
                ]);
            }

            switch ($existe_venta[0]->id) {
                case '1':
                    $message = "The order is waiting to be confirmed.";
                    break;

                case '3':
                    $message = "The order is being packed.";
                    break;

                case '4':
                    $message = "The order has been packed and is ready to ship.";
                    break;

                case '6':
                    $factura_data = @json_decode(file_get_contents(config('webservice.url') . $existe_venta[0]->bd  . '/Factura/Estado/Folio/' . $documento));

                    $message = "UUID not available yet.";

                    if (!empty($factura_data)) {
                        if (!is_array($factura_data)) {
                            if (!is_null($factura_data->uuid)) {
                                $message = "Billed.";
                                $uuid = $factura_data->uuid;

                                $xml_data_raw = @file_get_contents(config('webservice.url') . $existe_venta[0]->bd  . '/DescargarXML/Serie/' . $factura_data->serie . '/Folio/' . $documento);

                                if (!empty($xml_data)) {
                                    $xml_data = base64_encode($xml_data_raw);
                                }
                            }
                        }
                    }

                    break;

                default:
                    $message = "The order has been sent.";
                    break;
            }

            return response()->json([
                'code' => 200,
                'message' => $message,
                'uuid' => $uuid,
                'reference' => $existe_venta[0]->referencia,
                'xml' => $xml_data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_data_providers(Request $request)
    {
        try {
            $data_cliente = DB::select("SELECT id, id_periodo, id_cfdi, id_marketplace_area FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($data_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $paqueterias = DB::select("SELECT id, paqueteria FROM paqueteria WHERE status = 1");

            return response()->json([
                'code' => 200,
                'data' => $paqueterias
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_v1_order_data_currencies(Request $request)
    {
        try {
            $data_cliente = DB::select("SELECT id, id_periodo, id_cfdi, id_marketplace_area FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($data_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $monedas = DB::select("SELECT * FROM moneda");

            return response()->json([
                'code' => 200,
                'data' => $monedas
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* API > Sandbox */
    public function api_sandbox_order_create(Request $request)
    {
        try {
            $observaciones = str_replace(array("'", '"'), "", $request->input('comments'));
            $referencia = str_replace(array("'", '"'), "", $request->input('reference'));
            $paqueteria = str_replace(array("'", '"'), "", $request->input('logistic'));
            $moneda = str_replace(array("'", '"'), "", $request->input('currency'));
            $archivos = json_decode($request->input('files'));
            $productos = json_decode($request->input('products'));
            $paqueteria_id = 13;
            $total = 0;

            $data_cliente = DB::select("SELECT id, id_periodo, id_cfdi, id_marketplace_area FROM documento_entidad_ftp WHERE id_entidad = " . $request->auth . "");

            if (empty($data_cliente)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Customer not found."
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'reference' => "required|string|max:50",
                'comments' => "required|string|max:250",
                'currency' => "required|numeric|max:3",
                'files' => "required|json",
                'products' => "required|json",
                'products.*.sku' => "required",
                'products.*.quantity' => "required|numeric"
            ]);

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 400,
                    'error' => implode("; ", $validator->errors()->all())
                ], 400);
            }

            if (!empty($paqueteria)) {
                if (!is_numeric($paqueteria)) {
                    return response()->json([
                        'code' => 404,
                        'error' => 'Logistics provider ID must be integer.'
                    ], 404);
                }

                $existe_paqueteria = DB::select("SELECT id FROM paqueteria WHERE id = " . $paqueteria . "");

                if (empty($existe_paqueteria)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Logistics provider not found."
                    ], 404);
                }

                $paqueteria_id = $paqueteria;
            }

            $data_cliente = $data_cliente[0];

            $existe_moneda = DB::select("SELECT id FROM moneda WHERE id = " . $moneda . "");

            if (empty($existe_moneda)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Currency " . $moneda . " not found."
                ], 404);
            }

            $existe_moneda = $existe_moneda[0];

            if (!is_array($productos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Field products must be an array."
                ], 404);
            }

            if (empty($productos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Array of products must not be empty."
                ], 404);
            }

            if (!is_array($archivos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Field files must be an array."
                ], 404);
            }

            foreach ($productos as $producto) {
                if (!property_exists($producto, 'sku') || !property_exists($producto, 'quantity')) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Single product must have properties sku and quantity."
                    ], 404);
                }

                if ($producto->quantity < 1) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Single product quantity must be greater than 0."
                    ], 404);
                }

                $producto->sku = str_replace(array("'", '"'), "", $producto->sku);

                $existe_producto = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

                if (empty($existe_producto)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Sku " . $producto->sku . " was not found."
                    ], 404);
                }

                $producto_data  = @json_decode(file_get_contents("http://201.7.208.53:11903/api/WSCyberPuerta/Producto/SKU/" . trim(rawurlencode($producto->sku))));

                if (empty($producto_data)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Sku " . $producto->sku . " was not found."
                    ], 404);
                }

                $existencia_producto = DocumentoService::existenciaProducto($producto->sku, 114);

                if ($existencia_producto->error) {
                    return response()->json([
                        'code' => 404,
                        'error' => "There was an error looking for the product's availability. " . $existencia_producto->mensaje
                    ], 404);
                }

                $producto->existencia = ($existencia_producto->existencia < 0) ? 0 : $existencia_producto->existencia;

                $producto_tiene_margen = DB::select("SELECT
                                                        documento_entidad_modelo_margen.precio
                                                    FROM documento_entidad_modelo_margen
                                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND documento_entidad_modelo_margen.id_ftp = '" . $data_cliente->id . "'");

                if (empty($producto_tiene_margen)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "Sku " . $producto->sku . " was not found in your price list."
                    ], 404);
                }

                $producto->precio = round($producto_tiene_margen[0]->precio, 2);
                $producto->id = $existe_producto[0]->id;
            }

            foreach ($archivos as $archivo) {
                if (!$this->is_valid_base64($archivo)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "File must be a valid base64 string."
                    ], 404);
                }

                $pdf_raw_data = base64_decode($archivo);

                $is_pdf_or_zpl = $this->is_string_pdf($pdf_raw_data);

                if ($is_pdf_or_zpl->error) {
                    return response()->json([
                        'code' => 400,
                        'error' => "Only PDFs and ZPL files are allowed."
                    ], 400);
                }
            }

            $venta = 'B2B_' . mb_strtoupper(uniqid(), 'UTF-8');

            $documento = DB::table('documento')->insertGetId([
                'id_almacen_principal_empresa' => 114, # Almacén del B2B
                'id_marketplace_area' => $data_cliente->id_marketplace_area, # Marketplace de cyberpuerta
                'id_periodo' => $data_cliente->id_periodo, # Previamente registrado junto con el cliente
                'id_cfdi' => $data_cliente->id_cfdi, # Previamente registrado junto con el cliente
                'id_usuario' => 1, # Sistema
                'id_moneda' => $moneda, # Pesos
                'id_entidad' => $request->auth,
                'id_paqueteria' => $paqueteria_id,
                'id_fase' => 1,
                'sandbox' => 1,
                'status' => 0,
                'no_venta' => $venta, # Venta generar con el prefijo B2B y el uniqid
                'tipo_cambio' => $this->obtener_tipo_cambio($moneda),
                'referencia' => $referencia,
                'observacion' => $observaciones,
            ]);

            DB::table('documento_direccion')->insert([
                'id_documento' => $documento,
                'id_direccion_pro' => 0,
                'contacto' => '',
                'calle' => '',
                'numero' => '',
                'numero_int' => '',
                'colonia' => '',
                'ciudad' => '',
                'estado' => '',
                'codigo_postal' => '',
                'referencia' => ''
            ]);

            if (!empty($observaciones)) {
                DB::table('seguimiento')->insert([
                    'id_documento' => $documento,
                    'id_usuario' => 1,
                    'seguimiento' => $observaciones
                ]);
            }

            foreach ($productos as $producto) {
                DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $producto->id,
                    'cantidad' => $producto->quantity,
                    'precio' => $producto->precio / 1.16,
                    'garantia' => 90,
                    'modificacion' => '',
                    'comentario' => '',
                    'addenda' => '',
                    'regalo' => 0
                ]);

                $total += $producto->precio / 1.16 * $producto->quantity;
            }

            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => 1,
                'seguimiento' => '<p><h1>VENTA CREADA DE PRUEBA, FAVOR DE NO HACER NADA.</h1></p>'
            ]);

            foreach ($archivos as $archivo) {
                $is_pdf_or_zpl = $this->is_string_pdf(base64_decode($archivo));

                $archivo_nombre = "B2B_FILE_" . $documento . "_" . uniqid() . "." . $is_pdf_or_zpl->extension;

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo_nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body(base64_decode($archivo))
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento' =>  $documento,
                    'id_usuario' =>  1,
                    'tipo' =>  2,
                    'nombre' =>  $archivo_nombre,
                    'dropbox' =>  $response->body->id
                ]);
            }

            return response()->json([
                'code' => 200,
                'message' => "Order created succesfuly. You can track the progress of the order with the ID given",
                'order' => $documento,
                'total' => (float) $total
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_sandbox_order_confirm(Request $request)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $request->input('order')));
            $paqueteria = str_replace(array("'", '"'), "", $request->input('logistic'));

            $validator = Validator::make($request->all(), [
                'order' => "required|numeric",
                'logistic' => "required|numeric|exists:paqueteria,id"
            ]);

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 404,
                    'error' => implode("; ", $validator->errors()->all())
                ], 404);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 0)
                ->where('documento.sandbox', 1)
                ->where('documento_entidad.id', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The order with the given ID has been already confirmed.'
                ], 404);
            }

            $tiene_archivos = DB::select("SELECT id FROM documento_archivo WHERE id_documento = " . $documento . "");

            if (empty($tiene_archivos)) {
                return response()->json([
                    'code' => 406,
                    'error' => "Order can not be confirmed if there are not files attached."
                ], 406);
            }

            DB::table('documento')->where(['id' => $documento])->update([
                'id_fase' => 3,
                'id_paqueteria' => $paqueteria
            ]);

            return response()->json([
                'code' => 200,
                'message' => "The order has been confirmed and now is ready to be packed."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_sandbox_order_cancel(Request $request, $documento)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $documento));

            if (!is_numeric($documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order ID must be numeric.'
                ], 404);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 0)
                ->where('documento.sandbox', 1)
                ->where('documento_entidad.id', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 404,
                    'error' => 'The order with the given ID can not be cancel.'
                ], 404);
            }

            return response()->json([
                'code' => 200,
                'message' => "The order has been canceled correctly."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_sandbox_order_files_add(Request $request)
    {
        try {
            $documento = trim(str_replace(array("'", '"'), "", $request->input('order')));
            $archivos = json_decode($request->input('files'));

            $validator = Validator::make($request->all(), [
                'order' => "required|numeric",
                'files' => "required|json",
            ]);

            if (!is_numeric($documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order ID must be numeric.'
                ], 404);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 0)
                ->where('documento.sandbox', 1)
                ->where('documento_entidad.id', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            if ($existe_documento[0]->id_fase != 1) {
                return response()->json([
                    'code' => 404,
                    'error' => "You can not add more files because the order has been confirmed already."
                ], 404);
            }

            if (empty($archivos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Files array must not be empty."
                ], 404);
            }

            if (!is_array($archivos)) {
                return response()->json([
                    'code' => 404,
                    'error' => "Field files must be an array."
                ], 404);
            }

            foreach ($archivos as $archivo) {
                if (!$this->is_valid_base64($archivo)) {
                    return response()->json([
                        'code' => 404,
                        'error' => "File must be a valid base64 string."
                    ], 404);
                }

                $pdf_raw_data = base64_decode($archivo);

                $is_pdf_or_zpl = $this->is_string_pdf($pdf_raw_data);

                if ($is_pdf_or_zpl->error) {
                    return response()->json([
                        'code' => 400,
                        'error' => "Only PDFs and ZPL files are allowed."
                    ], 400);
                }
            }

            foreach ($archivos as $archivo) {
                $is_pdf_or_zpl = $this->is_string_pdf(base64_decode($archivo));

                $archivo_nombre = "B2B_FILE_" . $documento . "_" . uniqid() . "." . $is_pdf_or_zpl->extension;

                $response = \Httpful\Request::post('https://content.dropboxapi.com/2/files/upload')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo_nombre . '" , "mode": "add", "autorename": true}')
                    ->addHeader('Content-Type', 'application/octet-stream')
                    ->body(base64_decode($archivo))
                    ->send();

                DB::table('documento_archivo')->insert([
                    'id_documento' =>  $documento,
                    'id_usuario' =>  1,
                    'tipo' =>  2,
                    'nombre' =>  $archivo_nombre,
                    'dropbox' =>  $response->body->id
                ]);
            }

            return response()->json([
                'code' => 200,
                'message' => "Files were added correctly."
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_sandbox_order_files_view(Request $request, $documento)
    {
        try {
            $documento = str_replace(array("'", '"'), "", $documento);

            if (!is_numeric($documento)) {
                return response()->json([
                    'code'  => 404,
                    'error' => 'Order ID must be numeric.'
                ]);
            }

            $existe_documento = DB::table('documento')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 0)
                ->where('documento.sandbox', 1)
                ->where('documento_entidad.id', $request->auth)
                ->select('documento.id_fase')
                ->get();

            if (empty($existe_documento)) {
                return response()->json([
                    'code' => 404,
                    'error' => 'Order with the given ID not found.'
                ], 404);
            }

            $archivos = DB::select("SELECT dropbox FROM documento_archivo WHERE id_documento = " . $documento . "");
            $archivos_data = array();

            foreach ($archivos as $archivo) {
                $file_data = new \stdClass();
                $file_data->path = $archivo->dropbox;

                $response = \Httpful\Request::post('https://api.dropboxapi.com/2/files/get_temporary_link')
                    ->addHeader('Authorization', "Bearer AYQm6f0FyfAAAAAAAAAB2PDhM8sEsd6B6wMrny3TVE_P794Z1cfHCv16Qfgt3xpO")
                    ->addHeader('Content-Type', 'application/json')
                    ->body(json_encode($file_data))
                    ->send();

                $response = @json_decode($response->raw_body);

                if (empty($response)) {
                    return response()->json([
                        'code' => 500,
                        'error' => "There was an error while looking for the file, please contact the server admin."
                    ], 500);
                }

                array_push($archivos_data, property_exists($response, 'error') ? $response->error_summary : base64_encode(file_get_contents($response->link)));
            }

            return response()->json([
                'code' => 200,
                'files' => $archivos_data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function api_sandbox_order_status(Request $request, $documento)
    {
        try {
            $documento = str_replace(array("'", '"'), "", $documento);
            $uuid = "Not available.";

            if (!is_numeric($documento)) {
                return response()->json([
                    'code'  => 404,
                    'error' => 'Order ID must be numeric.'
                ]);
            }

            $existe_venta = DB::table('documento')
                ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
                ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
                ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
                ->join('documento_fase', 'documento.id_fase', '=', 'documento_fase.id')
                ->where('documento.id', $documento)
                ->where('documento.status', 0)
                ->where('documento.sandbox', 1)
                ->where('documento_entidad.id', $request->auth)
                ->select(
                    'documento_fase.id',
                    'documento.status',
                    'documento.referencia',
                    'empresa.bd'
                )
                ->get();

            if (empty($existe_venta)) {
                return response()->json([
                    'code'  => 404,
                    'error' => 'Order with the given ID not found.'
                ]);
            }

            switch ($existe_venta[0]->id) {
                case '1':
                    $message = "The order is waiting to be confirmed.";
                    break;

                case '3':
                    $message = "The order is being packed.";
                    break;

                case '4':
                    $message = "The order has been packed and is ready to ship.";
                    break;

                case '6':
                    $factura_data = @json_decode(file_get_contents(config('webservice.url') . $existe_venta[0]->bd  . '/Factura/Estado/Folio/' . $documento));

                    $message = "UUID not available yet.";

                    if (!empty($factura_data)) {
                        if (!is_array($factura_data)) {
                            $message = "Billed.";
                            $uuid = $factura_data->uuid;
                        }
                    }

                    break;

                default:
                    $message = "The order has been sent.";
                    break;
            }

            return response()->json([
                'code' => 200,
                'message' => $message,
                'uuid' => $uuid,
                'reference' => $existe_venta[0]->referencia
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function obtener_tipo_cambio($id_moneda)
    {
        $tipo_cambio    = 0;
        $serie_banxico  = "";

        switch ($id_moneda) {
            case 1:
                $serie_banxico = "SF46410";
                break;

            case 2:
                $serie_banxico = "SF60653";
                break;

            default:
                $serie_banxico = "";
                $tipo_cambio = 1;
                break;
        }

        if (empty($serie_banxico)) {
            return $tipo_cambio;
        }

        $opts = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $context = stream_context_create($opts);

        try {
            $cliente = new \SoapClient(config('webservice.tc'), array('stream_context' => $context, 'trace' => true));

            $xml_data = simplexml_load_string($cliente->tiposDeCambioBanxico(), "SimpleXMLElement");

            $documento = new DOMDocument;

            $documento->loadXML($xml_data->asXML());

            $monedas = $documento->getElementsByTagName('Series');

            foreach ($monedas as $moneda) {
                $serie = $moneda->getAttribute('IDSERIE');

                $tipo_cambio_data = $moneda->getElementsByTagName('Obs')[0];

                if ($serie === $serie_banxico) {
                    $tipo_cambio = (float) $tipo_cambio_data->getAttribute('OBS_VALUE');
                }
            }
        } catch (Exception $e) {
            $tipo_cambio = 1;
        }

        return $tipo_cambio == 0 ? 1 : $tipo_cambio;
    }

    protected function is_string_pdf($string)
    {
        $response = new \stdClass();
        $response->error = 1;

        $mime_type_found = (new \finfo(FILEINFO_MIME))->buffer($string);

        if (strpos($mime_type_found, 'application/pdf') !== false || strpos($mime_type_found, 'text/plain') !== false) {
            $response->error = 0;
            $response->extension = strpos($mime_type_found, 'application/pdf') !== false ? 'pdf' : 'zpl';
        }

        return $response;
    }

    protected function is_valid_base64($base64)
    {
        return (preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $base64)) ? true : false;
    }

    protected function jwt($id_entidad)
    {
        $payload = [
            'iss' => "lumen-api-jwt", // Issuer of the token.
            'sub' => $id_entidad, // Subject of the token.
            'iat' => time(), // Time when JWT was issued.
            'exp' => time() + 60 * 60 * 12 // El token expira cada 12 horas.
        ];

        // As you can see we are passing `JWT_SECRET` as the second parameter that will 
        // be used to decode the token in the future.
        return JWT::encode($payload, env('JWT_SECRET'));
    }
}
