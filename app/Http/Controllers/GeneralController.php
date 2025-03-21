<?php
/** @noinspection ALL */

namespace App\Http\Controllers;

use App\Http\Services\InventarioService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Http\Services\MercadolibreService;
use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use App\Events\PusherEvent;
use Crabbly\FPDF\FPDF;
use Mailgun\Mailgun;
use Exception;
use Validator;
use DB;
use DateTime;
use Illuminate\Support\Facades\Crypt;
use stdClass;

class GeneralController extends Controller
{
    /* General > Busqueda */
    public function general_busqueda_producto_data(Request $request)
    {
        $auth = json_decode($request->auth);

        $tipos_documento = DB::table("documento_tipo")
            ->get()
            ->toArray();

        $empresas = DB::table("empresa")
            ->select("empresa.id", "empresa.bd", "empresa.empresa")
            ->join("usuario_empresa", "empresa.id", "=", "usuario_empresa.id_empresa")
            ->where("empresa.status", 1)
            ->where("empresa.id", "<>", 0)
            ->where("usuario_empresa.id_usuario", $auth->id)
            ->get()
            ->toArray();

        foreach ($empresas as $empresa) {
            $empresa->almacenes = DB::table("empresa_almacen")
                ->select("empresa_almacen.id", "empresa_almacen.id_erp", "almacen.almacen")
                ->join("almacen", "empresa_almacen.id_almacen", "=", "almacen.id")
                ->where("empresa_almacen.id_empresa", $empresa->id)
                ->where("almacen.status", 1)
                ->where("almacen.id", "<>", 0)
                ->orderBy("almacen.almacen", "ASC")
                ->get()
                ->toArray();
        }

        return response()->json([
            'empresas' => $empresas,
            'tipos_documento' => $tipos_documento
        ]);
    }

    public function general_busqueda_producto_existencia(Request $request)
    {
        set_time_limit(0);

        $url = "";
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $bridge = array();
        $productos = array();
        $productos_array = array();
        $skubusqueda = true;
        $extras = '';

        if (empty($data->criterio)) {
            if ($data->almacen == 0) {
                return response()->json([
                    "code" => 500,
                    "message" => "Favor de seleccionar un almacén o escribir un criterio para realizar la búsqueda"
                ]);
            }
        }

        if (sizeof($data->etiquetas) >= 2) {
            $skubusqueda = false;
            foreach ($data->etiquetas as $criterio) {
                if ($data->almacen != 0) {
                    $url = config('webservice.url') . $data->empresa . '/Reporte/Productos/Existencia/Almacen/' . $data->almacen . '/Descripcion/' . rawurlencode($criterio);
                    $bridge = json_decode(file_get_contents($url));
                    $productos = array_merge($productos, $bridge);
                } else {
                    $url = config('webservice.url') . 'producto/Consulta/Productos/Descripcion/' . $data->empresa . '/' . rawurlencode($criterio);
                    $bridge = json_decode(file_get_contents($url));
                    $productos = array_merge($productos, $bridge);
                }
            }
        }

        if ($data->etiquetas == [] || sizeof($data->etiquetas) == 1) {
            $url = config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $data->empresa . '/' . rawurlencode($data->criterio);
            if (empty($data->criterio)) {
                $url = config('webservice.url') . $data->empresa . '/Reporte/Productos/Existencia/Almacen/' . $data->almacen;
                $productos = @json_decode(file_get_contents($url));
            } else {
                if ($data->almacen != 0) {
                    $url = config('webservice.url') . $data->empresa . '/Reporte/Productos/Existencia/Almacen/' . $data->almacen . '/SKU/' . rawurlencode($data->criterio);
                    $productos = json_decode(file_get_contents($url));
                    if (empty($productos)) {
                        $url = config('webservice.url') . $data->empresa . '/Reporte/Productos/Existencia/Almacen/' . $data->almacen . '/Descripcion/' . rawurlencode($data->criterio);
                        $productos = @json_decode(file_get_contents($url));
                        if (empty($productos)) {
                            $es_sinonimo = DB::table("modelo_sinonimo")
                                ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                                ->select("modelo.sku")
                                ->where("modelo_sinonimo.codigo", trim($data->criterio))
                                ->first();
                            if (!empty($es_sinonimo)) {
                                $url = config('webservice.url') . $data->empresa . '/Reporte/Productos/Existencia/Almacen/' . $data->almacen . '/SKU/' . rawurlencode($es_sinonimo->sku);
                                $productos = @json_decode(file_get_contents($url));
                            }
                        }
                    }
                } else {
                    $productos = @json_decode(file_get_contents($url));
                    if (empty($productos)) {
                        $url = config('webservice.url') . 'producto/Consulta/Productos/Descripcion/' . $data->empresa . '/' . rawurlencode($data->criterio);
                        $productos = @json_decode(file_get_contents($url));
                        if (empty($productos)) {
                            $es_sinonimo = DB::table("modelo_sinonimo")
                                ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                                ->select("modelo.sku")
                                ->where("modelo_sinonimo.codigo", trim($data->criterio))
                                ->first();
                            if (!empty($es_sinonimo)) {
                                $url = config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $data->empresa . '/' . rawurlencode($es_sinonimo->sku);
                                $productos = @json_decode(file_get_contents($url));
                            }
                        }
                    }
                }
            }
        }

        $es_admin = DB::table("usuario_subnivel_nivel")
            ->select("usuario_subnivel_nivel.id")
            ->join("subnivel_nivel", "usuario_subnivel_nivel.id_subnivel_nivel", "=", "subnivel_nivel.id")
            ->where("usuario_subnivel_nivel.id_usuario", $auth->id)
            ->where("subnivel_nivel.id_nivel", 6)
            ->where("subnivel_nivel.id_subnivel", 1)
            ->first();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $contador_fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'CÓDIGO');
        $sheet->setCellValue('B1', 'DESCRIPCIÓN');
        $sheet->setCellValue('C1', 'ÚLTIMO COSTO');
        $sheet->setCellValue('D1', 'ALMACÉN');
        $sheet->setCellValue('E1', 'INVENTARIO');
        $sheet->setCellValue('F1', 'PENDIENTES');
        $sheet->setCellValue('G1', 'EN TRANSITO');
        $sheet->setCellValue('H1', 'PRETRANSFERENCIA');
        $sheet->setCellValue('I1', 'DISPONIBLE');
        $sheet->setCellValue('J1', 'TIPO DE PRODUCTO');
        $sheet->setCellValue('K1', 'MARCA');
        $sheet->setCellValue('L1', 'SUBTIPO');
        $sheet->setCellValue('M1', 'VERTICAL');
        $sheet->setCellValue('N1', 'CODIGO SAT');
        $sheet->setCellValue('O1', 'SERIE');
        $sheet->setCellValue('P1', 'NP');

        $proveedores_b2b = DB::table("modelo_proveedor")
            ->select("id", "razon_social")
            ->where("status", 1)
            ->where("id", "<>", 0)
            ->get()
            ->toArray();

        $last_column = "P";

        foreach ($proveedores_b2b as $index => $proveedor) {
            $last_column = self::excelColumnRange('A', 'ZZ')[15 + $index];

            $sheet->setCellValue($last_column . '1', $proveedor->razon_social);
        }

        $sheet->getStyle('A:' . $last_column)->getAlignment()->setHorizontal('center'); # Texto centrado
        $spreadsheet->getActiveSheet()->getStyle('A1:' . $last_column . '1')->getFont()->setBold(1)->getColor()->setARGB('2B28F6'); # Cabecera en negritas y de color azul de fondo

        $productos = is_array($productos) ? $productos : (array) $productos;
        $data->etiquetas = is_array($data->etiquetas) ? $data->etiquetas : (array) $data->etiquetas;
        foreach ($productos as $index => $producto) {
            if (!$skubusqueda) {
                foreach ($data->etiquetas as $key) {

                    $haystack = strtolower($producto->producto);
                    $needle = strtolower($key);

                    if (!str_contains($haystack, $needle)) {
                        continue 2;
                    }
                };
            }

            $almacenes = array();
            $inventario = "";
            $pendientes = "";
            $transito = "";
            $disponible = "";

            if ($data->con_existencia) {
                if (COUNT($producto->existencias->almacenes) == 0) {
                    unset($productos[$index]);

                    continue;
                }

                $existencia_positiva = false;

                foreach ($producto->existencias->almacenes as $almacen) {
                    if ($almacen->fisico > 0) {
                        $existencia_positiva = true;

                        break;
                    }
                }

                if (!$existencia_positiva) {
                    unset($productos[$index]);

                    continue;
                }
            }

            $costo_extra = DB::select("SELECT id, costo_extra, serie  FROM modelo WHERE sku = '" . $producto->sku . "'");

            $producto->costo_extra = (empty($costo_extra)) ? 0 : (float) $costo_extra[0]->costo_extra;
            $producto->imagenes = DB::table("modelo_imagen")
                ->join("modelo", "modelo_imagen.id_modelo", "=", "modelo.id")
                ->select("dropbox")
                ->where("modelo.sku", $producto->sku)
                ->get()
                ->toArray();
            $producto->precio = DB::table("modelo_precio")
                ->selectRaw("ROUND(modelo_precio.precio, 2) AS precio")
                ->join("modelo", "modelo_precio.id_modelo", "=", "modelo.id")
                ->join("empresa", "modelo_precio.id_empresa", "=", "empresa.id")
                ->where("modelo.sku", $producto->sku)
                ->where("empresa.bd", $data->empresa)
                ->first();

            foreach ($producto->existencias->almacenes as $index_producto => $almacen) {
                # OMG
                if (($data->empresa == "7" && in_array($almacen->almacenid, [17, 0]) && empty($es_admin)) || ($data->empresa == "6" && $almacen->almacenid == 1010 && empty($es_admin)) || ($data->empresa == "8" && $almacen->almacenid == 8 && empty($es_admin))) {
                    unset($producto->existencias->almacenes[$index_producto]);

                    continue;
                }

                $pendientes_bo = DB::select("SELECT
                                                        IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                    FROM documento
                                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND empresa.bd = " . $data->empresa . "
                                                    AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                    AND documento.id_tipo = 2
                                                    AND documento.status = 1
                                                    AND documento.id_fase IN (1, 7)")[0]->cantidad;

                $pendientes_surtir = DB::select("SELECT
                                                        IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                    FROM documento
                                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND empresa.bd = " . $data->empresa . "
                                                    AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                    AND documento.id_tipo = 2
                                                    AND documento.status = 1
                                                    AND documento.anticipada = 0
                                                    AND documento.id_fase IN (2, 3)")[0]->cantidad;

                $pendientes_importar = DB::select("SELECT
                                                    IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                FROM documento
                                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE modelo.sku = '" . $producto->sku . "'
                                                AND empresa.bd = " . $data->empresa . "
                                                AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                AND documento.id_tipo = 2
                                                AND documento.status = 1
                                                AND documento.anticipada = 0
                                                AND documento.id_fase BETWEEN 4 AND 5")[0]->cantidad;

                $pendientes_pretransferencia = DB::select("SELECT
                                                                IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                            FROM documento
                                                            INNER JOIN empresa_almacen ON documento.id_almacen_secundario_empresa = empresa_almacen.id
                                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                            WHERE modelo.sku = '" . $producto->sku . "'
                                                            AND empresa.bd = " . $data->empresa . "
                                                            AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                            AND documento.id_tipo = 9
                                                            AND documento.status = 1
                                                            AND documento.id_fase IN (401, 402, 403, 404)")[0]->cantidad;

                $pendientes_recibir = DB::select("SELECT
                                                        movimiento.id AS movimiento_id,
                                                        modelo.sku,
                                                        modelo.serie,
                                                        movimiento.completa,
                                                        movimiento.cantidad,
                                                        movimiento.cantidad_aceptada,
                                                        (SELECT
                                                            COUNT(*) AS cantidad
                                                        FROM movimiento
                                                        INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                        WHERE movimiento.id = movimiento_id) AS recepcionadas
                                                    FROM documento
                                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                    WHERE documento.id_tipo = 1
                                                    AND documento.status = 1
                                                    AND modelo.sku = '" . $producto->sku . "'
                                                    AND empresa.bd = " . $data->empresa . "
                                                    AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                    AND documento.id_fase = 93");

                $total_pendientes = 0;

                foreach ($pendientes_recibir as $pendiente) {
                    if ($pendiente->serie) {
                        $total_pendientes += $pendiente->cantidad - $pendiente->recepcionadas;
                    } else {
                        #$total_pendientes += ($pendiente->completa) ? 0 : $pendiente->cantidad;
                        $total_pendientes += $pendiente->cantidad - $pendiente->cantidad_aceptada;
                    }
                }

                $almacen->nombre = $almacen->almacen;
                $almacen->fisico -= $pendientes_importar;

                $almacen->pendientes_surtir = (int) $pendientes_surtir;
                $almacen->pendientes_recibir = (int) $total_pendientes;
                $almacen->pendientes_pretransferencia = (int) $pendientes_pretransferencia;
                $almacen->pendientes_bo = (int) $pendientes_bo;

                array_push($almacenes, $almacen);
            }

            foreach ($almacenes as $almacen) {
                $pendientes_surtir = property_exists($almacen, "pendientes_surtir") ? $almacen->pendientes_surtir : 0;
                $pendientes_recibir = property_exists($almacen, "pendientes_recibir") ? $almacen->pendientes_recibir : 0;
                $pendientes_pretransferencia = property_exists($almacen, "pendientes_pretransferencia") ? $almacen->pendientes_pretransferencia : 0;
                $disponible = ((int) $almacen->fisico - (int) $pendientes_surtir - (int) $pendientes_pretransferencia);

                # Excel
                $sheet->setCellValue('A' . $contador_fila, $producto->sku);
                $sheet->setCellValue('B' . $contador_fila, $producto->producto);
                $sheet->setCellValue('C' . $contador_fila, $producto->ultimo_costo);
                $sheet->setCellValue('D' . $contador_fila, $almacen->nombre);
                $sheet->setCellValue('E' . $contador_fila, $almacen->fisico);
                $sheet->setCellValue('F' . $contador_fila, $pendientes_surtir);
                $sheet->setCellValue('G' . $contador_fila, $pendientes_recibir);
                $sheet->setCellValue('H' . $contador_fila, $pendientes_pretransferencia);
                $sheet->setCellValue('I' . $contador_fila, $disponible);
                $sheet->setCellValue('J' . $contador_fila, $producto->cat1);
                $sheet->setCellValue('K' . $contador_fila, $producto->cat2);
                $sheet->setCellValue('L' . $contador_fila, $producto->cat3);
                $sheet->setCellValue('M' . $contador_fila, $producto->cat4);
                $sheet->setCellValue('N' . $contador_fila, $producto->claveprodserv);
                $sheet->setCellValue('O' . $contador_fila, empty($costo_extra) ? 0 : ($costo_extra[0]->serie ? "SÍ" : "NO"));
                $sheet->setCellValue('P' . $contador_fila, property_exists($producto, "numero_parte") ? $producto->numero_parte : "N/A");

                foreach ($proveedores_b2b as $index => $proveedor) {
                    if (!empty($costo_extra)) {
                        $codigo_data = DB::table("modelo_proveedor_producto")
                            ->select("id_producto")
                            ->where("id_modelo_proveedor", $proveedor->id)
                            ->where("id_modelo", $costo_extra[0]->id)
                            ->first();

                        $last_producto_column = self::excelColumnRange('A', 'ZZ')[16 + $index];

                        $sheet->setCellValue($last_producto_column . $contador_fila, empty($codigo_data) ? "" : $codigo_data->id_producto);
                    }
                }

                $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(16, $contador_fila)->setValueExplicit(property_exists($producto, "numero_parte") ? $producto->numero_parte : "N/A", \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $contador_fila++;
            }

            if ($data->con_existencia) {
                if (COUNT($producto->existencias->almacenes) == 0) {
                    unset($productos[$index]);

                    continue;
                }
            }

            $producto->ultimo_costo = ROUND($producto->ultimo_costo, 2);
            $producto->existencias->almacenes   = $almacenes;

            array_push($productos_array, $producto);
        }

        foreach (range('A', $last_column) as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }


        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_productos.xlsx');

        $json['code'] = 200;
        $json['productos'] = $productos_array;
        $json['excel'] = base64_encode(file_get_contents('reporte_productos.xlsx'));
        $json['prod'] = $productos;
        $json['extra'] = $skubusqueda;
        $json['extras'] = $extras;

        unlink('reporte_productos.xlsx');

        return response()->json($json);
    }

    public function general_busqueda_producto_costo($producto)
    {
        $compras = DB::select("SELECT
                                SUBSTRING_INDEX(documento.created_at, ' ', 1) AS fecha,
                                ROUND(movimiento.precio * documento.tipo_cambio, 2) costo,
                                documento.factura_serie,
                                documento.factura_folio
                            FROM documento
                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                            WHERE documento.id_tipo = 1
                            AND documento.status = 1
                            AND modelo.sku = '" . $producto . "'
                            GROUP BY fecha");

        return response()->json([
            'code'  => 200,
            'compras'    => $compras
        ]);
    }

    public function general_busqueda_producto_precio($producto, $fecha)
    {
        $ventas = DB::select("SELECT
                                documento.id,
                                SUBSTRING_INDEX(documento.created_at, ' ', 1) AS fecha,
                                ROUND(SUM(movimiento.precio * 1.16 * documento.tipo_cambio) / COUNT(*), 2) precio,
                                SUM(movimiento.cantidad) AS cantidad
                            FROM documento
                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                            WHERE documento.id_tipo = 2
                            AND documento.status = 1
                            AND modelo.sku = '" . $producto . "'
                            AND documento.created_at BETWEEN '" . date("Y-m-d", strtotime(date("Y-m-d") . " " . $fecha . "days")) . " 00:00:00' AND '" . date("Y-m-d H:i:s") . "'
                            GROUP BY fecha");

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas
        ]);
    }

    public function general_busqueda_producto_kardex_crm(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input("data"));

        $data->producto = str_replace('%20', '', $data->producto);
        $documentos_almacen = array();

        $extra_query = "";

        if (!empty($data->tipo_documento)) {
            $extra_query .= " AND documento_tipo.id = " . $data->tipo_documento . "";
        }

        if (!empty($data->fecha_inicial) || !empty($data->fecha_final)) {
            $extra_query .=  " AND documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'";
        }

        $documentos = DB::select("SELECT
                                    documento.id,
                                    documento.no_venta,
                                    documento.tipo_cambio,
                                    documento.factura_serie,
                                    documento.factura_folio,
                                    documento.documento_extra,
                                    documento.observacion,
                                    documento.created_at,
                                    documento.status,
                                    documento.autorizado_by AS autorizado_por,
                                    documento.id_almacen_principal_empresa AS id_almacen_principal,
                                    documento.id_almacen_secundario_empresa AS id_almacen_secundario,
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
                                    ) AS almacen_alterno,
                                    (
                                        SELECT
                                            nombre
                                        FROM usuario
                                        WHERE id = autorizado_por
                                    ) AS autorizador,
                                    documento_tipo.tipo,
                                    documento_fase.fase,
                                    documento_entidad.razon_social,
                                    moneda.moneda,
                                    movimiento.cantidad,
                                    ROUND(IF (documento_tipo.tipo = 'COMPRA', movimiento.precio, movimiento.precio * 1.16), 2) AS precio,
                                    marketplace_area.serie AS serie_factura,
                                    marketplace.marketplace,
                                    area.area
                                FROM documento 
                                INNER JOIN moneda ON documento.id_moneda = moneda.id
                                INNER JOIN documento_tipo ON documento.id_tipo = documento_tipo.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                LEFT JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                LEFT JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                LEFT JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                LEFT JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                INNER JOIN movimiento ON documento.id = movimiento.id_documento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE modelo.sku = '" . trim($data->producto) . "'
                                AND empresa.bd = '" . $data->empresa . "'
                                " . $extra_query . "");

        foreach ($documentos as $documento) {
            $existe_almacen = 0;

            foreach ($documentos_almacen as $documento_almacen) {
                if ($documento_almacen->almacen == $documento->id_almacen_principal) {
                    array_push($documento_almacen->documentos, $documento);

                    $existe_almacen = 1;
                }
            }

            if (!$existe_almacen) {
                $nuevo_almacen = new \stdClass();
                $nuevo_almacen->almacen = $documento->id_almacen_principal;
                $nuevo_almacen->almacen_nombre = $documento->almacen_principal;
                $nuevo_almacen->documentos = array();

                array_push($nuevo_almacen->documentos, $documento);
                array_push($documentos_almacen, $nuevo_almacen);
            }
        }

        $spreadsheet = new Spreadsheet();

        foreach ($documentos_almacen as $index => $almacen) {
            $spreadsheet->createSheet();

            $spreadsheet->setActiveSheetIndex($index + 1);
            $spreadsheet->getActiveSheet()->setTitle($almacen->almacen_nombre);
            $spreadsheet->getActiveSheet()->getStyle('A1:Q1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

            $sheet = $spreadsheet->getActiveSheet();
            $fila = 2;

            $sheet->setCellValue('A1', 'DOCUMENTO ERP');
            $sheet->setCellValue('B1', 'DOCUMENTO CRM');
            $sheet->setCellValue('C1', 'VENTA (MARKETPLACE)');
            $sheet->setCellValue('D1', 'FOLIO');
            $sheet->setCellValue('E1', 'ENTIDAD');
            $sheet->setCellValue('F1', 'ALMACEN PRINCIPAL');
            $sheet->setCellValue('G1', 'ALMACEN SECUNDARIO');
            $sheet->setCellValue('H1', 'MONEDA');
            $sheet->setCellValue('I1', 'T.C');
            $sheet->setCellValue('J1', 'CANTIDAD');
            $sheet->setCellValue('K1', 'COSTO');
            $sheet->setCellValue('L1', 'TOTAL');
            $sheet->setCellValue('M1', 'OBSERVACION');
            $sheet->setCellValue('N1', 'FASE');
            $sheet->setCellValue('O1', 'FECHA');
            $sheet->setCellValue('P1', 'ESTADO');
            $sheet->setCellValue('Q1', 'TIPO DOCUMENTO');

            foreach ($almacen->documentos as $documento) {
                $sheet->setCellValue('A' . $fila, $documento->documento_extra);
                $sheet->setCellValue('B' . $fila, $documento->id);
                $sheet->setCellValue('C' . $fila, $documento->no_venta);
                $sheet->setCellValue('D' . $fila, $documento->factura_serie . " " . $documento->factura_folio);
                $sheet->setCellValue('E' . $fila, $documento->razon_social);
                $sheet->setCellValue('F' . $fila, $documento->almacen_principal);
                $sheet->setCellValue('G' . $fila, $documento->almacen_alterno);
                $sheet->setCellValue('H' . $fila, $documento->moneda);
                $sheet->setCellValue('I' . $fila, $documento->tipo_cambio);
                $sheet->setCellValue('J' . $fila, $documento->cantidad);
                $sheet->setCellValue('K' . $fila, $documento->precio);
                $sheet->setCellValue('L' . $fila, (float) $documento->tipo_cambio * (int) $documento->cantidad * (float) $documento->precio);
                $sheet->setCellValue('M' . $fila, $documento->observacion);
                $sheet->setCellValue('N' . $fila, $documento->fase);
                $sheet->setCellValue('O' . $fila, $documento->created_at);
                $sheet->setCellValue('P' . $fila, $documento->status ? 'ACTIVA' : 'CANCELADA');
                $sheet->setCellValue('Q' . $fila, $documento->tipo);

                $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($documento->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                $spreadsheet->getActiveSheet()->getStyle("I" . $fila . ":L" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

                if (!$documento->status) {
                    $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ":Q" . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('CD5C5C');
                }

                foreach (range('A', 'Q') as $columna) {
                    $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
                }

                $fila++;
            }
        }

        if (count($documentos_almacen) > 0) {
            $spreadsheet->removeSheetByIndex(0);
            $spreadsheet->setActiveSheetIndex(0);
        }

        $excel_name = uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($excel_name);

        $json['documentos'] = $documentos_almacen;
        $json['excel_data'] = base64_encode(file_get_contents($excel_name));
        $json['excel_name'] = $excel_name;

        unlink($excel_name);

        return response()->json($json);
    }

    public function general_busqueda_venta_nota_informacion_pendientes(Request $request)
    {
        $data = json_decode($request->input("data"));
        $criterio = trim(str_replace("%20", " ", $data->criterio));
        $currentDate = date('d/m/Y');
        $titulo = '';
        $marketplace = '';
        $periodo = '';
        $almacen = '';
        $empresa = '';
        $uso_cod = '';
        $uso_desc = '';
        $metodo_pago = '';
        $formapago = '';
        $doc = '';
        $folio = '';

        $notas = @json_decode(file_get_contents(config('webservice.url') . 'PendientesAplicar/' . $data->empresa . '/NotasCredito/rangofechas/De/01/01/2023/Al/' . $currentDate));

        $documento =
            DB::table('documento')
                ->select('documento.id', 'marketplace.marketplace', 'area.area', 'documento_periodo.periodo', 'almacen.almacen', 'empresa.empresa', 'documento_uso_cfdi.descripcion as uso_desc', 'documento_uso_cfdi.codigo as uso_cod')
                ->join('documento_periodo', 'documento.id_periodo', '=', 'documento_periodo.id')
                ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
                ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
                ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
                ->join('marketplace_area', 'marketplace_area.id', '=', 'documento.id_marketplace_area')
                ->join('marketplace', 'marketplace.id', '=', 'marketplace_area.id_marketplace')
                ->join('area', 'area.id', '=', 'marketplace_area.id_area')
                ->join('documento_uso_cfdi', 'documento.id_cfdi', '=', 'documento_uso_cfdi.id')
                ->where('documento.nota', $criterio)
                ->get()
                ->toArray();

        if (!empty($documento)) {
            $documento = $documento[0];
            $marketplace = '' . $documento->marketplace . ' / ' . $documento->area;
            $doc = $documento->id;
            $periodo = $documento->periodo;
            $almacen = $documento->almacen;
            $empresa = $documento->empresa;
            $uso_cod = $documento->uso_cod;
            $uso_desc = $documento->uso_desc;
        } else {
            $nota_data = @json_decode(file_get_contents(config('webservice.url') . 'cliente/notacredito/' . $data->empresa . '/ID/' . $criterio));

            if (!empty($nota_data[0]->documentos_pagos)) {
                $nota_data = $nota_data[0];
                $folio = $nota_data->documentos_pagos[0]->folio;
            };
            $documento =
                DB::table('documento')
                    ->select('documento.id', 'marketplace.marketplace', 'area.area', 'documento_periodo.periodo', 'almacen.almacen', 'empresa.empresa', 'documento_uso_cfdi.descripcion as uso_desc', 'documento_uso_cfdi.codigo as uso_cod')
                    ->join('documento_periodo', 'documento.id_periodo', '=', 'documento_periodo.id')
                    ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
                    ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
                    ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
                    ->join('marketplace_area', 'marketplace_area.id', '=', 'documento.id_marketplace_area')
                    ->join('marketplace', 'marketplace.id', '=', 'marketplace_area.id_marketplace')
                    ->join('area', 'area.id', '=', 'marketplace_area.id_area')
                    ->join('documento_uso_cfdi', 'documento.id_cfdi', '=', 'documento_uso_cfdi.id')
                    ->where('documento.id', $folio)
                    ->get()
                    ->toArray();
            if (!empty($documento)) {
                $documento = $documento[0];
                $marketplace = '' . $documento->marketplace . ' / ' . $documento->area;
                $doc = $documento->id;
                $periodo = $documento->periodo;
                $almacen = $documento->almacen;
                $empresa = $documento->empresa;
                $uso_cod = $documento->uso_cod;
                $uso_desc = $documento->uso_desc;
            }
        }

        $documento_nota = DB::table('documento')
            ->select('referencia', 'observacion')
            ->where('documento.documento_extra', $criterio)
            ->first();

        if (!empty($documento_nota)) {
            $d_n = '';
            if ($documento_nota->referencia) {
                $n = explode(' ', $documento_nota->referencia);
                $d_n = $n[count($n) - 1];
                if ($n[count($n) - 1] == '') {
                    $d_n = $n[count($n) - 2];
                }
                $titulo = $documento_nota->referencia;
            } else {
                $d_n = $documento_nota->observacion;
                $n = explode(' ', $documento_nota->observacion);
                $d_n = $n[count($n) - 1];
                if ($n[count($n) - 1] == '') {
                    $d_n = $n[count($n) - 2];
                }
                $titulo = $documento_nota->observacion;
            }
        }

        $factura_data = @json_decode(file_get_contents(config('webservice.url') . $data->empresa  . '/Factura/Estado/Folio/' . $doc));

        if (!empty($factura_data)) {
            $factura_data = is_array($factura_data) ? $factura_data[0] : $factura_data;
            $metodo_pago = $factura_data->metodopago;
            $formapago = $factura_data->formapago;
        }

        if (empty($notas)) {
            return response()->json([
                "code" => 200,
                "por_aplicar" => 0,
                "documento" => $doc,
                "marketplace" => $marketplace,
                "titulo" => $titulo,
                'periodo' =>  $periodo,
                'almacen' => $almacen,
                'empresa' => $empresa,
                'uso_cod' => $uso_cod, 'uso_desc' => $uso_desc,
                'metodopago' => $metodo_pago,
                "formapago" => $formapago
            ]);
        }

        array_reverse($notas);

        foreach ($notas as $element) {
            if ($criterio == $element->documento) {
                return response()->json([
                    "code" => 200,
                    "por_aplicar" => 1,
                    "documento" => $doc,
                    "marketplace" => $marketplace,
                    "titulo" => $titulo,
                    'periodo' =>  $periodo,
                    'almacen' => $almacen,
                    'empresa' => $empresa,
                    'uso_cod' => $uso_cod,
                    'uso_desc' => $uso_desc,
                    'metodopago' => $metodo_pago,
                    "formapago" => $formapago
                ]);
            }
        }

        return response()->json([
            "code" => 200,
            "por_aplicar" => 0,
            "documento" => $doc,
            "marketplace" => $marketplace,
            "titulo" => $titulo,
            'periodo' =>  $periodo,
            'almacen' => $almacen,
            'empresa' => $empresa,
            'uso_cod' => $uso_cod,
            'uso_desc' => $uso_desc,
            'metodopago' => $metodo_pago,
            "formapago" => $formapago
        ]);
    }



    public function general_busqueda_venta_nota_informacion_canceladas(Request $request)
    {
        $data = json_decode($request->input("data"));

        $criterio = trim(str_replace("%20", " ", $data->criterio));

        $notas = @json_decode(file_get_contents(config('webservice.url') . 'cliente/notacredito/' . $data->empresa . '/ID/' . $criterio));
        if (!$notas) {
            return response()->json([
                "code" => 300,
                "message" => 'No se encontró la nota solicitada'
            ]);
        }

        return response()->json([
            "code" => 200,
            "nota" => $notas[0],
        ]);
    }

    public function general_busqueda_venta_nota_informacion(Request $request)
    {
        $data = json_decode($request->input("data"));
    }

    public function general_busqueda_venta_informacion(Request $request)
    {
        $data = json_decode($request->input("data"));
        $criterio = trim(str_replace("%20", " ", $data->criterio));

        if (isset($data->criterio) && !is_numeric($data->criterio)) {
            $data_arreglo = explode("&", $data->criterio);

            if (count($data_arreglo) > 1 && is_numeric($data_arreglo[1])) {
                return response()->json([
                    'code' => 200,
                    'redireccionar' => true,
                    'url' => "/busqueda/movimiento/" . $data_arreglo[1]
                ]);
            }
        }

        $query = DB::table('documento')
            ->join('documento_periodo', 'documento.id_periodo', '=', 'documento_periodo.id')
            ->join('moneda', 'documento.id_moneda', '=', 'moneda.id')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('paqueteria', 'documento.id_paqueteria', '=', 'paqueteria.id')
            ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
            ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
            ->join('documento_fase', 'documento.id_fase', '=', 'documento_fase.id')
            ->join('marketplace_area', 'documento.id_marketplace_area', '=', 'marketplace_area.id')
            ->join('area', 'marketplace_area.id_area', '=', 'area.id')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->join('modelo_proveedor', 'documento.id_modelo_proveedor', '=', 'modelo_proveedor.id')
            ->leftJoin('documento_guia', 'documento.id', '=', 'documento_guia.id_documento')
            ->whereIn('documento.id_tipo', [1, 2]);

        if (in_array($data->campo, ["rfc", "razon_social", "correo"])) {
            $query->where('documento_entidad.' . $data->campo, 'LIKE', '%' . $criterio . '%');
        } elseif ($data->campo == "guia") {
            $query->where('documento_guia.guia', 'LIKE', '%' . $criterio . '%');
        } else {
            $query->where('documento.' . $data->campo, 'LIKE', '%' . $criterio . '%');
        }

        $ventas = $query->select(
            'documento.id',
            'documento.id_fase',
            'documento.id_periodo',
            'documento.nota',
            'documento.documento_extra',
            'documento.tipo_cambio',
            'documento.factura_serie',
            'documento.factura_folio',
            'documento.no_venta',
            'documento.no_venta_btob',
            'documento.pagado',
            'documento.refacturado',
            'documento.observacion',
            'documento.comentario',
            'documento.referencia',
            'documento.modificacion',
            'documento.problema',
            'documento.status',
            'documento.uuid',
            'documento.mkt_total',
            'documento.mkt_coupon',
            'documento.created_at',
            'documento.packing_date',
            'documento.packing_by AS usuario_empaquetador',
            DB::raw('(SELECT nombre FROM usuario WHERE id = documento.packing_by) AS empaquetado_por'),
            'marketplace.marketplace',
            'marketplace_area.id AS id_marketplace_area',
            'marketplace_area.serie',
            'marketplace_area.publico',
            'area.area',
            'paqueteria.url',
            'paqueteria.paqueteria',
            'usuario.nombre AS usuario',
            'documento_entidad.razon_social AS cliente',
            'documento_entidad.rfc',
            'documento_entidad.correo',
            'documento_entidad.telefono',
            'documento_entidad.telefono_alt',
            'documento_fase.fase',
            'documento_periodo.periodo',
            'almacen.almacen',
            'empresa.empresa AS empresa_razon',
            'empresa.bd AS empresa',
            'moneda.moneda',
            'modelo_proveedor.id AS modelo_proveedor_id',
            'modelo_proveedor.razon_social AS modelo_proveedor'
        )
            ->groupBy('documento.id')
            ->get();

        foreach ($ventas as $venta) {
            $venta->fase = $this->determinarFase($venta);
            $venta->direccion = DB::table('documento_direccion')->where('id_documento', $venta->id)->first() ?? 0;

            $venta->guias = DB::table('documento_guia')->where('id_documento', $venta->id)->get();
            foreach ($venta->guias as $guia) {
                $guia->manifiesto = DB::table('manifiesto')
                    ->select('salida', 'impreso', 'created_at')
                    ->where('guia', trim($guia->guia))
                    ->get();
            }

            $venta->productos = DB::table('movimiento')
                ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                ->where('id_documento', $venta->id)
                ->select('movimiento.id', 'movimiento.cantidad','movimiento.garantia',
                    'modelo.sku', 'modelo.descripcion', 'modelo.serie',
                    DB::raw('ROUND((movimiento.precio * 1.16), 2) AS precio'))
                ->get();

            foreach ($venta->productos as $producto) {
                $producto->series = DB::table('movimiento_producto')
                    ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
                    ->where('movimiento_producto.id_movimiento', $producto->id)
                    ->select('producto.id', 'producto.serie')
                    ->get();
            }

            $venta->archivos = DB::table('documento_archivo')
                ->join('usuario', 'documento_archivo.id_usuario', '=', 'usuario.id')
                ->where('documento_archivo.id_documento', $venta->id)
                ->where('documento_archivo.status', 1)
                ->select('usuario.id', 'usuario.nombre AS usuario', 'documento_archivo.nombre AS archivo', 'documento_archivo.dropbox', 'documento_archivo.tipo')
                ->get();

            $venta->seguimiento = DB::table('seguimiento')
                ->select('seguimiento.*', 'usuario.nombre')
                ->join('usuario','seguimiento.id_usuario','=','usuario.id')
                ->where('id_documento',$venta->id)
                ->get();

            $venta->api = DB::table('marketplace_api')->where('id_marketplace_area','=',$venta->id_marketplace_area)->first() ?? 0;
            $venta->refacturacion_pendiente = DB::table('refacturacion')->where('id_documento', $venta->id)->whereNotIn('step', ['99'])->first() ? 1 : 0;

            $empresa_externa = DB::table('documento')
                ->select('empresa.bd', 'empresa.empresa')
                ->join('marketplace_area_empresa', 'documento.id_marketplace_area', '=', 'marketplace_area_empresa.id_marketplace_area')
                ->join('empresa', 'marketplace_area_empresa.id_empresa', '=', 'empresa.id')
                ->where('documento.id', $venta->id)
                ->first();
            $venta->empresa_externa = $empresa_externa->bd ?? 0;
            $venta->empresa_externa_razon = $empresa_externa->empresa ?? 0;

            $nota = DB::table('documento_nota_autorizacion')
                ->select('estado')
                ->where('id_documento', $venta->id)
                ->where('estado', 1)
                ->first();
            $venta->nota_pendiente = $nota->estado ?? 0;

            $venta->usuario_agro = 0;
            if ($venta->area === "AGRO") {
                $usuario_agro = DB::table('usuarios_agro')
                    ->select('usuarios_agro.nombre')
                    ->join('documento_usuario_agro', 'documento_usuario_agro.id_usuarios_agro', '=', 'usuarios_agro.id')
                    ->join('documento', 'documento.id', '=', 'documento_usuario_agro.id_documento')
                    ->where('documento.id', $venta->id)
                    ->first();
                $venta->usuario_agro = $usuario_agro->nombre ?? 0;
            }
        }

        return response()->json([
            'code'  => 200,
            'ventas'    => $ventas
        ]);
    }
    private function determinarFase($venta)
    {
        $fase = $venta->fase;

        if ($venta->id_fase == 2 && $venta->modificacion) {
            return "Pendiente de modificacion";
        }

        if ($venta->publico == 0 && $venta->pagado == 0 && $venta->id_periodo == 1) {
            return "Pendiente de pago";
        } elseif ($venta->problema) {
            return "En problemas";
        }

        return $fase;
    }

    public function general_busqueda_venta_borrar($dropbox)
    {
        DB::table('documento_archivo')->where(['dropbox' => $dropbox])->update(['status' => 0]);

        return response()->json([
            'code'  => 200
        ]);
    }

    public function general_busqueda_venta_refacturacion(Request $request)
    {
        $auth = json_decode($request->auth);
        $data = json_decode($request->input("data"));
        $option = json_decode($request->input("option"));

        if ($data->necesita_token) {
            $validate_authy = DocumentoService::authy($auth->id, $data->token);

            if ($validate_authy->error) {
                return response()->json([
                    "code" => 500,
                    "message" => $validate_authy->mensaje
                ]);
            }
        }

        $crear_refacturacion = DocumentoService::crearRefacturacion($data->documento, $option);

        if ($crear_refacturacion->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $crear_refacturacion->mensaje,
                'raw'   => property_exists($crear_refacturacion, 'raw') ? $crear_refacturacion->raw : null,
                'data'  => property_exists($crear_refacturacion, 'data') ? $crear_refacturacion->data : null
            ]);
        }

        if (!empty($crear_refacturacion->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento'  => $data->documento,
                'id_usuario'    => $auth->id,
                'seguimiento'   => $crear_refacturacion->seguimiento
            ]);
        }

        return response()->json([
            'code'  => 200,
            'message'   => $crear_refacturacion->mensaje
        ]);
    }

    public function general_busqueda_venta_autorizar_nota(Request $request)
    {

        $data = json_decode($request->input('data'));
        $modulo = json_decode($request->input('modulo'));
        $auth = json_decode($request->auth);

        $existe_pendiente = DB::table('documento_nota_autorizacion')->where('id_documento', $data)->where('estado', 1)->first();

        if ($existe_pendiente) {
            return response()->json([
                "code" => 400,
                "message" => "Autorizacion pendiente de esta Nota de Credito ya existe <br> Actualice la pestaña (F5)",
            ]);
        }

        DB::table('documento_nota_autorizacion')->insert([
            'id_documento' => $data,
            'id_usuario' => $auth->id,
            'modulo' => $modulo
        ]);


        DB::table('seguimiento')->insert([
            'id_documento'  => $data,
            'id_usuario'    => $auth->id,
            'seguimiento'   => "<p>Se envía la nota a autorización</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Nota de credito en autorización pendiente",
        ]);
    }

    public function general_busqueda_sin_venta_autorizar_nota(Request $request)
    {

        $data = json_decode($request->input('data'));
        $modulo = json_decode($request->input('modulo'));
        $invoice = json_decode($request->input('invoice'));
        $auth = json_decode($request->auth);

        $encryptedData = Crypt::encrypt($data);

        DB::table('documento_nota_autorizacion')->insert([
            'id_documento' => $invoice,
            'id_usuario' => $auth->id,
            'modulo' => $modulo,
            'data' => $encryptedData
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Nota de credito en autorización pendiente",
        ]);
    }

    public function general_busqueda_venta_autorizar_nota_garantia(Request $request)
    {

        $data = json_decode($request->input('data'));
        $auth = json_decode($request->input('usuario'));
        $modulo = json_decode($request->input('modulo'));

        $existe_pendiente = DB::table('garantia_nota_autorizacion')->where('documento', $data->documento)->where('estado', 1)->first();

        if ($existe_pendiente) {
            return response()->json([
                "code" => 400,
                "message" => "Autorizacion pendiente de esta Nota de Credito ya existe <br> Actualice la pestaña (F5)",
            ]);
        }

        DB::table('garantia_nota_autorizacion')->insert([
            'json' => $request->input('data'),
            'usuario' => $auth,
            'documento' => $data->documento,
            'documento_garantia' => $data->documento_garantia,
            'modulo' => $modulo,
            'data' => $request->input('doc')
        ]);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth,
            'seguimiento'   => "<p>Se envía la nota a autorización</p>"
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Nota de credito en autorización pendiente",
        ]);
    }

    // AQUI
    public function general_busqueda_venta_nota(Request $request, $documento)
    {
        $auth = json_decode($request->auth);
        $seguimiento = "";

        $info_documento = DB::select("SELECT 
                                        documento.factura_serie, 
                                        documento.factura_folio,
                                        documento.documento_extra,
                                        documento.nota,
                                        documento_entidad.rfc,
                                        empresa.bd,
                                        marketplace_area.publico,
                                        marketplace.marketplace
                                    FROM documento 
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    WHERE documento.id = " . $documento . " AND documento.status = 1");

        if (empty($info_documento)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el detalle del documento, favor de verificar que no haya sido cancelado, de no estar cancelado, contacte al administrador."
            ]);
        }

        $info_documento = $info_documento[0];

        if ($info_documento->nota != 'N/A') {
            return response()->json([
                "code" => 500,
                "message" => "Ya existe una nota de credito relacionada con este pedido con el ID " . $info_documento->nota
            ]);
        }

        $folio_factura = ($info_documento->factura_serie == 'N/A') ? $documento : $info_documento->factura_folio;

        $informacion_factura = @json_decode(file_get_contents(config('webservice.url')  . $info_documento->bd . '/Factura/Estado/Folio/' . $folio_factura));

        if (empty($informacion_factura)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró información de la factura " . $folio_factura
            ]);
        }

        if (is_array($informacion_factura)) {
            foreach ($informacion_factura as $factura) {
                if (($factura->eliminado == 0 || $factura->eliminado == null) && ($factura->cancelado == 0 || $factura->cancelado == null)) {
                    $informacion_factura = $factura;

                    break;
                }
            }
        }

        if (is_null($informacion_factura->uuid)) {
            return response()->json([
                'code' => 200,
                'message' => "La factura no se encuentra timbrada."
            ]);
        }

        if ($informacion_factura->pagado > 0) {
            if (!$info_documento->publico) {
                return response()->json([
                    "code" => 500,
                    "message" => "No se puede crear la NC por que la factura tiene pagos asociados, favor de desaplicar e intentar de nuevo."
                ]);
            }

            $pagos_asociados = @json_decode(file_get_contents(config('webservice.url') . $info_documento->bd . '/Documento/' . $info_documento->documento_extra . '/PagosRelacionados'));

            if (!empty($pagos_asociados)) {
                foreach ($pagos_asociados as $pago) {
                    if ($pago->pago_con_documento != 0) {
                        return response()->json([
                            "code" => 500,
                            "message" => "La factura ya tiene aplicada una NC en el ERP con el ID " . $pago->pago_con_documento
                        ]);
                    }
                }

                foreach ($pagos_asociados as $pago) {
                    $pago_id = ($pago->pago_con_operacion == 0) ? $pago->pago_con_documento : $pago->pago_con_operacion;

                    $eliminar_relacion = DocumentoService::desaplicarPagoFactura($documento, $pago_id);

                    if ($eliminar_relacion->error) {
                        $seguimiento .= ($pago->pago_con_operacion == 0) ? "<p>No fue posible eliminar la relación de la nc con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>" : "<p>No fue posible eliminar la relación del pago con el ID " . $pago_id . ", mensaje de error: " . $eliminar_relacion->mensaje . ".</p>";
                    } else {
                        $seguimiento .= ($pago->pago_con_operacion == 0) ? "<p>Se eliminó la relación de la nc con el ID " . $pago_id . ", correctamente.</p>" : "<p>Se eliminó la relación del pago con el ID " . $pago_id . " correctamente. </p>";
                    }
                }
            }
        }

        # Se crear el documento nota de credito en el CRM para hacer el movimiento de series
        $crear_nota_credito = DocumentoService::crearNotaCredito($documento, 1);

        if ($crear_nota_credito->error) {
            return response()->json([
                'code' => 500,
                'message' => $crear_nota_credito->mensaje
            ]);
        }

        $seguimiento .= "<p>Nota de credito creada con el ID: " . $crear_nota_credito->id . "</p>";

        $saldar_factura_nota = DocumentoService::saldarFactura($documento, $crear_nota_credito->id, 0);

        if ($saldar_factura_nota->error) {
            return response()->json([
                'code' => 500,
                'message' => $saldar_factura_nota->mensaje
            ]);
        }

        $seguimiento .= "<p>Factura saldada correctamente con la NC: " . $crear_nota_credito->id . "</p>";

        DB::table('seguimiento')->insert([
            'id_documento'  => $documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Factura saldada correctamente con la NC " . $crear_nota_credito->id,
            'nota' => $crear_nota_credito->id
        ]);
    }

    public function general_busqueda_venta_guardar(Request $request)
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('seguimiento')->insert([
            'id_documento'  => $data->documento,
            'id_usuario'    => $auth->id,
            'seguimiento'   => $data->seguimiento
        ]);

        if (!empty($data->archivos)) {
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
                        'id_documento'  => $data->documento,
                        'id_usuario'    => $auth->id,
                        'tipo'          => $archivo->guia,
                        'id_impresora'  => $archivo->impresora,
                        'nombre'        => $archivo->nombre,
                        'dropbox'       => $response->body->id
                    ]);
                }
            }
        }

        return response()->json([
            'code'  => 200,
            'message'   => "Seguimiento guardado correctamente"
        ]);
    }

    public function general_busqueda_serie_vs_sku(Request $request)
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

    public function general_busqueda_serie($serie)
    {
        //        $apos = `'`;
        //        //Checa si tiene ' , entonces la escapa para que acepte la consulta con '
        //        if (str_contains($serie, $apos)) {
        //            $serie = addslashes($serie);
        //        }
        $serie = urldecode($serie);
        $serie = str_replace(["'", '\\'], '', $serie);
        $empresas = DB::table('documento')
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
            ->join('movimiento_producto', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
            ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
            ->where('producto.serie', '=', trim(mb_strtoupper($serie, 'UTF-8'))) // Asegúrate de que $serie esté correctamente escapado y saneado
            ->groupBy('empresa.id')
            ->select('empresa.id', 'empresa.empresa')
            ->get();

        if (empty($empresas)) {
            return response()->json([
                "code" => 500,
                "message" => "La serie proporcionada no existe registrada en el sistema."
            ]);
        }

        foreach ($empresas as $empresa) {
            // Definir las subconsultas para los almacenes
            $subQueryAlmacenPrincipal = DB::table('empresa_almacen')
                ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
                ->select('almacen')
                ->whereColumn('empresa_almacen.id', 'documento.id_almacen_principal_empresa')
                ->limit(1)
                ->toSql(); // Convertir a SQL crudo

            $subQueryAlmacenAlterno = DB::table('empresa_almacen')
                ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
                ->select('almacen')
                ->whereColumn('empresa_almacen.id', 'documento.id_almacen_secundario_empresa')
                ->limit(1)
                ->toSql(); // Convertir a SQL crudo

            // Consulta principal con Query Builder
            $empresa->movimientos = DB::table('documento')
                ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
                ->join('documento_tipo', 'documento.id_tipo', '=', 'documento_tipo.id')
                ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
                ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
                ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
                ->join('movimiento_producto', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
                ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
                ->select([
                    'documento.id',
                    DB::raw("IF(documento.id_tipo = 2, documento.id, documento.factura_folio) AS factura_folio"),
                    'documento.created_at',
                    'documento.observacion',
                    'documento.status',
                    'documento_tipo.tipo',
                    'documento.id_almacen_principal_empresa AS id_almacen_principal',
                    'documento.id_almacen_secundario_empresa AS id_almacen_secundario',
                    DB::raw("($subQueryAlmacenPrincipal) AS almacen_principal"),
                    DB::raw("($subQueryAlmacenAlterno) AS almacen_alterno"),
                    'usuario.nombre',
                    'modelo.sku',
                    'modelo.descripcion'
                ])
                ->where('producto.serie', '=', $serie)
                ->where('empresa_almacen.id_empresa', '=', $empresa->id)
                ->orderBy('created_at', 'DESC')
                ->get();

            $empresa->serie = DB::table('producto')
                ->join('almacen', 'producto.id_almacen', '=', 'almacen.id')
                ->select('almacen.almacen', 'producto.extra', 'producto.status', 'producto.fecha_caducidad')
                ->where('producto.serie', '=', trim($serie)) // Asegúrate de que $serie esté correctamente escapado y saneado
                ->get();
        }

        return response()->json([
            'code' => 200,
            'empresas' => $empresas
        ]);
    }

    public function general_busqueda_serie_imprimir(Request $request)
    {
        $data = json_decode($request->input("data"));
        $etiquetas = array();

        $impresora = DB::table("impresora")
            ->select("servidor")
            ->where("id", 40)
            ->first();

        if (empty($impresora)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró la impresora proporcionada"
            ]);
        }

        $etiqueta_data = new \stdClass();
        $etiqueta_data->serie = $data->serie;
        $etiqueta_data->codigo = $data->codigo;
        $etiqueta_data->descripcion = $data->descripcion;
        $etiqueta_data->cantidad = 1;
        $etiqueta_data->extra = property_exists($data, "extra") ? $data->extra : "";

        array_push($etiquetas, $etiqueta_data);

        $data = array(
            "etiquetas" => $etiquetas,
            "impresora" => 40
        );

        $token = $request->get("token");

        $impresion = \Httpful\Request::post($impresora->servidor . "/raspberry-print-server/public/label/sku-and-description-and-serie?token=" . $token)
            ->body($data, \Httpful\Mime::FORM)
            ->send();

        $impresion_raw = $impresion->raw_body;
        $impresion = @json_decode($impresion_raw);

        return (array) $impresion_raw;
    }

    # Tipo es compra o venta
    public function rawinfo_precio_cantidad_mes($mes, $producto, $empresa, $anio)
    {
        $data = DB::select("SELECT
                                IFNULL(SUM((movimiento.precio * movimiento.cantidad * documento.tipo_cambio) * 1.16), 0) AS total,
                                IFNULL(SUM(movimiento.cantidad), 0) AS productos
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                            WHERE documento.id_tipo = 2
                            AND documento.status = 1
                            AND documento.created_at BETWEEN '" . date("" . $anio . "-m-01", strtotime(date("Y-" . $mes))) . " 00:00:00' AND '" . date("" . $anio . "-m-t", strtotime(date("Y-" . $mes))) . " 23:59:59'
                            AND movimiento.id_modelo = " . $producto . "
                            AND empresa_almacen.id_empresa = " . $empresa . "")[0];

        return [
            "total" => $data->total,
            "productos" => $data->productos
        ];
    }

    public function general_reporte_producto_incidencia(Request $request)
    {
        $data = json_decode($request->input("data"));

        setlocale(LC_ALL, "es_MX");

        $empresa = DB::table("empresa")->where("id", $data->empresa)->select("empresa")->first();

        $productos = DB::select("SELECT
                                    modelo.id,
                                    modelo.sku,
                                    descripcion
                                FROM documento_garantia
                                INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                INNER JOIN documento ON documento_garantia_re.id_documento = documento.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                                AND empresa_almacen.id_empresa = " . $data->empresa . "
                                GROUP BY modelo.sku");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle("REPORTE DE INCIDENCIAS");

        $sheet->setCellValue('B1', $empresa->empresa);

        $sheet->setCellValue('B2', 'REPORTE DE INCIDENCIAS');
        $sheet->setCellValue('B3', strftime("%A %d de %B del %Y", strtotime($data->fecha_inicial)) . " al " . strftime("%A %d de %B del %Y", strtotime($data->fecha_final)));

        $spreadsheet->getActiveSheet()->getStyle('B1:B3')->getFont()->setBold(1);

        $sheet->setCellValue('A6', 'CODIGO');
        $sheet->setCellValue('B6', 'DESCRIPCION');
        $sheet->setCellValue('C6', 'INCIDENCIAS');
        $sheet->setCellValue('D6', 'VENTAS');
        $sheet->setCellValue('E6', 'TAZA');

        $spreadsheet->getActiveSheet()->getStyle('A6:E6')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        $contador_fila = 7;

        foreach ($productos as $producto) {
            $producto->ventas = DB::select("SELECT
                                                IFNULL(count(*), 0) AS cantidad
                                            FROM documento
                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                            WHERE documento.id_tipo = 2
                                            AND documento.status = 1
                                            AND movimiento.id_modelo = " . $producto->id . "
                                            AND empresa_almacen.id_empresa = " . $data->empresa . "
                                            AND documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'")[0]->cantidad;

            $producto->incidencias = DB::select("SELECT
                                                    IFNULL(count(*), 0) AS cantidad
                                                FROM documento_garantia
                                                INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                                INNER JOIN documento ON documento_garantia_re.id_documento = documento.id
                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                WHERE documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                                                AND empresa_almacen.id_empresa = " . $data->empresa . "
                                                AND movimiento.id_modelo = " . $producto->id . "")[0]->cantidad;

            $producto->taza = $producto->incidencias == 0 || $producto->ventas == 0 ? 0 : round(($producto->incidencias / $producto->ventas) * 100, 2);
            $producto->fecha_inicial = $data->fecha_inicial;
            $producto->fecha_final = $data->fecha_final;
            $producto->empresa = $data->empresa;

            $sheet->setCellValue('A' . $contador_fila, $producto->sku);
            $sheet->setCellValue('B' . $contador_fila, $producto->descripcion);
            $sheet->setCellValue('C' . $contador_fila, $producto->incidencias);
            $sheet->setCellValue('D' . $contador_fila, $producto->ventas);
            $sheet->setCellValue('E' . $contador_fila, $producto->incidencias / $producto->ventas);

            $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $spreadsheet->getActiveSheet()->getStyle("E" . $contador_fila)->getNumberFormat()->setFormatCode('0.00%;[Red]-0.00%');

            $contador_fila++;
        }

        foreach (range('A', 'E') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $nombre_archivo = "REPORTE DE INCIDENCIAS " . $empresa->empresa . " " . $data->fecha_inicial . " a " . $data->fecha_final . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($nombre_archivo);

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents($nombre_archivo));
        $json['nombre'] = $nombre_archivo;
        $json['data'] = $productos;

        unlink($nombre_archivo);

        return response()->json($json);
    }

    public function general_reporte_producto_incidencia_detalle(Request $request)
    {
        $data = json_decode($request->input("data"));

        $incidencias = DB::select("SELECT
                                        documento_garantia.id,
                                        documento_garantia.created_at,
                                        documento_garantia_tipo.tipo,
                                        documento_garantia_causa.causa
                                    FROM documento_garantia
                                    INNER JOIN documento_garantia_causa ON documento_garantia.id_causa = documento_garantia_causa.id
                                    INNER JOIN documento_garantia_tipo ON documento_garantia.id_tipo = documento_garantia_tipo.id
                                    INNER JOIN documento_garantia_re ON documento_garantia.id = documento_garantia_re.id_garantia
                                    INNER JOIN documento ON documento_garantia_re.id_documento = documento.id
                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    WHERE documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                                    AND empresa_almacen.id_empresa = " . $data->empresa . "
                                    AND movimiento.id_modelo = " . $data->id . "");

        foreach ($incidencias as $incidencia) {
            $incidencia->seguimiento = DB::select("SELECT
                                                    documento_garantia_seguimiento.*,
                                                    usuario.nombre
                                                FROM documento_garantia
                                                INNER JOIN documento_garantia_seguimiento ON documento_garantia.id = documento_garantia_seguimiento.id_documento
                                                INNER JOIN usuario ON documento_garantia_seguimiento.id_usuario = usuario.id
                                                WHERE documento_garantia.id = " . $incidencia->id . "");
        }

        return response()->json([
            "code" => 200,
            "data" => $incidencias
        ]);
    }

    public function general_reporte_producto_btob_reporte(Request $request)
    {
        set_time_limit(0);

        $provider = $request->input("provider");

        $products = DB::table("modelo_proveedor_producto AS mpp")
            ->join("modelo_proveedor_producto_existencia AS mppe", "mpp.id", "=", "mppe.id_modelo")
            ->join("modelo_proveedor_almacen AS mpa", "mppe.id_almacen", "=", "mpa.id")
            ->where("mpp.id_modelo_proveedor", $provider)
            ->where("mppe.existencia", ">", 0)
            ->get()
            ->toArray();

        $provider_name = DB::table("modelo_proveedor")->find($provider);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('REPORTE DE PRODUCTOS B2B');

        $line = 7;

        $sheet->setCellValue('B2', mb_strtoupper($provider_name->razon_social, 'UTF-8'));
        $sheet->setCellValue('B3', "REPORTE DE PRODUCTOS Y EXISTENCIAS B2B");

        $spreadsheet->getActiveSheet()->getStyle("B2")->getFont()->setSize(14)->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle("B3")->getFont()->setSize(14)->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle("B4")->getFont()->setSize(14)->setBold(1);

        $sheet->getStyle('B2')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('B3')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('B4')->getAlignment()->setHorizontal('center');

        # Header
        $sheet->setCellValue('A6', 'SKU');
        $sheet->setCellValue('B6', 'DESCRIPCIÓN');
        $sheet->setCellValue('C6', 'PRECIO');
        $sheet->setCellValue('D6', 'MONEDA');
        $sheet->setCellValue('E6', 'ALMACÉN');
        $sheet->setCellValue('F6', 'EXISTENCIA');
        $sheet->setCellValue('G6', 'MARCA');
        $sheet->setCellValue('H6', 'FAMILIA');
        $sheet->setCellValue('I6', 'CATEGORIA');
        $sheet->setCellValue('J6', 'SUCATEGORIA');

        $spreadsheet->getActiveSheet()->getStyle('A6:J6')->getFont()->setBold(1)->getColor()->setARGB('A8CEA0'); # Cabecera en negritas con color negro

        $sheet->freezePane("A7");

        foreach ($products as $product) {
            $sheet->setCellValue('A' . $line, $product->codigo_barra);
            $sheet->setCellValue('B' . $line, $product->descripcion);
            $sheet->setCellValue('C' . $line, $product->precioLista);
            $sheet->setCellValue('D' . $line, "MXN");
            $sheet->setCellValue('E' . $line, $product->locacion);
            $sheet->setCellValue('F' . $line, $product->existencia);
            $sheet->setCellValue('G' . $line, $product->marca);
            $sheet->setCellValue('H' . $line, $product->familia);
            $sheet->setCellValue('I' . $line, $product->categoria);
            $sheet->setCellValue('J' . $line, $product->subcategoria);

            $spreadsheet->getActiveSheet()->getStyle("C" . $line)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
            $sheet->getCellByColumnAndRow(1, $line)->setValueExplicit($product->codigo_barra, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $line++;
        }

        foreach (range('A', 'J') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $file_name = uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($file_name);

        $json['excel'] = base64_encode(file_get_contents($file_name));
        $json['name'] = $file_name;

        unlink($file_name);

        return response()->json($json);
    }

    /*
    public function general_reporte_venta_diario(){
        set_time_limit(0);

        $marketplaces = DB::select("SELECT id, marketplace FROM marketplace GROUP BY marketplace");
        $productos = DB::select("SELECT id, sku, descripcion FROM modelo WHERE id_tipo = 1 LIMIT 50");
        
        $spreadsheet    = new Spreadsheet();
        $sheet          = $spreadsheet->getActiveSheet();
        $contador_fila  = 2;

        $spreadsheet->getActiveSheet()->getStyle('A1:' . range('A', 'Z')[5 + COUNT($marketplaces)] . '1')->getFont()->setBold(1); # Cabecera en negritas
        $spreadsheet->getActiveSheet()->getStyle('A1:' . range('A', 'Z')[5 + COUNT($marketplaces)] . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('A2ECE2');

        # Cabecera
        $sheet->setCellValue('A1', 'CODIGO');
        $sheet->setCellValue('B1', 'DESCRIPCION');
        $sheet->setCellValue('C1', 'ULTIMA COMPRA');
        $sheet->setCellValue('D1', 'INVENTARIO DISPONIBLE');

        foreach ($marketplaces as $index_marketplace => $marketplace) {
            $sheet->setCellValue(range('A', 'Z')[5 + $index_marketplace] . '1', $marketplace->marketplace);
        }

        foreach ($productos as $producto) {
            $disponible = '';

            foreach ($marketplaces as $index_marketplace => $marketplace) {
                $cantidad = DB::select("SELECT
                                            IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                        FROM documento
                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                        WHERE movimiento.id_modelo = " . $producto->id . "
                                        AND documento.id_marketplace_area = " . $marketplace->id . "
                                        AND documento.id_tipo = 2
                                        AND documento.created_at BETWEEN '" . date('Y-m-d 00:00:00', (strtotime ( '-1 day' , strtotime (date('Y-m-d'))))) . "' AND '" . date('Y-m-d 23:59:59', (strtotime ( '-1 day' , strtotime (date('Y-m-d'))))) . "'")[0]->cantidad;

                $sheet->setCellValue(range('A', 'Z')[5 + $index_marketplace] . $contador_fila, $cantidad);
            }

            $ultima_compra = DB::select("SELECT
                                            IFNULL(cantidad, 0) AS cantidad
                                        FROM documento
                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                        WHERE movimiento.id_modelo = " . $producto->id . "
                                        AND documento.id_tipo = 1
                                        AND documento.id_fase IN (89, 94)
                                        ORDER BY documento.created_at DESC");

            $producto_data = @json_decode(file_get_contents('http://rdp.crmomg.mx:49570/erps-ws/public/api/adminpro/producto/Consulta/Productos/SKU/7/' . rawurlencode($producto->sku) . ''));

            if (empty($producto_data)) {
                $disponible = "SIN EXISTENCIA";
            }
            else {
                $producto_data = $producto_data[0];

                if (empty($producto_data->existencias->almacenes)) {
                    $disponible  = "SIN EXISTENCIA";
                }
                else {
                    foreach ($producto_data->existencias->almacenes as $index_producto => $almacen) {
                        if ($almacen->almacenid == 17 || $almacen->almacenid == 0) {
                            unset($producto_data->existencias->almacenes[$index_producto]);
        
                            continue;
                        }
        
                        $pendientes_surtir = DB::select("SELECT
                                                            IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                        FROM documento
                                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                        WHERE modelo.sku = '" . $producto_data->sku . "'
                                                        AND empresa_almacen.id_erp = " . $almacen->almacenid . "
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
                                                                WHERE modelo.sku = '" . $producto_data->sku . "'
                                                                AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                                AND documento.id_tipo = 9
                                                                AND documento.status = 1
                                                                AND documento.id_fase IN (401, 402, 403)")[0]->cantidad;
        
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
                                                        AND modelo.sku = '" . $producto_data->sku . "'
                                                        AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                        AND documento.id_fase = 89");
        
                        $total_pendientes = 0;
        
                        foreach ($pendientes_recibir as $pendiente) {
                            if ($pendiente->serie) {
                                $total_pendientes += $pendiente->cantidad - $pendiente->recepcionadas;
                            }
                            else {
                                $total_pendientes += ($pendiente->completa) ? 0 : $pendiente->cantidad;
                            }
                        }
        
                        $disponible .= $almacen->almacen . ": " . ($almacen->fisico - $pendientes_surtir - $total_pendientes) . "\n";
                    }

                    $disponible = substr($disponible, 0, -1);
                }
            }

            # Excel
            $sheet->setCellValue('A' . $contador_fila, $producto->sku);
            $sheet->setCellValue('B' . $contador_fila, $producto->descripcion);                                            
            $sheet->setCellValue('C' . $contador_fila, (empty($ultima_compra) ? 0 : $ultima_compra[0]->cantidad));
            $sheet->setCellValue('D' . $contador_fila, $disponible);

            $contador_fila++;
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach(range('A','Z') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_diario.xlsx');

        $usuarios = DB::select("SELECT
                                    usuario.id,
                                    usuario.email
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                WHERE nivel.id IN(6, 8)
                                AND usuario.id != 1
                                GROUP BY usuario.id");

        $usuario_email = "";

        foreach ($usuarios as $usuario) {
            if (filter_var($usuario->email, FILTER_VALIDATE_EMAIL)) {
                $usuario_email .= $usuario->email . ";";
            }
        }

        $usuario_email = rtrim($usuario_email, ";");

        $html = view('email.notificacion_reporte_diario')->with(['anio' => date('Y')]);

        $mg     = Mailgun::create('key-ff8657eb0bb864245bfff77c95c21bef');
        $domain = "omg.com.mx";
        $mg->sendMessage($domain, array('from'  => 'Reportes OMG International <crm@omg.com.mx>',
                                'to'      => 'desarrollo1@omg.com.mx',
                                'subject' => 'Reporte de actividades OMG International ' . date('d/m/Y'),
                                'html'    => $html),
                                array(
                                    'attachment' => array(
                                        'reporte_diario.xlsx'
                                    )
                                ));

        unlink('estado_cuenta.xlsx');
    }
    */

    public function general_reporte_venta_mercadolibre_venta(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $credenciales = DB::select("SELECT * FROM marketplace_api WHERE id_marketplace_area = " . $data->marketplace . "");

        if (empty($credenciales)) {
            return response()->json([
                'code'      => 500,
                'message'   => "No se encontró información de la API REST del marketplace."
            ]);
        }

        $credenciales[0]->fecha_inicio = $data->fecha_inicio;
        $credenciales[0]->fecha_final = $data->fecha_final;

        $ventas = MercadolibreService::ventas($credenciales[0], $data->publicacion);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('VENTAS MERCADOLIBRE');
        $contador_fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'PAQUETE');
        $sheet->setCellValue('B1', 'VENTA');
        $sheet->setCellValue('C1', 'TOTAL');
        $sheet->setCellValue('D1', 'CLIENTE');
        $sheet->setCellValue('E1', 'ID PUBLICACION');
        $sheet->setCellValue('F1', 'TITULO');
        $sheet->setCellValue('G1', 'CANTIDAD');
        $sheet->setCellValue('H1', 'CATEGORIA');
        $sheet->setCellValue('I1', 'ATRIBUTO (S)');
        $sheet->setCellValue('J1', 'PAQUETERÍA');
        $sheet->setCellValue('K1', 'GUÍA');
        $sheet->setCellValue('L1', 'FECHA');
        $sheet->setCellValue('M1', 'FULFILLMENT');
        $sheet->setCellValue('N1', 'CONTIENE RECLAMOS');

        if ($data->crm) {
            $sheet->setCellValue('O1', 'VENTA CRM');
            $sheet->setCellValue('P1', 'ESTADO CRM');
            $sheet->setCellValue('Q1', 'DEVOLUCIÓN / GARANTIA');
            $sheet->setCellValue('R1', 'FASE');
        }

        $sheet->setCellValue('S1', 'CANAL DE VENTA');
        $sheet->setCellValue('T1', 'ESTATUS ML');

        $spreadsheet->getSheet(0)->getStyle('A1:T1')->getFont()->setBold(1); # Cabecera en negritas

        foreach ($ventas as $pack) {
            if ($pack->es_paquete) {
                $devoluciones_text = "";

                $sheet->setCellValue('A' . $contador_fila, $pack->id);

                $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($pack->id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT); # Alineación centrica

                $pack->venta_crm = new \stdClass();

                if ($data->crm) {
                    $existe_venta = DB::table("documento")
                        ->join("documento_fase", "documento.id_fase", "=", "documento_fase.id")
                        ->select("documento.id", "documento.no_venta", "documento.status", "documento_fase.fase")
                        ->where("documento.comentario", $pack->id)
                        ->first();

                    if (!empty($existe_venta)) {
                        $devoluciones = DB::table("documento_garantia_re")
                            ->where("id_documento", $existe_venta->id)
                            ->get();

                        $existe_venta->devoluciones_text = "";

                        foreach ($devoluciones as $devolucion) {
                            $existe_venta->devoluciones_text .= $devolucion->id_garantia . ", ";
                        }
                    }

                    $pack->venta_crm = $existe_venta;
                }
            }

            foreach ($pack->ventas as $venta) {
                $sheet->setCellValue('A' . $contador_fila, $pack->id);
                $sheet->setCellValue('B' . $contador_fila, $venta->venta);
                $sheet->setCellValue('C' . $contador_fila, $venta->total);
                $sheet->setCellValue('D' . $contador_fila, preg_replace('/[^A-Za-z0-9\-]/', '', $venta->cliente));
                $sheet->setCellValue('J' . $contador_fila, $venta->paqueteria);
                $sheet->setCellValue('K' . $contador_fila, $venta->guia);
                $sheet->setCellValue('L' . $contador_fila, $venta->fecha);
                $sheet->setCellValue('M' . $contador_fila, $venta->fulfillment ? 'SI' : 'NO');
                $sheet->setCellValue('N' . $contador_fila, $venta->reclamos ? 'SI' : 'NO');
                $sheet->setCellValue('S' . $contador_fila, $venta->canal_venta);
                $sheet->setCellValue('T' . $contador_fila, $venta->cancelada ? 'CANCELADA' : 'ACTIVA');

                $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($pack->id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit($venta->venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

                if ($data->crm) {
                    if ($pack->es_paquete) {
                        $sheet->setCellValue('O' . $contador_fila, empty($pack->venta_crm) ? "NO EXISTE" : $pack->venta_crm->id);
                        $sheet->setCellValue('P' . $contador_fila, empty($pack->venta_crm) ? "NO EXISTE" : ($pack->venta_crm->status ? "ACTIVA" : "CANCELADA"));
                        $sheet->setCellValue('Q' . $contador_fila, empty($pack->venta_crm) ? "NO EXISTE" : $pack->venta_crm->devoluciones_text);
                        $sheet->setCellValue('R' . $contador_fila, empty($pack->venta_crm) ? "NO EXISTE" : $pack->venta_crm->fase);
                    } else {
                        $existe_venta = DB::table("documento")
                            ->join("documento_fase", "documento.id_fase", "=", "documento_fase.id")
                            ->select("documento.id", "documento.status", "documento_fase.fase")
                            ->where("documento.no_venta", trim($venta->venta))
                            ->first();

                        $sheet->setCellValue('O' . $contador_fila, empty($existe_venta) ? "NO EXISTE" : $existe_venta->id);
                        $sheet->setCellValue('P' . $contador_fila, empty($existe_venta) ? "NO EXISTE" : ($existe_venta->status ? "ACTIVA" : "CANCELADA"));
                        $sheet->setCellValue('Q' . $contador_fila, empty($existe_venta) ? "NO EXISTE" : "");
                        $sheet->setCellValue('R' . $contador_fila, empty($existe_venta) ? "NO EXISTE" : $existe_venta->fase);

                        if (!empty($existe_venta)) {
                            $devoluciones_text = "";

                            $devoluciones = DB::table("documento_garantia_re")
                                ->where("id_documento", $existe_venta->id)
                                ->get();

                            foreach ($devoluciones as $devolucion) {
                                $devoluciones_text .= $devolucion->id_garantia . ", ";
                            }

                            $sheet->setCellValue('Q' . $contador_fila, substr($devoluciones_text, 0, -2));
                        }
                    }
                }

                $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $sheet->getCellByColumnAndRow(11, $contador_fila)->setValueExplicit($venta->guia, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                foreach ($venta->productos as $producto) {
                    $sheet->setCellValue('E' . $contador_fila, $producto->id);
                    $sheet->setCellValue('F' . $contador_fila, preg_replace('/[^A-Za-z0-9\-]/', '', $producto->titulo));
                    $sheet->setCellValue('G' . $contador_fila, $producto->cantidad);
                    $sheet->setCellValue('H' . $contador_fila, $producto->categoria);

                    if (!empty($producto->atributos)) {
                        $sheet->setCellValue('I' . $contador_fila, substr($producto->atributos, 0, -1));
                        $spreadsheet->getActiveSheet()->getStyle('I' . $contador_fila)->getAlignment()->setWrapText(true);
                    }

                    $contador_fila++;
                }
            }
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'N') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        if ($data->crm) {
            foreach (range('N', 'R') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }
        }

        $spreadsheet->getActiveSheet()->getColumnDimension("S")->setAutoSize(true);

        $nombre_archivo = "Reporte de ventas de mercadolibre " . date("d F Y", strtotime($credenciales[0]->fecha_inicio)) . " a " . date("d F Y", strtotime($credenciales[0]->fecha_final)) . " " . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($nombre_archivo);

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents($nombre_archivo));
        $json['nombre'] = $nombre_archivo;

        unlink($nombre_archivo);

        return response()->json($json);
    }

    public function general_reporte_venta_mercadolibre_crm(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('VENTAS MERCADOLIBRE ');
        $fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'VENTA');
        $sheet->setCellValue('B1', 'TOTAL');
        $sheet->setCellValue('C1', 'CLIENTE');
        $sheet->setCellValue('D1', 'ID PUBLICACION');
        $sheet->setCellValue('E1', 'TITULO');
        $sheet->setCellValue('F1', 'CANTIDAD');
        $sheet->setCellValue('G1', 'CATEGORIA');
        $sheet->setCellValue('H1', 'ATRIBUTO (S)');
        $sheet->setCellValue('I1', 'PAQUETERÍA');
        $sheet->setCellValue('J1', 'GUÍA');
        $sheet->setCellValue('K1', 'FECHA');
        $sheet->setCellValue('L1', 'FULFILLMENT');
        $sheet->setCellValue('M1', 'PACK');
        $sheet->setCellValue('N1', 'VENTA CRM');
        $sheet->setCellValue('O1', 'ESTADO CRM');
        $sheet->setCellValue('P1', 'DEVOLUCIÓN / GARANTIA');
        $sheet->setCellValue('Q1', 'FASE');
        $sheet->setCellValue('R1', 'ESTADO MERCADOLIBRE');

        $spreadsheet->getSheet(0)->getStyle('A1:R1')->getFont()->setBold(1); # Cabecera en negritas

        $extra_query = "";

        if (!empty($data->publicacion)) {
            $extra_query = "AND documento.mkt_publicacion = '" . $data->publicacion . "'";
        }

        $ventas = DB::select("SELECT
                                documento.id,
                                documento.status,
                                documento.no_venta,
                                documento_fase.fase
                            FROM documento
                            INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                            WHERE documento.id_marketplace_area = " . $data->marketplace . "
                            AND created_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                            " . $extra_query . "");

        foreach ($ventas as $venta) {
            $informacion = MercadolibreService::venta($venta->no_venta, $data->marketplace);

            if ($informacion->error) {
                $sheet->setCellValue('A' . $fila, $venta->no_venta);
                $sheet->setCellValue('B' . $fila, $informacion->mensaje);

                $fila++;

                continue;
            }

            if (empty($informacion->data)) {
                $sheet->setCellValue('A' . $fila, $venta->no_venta);
                $sheet->setCellValue('B' . $fila, "No se encontró información de la venta en Mercadolibre");

                $fila++;

                continue;
            }

            $informacion->data = $informacion->data[0];

            $cancelada = 0;

            if ($informacion->data->status == "cancelled") {
                $cancelada = 1;
            }

            $pack = explode(".", empty($informacion->data->pack_id) ? $informacion->data->id : sprintf('%lf', $informacion->data->pack_id))[0];

            $first_name = property_exists($informacion->data->buyer, "first_name") ? $informacion->data->buyer->first_name : "PUBLICO GENERAL";
            $last_name = property_exists($informacion->data->buyer, "last_name") ? $informacion->data->buyer->last_name : "";

            $sheet->setCellValue('A' . $fila, $venta->no_venta);
            $sheet->setCellValue('B' . $fila, $informacion->data->total_amount);
            $sheet->setCellValue('C' . $fila, $first_name . " " . $last_name);
            $sheet->setCellValue('I' . $fila, is_object($informacion->data->shipping) ? $informacion->data->shipping->tracking_method : "SIN ENVÍO");
            $sheet->setCellValue('J' . $fila, is_object($informacion->data->shipping) ? $informacion->data->shipping->tracking_number : "SIN ENVÍO");
            $sheet->setCellValue('K' . $fila, $informacion->data->date_created);
            $sheet->setCellValue('L' . $fila, (is_object($informacion->data->shipping) ? (property_exists($informacion->data->shipping, 'logistic_type') ? ($informacion->data->shipping->logistic_type == "fulfillment" ? "SI" : "NO") : "NO") : "SIN ENVÍO"));
            $sheet->setCellValue('M' . $fila, $pack);
            $sheet->setCellValue('N' . $fila, $venta->id);
            $sheet->setCellValue('O' . $fila, $venta->status ? 'ACTIVA' : 'CANCELADA');
            $sheet->setCellValue('Q' . $fila, $venta->fase);
            $sheet->setCellValue('R' . $fila, $cancelada ? 'CANCELADA' : 'ACTIVA');

            $devoluciones = DB::select("SELECT id_garantia FROM documento_garantia_re WHERE id_documento = " . $venta->id . "");

            $devoluciones_text = "";

            foreach ($devoluciones as $devolucion) {
                $devoluciones_text .= $devolucion->id_garantia . ", ";
            }

            if (!empty($devoluciones_text)) {
                $sheet->setCellValue('P' . $fila, substr($devoluciones_text, 0, -2));
            }

            $spreadsheet->getActiveSheet()->getStyle("B" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
            $sheet->getCellByColumnAndRow(10, $fila)->setValueExplicit(is_object($informacion->data->shipping) ? $informacion->data->shipping->tracking_number : "SIN ENVÍO", \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(13, $fila)->setValueExplicit($pack, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            foreach ($informacion->data->order_items as $item) {
                $atributos = "";
                $categoria = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "categories/" . $item->item->category_id));

                foreach ($item->item->variation_attributes as $atributo) {
                    $atributos .= $atributo->name . ": " . $atributo->value_name . "\n";
                }

                $sheet->setCellValue('D' . $fila, $item->item->id);
                $sheet->setCellValue('E' . $fila, $item->item->title);
                $sheet->setCellValue('F' . $fila, $item->quantity);
                $sheet->setCellValue('G' . $fila, $categoria->name);

                if (!empty($atributos)) {
                    $sheet->setCellValue('H' . $fila, substr($atributos, 0, -1));
                    $spreadsheet->getActiveSheet()->getStyle('H' . $fila)->getAlignment()->setWrapText(true);
                }

                $fila++;
            }
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'R') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('VENTAS MERCADOLIBRE.xlsx');

        $json['code']   = 200;
        $json['excel']  = base64_encode(file_get_contents('VENTAS MERCADOLIBRE.xlsx'));

        unlink('VENTAS MERCADOLIBRE.xlsx');

        return response()->json($json);
    }

    public function general_reporte_venta_mercadolibre_publicacion(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $response = MercadolibreService::publicaciones($data->marketplace);

        if ($response->error) {
            return response()->json([
                'code' => 500,
                'message' => $response->mensaje
            ]);
        }

        $publicaciones = $response->publicaciones;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('PUBLICACIONES MERCADOLIBRE');
        $fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'TITULO');
        $sheet->setCellValue('C1', 'SKU');
        $sheet->setCellValue('D1', 'DESCRIPCION');
        $sheet->setCellValue('E1', 'COSTO');
        $sheet->setCellValue('F1', 'PRECIO');
        $sheet->setCellValue('G1', 'INVENTARIO');
        $sheet->setCellValue('H1', 'ESTATUS');
        $sheet->setCellValue('I1', 'LOGISTICA');
        $sheet->setCellValue('J1', 'TIENDA OFICIAL');

        $spreadsheet->getActiveSheet()->getStyle('A1:J1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:J1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica

        foreach ($publicaciones as $publicacion) {
            $sheet->setCellValue('A' . $fila, $publicacion->id);
            $sheet->setCellValue('B' . $fila, $publicacion->titulo);
            $sheet->setCellValue('F' . $fila, $publicacion->precio);
            $sheet->setCellValue('G' . $fila, $publicacion->inventario);
            $sheet->setCellValue('H' . $fila, $publicacion->status);
            $sheet->setCellValue('I' . $fila, $publicacion->logistica);
            $sheet->setCellValue('J' . $fila, $publicacion->tienda);

            if (empty($publicacion->productos)) {
                $sheet->setCellValue('C' . $fila, 'SIN RELACION');
                $sheet->setCellValue('D' . $fila, 'SIN RELACION');
                $sheet->setCellValue('E' . $fila, 0);

                $fila++;
            } else {
                foreach ($publicacion->productos as $producto) {
                    $sheet->setCellValue('C' . $fila, $producto->sku);
                    $sheet->setCellValue('D' . $fila, $producto->descripcion);
                    $sheet->setCellValue('E' . $fila, 0);

                    $ultimo_costo = @json_decode(file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $data->empresa . '/' . rawurlencode(trim($producto->sku))));

                    if (empty($ultimo_costo)) {
                        continue;
                    }

                    if (is_array($ultimo_costo)) {
                        if (count($ultimo_costo) > 1) {
                            continue;
                        }
                    }

                    $sheet->setCellValue('E' . $fila, round((float) $ultimo_costo[0]->ultimo_costo, 2));

                    $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $spreadsheet->getActiveSheet()->getStyle("E" . $fila . ":F" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

                    $fila++;
                }
            }
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'J') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('PUBLICACIONES MERCADOLIBRE.xlsx');

        $json['code']   = 200;
        $json['excel']  = base64_encode(file_get_contents('PUBLICACIONES MERCADOLIBRE.xlsx'));

        unlink('PUBLICACIONES MERCADOLIBRE.xlsx');

        return response()->json($json);
    }

    public function general_reporte_venta_mercadolibre_catalogo(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $response = MercadolibreService::publicaciones($data->marketplace, "&tags=catalog_forewarning");

        if ($response->error) {
            return response()->json([
                'code' => 500,
                'message' => $response->mensaje
            ]);
        }

        $publicaciones = $response->publicaciones;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('PUBLICACIONES MERCADOLIBRE');
        $fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'TITULO');
        $sheet->setCellValue('C1', 'SKU');
        $sheet->setCellValue('D1', 'DESCRIPCION');
        $sheet->setCellValue('E1', 'COSTO');
        $sheet->setCellValue('F1', 'PRECIO');
        $sheet->setCellValue('G1', 'INVENTARIO');
        $sheet->setCellValue('H1', 'ESTATUS');
        $sheet->setCellValue('I1', 'LOGISTICA');
        $sheet->setCellValue('J1', 'TIENDA OFICIAL');

        $spreadsheet->getActiveSheet()->getStyle('A1:J1')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:J1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica

        foreach ($publicaciones as $publicacion) {
            $sheet->setCellValue('A' . $fila, $publicacion->id);
            $sheet->setCellValue('B' . $fila, $publicacion->titulo);
            $sheet->setCellValue('F' . $fila, $publicacion->precio);
            $sheet->setCellValue('G' . $fila, $publicacion->inventario);
            $sheet->setCellValue('H' . $fila, $publicacion->status);
            $sheet->setCellValue('I' . $fila, $publicacion->logistica);
            $sheet->setCellValue('J' . $fila, $publicacion->tienda);

            if (empty($publicacion->productos)) {
                $sheet->setCellValue('C' . $fila, 'SIN RELACION');
                $sheet->setCellValue('D' . $fila, 'SIN RELACION');
                $sheet->setCellValue('E' . $fila, 0);

                $fila++;
            } else {
                foreach ($publicacion->productos as $producto) {
                    $sheet->setCellValue('C' . $fila, $producto->sku);
                    $sheet->setCellValue('D' . $fila, $producto->descripcion);
                    $sheet->setCellValue('E' . $fila, 0);

                    $ultimo_costo = @json_decode(file_get_contents(config('webservice.url') . 'producto/Consulta/Productos/SKU/' . $data->empresa . '/' . rawurlencode(trim($producto->sku))));

                    if (empty($ultimo_costo)) {
                        continue;
                    }

                    if (is_array($ultimo_costo)) {
                        if (count($ultimo_costo) > 1) {
                            continue;
                        }
                    }

                    $sheet->setCellValue('E' . $fila, round((float) $ultimo_costo[0]->ultimo_costo, 2));

                    $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $spreadsheet->getActiveSheet()->getStyle("E" . $fila . ":F" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

                    $fila++;
                }
            }
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'J') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('PUBLICACIONES MERCADOLIBRE.xlsx');

        $json['code']   = 200;
        $json['excel']  = base64_encode(file_get_contents('PUBLICACIONES MERCADOLIBRE.xlsx'));

        unlink('PUBLICACIONES MERCADOLIBRE.xlsx');

        return response()->json($json);
    }

    public function general_reporte_venta_amazon(Request $request)
    {
        $ventas = json_decode($request->input('ventas'));
        $auth = json_decode($request->auth);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('REPORTE DE VENTAS AMAZON');
        $contador_fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'VENTA AMAZON');
        $sheet->setCellValue('B1', 'VENTA CRM');
        $sheet->setCellValue('C1', 'ESTADO AMAZON');
        $sheet->setCellValue('D1', 'ESTADO CRM');
        $sheet->setCellValue('E1', 'OBSERVACIONES');

        foreach ($ventas as $venta) {
            $existe_venta = DB::select("SELECT id, status FROM documento WHERE no_venta = '" . trim($venta->venta) . "'");

            $sheet->setCellValue('A' . $contador_fila, $venta->venta);
            $sheet->setCellValue('B' . $contador_fila, empty($existe_venta) ? 'NO EXISTE' : $existe_venta[0]->id);
            $sheet->setCellValue('C' . $contador_fila, $venta->status);
            $sheet->setCellValue('D' . $contador_fila, empty($existe_venta) ? 'NO EXISTE' : ($existe_venta[0]->status ? 'ACTIVA' : 'CANCELADA'));

            if (empty($existe_venta)) {
                $sheet->setCellValue('E' . $contador_fila, 'TODO CORRECTO');

                if ($venta->status != 'Cancelled') {
                    $sheet->setCellValue('E' . $contador_fila, 'CREAR VENTA EN CRM');
                }
            } else {
                $sheet->setCellValue('E' . $contador_fila, 'TODO CORRECTO');

                if ($venta->status == 'Cancelled') {
                    if ($existe_venta[0]->status) {
                        $sheet->setCellValue('E' . $contador_fila, 'CANCELAR VENTA EN CRM');
                    }
                }
            }

            $contador_fila++;
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_ventas_amazon.xlsx');

        try {
            $correo = DB::select("SELECT nombre, email FROM usuario WHERE id = " . $auth->id . "")[0];

            $view       = view('email.notificacion_reporte_ventas_amazon')->with(['nombre' => $correo->nombre, 'anio' => date('Y')]);

            $mg     = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
            $domain = "omg.com.mx";
            $mg->sendMessage(
                $domain,
                array(
                    'from'  => 'CRM OMG International <crm@omg.com.mx>',
                    'to'            => $correo->email,
                    'subject'       => 'REPORTE DE VENTAS AMAZON.',
                    'html'          => $view
                ),
                array(
                    'attachment' => array(
                        'reporte_ventas_amazon.xlsx'
                    )
                )
            );

            unlink('reporte_ventas_amazon.xlsx');

            return response()->json([
                'code'  => 200,
                'message'   => "Reporte generado correctamente, en unos momentos lo recibirás en tu correo."
            ]);
        } catch (Exception $e) {
            unlink('reporte_ventas_amazon.xlsx');

            return response()->json([
                'code'  => 500,
                'message'   => "Ocurrió un error al enviar el correo con el archivo, mensaje de error: " . $e->getMessage()
            ]);
        }
    }

    public function general_reporte_venta_huawei(Request $request)
    {
        $data = json_decode($request->input('data'));

        if ($data->fecha_inicio > $data->fecha_final) {
            return response()->json([
                'code' => 500,
                'message' => "El rango de fechas es incorrecto, la fecha inicial no puede ser mayor a la fecha de fin"
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('REPORTE DE VENTAS HUAWEI');
        $fila  = 2;

        if ($data->huawei) {
            # Cabecera
            $sheet->setCellValue('A1', 'Country/District');
            $sheet->setCellValue('B1', 'Data Level');
            $sheet->setCellValue('C1', 'Send Flag');
            $sheet->setCellValue('D1', 'Trans Type');
            $sheet->setCellValue('E1', 'Trans Quantity');
            $sheet->setCellValue('F1', 'Trans Time');
            $sheet->setCellValue('G1', 'Original Code');
            $sheet->setCellValue('H1', 'Original Name');
            $sheet->setCellValue('I1', 'SKU');

            $ventas = DB::select("SELECT
                                    area.area,
                                    almacen.almacen,
                                    documento.id,
                                    documento.referencia,
                                    documento.fulfillment,
                                    SUBSTRING_INDEX(documento.created_at, ' ', 1) AS fecha,
                                    marketplace.marketplace,
                                    SUM(movimiento.cantidad) AS cantidad,
                                    modelo.np
                                FROM documento
                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                WHERE documento.id_almacen_principal_empresa = 34
                                AND documento.created_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                                GROUP BY modelo.np, marketplace.marketplace, fecha");

            foreach ($ventas as $venta) {
                $sheet->setCellValue('A' . $fila, "MX");
                $sheet->setCellValue('B' . $fila, $venta->fulfillment ? 'Store' : 'Warehouse');
                $sheet->setCellValue('C' . $fila, 'Forward');
                $sheet->setCellValue('D' . $fila, 'SO');
                $sheet->setCellValue('E' . $fila, $venta->cantidad);
                $sheet->setCellValue('F' . $fila, $venta->fecha);
                $sheet->setCellValue('G' . $fila, $venta->marketplace);
                $sheet->setCellValue('H' . $fila, $venta->marketplace);
                $sheet->setCellValue('I' . $fila, $venta->np);

                $sheet->getCellByColumnAndRow(9, $fila)->setValueExplicit($venta->np, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                $fila++;
            }

            foreach (range('A', 'I') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }
        } else {
            # Cabecera
            $sheet->setCellValue('A1', 'MARKETPLACE');
            $sheet->setCellValue('B1', 'VENTA MARKETPLACE');
            $sheet->setCellValue('C1', 'VENTA CRM');
            $sheet->setCellValue('D1', 'VENTA HUAWEI');
            $sheet->setCellValue('E1', 'TOTAL');
            $sheet->setCellValue('F1', 'FECHA MARKETPLACE');
            $sheet->setCellValue('G1', 'FECHA CRM');
            $sheet->setCellValue('H1', 'FASE');
            $sheet->setCellValue('I1', 'ESTADO');

            $ventas = DB::select("SELECT
                                    area.area,
                                    marketplace.marketplace,
                                    documento.no_venta,
                                    documento.id,
                                    documento.referencia,
                                    documento.mkt_total,
                                    documento.mkt_created_at,
                                    documento.created_at,
                                    documento_fase.fase
                                FROM documento
                                INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                INNER JOIN area ON marketplace_area.id_area = area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                WHERE documento.id_almacen_principal_empresa = 34
                                AND documento.id_fase < 5
                                AND documento.created_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'");

            foreach ($ventas as $venta) {
                $sheet->setCellValue('A' . $fila, $venta->area . " / " . $venta->marketplace);
                $sheet->setCellValue('B' . $fila, $venta->no_venta);
                $sheet->setCellValue('C' . $fila, $venta->id);
                $sheet->setCellValue('D' . $fila, $venta->referencia);
                $sheet->setCellValue('E' . $fila, $venta->mkt_total);
                $sheet->setCellValue('F' . $fila, $venta->mkt_created_at);
                $sheet->setCellValue('G' . $fila, $venta->created_at);
                $sheet->setCellValue('H' . $fila, $venta->fase);

                $fecha_actual = time();
                $fecha_final = strtotime($venta->created_at);
                $diferencia = $fecha_actual - $fecha_final;

                $horas_transcurridas = floor($diferencia / (60 * 60));

                $sheet->setCellValue('I' . $fila, $horas_transcurridas);

                $color_fila = $horas_transcurridas > 12 ? 'CB2906' : '60CB06';

                $spreadsheet->getActiveSheet()->getStyle('I' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($color_fila);

                $spreadsheet->getActiveSheet()->getStyle("E" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $sheet->getCellByColumnAndRow(2, $fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(4, $fila)->setValueExplicit($venta->referencia, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                $fila++;
            }

            foreach (range('A', 'I') as $columna) {
                $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('VENTAS HUAWEI.xlsx');

        $json['code']   = 200;
        $json['excel']  = base64_encode(file_get_contents('VENTAS HUAWEI.xlsx'));

        unlink('VENTAS HUAWEI.xlsx');

        return response()->json($json);
    }

    public function general_reporte_venta_devolucion(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('VENTAS DEVOLUCIÓN O GARANTÍA');
        $fila = 2;

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'VENTA');
        $sheet->setCellValue('C1', 'MARKETPLACE');
        $sheet->setCellValue('D1', 'CLIENTE');
        $sheet->setCellValue('E1', 'ALMACEN');
        $sheet->setCellValue('F1', 'CODIGO');
        $sheet->setCellValue('G1', 'DESCRIPCIÓN');
        $sheet->setCellValue('H1', 'CANTIDAD');
        $sheet->setCellValue('I1', 'PRECIO');
        $sheet->setCellValue('J1', 'TIPO');
        $sheet->setCellValue('K1', 'DOCUMENTO');
        $sheet->setCellValue('L1', 'FECHA');

        $spreadsheet->getSheet(0)->getStyle('A1:L1')->getFont()->setBold(1); # Cabecera en negritas

        $ventas = DB::select("SELECT
                                documento.id,
                                documento.no_venta,
                                area.area,
                                marketplace.marketplace,
                                documento_entidad.razon_social,
                                almacen.almacen,
                                modelo.sku,
                                modelo.descripcion,
                                movimiento.cantidad,
                                movimiento.precio,
                                documento_garantia_tipo.tipo,
                                documento_garantia.id AS documento_garantia_id,
                                documento_garantia.created_at
                            FROM documento
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN documento_garantia_re ON documento.id = documento_garantia_re.id_documento
                            INNER JOIN documento_garantia ON documento_garantia_re.id_garantia = documento_garantia.id
                            INNER JOIN documento_garantia_tipo ON documento_garantia.id_tipo = documento_garantia_tipo.id
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                            WHERE documento_garantia.created_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                            AND empresa_almacen.id_empresa = " . $data->empresa . "");

        foreach ($ventas as $venta) {
            $sheet->setCellValue('A' . $fila, $venta->id);
            $sheet->setCellValue('B' . $fila, $venta->no_venta);
            $sheet->setCellValue('C' . $fila, $venta->area . " / " . $venta->marketplace);
            $sheet->setCellValue('D' . $fila, $venta->razon_social);
            $sheet->setCellValue('E' . $fila, $venta->almacen);
            $sheet->setCellValue('F' . $fila, $venta->sku);
            $sheet->setCellValue('G' . $fila, $venta->descripcion);
            $sheet->setCellValue('H' . $fila, $venta->cantidad);
            $sheet->setCellValue('I' . $fila, round($venta->precio * 1.16, 2));
            $sheet->setCellValue('J' . $fila, $venta->tipo);
            $sheet->setCellValue('K' . $fila, $venta->documento_garantia_id);
            $sheet->setCellValue('L' . $fila, $venta->created_at);

            $spreadsheet->getActiveSheet()->getStyle("I" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
            $sheet->getCellByColumnAndRow(6, $fila)->setValueExplicit($venta->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(2, $fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $fila++;
        }

        foreach (range('A', 'P') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('VENTAS DEVOLUCIONES.xlsx');

        $json['code']   = 200;
        $json['excel']  = base64_encode(file_get_contents('VENTAS DEVOLUCIONES.xlsx'));

        unlink('VENTAS DEVOLUCIONES.xlsx');

        return response()->json($json);
    }

    public function general_reporte_venta_api_credenciales(Request $request)
    {
        $marketplaces = json_decode($request->input('marketplaces'));

        $credenciales = DB::select("SELECT
                                        area.area,
                                        marketplace.marketplace,
                                        marketplace_api.*
                                    FROM marketplace_api
                                    INNER JOIN marketplace_area ON marketplace_api.id_marketplace_area = marketplace_area.id
                                    INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                    INNER JOIN area ON marketplace_area.id_area = area.id
                                    WHERE marketplace_area.id IN (" . implode(",", $marketplaces) . ")
                                    AND marketplace.marketplace = 'MERCADOLIBRE'");

        return response()->json([
            'code'  => 200,
            'credenciales'  => $credenciales
        ]);
    }

    public function general_reporte_logistica_guia_data($fecha_inicial, $fecha_final)
    {
        $guias = DB::select("SELECT
                                paqueteria_guia.*,
                                usuario.nombre,
                                paqueteria.paqueteria
                            FROM paqueteria_guia
                            INNER JOIN usuario ON paqueteria_guia.id_usuario = usuario.id
                            INNER JOIN paqueteria ON paqueteria_guia.id_paqueteria = paqueteria.id
                            WHERE paqueteria_guia.created_at BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('REPORTE DE GUIAS GENERADAS');
        $contador_fila = 2;
        $total = 0;

        # Cabecera
        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'PAQUETERÍA');
        $sheet->setCellValue('C1', 'GUÍA');
        $sheet->setCellValue('D1', 'COSTO');
        $sheet->setCellValue('E1', 'CREADOR');

        /* Dirección remitente */
        $sheet->setCellValue('F1', 'EMPRESA R');
        $sheet->setCellValue('G1', 'CONTACTO R');
        $sheet->setCellValue('H1', 'TELÉFONO D');
        $sheet->setCellValue('I1', 'CELULAR D');
        $sheet->setCellValue('J1', 'DIRECCIÓN 1 R');
        $sheet->setCellValue('K1', 'DIRECCIÓN 2 R');
        $sheet->setCellValue('L1', 'DIRECCIÓN 3 R');
        $sheet->setCellValue('M1', 'REFERENCIA');
        $sheet->setCellValue('N1', 'COLONIA');
        $sheet->setCellValue('O1', 'CIUDAD');
        $sheet->setCellValue('P1', 'ESTADO');
        $sheet->setCellValue('Q1', 'CÓDIGO POSTAL');

        /* Dirección destinatario */
        $sheet->setCellValue('R1', 'EMPRESA D');
        $sheet->setCellValue('S1', 'CONTACTO D');
        $sheet->setCellValue('T1', 'TELÉFONO D');
        $sheet->setCellValue('U1', 'TELÉFONO D');
        $sheet->setCellValue('V1', 'DIRECCION 1 D');
        $sheet->setCellValue('W1', 'DIRECCION 2 D');
        $sheet->setCellValue('X1', 'DIRECCION 3 D');
        $sheet->setCellValue('Y1', 'REFERENCIA');
        $sheet->setCellValue('Z1', 'COLONIA');
        $sheet->setCellValue('AA1', 'CIUDAD');
        $sheet->setCellValue('AB1', 'ESTADO');
        $sheet->setCellValue('AC1', 'CÓDIGO POSTAL');

        $sheet->setCellValue('AD1', 'FECHA');

        $spreadsheet->getActiveSheet()->getStyle('A1:AD1')->getFont()->setBold(1); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle("A1:AD1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('92D050');

        foreach ($guias as $guia) {
            $sheet->setCellValue('A' . $contador_fila, $guia->id_documento);
            $sheet->setCellValue('B' . $contador_fila, $guia->paqueteria);
            $sheet->setCellValue('C' . $contador_fila, $guia->guia);
            $sheet->setCellValue('D' . $contador_fila, $guia->costo);
            $sheet->setCellValue('E' . $contador_fila, $guia->nombre);

            /* Dirección remitente */
            $sheet->setCellValue('F' . $contador_fila, $guia->ori_empresa);
            $sheet->setCellValue('G' . $contador_fila, $guia->ori_contacto);
            $sheet->setCellValue('H' . $contador_fila, $guia->ori_celular);
            $sheet->setCellValue('I' . $contador_fila, $guia->ori_telefono);
            $sheet->setCellValue('J' . $contador_fila, $guia->ori_direccion_1);
            $sheet->setCellValue('K' . $contador_fila, $guia->ori_direccion_2);
            $sheet->setCellValue('L' . $contador_fila, $guia->ori_direccion_3);
            $sheet->setCellValue('M' . $contador_fila, $guia->ori_referencia);
            $sheet->setCellValue('N' . $contador_fila, $guia->ori_colonia);
            $sheet->setCellValue('O' . $contador_fila, $guia->ori_ciudad);
            $sheet->setCellValue('P' . $contador_fila, $guia->ori_estado);
            $sheet->setCellValue('Q' . $contador_fila, $guia->ori_cp);

            /* Dirección destinatario */
            $sheet->setCellValue('R' . $contador_fila, $guia->des_empresa);
            $sheet->setCellValue('S' . $contador_fila, $guia->des_contacto);
            $sheet->setCellValue('T' . $contador_fila, $guia->des_celular);
            $sheet->setCellValue('U' . $contador_fila, $guia->des_telefono);
            $sheet->setCellValue('V' . $contador_fila, $guia->des_direccion_1);
            $sheet->setCellValue('W' . $contador_fila, $guia->des_direccion_2);
            $sheet->setCellValue('X' . $contador_fila, $guia->des_direccion_3);
            $sheet->setCellValue('Y' . $contador_fila, $guia->des_referencia);
            $sheet->setCellValue('Z' . $contador_fila, $guia->des_colonia);
            $sheet->setCellValue('AA' . $contador_fila, $guia->des_ciudad);
            $sheet->setCellValue('AB' . $contador_fila, $guia->des_estado);
            $sheet->setCellValue('AC' . $contador_fila, $guia->des_cp);

            $sheet->setCellValue('AD' . $contador_fila, $guia->created_at);

            $sheet->getCellByColumnAndRow(3, $contador_fila)->setValueExplicit($guia->guia, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $spreadsheet->getActiveSheet()->getStyle("D" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

            $contador_fila++;
        }

        foreach (range('A', 'Z') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        foreach (range('A', 'D') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension("A" . $columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $file_name = "REPORTE DE GUIAS " . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($file_name);

        $json = [
            'code'  => 200,
            'guias' => $guias,
            'excel' => array(
                "file_name" => $file_name,
                "data" => base64_encode(file_get_contents($file_name))
            )
        ];

        unlink($file_name);

        return response()->json($json);
    }

    public function general_reporte_logistica_guia_decode(Request $request)
    {
        $binario = $request->input('binario');

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, "http://api.labelary.com/v1/printers/8dpmm/labels/4x8/");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, base64_decode($binario));


        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Accept: application/pdf"));

        $data = curl_exec($curl);

        $pdf = tempnam('', 'zpl');
        rename($pdf, $pdf .= '.pdf');
        $file = fopen($pdf, 'r+');
        fwrite($file, $data);

        $binario = base64_encode(file_get_contents($pdf));

        return response()->json([
            'code'  => 200,
            'binario'   => $binario
        ]);
    }

    public function general_reporte_logistica_manifiesto_generar($paqueteria, $fecha)
    {
        //$pdf = app('FPDF');
        $pdf = new FPDF('P', 'in', array(8, 4));

        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(69, 90, 100);

        $pdf->Image('img/omg.png', 1, .1, 2, 0.7, 'png');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Write(1.5, 'Manifiesto del Dia:  ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Write(1.5, $fecha);
        $pdf->Ln(0.1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Write(1.6, 'Proveedor de logistica: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Write(1.6, $paqueteria);
        $pdf->Ln(1.1);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(3.25, 0.3, 'Numero de guia', 1);
        $pdf->Ln();

        $fecha_manifiesto = explode(' ', $fecha)[0];
        $fecha_manifiesto = explode('-', $fecha_manifiesto);
        $fecha_manifiesto = $fecha_manifiesto[2] . $fecha_manifiesto[1] . $fecha_manifiesto[0];

        $guias = DB::select("SELECT
                                id,
                                guia, 
                                CASE LENGTH(guia) 
                                    WHEN 10 THEN 'DHL' 
                                    WHEN 11 THEN 'MEL'
                                    WHEN 18 THEN 'UPS' 
                                    WHEN 20 THEN 'PAQUETEXPRESS' 
                                    WHEN 34 THEN 'Fedex' 
                                    ELSE 'Estafeta'
                                    END AS paqueteria
                            FROM manifiesto
                            WHERE manifiesto = '" . $fecha_manifiesto . "' AND salida = 1 AND impreso = 1");

        $contador   = 0;
        $suma       = 0;
        $arrayE     = array();

        $pdf->SetFont('Arial', '', 12);

        foreach ($guias as $guia) {
            if ($guia->paqueteria == $paqueteria) {
                $pdf->Cell(3.25, 0.3, $guia->guia, 1);

                $pdf->Ln();
                $contador++;
            }
        }

        $pdf->SetFont('Arial', '', 12);
        $pdf->Write(0.5, 'Total de guias: ');
        $pdf->Write(0.5, $contador);

        $pdf_name   = uniqid() . ".pdf";
        $pdf_data   = $pdf->Output($pdf_name, 'S');

        $file_name  = "MANIFIESTO_" . mb_strtoupper($paqueteria, 'UTF-8') . "_" . date('d-m-Y') . "_" . uniqid() . ".pdf";

        return response()->json([
            'code'  => 200,
            'file'  => base64_encode($pdf_data),
            'name'  => $file_name
        ]);
    }

    public function general_reporte_logistica_marketplace($fecha_inicial, $fecha_final)
    {
        $productos_enviados = array();

        $empresas = DB::select("SELECT
                                empresa.id,
                                empresa.empresa
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            WHERE documento.shipping_date BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'
                            AND documento.status = 1
                            AND documento.id_fase IN (5, 6)
                            GROUP BY empresa.empresa, empresa.id");

        foreach ($empresas as $empresa) {
            $empresa->areas = DB::select("SELECT
                                area.id,
                                area.area,
                                IFNULL(COUNT(*), 0) AS total
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            WHERE documento.shipping_date BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'
                            AND documento.status = 1
                            AND documento.id_fase IN (5, 6)
                            AND empresa_almacen.id_empresa = " . $empresa->id . "
                            GROUP BY area.area");

            foreach ($empresa->areas as $area) {
                $area->marketplaces = DB::select("SELECT
                                                    marketplace.marketplace,
                                                    IFNULL(COUNT(*), 0) AS total
                                                FROM documento
                                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                                WHERE documento.shipping_date BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'
                                                AND documento.status = 1
                                                AND documento.id_fase IN (5, 6)
                                                AND empresa_almacen.id_empresa = " . $empresa->id . "
                                                AND marketplace_area.id_area = " . $area->id . "
                                                GROUP BY marketplace.marketplace");
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('VENTAS ENVIADAS (MARKETPLACES)');
        $contador_fila = 3;
        $total = 0;

        # Cabecera
        $sheet->setCellValue('A1', 'EMPRESA');
        $sheet->setCellValue('B1', 'AREA');
        $sheet->setCellValue('C1', 'MARKETPLACE');
        $sheet->setCellValue('D1', 'TOTAL');

        $spreadsheet->getActiveSheet()->getStyle('A1:D1')->getFont()->setBold(1); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle("A1:D1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('92D050');

        foreach ($empresas as $empresa) {
            $sheet->setCellValue('A' . $contador_fila, $empresa->empresa);
            $sheet->setCellValue('B' . $contador_fila, "");
            $sheet->setCellValue('C' . $contador_fila, "");
            $sheet->setCellValue('D' . $contador_fila, "");

            $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":D" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('C4D79B');

            $contador_fila++;

            foreach ($empresa->areas as $area) {
                $sheet->setCellValue('A' . $contador_fila, "");
                $sheet->setCellValue('B' . $contador_fila, $area->area);
                $sheet->setCellValue('C' . $contador_fila, "");
                $sheet->setCellValue('D' . $contador_fila, "");

                $spreadsheet->getActiveSheet()->getStyle("B" . $contador_fila . ":D" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('D8E4BC');

                $contador_fila++;

                foreach ($area->marketplaces as $marketplace) {
                    $sheet->setCellValue('A' . $contador_fila, "");
                    $sheet->setCellValue('B' . $contador_fila, "");
                    $sheet->setCellValue('C' . $contador_fila, $marketplace->marketplace);
                    $sheet->setCellValue('D' . $contador_fila, $marketplace->total);

                    $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila . ":D" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('EBF1DE');

                    $contador_fila++;
                }

                $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila . ":D" . $contador_fila)->getFont()->setBold(1); # Cabecera en negritas con color negro

                $sheet->setCellValue('A' . $contador_fila, "");
                $sheet->setCellValue('B' . $contador_fila, "");
                $sheet->setCellValue('C' . $contador_fila, "");
                $sheet->setCellValue('D' . $contador_fila, $area->total);

                $contador_fila += 2;
                $total += $area->total;
            }
        }

        $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila . ":D" . $contador_fila)->getFont()->setBold(1); # Cabecera en negritas con color negro

        $sheet->setCellValue('A' . $contador_fila, "");
        $sheet->setCellValue('B' . $contador_fila, "");
        $sheet->setCellValue('C' . $contador_fila, "GRAND TOTAL");
        $sheet->setCellValue('D' . $contador_fila, $total);

        $pretransferencias = DB::select("SELECT
                                            documento.id
                                        FROM documento
                                        WHERE documento.id_tipo = 9
                                        AND status = 1
                                        AND documento.id_fase = 100
                                        AND documento.shipping_date BETWEEN '" . $fecha_inicial . " 00:00:00' AND '" . $fecha_final . " 23:59:59'");

        if (!empty($pretransferencias)) {
            foreach ($pretransferencias as $pretransferencia) {
                $productos = DB::select("SELECT
                                            modelo.sku,
                                            modelo.descripcion,
                                            movimiento.cantidad
                                        FROM movimiento
                                        INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                        WHERE movimiento.id_documento = " . $pretransferencia->id . "");


                foreach ($productos as $producto) {
                    foreach ($productos_enviados as $enviados) {
                        if ($enviados->sku == $producto->sku) {
                            $enviados->cantidad += $enviados->cantidad;

                            break 2;
                        }
                    }

                    array_push($productos_enviados, $producto);
                }
            }
        }

        $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(1);
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('PRODUCTOS (PRETRANSFERENCIAS)');
        $contador_fila_pretransferencia = 2;

        $sheet->setCellValue('A1', 'CANTIDAD');
        $sheet->setCellValue('B1', 'CÓDIGO');
        $sheet->setCellValue('C1', 'DESCRIPCION');

        $spreadsheet->getActiveSheet()->getStyle('A1:C1')->getFont()->setBold(1); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle("A1:C1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('92D050');

        foreach ($productos_enviados as $producto) {
            $sheet->setCellValue('A' . $contador_fila_pretransferencia, $producto->cantidad);
            $sheet->setCellValue('B' . $contador_fila_pretransferencia, $producto->sku);
            $sheet->setCellValue('C' . $contador_fila_pretransferencia, $producto->descripcion);

            $sheet->getCellByColumnAndRow(2, $contador_fila_pretransferencia)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $contador_fila_pretransferencia++;
        }

        foreach (range('A', 'C') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        foreach (range('A', 'C') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        /* Hoja de productos enviados en transferencias */
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_logistica.xlsx');

        $json['code'] = 200;
        $json['data'] = $empresas;
        $json['productos'] = $productos_enviados;
        $json['excel'] = base64_encode(file_get_contents('reporte_logistica.xlsx'));

        unlink('reporte_logistica.xlsx');

        return response()->json($json);
    }

    public function general_reporte_administracion_margen_data()
    {
        $empresas = DB::select("SELECT id, empresa FROM empresa WHERE status = 1 AND id != 0");
        $clientes = DB::select("SELECT
                                    documento_entidad_ftp.id,
                                    documento_entidad_ftp.ftp,
                                    documento_entidad_ftp.created_at,
                                    documento_entidad.razon_social as cliente,
                                    documento_periodo.periodo,
                                    documento_uso_cfdi.codigo,
                                    documento_uso_cfdi.descripcion
                                FROM documento_entidad_ftp
                                INNER JOIN documento_periodo ON documento_entidad_ftp.id_periodo = documento_periodo.id
                                INNER JOIN documento_uso_cfdi ON documento_entidad_ftp.id_cfdi = documento_uso_cfdi.id
                                INNER JOIN documento_entidad ON documento_entidad_ftp.id_entidad = documento_entidad.id
                                WHERE documento_entidad_ftp.status = 1");
        $periodos = DB::select("SELECT id, periodo FROM documento_periodo WHERE status = 1");
        $usos_venta = DB::select("SELECT * FROM documento_uso_cfdi");

        $marketplaces = DB::select("SELECT
                                            marketplace_area.id, 
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN area ON marketplace_area.id_area = area.id
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id 
                                        WHERE area.area = 'B2B' AND marketplace_area.status = 1
                                        GROUP BY marketplace_area.id");

        return response()->json([
            'clientes' => $clientes,
            'empresas' => $empresas,
            'periodos' => $periodos,
            'usos_venta' => $usos_venta,
            'marketplaces' => $marketplaces
        ]);
    }

    public function general_reporte_administracion_margen_guardar(Request $request)
    {
        $raw_data = $request->input('data');
        $data = json_decode($request->input('data'));

        $validator = Validator::make(json_decode($raw_data, true), [
            'cliente'       => "required|unique:documento_entidad_ftp,id_entidad",
            'api_key'       => "required|unique:documento_entidad_ftp,api_key",
            'ftp'           => "required|max:50|regex:([^'&quot;]$)",
            'marketplace'   => "required|max:2",
            'uso_venta'     => "required",
            'periodo'       => "required"
        ]);

        if (!$validator->passes()) {
            return response()->json([
                'code'  => 500,
                'message'   => implode("; ", $validator->errors()->all())
            ]);
        }

        DB::table("documento_entidad_ftp")->insert([
            'id_entidad'            => $data->cliente,
            'ftp'                   => $data->ftp,
            'id_cfdi'               => $data->uso_venta,
            'id_periodo'            => $data->periodo,
            'api_key'               => $data->api_key,
            'id_marketplace_area'   => $data->marketplace
        ]);

        $clientes   = DB::select("SELECT
                                    documento_entidad_ftp.id,
                                    documento_entidad_ftp.ftp,
                                    documento_entidad_ftp.created_at,
                                    documento_entidad.razon_social as cliente,
                                    documento_periodo.periodo,
                                    documento_uso_cfdi.codigo,
                                    documento_uso_cfdi.descripcion
                                FROM documento_entidad_ftp
                                INNER JOIN documento_periodo ON documento_entidad_ftp.id_periodo = documento_periodo.id
                                INNER JOIN documento_uso_cfdi ON documento_entidad_ftp.id_cfdi = documento_uso_cfdi.id
                                INNER JOIN documento_entidad ON documento_entidad_ftp.id_entidad = documento_entidad.id
                                WHERE documento_entidad_ftp.status = 1");

        return response()->json([
            'code'  => 200,
            'message'   => "Cliente agregado correctamente.",
            'clientes'  => $clientes
        ]);
    }

    public function general_reporte_administracion_margen_cliente($criterio)
    {
        $criterio = str_replace("%20", " ", $criterio);

        $clientes = DB::select("SELECT id, razon_social FROM documento_entidad WHERE razon_social LIKE '%" . $criterio . "%' AND tipo = 1");

        return response()->json([
            'code'  => 200,
            'clientes'  => $clientes
        ]);
    }

    public function general_reporte_adminsitracion_cliente_productos($cliente_id)
    {
        $productos = DB::select("SELECT
                                        documento_entidad_modelo_margen.id,
                                        documento_entidad_modelo_margen.precio,
                                        modelo.sku,
                                        modelo.descripcion
                                    FROM documento_entidad_modelo_margen
                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                    WHERE documento_entidad_modelo_margen.id_ftp = " . $cliente_id . "");

        foreach ($productos as $producto) {
            $response = InventarioService::existenciaProducto($producto->sku, 114);

            $producto->existencia = $response->error ? 0 : $response->existencia;
        }

        return response()->json([
            "code" => 200,
            "data" => $productos
        ]);
    }

    public function general_reporte_administracion_margen_producto_data($criterio)
    {
        $productos = DB::select("SELECT id, sku, descripcion FROM modelo WHERE descripcion LIKE '%" . $criterio . "%'");

        if (empty($productos)) {
            $productos = DB::select("SELECT id, sku, descripcion FROM modelo WHERE sku = '" . $criterio . "'");
        }

        return response()->json([
            'code'  => 200,
            'productos' => $productos
        ]);
    }

    public function general_reporte_administracion_margen_producto_guardar(Request $request)
    {
        $cliente = $request->input('cliente');
        $producto = json_decode($request->input('producto'));

        $existe_modelo = DB::table("modelo")
            ->where("sku", trim($producto->producto))
            ->first();

        if (!$existe_modelo) {
            $existe_modelo = DB::table("modelo")->insertGetId([
                'id_tipo' => 1,
                'sku' => mb_strtoupper(trim($producto->producto), 'UTF-8'),
                'descripcion' => mb_strtoupper(trim($producto->descripcion), 'UTF-8'),
                'costo' => (float) $producto->precio,
                'alto' => 0,
                'ancho' => 0,
                'largo' => 0,
                'peso' => 0
            ]);
        }

        $existe_modelo_margen = DB::table("documento_entidad_modelo_margen")
            ->where("id_modelo", $existe_modelo->id)
            ->where("id_ftp", $cliente)
            ->first();

        if (!$existe_modelo_margen) {
            DB::table('documento_entidad_modelo_margen')->insert([
                'id_ftp' => $cliente,
                'id_modelo' => $existe_modelo->id,
                'precio' => $producto->precio
            ]);
        } else {
            DB::table('documento_entidad_modelo_margen')->where("id", $existe_modelo_margen->id)->update([
                'precio' => $producto->precio
            ]);
        }

        $productos = DB::select("SELECT
                                        documento_entidad_modelo_margen.id,
                                        documento_entidad_modelo_margen.precio,
                                        modelo.sku,
                                        modelo.descripcion
                                    FROM documento_entidad_modelo_margen
                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                    WHERE documento_entidad_modelo_margen.id_ftp = " . $cliente . "");

        return response()->json([
            'code' => 200,
            'message' => "Producto agregado correctamente.",
            'productos' => $productos
        ]);
    }

    public function general_reporte_administracion_margen_producto_borrar($producto)
    {
        DB::table('documento_entidad_modelo_margen')->where(['id' => $producto])->delete();

        return response()->json([
            'code'  => 200
        ]);
    }

    public function general_reporte_administracion_margen_producto_cambiar($producto, $precio)
    {
        DB::table('documento_entidad_modelo_margen')->where(['id' => $producto])->update([
            'precio' => $precio
        ]);

        return response()->json([
            'code'  => 200
        ]);
    }

    public function general_reporte_contabilidad_refacturacion(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));
        $ventas_shifted = array();

        if ($data->fecha_inicio == "" || $data->fecha_final == "") {
            return response()->json([
                'code' => 500,
                'message' => "Favor de escoger un rango de fechas"
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('REPORTE DE REFACTURACIONES');
        $contador_fila = 2;

        $sheet->setCellValue('A1', 'PEDIDO');
        $sheet->setCellValue('B1', 'FACTURA');
        $sheet->setCellValue('C1', 'FACTURA NUEVA');
        $sheet->setCellValue('D1', 'NOTA DE CREDITO');
        $sheet->setCellValue('E1', 'VENTA');
        $sheet->setCellValue('F1', 'EMPRESA');
        $sheet->setCellValue('G1', 'AREA');
        $sheet->setCellValue('H1', 'MARKETPLACE');
        $sheet->setCellValue('I1', 'CLIENTE');
        $sheet->setCellValue('J1', 'PAQUETERÍA');
        $sheet->setCellValue('K1', 'VENDEDOR');
        $sheet->setCellValue('L1', 'CODIGO');
        $sheet->setCellValue('M1', 'DESCRIPCION');
        $sheet->setCellValue('N1', 'CANTIDAD');
        $sheet->setCellValue('O1', 'PRECIO');
        $sheet->setCellValue('P1', 'TOTAL');
        $sheet->setCellValue('Q1', 'ALMACEN');
        $sheet->setCellValue('R1', 'METODO DE PAGO');
        $sheet->setCellValue('S1', 'FASE');
        $sheet->setCellValue('T1', 'REFACTURADA');
        $sheet->setCellValue('U1', 'MARCA');
        $sheet->setCellValue('V1', 'CATEGORIA');
        $sheet->setCellValue('W1', 'SUBCATEGORIA');
        $sheet->setCellValue('X1', 'FECHA');
        $sheet->setCellValue('Y1', 'FECHA MARKETPLACE');
        $sheet->setCellValue('Z1', 'FECHA TIMBRE');
        $sheet->setCellValue('AA1', 'ESTATUS CRM');
        $sheet->setCellValue('AB1', 'ESTATUS FACTURA');

        $spreadsheet->getActiveSheet()->getStyle('A1:AB1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:AB1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        $ventas = DB::select("SELECT 
                                documento.id, 
                                documento.no_venta,
                                documento.nota,
                                documento.factura_serie,
                                documento.factura_folio,
                                documento.refacturado,
                                documento_fase.fase,
                                documento.status,
                                documento.status_erp,
                                documento.fecha_timbrado_erp,
                                documento.created_at, 
                                documento.mkt_created_at,
                                marketplace.marketplace, 
                                area.area, 
                                almacen.almacen,
                                paqueteria.paqueteria, 
                                usuario.nombre AS usuario,
                                documento_entidad.razon_social AS cliente,
                                empresa.empresa,
                                empresa.bd
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN empresa on empresa_almacen.id_empresa = empresa.id
                            INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                            INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                            INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                            INNER JOIN area ON marketplace_area.id_area = area.id
                            INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                            WHERE documento.id_tipo = 2
                            AND documento.refacturado = 1
                            AND documento.refacturado_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'");

        foreach ($ventas as $venta) {
            $total = 0;

            $productos = DB::select("SELECT 
                                    modelo.sku, 
                                    modelo.descripcion, 
                                    modelo.cat1,
                                    modelo.cat2,
                                    modelo.cat3,
                                    movimiento.cantidad, 
                                    ROUND((movimiento.precio * 1.16), 2) AS precio
                                FROM movimiento 
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id 
                                WHERE id_documento = " . $venta->id . "");

            $pagos = DB::select("SELECT
                                    metodo_pago.metodo_pago
                                FROM documento_pago
                                INNER JOIN metodo_pago ON documento_pago.id_metodopago = metodo_pago.id
                                INNER JOIN documento_pago_re ON documento_pago.id = documento_pago_re.id_pago
                                WHERE documento_pago_re.id_documento = " . $venta->id . "");

            $venta->productos   = $productos;
            $venta->total = $total;

            array_push($ventas_shifted, $venta);

            $estatus_factura = "NO ENCONTRADA";
            $fecha_timbre = "NO ENCONTRADA";
            $folio_factura_nueva = "NO ENCONTRADA";

            switch ($venta->status_erp) {
                case 0:
                    $estatus_factura = 'CANCELADA/ELIMINADA';
                    break;
                case 1:
                    $estatus_factura = 'ACTIVA';
                    break;
                case 2:
                case 3:
                    $estatus_factura = 'NO ENCONTRADA';
                    break;
                case 4:
                    $estatus_factura = 'NOTA DE CREDITO';
                    break;
                default:
                    $estatus_factura = 'NO ENCONTRADA';
                    break;
            }

            $fecha_timbre = $venta->fecha_timbrado_erp ?? 'NO ENCONTRADA';

            $seguimiento = DB::select("SELECT seguimiento FROM seguimiento WHERE id_documento = " . $venta->id . " AND seguimiento LIKE '%Se generá una nueva factura por refacturación con el folio%'");

            if (empty($productos)) continue;

            if (!empty($seguimiento)) {
                $seguimiento_split = explode(",", $seguimiento[0]->seguimiento);
                $seguimiento_split_2 = explode(" ", $seguimiento_split[0]);

                $folio_factura_nueva = $seguimiento_split_2[array_key_last($seguimiento_split_2)];
            }

            foreach ($productos as $index => $producto) {
                $total += round($producto->cantidad * $producto->precio, 2);

                $sheet->setCellValue('A' . $contador_fila, $venta->id);
                $sheet->setCellValue('B' . $contador_fila, ($venta->factura_serie == 'N/A') ? $venta->id : $venta->factura_folio);
                $sheet->setCellValue('C' . $contador_fila, $folio_factura_nueva);
                $sheet->setCellValue('D' . $contador_fila, $venta->nota);
                $sheet->setCellValue('E' . $contador_fila, $venta->no_venta);
                $sheet->setCellValue('F' . $contador_fila, $venta->empresa);
                $sheet->setCellValue('G' . $contador_fila, $venta->area);
                $sheet->setCellValue('H' . $contador_fila, $venta->marketplace);
                $sheet->setCellValue('I' . $contador_fila, $venta->cliente);
                $sheet->setCellValue('J' . $contador_fila, $venta->paqueteria);
                $sheet->setCellValue('K' . $contador_fila, $venta->usuario);
                $sheet->setCellValue('L' . $contador_fila, $producto->sku);
                $sheet->setCellValue('M' . $contador_fila, $producto->descripcion);
                $sheet->setCellValue('N' . $contador_fila, $producto->cantidad);
                $sheet->setCellValue('O' . $contador_fila, $producto->precio);
                $sheet->setCellValue('P' . $contador_fila, round($producto->cantidad * $producto->precio, 2));
                $sheet->setCellValue('Q' . $contador_fila, $venta->almacen);
                $sheet->setCellValue('R' . $contador_fila, empty($pagos) ? "SIN PAGO REGISTRADO" : $pagos[0]->metodo_pago);
                $sheet->setCellValue('S' . $contador_fila, $venta->fase);
                $sheet->setCellValue('T' . $contador_fila, ($venta->refacturado) ? "SÍ" : "NO");
                $sheet->setCellValue('U' . $contador_fila, $producto->cat2);
                $sheet->setCellValue('V' . $contador_fila, $producto->cat1);
                $sheet->setCellValue('W' . $contador_fila, $producto->cat3);
                $sheet->setCellValue('X' . $contador_fila, $venta->created_at);
                $sheet->setCellValue('Y' . $contador_fila, $venta->mkt_created_at);
                $sheet->setCellValue('Z' . $contador_fila, $fecha_timbre);
                $sheet->setCellValue('AA' . $contador_fila, ($venta->status) ? 'ACTIVA' : 'CANCELADA');
                $sheet->setCellValue('AB' . $contador_fila, $estatus_factura);

                $spreadsheet->getActiveSheet()->getStyle("O" . $contador_fila . ":P" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');
                $sheet->getCellByColumnAndRow(12, $contador_fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(5, $contador_fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                if (!$venta->status) {
                    $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':AB' . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('DA5A5A');
                }

                $venta->total += $total;

                $contador_fila++;
            }

            $contador_fila++;

            if (count($productos) > 0) {
                $contador_fila--;
            }
        }

        foreach (range('A', 'Z') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->getActiveSheet()->getColumnDimension("AA")->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension("AB")->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_refacturacion.xlsx');

        $json['code'] = 200;
        $json['ventas'] = $ventas_shifted;
        $json['excel'] = base64_encode(file_get_contents('reporte_refacturacion.xlsx'));

        unlink('reporte_refacturacion.xlsx');

        return response()->json($json);
    }

    public function general_reporte_contabilidad_factura_sin_timbre(Request $request)
    {
        $data = json_decode($request->input("data"));

        $empresa = DB::table("empresa")
            ->select("empresa")
            ->where("bd", $data->empresa)
            ->first();

        $fecha_inicial = date("d/m/Y", strtotime($data->fecha_inicial));
        $fecha_final = date("d/m/Y", strtotime($data->fecha_final));

        $documentos = json_decode(file_get_contents(config('webservice.url') . "Reportes/PendientesPorTimbrar/" . $data->empresa . "/Tipo/" . $data->tipo . "/Fechas/De/" . $fecha_inicial . "/Al/" . $fecha_final));

        $tipo_documento = $data->tipo == "1" ? "Facturas" : "Notas de credito";
        $tipo_documento .= " sin timbre";

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle($tipo_documento);
        $contador_fila = 5;

        setlocale(LC_ALL, "es_MX");

        $sheet->setCellValue('A1', $empresa->empresa);
        $sheet->setCellValue('A2', strftime("%A %d de %B del %Y", strtotime($data->fecha_inicial)));
        $sheet->setCellValue('B2', strftime("%A %d de %B del %Y", strtotime($data->fecha_final)));

        # Cabecera
        $sheet->setCellValue('A4', 'Fecha');
        $sheet->setCellValue('B4', 'Modulo');
        $sheet->setCellValue('C4', 'Cliente');
        $sheet->setCellValue('D4', 'Serie');
        $sheet->setCellValue('E4', 'Folio');
        $sheet->setCellValue('F4', 'Titulo');
        $sheet->setCellValue('G4', 'Total');
        $sheet->setCellValue('H4', 'Pagado');
        $sheet->setCellValue('I4', 'Restante');
        $sheet->setCellValue('J4', 'Es Global');

        $sheet->freezePane("A5");

        $spreadsheet->getActiveSheet()->getStyle('A1')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('A4:J4')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        foreach ($documentos as $documento) {
            $sheet->setCellValue('A' . $contador_fila, $documento->fechadocumento);
            $sheet->setCellValue('B' . $contador_fila, $documento->modulo);
            $sheet->setCellValue('C' . $contador_fila, $documento->cliente);
            $sheet->setCellValue('D' . $contador_fila, $documento->serie);
            $sheet->setCellValue('E' . $contador_fila, $documento->folio);
            $sheet->setCellValue('F' . $contador_fila, $documento->titulo);
            $sheet->setCellValue('G' . $contador_fila, $documento->total);
            $sheet->setCellValue('H' . $contador_fila, $documento->pagado);
            $sheet->setCellValue('I' . $contador_fila, $documento->balance);
            $sheet->setCellValue('J' . $contador_fila, $documento->globalizado ? "Sí" : "No");

            $spreadsheet->getActiveSheet()->getStyle("G" . $contador_fila . ":I" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

            $contador_fila++;
        }

        foreach (range('A', 'J') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $file_name = "DOCUMENTOS_SIN_TIMBRE_" . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($file_name);

        $json['code'] = 200;
        $json['file'] = base64_encode(file_get_contents($file_name));
        $json['name'] = $file_name;

        unlink($file_name);

        return response()->json($json);
    }

    public function general_reporte_contabilidad_costo_sobre_venta(Request $request)
    {
        $data = json_decode($request->input("data"));

        $empresa = DB::table("empresa")
            ->select("empresa")
            ->where("bd", $data->empresa)
            ->first();

        $fecha_inicial = date("Y/m/d", strtotime($data->fecha_inicial));
        $fecha_final = date("Y/m/d", strtotime($data->fecha_final));

        $documentos = json_decode(file_get_contents(config('webservice.url') . "ReporteVentas/DB/" . $data->empresa . "/rangofechas/De/" . $fecha_inicial . "/Al/" . $fecha_final));

        $tipo_documento = "Costos sobre ventas";

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle($tipo_documento);
        $contador_fila = 5;

        setlocale(LC_ALL, "es_MX");

        $sheet->setCellValue('A1', $empresa->empresa);
        $sheet->setCellValue('A2', strftime("%A %d de %B del %Y", strtotime($data->fecha_inicial)));
        $sheet->setCellValue('B2', strftime("%A %d de %B del %Y", strtotime($data->fecha_final)));

        # Cabecera
        $sheet->setCellValue('A4', 'Fecha');
        $sheet->setCellValue('B4', 'Factura');
        $sheet->setCellValue('C4', 'Cliente');
        $sheet->setCellValue('D4', 'Producto');
        $sheet->setCellValue('E4', 'Subtotal Venta');
        $sheet->setCellValue('F4', 'Subtotal Costo');
        $sheet->setCellValue('G4', 'Utilidad o Perdidad');

        $sheet->freezePane("A5");

        $spreadsheet->getActiveSheet()->getStyle('A1')->getFont()->setBold(1);
        $spreadsheet->getActiveSheet()->getStyle('A4:J4')->getFont()->setBold(1)->getColor()->setARGB('DE573A'); # Cabecera en negritas con color negro

        foreach ($documentos as $documento) {
            foreach ($documento->productos as $producto) {
                $producto->utilidad = round($producto->costo == 0 ? 100 : ((1 - ($producto->costo / $producto->precio)) * 100), 2);
                $sheet->setCellValue('A' . $contador_fila, $documento->fecha_timbrado);
                $sheet->setCellValue('B' . $contador_fila, $documento->serie . " " . $documento->folio);
                $sheet->setCellValue('C' . $contador_fila, $documento->cliente);
                $sheet->setCellValue('D' . $contador_fila, $producto->producto);
                $sheet->setCellValue('E' . $contador_fila, round($producto->precio * $producto->cantidad, 2));
                $sheet->setCellValue('F' . $contador_fila, round($producto->costo * $producto->cantidad, 2));
                $sheet->setCellValue('G' . $contador_fila, $producto->utilidad);

                $spreadsheet->getActiveSheet()->getStyle("E" . $contador_fila . ":G" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $contador_fila++;
            }
        }

        foreach (range('A', 'G') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $file_name = $tipo_documento . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($file_name);

        $json['ventas'] = $documentos;
        $json['file'] = base64_encode(file_get_contents($file_name));
        $json['name'] = $file_name;

        unlink($file_name);

        return response()->json($json);
    }

    /* General > Notificaciones */
    public function general_notificacion_data(Request $request)
    {
        $notificaciones = DB::select("SELECT id, id_subnivel_nivel, data FROM notificacion WHERE dismissed = 0 ORDER BY date_added DESC");
        $auth = json_decode($request->auth);
        $array = array();

        foreach ($notificaciones as $notificacion) {
            $usuarios = DB::select("SELECT id_usuario FROM notificacion_usuario WHERE id_notificacion = " . $notificacion->id . "");

            if (!empty($usuarios)) {
                foreach ($usuarios as $usuario) {
                    if ($auth->id == $usuario->id_usuario) {
                        $data       = json_decode($notificacion->data);
                        $data->id   = $notificacion->id;

                        array_push($array, $data);
                    }
                }
            } else {
                $usuarios_id = array();
                $usuarios = DB::select("SELECT id_usuario FROM usuario_subnivel_nivel WHERE id_subnivel_nivel = " . $notificacion->id_subnivel_nivel . "");

                foreach ($usuarios as $usuario) {
                    if ($usuario->id_usuario == $auth->id) {
                        $data = json_decode($notificacion->data);
                        $data->id = $notificacion->id;

                        array_push($array, $data);
                    }
                }
            }
        }

        return response()->json([
            'code'  => 200,
            'notificaciones' => $array
        ]);
    }

    public function general_notificacion_dismiss(Request $request)
    {
        $notificacion   = $request->input('notificacion');
        $auth = json_decode($request->auth);

        DB::table('notificacion')->where(['id' => $notificacion])->update([
            'dismissed'     => 1,
            'dismissed_by'  => $auth->id
        ]);

        return response()->json([
            'code'  => 200
        ]);
    }

    public function general_notificacion_problema()
    {
        $usuarios = DB::select("SELECT
                                    usuario.id,
                                    usuario.nombre,
                                    usuario.email
                                FROM documento 
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                WHERE documento.problema = 1 
                                AND documento.status = 1 
                                GROUP BY usuario.id");

        if (!file_exists("logs")) {
            mkdir("logs", 0777, true);
        }

        if (!empty($usuarios)) {
            foreach ($usuarios as $usuario) {
                $documentos = DB::select("SELECT id FROM documento WHERE id_usuario = " . $usuario->id . " AND problema = 1 AND status = 1");

                if (!empty($documentos)) {
                    $array_documentos = array();

                    foreach ($documentos as $documento) {
                        array_push($array_documentos, $documento->id);
                    }

                    try {
                        $notificacion['titulo']     = "Pedido en problemas.";
                        $notificacion['message']    = "Se te recuerda que todavía cuentas con los siguientes pedidos en problemas: " . implode(" ", $array_documentos) . ".";
                        $notificacion['tipo']       = "danger"; // success, warning, danger
                        $notificacion['link']       = "/venta/venta/problema";

                        $notificacion_id = DB::table('notificacion')->insertGetId([
                            'data'  => json_encode($notificacion)
                        ]);

                        $notificacion['id']         = $notificacion_id;

                        DB::table('notificacion_usuario')->insert([
                            'id_usuario'        => $usuario->id,
                            'id_notificacion'   => $notificacion_id
                        ]);

                        $notificacion['usuario']    = $usuario->id;

                        event(new PusherEvent(json_encode($notificacion)));

                        $view       = view('email.notificacion_problema_recordatorio')->with(['vendedor' => $usuario->nombre, 'anio' => date('Y'), 'documentos' => $array_documentos]);

                        $mg     = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
                        $domain = "omg.com.mx";
                        $mg->sendMessage($domain, array(
                            'from'  => 'CRM OMG International <generico@omg.com.mx>',
                            'to'            => $usuario->email,
                            'subject'       => 'Recordatorio de pedidos en problemas',
                            'html'          => $view
                        ));

                        # Generar bitacora
                        file_put_contents("logs/notificaciones.log", date("d/m/Y H:i:s") . " Notificación sobre pedidos en problemas enviada correctamente a " . $usuario->nombre . "" . PHP_EOL, FILE_APPEND);

                        return 1;
                    } catch (Exception $e) {
                        # Generar bicatora
                        file_put_contents("logs/notificaciones.log", date("d/m/Y H:i:s") . " Ocurrió un error al generar la notificación para el usuario " . $usuario->nombre . " mensaje de error: " . $e->getMessage() . "" . PHP_EOL, FILE_APPEND);

                        echo $e->getMessage();

                        return 0;
                    }
                }
            }
        }

        return 1;
    }

    public function rawinfo_productos()
    {
        set_time_limit(0);

        $productos = \Httpful\Request::get(config('webservice.url') . '7/Reporte/Productos/Existencia')->send();

        $productos = $productos->body;

        if (empty($productos)) {
            return;
        }

        foreach ($productos as $producto) {
            $existe = DB::select("SELECT id FROM modelo WHERE sku = '" . trim($producto->sku) . "'");

            if (!empty($existe)) {
                DB::table('modelo')->where(['id' => $existe[0]->id])->update([
                    'cat1' => $producto->cat1,
                    'cat2' => $producto->cat2,
                    'cat3' => $producto->cat3,
                    'cat4' => $producto->cat4,
                ]);
            }
        }
    }

    private function excelColumnRange($lower, $upper)
    {
        $range = array();

        ++$upper;
        for ($i = $lower; $i !== $upper; ++$i) {
            $range[] = $i;
        }

        return $range;
    }

    public function general_reporte_venta_mercadolibre_ventas_crm(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        return response()->json([
            'ventasCRM' =>  DB::select("SELECT
                                documento.id,
                                documento.status,
                                documento.id_tipo,
                                documento.no_venta,
                                documento_fase.fase,
                                documento.nota
                            FROM documento
                            INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                            WHERE documento.id_marketplace_area = " . $data->marketplace . "
                            AND created_at BETWEEN '" . $data->fecha_inicio . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'
                            ")
        ]);
    }

    public function general_reporte_venta_mercadolibre_ventas_ml(Request $request)
    {
        set_time_limit(0);

        $data = json_decode($request->input('data'));

        $credenciales = DB::select("SELECT * FROM marketplace_api WHERE id_marketplace_area = " . $data->marketplace . "");

        if (empty($credenciales)) {
            return response()->json([
                'code'      => 500,
                'message'   => "No se encontró información de la API REST del marketplace."
            ]);
        }

        $credenciales[0]->fecha_inicio = $data->fecha_inicio;
        $credenciales[0]->fecha_final = $data->fecha_final;

        return response()->json([
            'ventasML' =>  MercadolibreService::ventas($credenciales[0], $data->publicacion)
        ]);
    }

    public function general_reporte_venta_mercadolibre_comparacion(Request $request)
    {
        set_time_limit(0);
        $response = array();
        $data = json_decode($request->input('data'));

        foreach ($data->ventasML as $ml) {
            foreach ($ml->ventas as $venta) {
                $match = array_filter($data->ventasCRM, function ($crm) use ($venta) {
                    return $crm->no_venta === strval($venta->venta);
                });

                if (!empty($match)) {
                    continue 2;
                }
                array_push($response, $venta);
            }
        }

        return response()->json([
            'comparacion' => $response,
        ]);
    }

    public function general_reporte_venta_mercadolibre_revision(Request $request)
    {
        set_time_limit(0);
        $response = array();

        $data = json_decode($request->input('data'));
        $marketplace = json_decode($request->input('marketplace'));

        foreach ($data as $venta) {
            $object = new \stdClass();
            $informacion = MercadolibreService::checarEstado($venta->venta, 2, $marketplace);

            $dateTime = new DateTime($venta->fecha);
            $date = $dateTime->format('Y-m-d');

            $object->venta = $venta->venta;
            $object->fecha = $date;
            $object->estado = $informacion->estado;
            $object->subestado = $informacion->subestado;

            array_push($response, $object);
        }
        return response()->json([
            'reporte' => $response
        ]);
    }

    public function general_reporte_venta_mercadolibre_revision_canceladas(Request $request)
    {
        set_time_limit(0);
        $response = array();
        $error = array();
        $data = json_decode($request->input('data'));
        $marketplace = json_decode($request->input('marketplace'));

        foreach ($data as $venta) {
            $info = '';
            $info = MercadolibreService::checarCancelados($venta->id, 1, $marketplace);
            if ($info->error == 1) {
                $object = new \stdClass();
                $object->id = $venta->id;
                $object->venta = $venta->no_venta;
                $object->error = $info->mensaje;
                array_push($error, $object);
            } else {
                if ($info->cancelada) {
                    array_push($response, $venta);
                }
            }
        }
        return response()->json([
            'reporte' => $response,
            'errores' => $error
        ]);
    }


    public function general_reporte_venta_mercadolibre_estatus(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $date = json_decode($request->input('date'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('VENTAS FALTANTES MERCADOLIBRE');

        $contador_fila  = 2;
        # Cabecera
        $sheet->setCellValue('A1', 'VENTA');
        $sheet->setCellValue('B1', 'FECHA');
        $sheet->setCellValue('C1', 'ESTADO');
        $sheet->setCellValue('D1', 'SUB ESTADO');

        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($data as $venta) {
            $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($venta->venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(2, $contador_fila)->setValueExplicit($venta->fecha, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $contador_fila, $venta->estado);
            $sheet->setCellValue('D' . $contador_fila, $venta->subestado);
            $contador_fila++;
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'D') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $primera = 1;
        $ultima = $contador_fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":E" . $ultima);

        $nombre_archivo = "Reporte de ventas faltantes de mercadolibre " . date("d F Y", strtotime($date->fecha_inicio)) . " a " . date("d F Y", strtotime($date->fecha_final)) . " " . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($nombre_archivo);

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents($nombre_archivo));
        $json['nombre'] = $nombre_archivo;

        unlink($nombre_archivo);

        return response()->json($json);
    }
    public function general_reporte_venta_mercadolibre_estatus_cancelados(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $errores = json_decode($request->input('errores'));
        $date = json_decode($request->input('date'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('VENTAS CANCELADAS MERCADOLIBRE');

        $contador_fila  = 2;
        # Cabecera
        $sheet->setCellValue('A1', 'VENTA');
        $sheet->setCellValue('B1', 'PEDIDO');
        $sheet->setCellValue('C1', 'FASE');
        $sheet->setCellValue('D1', 'ESTATUS MERCADOLIBRE');
        $sheet->setCellValue('E1', '# NDC');

        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($data as $venta) {
            $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($venta->no_venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $contador_fila, $venta->id);
            $sheet->setCellValue('C' . $contador_fila, $venta->fase);
            $sheet->setCellValue('D' . $contador_fila, 'Cancelada');
            $sheet->getCellByColumnAndRow(5, $contador_fila)->setValueExplicit($venta->nota, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $contador_fila++;
        }
        $contador_fila++;

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach (range('A', 'E') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }
        $contador_fila++;

        $sheet->setCellValue('F' . $contador_fila, 'VENTA');
        $sheet->setCellValue('G' . $contador_fila, 'PEDIDO');
        $sheet->setCellValue('H' . $contador_fila, 'ERROR');

        foreach ($errores as $venta) {
            $sheet->getCellByColumnAndRow(6, $contador_fila)->setValueExplicit($venta->venta, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('G' . $contador_fila, $venta->id);
            $sheet->getCellByColumnAndRow(8, $contador_fila)->setValueExplicit($venta->error, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $contador_fila++;
        }

        $primera = 1;
        $ultima = $contador_fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":E" . $ultima);

        $nombre_archivo = "Reporte de ventas canceladas de mercadolibre " . date("d F Y", strtotime($date->fecha_inicio)) . " a " . date("d F Y", strtotime($date->fecha_final)) . " " . uniqid() . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($nombre_archivo);

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents($nombre_archivo));
        $json['nombre'] = $nombre_archivo;

        unlink($nombre_archivo);

        return response()->json($json);
    }

    public function general_reporte_notas_autorizadas(Request $request)
    {
        set_time_limit(0);

        $spreadsheet = new Spreadsheet();
        $index = 0;
        $colorear = false;

        $data = json_decode($request->input('data'));
        $modulo = $data->modulo == 'D' ? 'Devoluciones' : 'Garantias';
        $notas = DB::table('garantia_nota_autorizacion')
            ->select('*')
            ->whereBetween('created_at', [$data->fecha_inicio . ' 00:00:00', $data->fecha_final . ' 23:59:59'])
            ->where('modulo', $data->modulo)
            ->where('estado', 2)
            ->get()
            ->toArray();


        if (empty($notas)) {
            $json['code'] = 500;
            $json['message'] = "No se encontraron Notas Autorizadas";
            return response()->json($json);
        }


        $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex($index);
        $sheet = $spreadsheet->getActiveSheet()->setTitle($modulo);
        $fila = 3;

        $sheet->setCellValue('C1', 'PRODUCTOS');
        $spreadsheet->setActiveSheetIndex(0)->mergeCells('C1:E1');
        $sheet->getStyle('C1:E1')->getAlignment()->setHorizontal('center');

        $sheet->setCellValue('A2', 'DOCUMENTO');
        $sheet->setCellValue('B2', 'GARANTIA');
        $sheet->setCellValue('C2', 'SKU');
        $sheet->setCellValue('D2', 'SERIE');
        $sheet->setCellValue('E2', 'CAMBIO');
        $sheet->setCellValue('F2', 'DESCRIPCION');
        $sheet->setCellValue('G2', 'CREADO');
        $sheet->setCellValue('H2', 'AUTORIZADO');

        $spreadsheet->getActiveSheet()->getStyle('A1:H2')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:H2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        foreach ($notas as $nota) {
            $sheet->setCellValue('A' . $fila, $nota->documento);
            $sheet->setCellValue('B' . $fila, $nota->documento_garantia);
            $sheet->setCellValue('G' . $fila, explode(' ', $nota->created_at)[0]);
            $sheet->setCellValue('H' . $fila, explode(' ', $nota->authorized_at)[0]);

            $nota_d = json_decode($nota->json);
            if ($colorear) {
                $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ':H' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('a9e9f5');
            }
            foreach (isset($nota_d->productos) ? $nota_d->productos : $nota_d->productos_anteriores as $producto) {

                if (sizeof($producto->series) >= 1) {
                    foreach ($producto->series as $serie) {
                        $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet->getCellByColumnAndRow(4, $fila)->setValueExplicit($data->modulo == 'D' ? $serie : $serie->serie, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet->setCellValue('E' . $fila, $producto->cambio == 0 ? "NO" : "SI");
                        $sheet->setCellValue('F' . $fila, $producto->descripcion);
                        if ($colorear) {
                            $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ':H' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('a9e9f5');
                        }
                        $fila++;
                    }
                } else {
                    $i = 0;
                    for ($i = 0; $i < $producto->cantidad; $i++) {
                        $sheet->getCellByColumnAndRow(3, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet->setCellValue('D' . $fila, 'SIN SERIE');
                        $sheet->setCellValue('E' . $fila, $producto->cambio == 0 ? "NO" : "SI");
                        $sheet->setCellValue('F' . $fila, $producto->descripcion);
                        if ($colorear) {
                            $spreadsheet->getActiveSheet()->getStyle('A' . $fila . ':H' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('a9e9f5');
                        }
                        $fila++;
                    }
                }
            }
            $colorear = !$colorear;
        }

        foreach (range('A', 'H') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }
        $primera = 2;
        $ultima = $fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":H" . $ultima);

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_' . $modulo . '.xlsx');
        $json['notas'] = $notas;

        $json['code'] = 200;
        $json['excel'] = base64_encode(file_get_contents('reporte_' . $modulo . '.xlsx'));
        $json['message'] = "Creacion correcta del reporte";

        unlink('reporte_' . $modulo . '.xlsx');

        return response()->json($json);
    }

    private function getHpSkusArray()
    {
        return DB::table('modelo')
            ->where('descripcion', 'like', '%hp%')
            ->where('cat2', 'hp')
            ->pluck('sku')
            ->toArray();
    }

    private function getFilteredCompras($data, $hpSkusArray)
    {
        $compras = DB::table('movimiento')
            ->select('documento.id', 'documento.documento_extra', 'documento.factura_folio')
            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
            ->join('documento', 'movimiento.id_documento', '=', 'documento.id')
            ->whereIn('modelo.sku', $hpSkusArray)
            ->whereBetween('documento.expired_at', [$data->fecha_inicio, $data->fecha_final])
            ->where(
                'documento.status',
                1
            )
            ->where('documento.id_tipo', 1)
            ->groupBy('documento.id')
            ->get();

        $filteredCompras = [];
        foreach ($compras as $compra) {
            $searchCompra = $this->compras_raw_data($compra->id);
            if ($searchCompra) {
                $searchCompra->id_documento = $compra->id;
                $filteredCompras[] = $searchCompra;
            }
        }

        return $filteredCompras;
    }

    private function getProductos($filteredCompras)
    {
        $productos = [];
        foreach ($filteredCompras as $document) {
            if (isset($document->productos) && is_array($document->productos)) {
                foreach ($document->productos as $producto) {
                    $producto->id_documento = $document->id_documento;
                    $producto->razon_social = $document->proveedor;
                    $productos[] = $producto;
                }
            }
        }
        return $productos;
    }

    private function compras_raw_data($compra)
    {
        $allowedProveedores = [
            'CVA9904266T9',
            'ENO8910131AA',
            'IMM9304016Z4',
            'CIN960904FQ2',
            'CAS850526N64'
        ];

        $results = DB::select("SELECT
                                de.razon_social,
                                de.rfc,
                                m.id as movimiento_id,
                                m.cantidad,
                                m.cantidad_aceptada,
                                mo.id AS id_modelo,
                                mo.sku,
                                mo.descripcion,
                                mo.np
                            FROM documento_entidad de
                            INNER JOIN documento der ON de.id = der.id_entidad
                            INNER JOIN movimiento m ON der.id_documento = m.id_documento
                            INNER JOIN modelo mo ON m.id_modelo = mo.id
                            WHERE der.id = :compra AND de.tipo = 2", ['compra' => $compra]);

        $filteredResults = array_filter($results, function ($result) use ($allowedProveedores) {
            return in_array($result->rfc, $allowedProveedores);
        });

        if (empty($filteredResults)) {
            return null;
        }

        $compras = new \stdClass();
        $compras->proveedor = $filteredResults[0]->razon_social;
        $compras->rfc = $filteredResults[0]->rfc;
        $compras->productos = array_map(function ($result) {
            return (object) [
                'id' => $result->movimiento_id,
                'cantidad' => $result->cantidad,
                'cantidad_aceptada' => $result->cantidad_aceptada,
                'id_modelo' => $result->id_modelo,
                'sku' => $result->sku,
                'descripcion' => $result->descripcion,
                'np' => $result->np
            ];
        }, $filteredResults);

        return $compras;
    }

    private function processProductos($productos, $hpSkus, $date)
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->createSheet();
        $fila = 2;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Reporte Inv & Sellin Reseller');
        $headerStyle1 = [
            'font' => [
                'name' => 'arial',
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
                'left' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
                'right' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],

        ];
        $headerStyle2 = [
            'font' => [
                'name' => 'arial',
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
                'left' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
                'right' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'argb' => 'ffbfbfbf',
                ],
            ],
        ];

        $sheet->setCellValue(
            'A1',
            'Period End Date'
        ); //1
        $sheet->setCellValue(
            'B1',
            'Reporter_id'
        ); //2
        $sheet->setCellValue(
            'C1',
            'Reporter Company Name'
        ); //3
        $sheet->setCellValue(
            'D1',
            'Site Location ID'
        ); //4
        $sheet->setCellValue(
            'E1',
            'Site Name'
        ); //5
        $sheet->setCellValue(
            'F1',
            'Partner Reported Product ID'
        ); //6
        $sheet->setCellValue(
            'G1',
            'Product Description'
        ); //7
        $sheet->setCellValue(
            'H1',
            'Sellin qty from HP'
        ); //8
        $sheet->setCellValue(
            'I1',
            'Total Sell In Qty (from HP AND other source)'
        ); //9
        $sheet->setCellValue(
            'J1',
            'Quantity on Hand'
        ); //10
        $sheet->setCellValue(
            'K1',
            'Reserved Inventory'
        ); //11
        $sheet->setCellValue(
            'L1',
            'Quantity in float  (In transit between partner warehouses)'
        ); //12
        $sheet->setCellValue(
            'M1',
            'Quantity in stock allocated to be returned to supplier'
        ); //13
        $sheet->setCellValue(
            'N1',
            'BORRAR! COMPRA CRM'
        ); //14
        $sheet->setCellValue(
            'O1',
            'BORRAR! PROVEEDOR'
        ); //14

        $sheet->getStyle('A1')->applyFromArray($headerStyle1);
        $sheet->getStyle('B1')->applyFromArray($headerStyle2);
        $sheet->getStyle('C1')->applyFromArray($headerStyle1);
        $sheet->getStyle('D1')->applyFromArray($headerStyle2);
        $sheet->getStyle('E1')->applyFromArray($headerStyle1);
        $sheet->getStyle('F1')->applyFromArray($headerStyle2);
        $sheet->getStyle('G1')->applyFromArray($headerStyle1);
        $sheet->getStyle('H1')->applyFromArray($headerStyle2);
        $sheet->getStyle('I1')->applyFromArray($headerStyle1);
        $sheet->getStyle('J1')->applyFromArray($headerStyle2);
        $sheet->getStyle('K1')->applyFromArray($headerStyle1);
        $sheet->getStyle('L1')->applyFromArray($headerStyle2);
        $sheet->getStyle('M1')->applyFromArray($headerStyle1);
        $sheet->getStyle('N1')->applyFromArray($headerStyle2);
        $sheet->getStyle('O1')->applyFromArray($headerStyle2);

        $sheet->getStyle('A1:O1')->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(28.86);
        $sheet->getColumnDimension('B')->setWidth(47.14);
        $sheet->getColumnDimension('C')->setWidth(32.71);
        $sheet->getColumnDimension('D')->setWidth(62.57);
        $sheet->getColumnDimension('E')->setWidth(62.57);
        $sheet->getColumnDimension('F')->setWidth(36.71);
        $sheet->getColumnDimension('G')->setWidth(20.14);
        $sheet->getColumnDimension('H')->setWidth(26);
        $sheet->getColumnDimension('I')->setWidth(26.86);
        $sheet->getColumnDimension('J')->setWidth(66.14);
        $sheet->getColumnDimension('K')->setWidth(30.29);
        $sheet->getColumnDimension('L')->setWidth(30.29);
        $sheet->getColumnDimension('M')->setWidth(30.29);

        $spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('N')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('O')->setAutoSize(true);

        $sheet->getRowDimension(1)->setRowHeight(26.25);

        foreach ($productos as $producto) {


            if (in_array($producto->sku, $hpSkus)) {
                $sheet->getCellByColumnAndRow(1, $fila)->setValueExplicit($date, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $fila, 'AM62249');
                $sheet->setCellValue('C' . $fila, 'Omg International, S.A. de C.V.');
                $sheet->setCellValue('D' . $fila, '10294755');
                $sheet->setCellValue('E' . $fila, 'Omg International, S.A. de C.V.');
                $sheet->getCellByColumnAndRow(6, $fila)->setValueExplicit($producto->np, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('G' . $fila, $producto->descripcion);
                $sheet->getCellByColumnAndRow(9, $fila)->setValueExplicit($producto->cantidad, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(10, $fila)->setValueExplicit($producto->cantidad, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(14, $fila)->setValueExplicit($producto->id_documento, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(15, $fila)->setValueExplicit($producto->razon_social, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $fila++;
            }
        }
        $range = 'A2:O' . $fila;

        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->removeSheetByIndex(1);
        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_compras_HP.xlsx');
        $excel
            = base64_encode(file_get_contents('reporte_compras_HP.xlsx'));
        unlink('reporte_compras_HP.xlsx');

        return $excel;
    }

    private function processProductosVentas($filteredVentas, $hpSkusArray)
    {
        $inputFileName = 'archivos/base_ventas_hp.xlsx';
        $spreadsheet = IOFactory::load($inputFileName);
        $sheet = $spreadsheet->getActiveSheet();
        $fila = 2;
        foreach ($filteredVentas as $venta) {
            $searchWord = $venta->direccion->estado;
            $estado = DB::table('estados')
                ->where('name', 'like', '%' . $searchWord . '%')
                ->first();

            if ($estado) {
                $codeHp = $estado->code_hp;
            } else {
                $codeHp = 'N/A';
            }
            $aux_productos = 0;
            foreach ($venta->productos as $producto) {
                if (in_array($producto->sku, $hpSkusArray)) {
                    while ($aux_productos < $producto->cantidad) {

                        $sheet->setCellValue('A' . $fila, 'AM62249');
                        $sheet->setCellValue('B' . $fila, 'Omg International, S.A. de C.V.');
                        $sheet->setCellValue('C' . $fila, '10294755');
                        $sheet->setCellValue('D' . $fila, 'Omg International, S.A. de C.V.');
                        $sheet->setCellValue('E' . $fila, 'Industria Maderera  #226-A');
                        // $sheet->setCellValue('F' . $fila, 'Zapopan');
                        $sheet->setCellValue('G' . $fila, 'Zapopan');
                        $sheet->setCellValue('H' . $fila, 'JA');
                        $sheet->setCellValue('I' . $fila, '45130');
                        $sheet->setCellValue('J' . $fila, 'MX');
                        $sheet->setCellValue('K' . $fila, $venta->cliente);
                        $sheet->setCellValue('L' . $fila, $venta->rfc);
                        $sheet->setCellValue('M' . $fila, $venta->direccion->calle);
                        // $sheet->setCellValue('N' . $fila, 'Zapopan');
                        $sheet->setCellValue('O' . $fila, $venta->direccion->ciudad);
                        $sheet->setCellValue('P' . $fila, $codeHp);
                        $sheet->setCellValue('Q' . $fila, $venta->direccion->codigo_postal);
                        $sheet->setCellValue('R' . $fila, 'MX');
                        $sheet->setCellValue('S' . $fila, $venta->cliente_id);
                        $sheet->setCellValue('T' . $fila, $venta->cliente);
                        $sheet->setCellValue('U' . $fila, $venta->rfc);
                        $sheet->setCellValue('V' . $fila, $venta->direccion->calle);
                        // $sheet->setCellValue('W' . $fila, 'Zapopan');
                        $sheet->setCellValue('X' . $fila, $venta->direccion->ciudad);
                        $sheet->setCellValue('Y' . $fila, $codeHp);
                        $sheet->setCellValue('Z' . $fila, $venta->direccion->codigo_postal);
                        $sheet->setCellValue('AA' . $fila, 'MX');
                        $sheet->setCellValue('AB' . $fila, $venta->cliente_id);
                        $sheet->setCellValue('AC' . $fila, $venta->status == 1 ? 1 : -1);
                        // $sheet->setCellValue('AD' . $fila, $producto->np);
                        $sheet->getCellByColumnAndRow(30, $fila)->setValueExplicit($producto->np, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet->setCellValue('AE' . $fila, $producto->descripcion);
                        // $sheet->setCellValue('AF' . $fila, $producto->sku);
                        $sheet->getCellByColumnAndRow(32, $fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                        $fecha = DB::select("SELECT 
                        documento_pago.destino_fecha_operacion
                        FROM documento_pago_re
                        INNER JOIN documento_pago ON documento_pago_re.id_pago = documento_pago.id
                        INNER JOIN documento ON documento_pago_re.id_documento = documento.id
                        WHERE documento.id =" . $venta->id);

                        if (!empty($fecha)) {
                            $fecha = $fecha[0];
                            $fecha = str_replace(
                                '-',
                                '',
                                $fecha->destino_fecha_operacion
                            );
                            $sheet->setCellValue('AG' . $fila, $fecha);
                        }

                        $sheet->setCellValue('AH' . $fila, $venta->factura_serie . '-' . $venta->factura_folio);
                        $sheet->setCellValue('AI' . $fila, 'Y');
                        $sheet->setCellValue('AJ' . $fila, 'RR');
                        $sheet->setCellValue('AK' . $fila, 'Y');

                        $dateTime = new DateTime($venta->created_at);
                        $formattedDate = $dateTime->format('Ymd');
                        $sheet->setCellValue('AL' . $fila, $formattedDate);
                        if (!empty($producto->series)) {
                            $sheet->setCellValue('AP' . $fila, $producto->series[$aux_productos]->serie);
                        }
                        $sheet->setCellValue('BZ1', 'VENTA CRM');

                        $sheet->setCellValue('BZ' . $fila, $venta->id);


                        $fila++;
                        $aux_productos++;
                    }
                } else {
                    continue;
                }
            }
        }

        $range = 'A2:AP' . $fila;
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', 'Z') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $spreadsheet->getActiveSheet()->getColumnDimension('AA')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AB')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AC')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AD')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AE')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AF')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AG')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AH')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AI')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AJ')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AK')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AL')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('AP')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_ventas_HP.xlsx');
        $excel
            = base64_encode(file_get_contents('reporte_ventas_HP.xlsx'));
        unlink('reporte_ventas_HP.xlsx');

        return $excel;
    }
}
