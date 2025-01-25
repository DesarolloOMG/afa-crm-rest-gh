<?php

namespace App\Http\Controllers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Http\Request;
use Crabbly\FPDF\FPDF;
use Mailgun\Mailgun;
use Exception;
use Validator;
use MP;
use DB;

class ReporteController extends Controller
{
    public function reporte_ftp_arome_inventario()
    {
        set_time_limit(0);

        # Existencia de todos los productos en el almacén de arome
        $productos = @json_decode(file_get_contents('http://201.7.208.53:11903/api/adminpro/6/Reporte/Productos/Existencia/Almacen/7'));

        if (empty($productos)) {
            return 0;
        }


        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $contador_fila  = 2;

        # Cabecera
        $sheet->setCellValue('A1', 'Código');
        $sheet->setCellValue('B1', 'Descripción');
        $sheet->setCellValue('C1', 'Último costo');
        $sheet->setCellValue('D1', 'Disponible');
        $sheet->setCellValue('E1', 'Existencia');

        foreach ($productos as $index => $producto) {

            if ($producto->existencias->total_fisico > 0) {

                $almacenes      = array();
                $disponible     = "";

                foreach ($producto->existencias->almacenes as $index_producto => $almacen) {
                    if ($almacen->almacenid == 17 || $almacen->almacenid == 0) {
                        unset($producto->existencias->almacenes[$index_producto]);

                        continue;
                    }

                    $pendientes_surtir = DB::select("SELECT
                                                    IFNULL(SUM(movimiento.cantidad), 0) as cantidad
                                                FROM documento
                                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE modelo.sku = '" . $producto->sku . "'
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
                                                        WHERE modelo.sku = '" . $producto->sku . "'
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
                                                AND modelo.sku = '" . $producto->sku . "'
                                                AND empresa_almacen.id_erp = " . $almacen->almacenid . "
                                                AND documento.id_fase = 89");

                    $total_pendientes = 0;

                    foreach ($pendientes_recibir as $pendiente) {
                        if ($pendiente->serie) {
                            $total_pendientes += $pendiente->cantidad - $pendiente->recepcionadas;
                        } else {
                            $total_pendientes += ($pendiente->completa) ? 0 : $pendiente->cantidad;
                        }
                    }

                    $almacen->pendientes_surtir = (int) $pendientes_surtir;
                    $almacen->pendientes_recibir = (int) $total_pendientes;
                    $almacen->pendientes_pretransferencia = (int) $pendientes_pretransferencia;

                    array_push($almacenes, $almacen);
                    $disponible .= ($almacen->fisico - $pendientes_surtir - $total_pendientes) . "\n";
                }

                if (empty($producto->existencias->almacenes)) {
                    $disponible  = "Sin existencia";
                } else {
                    $disponible = substr($disponible, 0, -1);
                }

                # Excel
                $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($producto->sku, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $contador_fila, $producto->producto);
                $sheet->setCellValue('C' . $contador_fila, $producto->ultimo_costo);
                $sheet->setCellValue('D' . $contador_fila, $disponible);
                $sheet->setCellValue('E' . $contador_fila, $producto->existencias->total_fisico);


                $spreadsheet->getActiveSheet()->getStyle("C" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "0"??_);_(@_)');

                $contador_fila++;

                $producto->ultimo_costo             = ROUND($producto->ultimo_costo, 2);
                $producto->existencias->almacenes   = $almacenes;
            }
        }

        $sheet->getStyle('A:E')->getAlignment()->setHorizontal('center'); # Texto centrado

        $spreadsheet->getActiveSheet()->getStyle('A1:D1')->getFont()
            ->setBold(1) # Cabecera en negritas
            ->getColor()
            ->setARGB('2B28F6'); # La cabecera de color azul

        foreach (range('A', 'E') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }
        $primera = 1;
        $ultima = $contador_fila - 1;

        $spreadsheet->getActiveSheet()->setAutoFilter("A" . $primera . ":E" . $ultima);

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save('reportes_existencia_arome.xlsx');

            $json['code'] = 200;
            $json['excel'] = base64_encode(file_get_contents('reportes_existencia_arome.xlsx'));
            $json['nombre'] = 'reportes_existencia_arome.xlsx';

            $reporte_excel = fopen('reportes_existencia_arome.xlsx', 'r+');

            app('filesystem')->disk('ftp-arome')->put('Arome.xlsx', $reporte_excel);
            fclose($reporte_excel);

            unlink('reportes_existencia_arome.xlsx');

            return response()->json($json);
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function reporte_ftp_cliente_inventario()
    {
        $productos = @json_decode(file_get_contents("http://201.7.208.53:11903/api/WSCyberPuerta/Productos"));

        if (!is_array($productos)) {
            throw new Exception("No se pudieron obtenerlos productos del WS.");
        }

        $clientes = DB::select("SELECT
                                    documento_entidad_ftp.id,
                                    documento_entidad_ftp.ftp,
                                    documento_entidad.razon_social
                                FROM documento_entidad_ftp
                                INNER JOIN documento_entidad ON documento_entidad_ftp.id_entidad = documento_entidad.id
                                WHERE documento_entidad_ftp.status = 1
                                AND documento_entidad_ftp.ftp != 'N/A'");

        foreach ($clientes as $cliente) {
            $file_name = mb_strtolower(preg_replace('/\s+/', '_', $cliente->razon_social), 'UTF-8') . '.csv';
            // create a file pointer connected to the output stream
            $output = fopen($file_name, 'w');

            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // output the column headings
            fputcsv($output, array(
                "Número de artículo Mayorista",
                "Número de artículo Fabricante",
                "Nombre del producto",
                "Descripción del producto",
                "Marca del producto",
                "Precio Regular de venta a Cyberpuerta",
                "Moneda",
                "Disponibilidad GDL",
                "Disponibilidad  MX",
                "Refurbished"
            ));

            foreach ($productos as $producto) {
                $producto_tiene_margen = DB::select("SELECT
                                                        documento_entidad_modelo_margen.precio
                                                    FROM documento_entidad_modelo_margen
                                                    INNER JOIN modelo ON documento_entidad_modelo_margen.id_modelo = modelo.id
                                                    WHERE modelo.sku = '" . $producto->sku . "'
                                                    AND documento_entidad_modelo_margen.id_ftp = '" . $cliente->id . "'");

                if (empty($producto_tiene_margen)) {
                    continue;
                }

                $total_pendientes = DB::select("SELECT
                                                    IFNULL(SUM(movimiento.cantidad), 0) AS total
                                                FROM movimiento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                INNER JOIN documento ON movimiento.id_documento = documento.id
                                                WHERE modelo.sku = '" . $producto->sku . "'
                                                AND documento.id_almacen_principal_empresa = 2
                                                AND documento.status = 1
                                                AND documento.id_tipo = 2
                                                AND documento.anticipada = 0
                                                AND documento.id_fase < 6")[0]->total;

                $pendientes_pretransferencia = DB::select("SELECT
                                                                IFNULL(SUM(movimiento.cantidad), 0) AS cantidad
                                                            FROM documento
                                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                            WHERE modelo.sku = '" . $producto->sku . "'
                                                            AND documento.id_almacen_secundario_empresa = 2
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
                                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                                WHERE documento.id_tipo = 1
                                                AND documento.status = 1
                                                AND modelo.sku = '" . $producto->sku . "'
                                                AND documento.id_almacen_principal_empresa = 2
                                                AND documento.id_fase = 89");

                $total_pendientes_recibir = 0;

                foreach ($pendientes_recibir as $pendiente) {
                    if ($pendiente->serie) {
                        $total_pendientes_recibir += $pendiente->cantidad - $pendiente->recepcionadas;
                    } else {
                        $total_pendientes_recibir += ($pendiente->completa) ? 0 : $pendiente->cantidad;
                    }
                }

                $total_pendientes += $total_pendientes_recibir;
                $total_pendientes += $pendientes_pretransferencia;

                $producto->disponibilidad -= $total_pendientes;

                $producto->precio = round($producto_tiene_margen[0]->precio, 2);

                fputcsv($output, array(
                    $producto->sku,
                    $producto->numero_parte,
                    $producto->producto,
                    $producto->descripcion,
                    $producto->marca,
                    $producto->precio / 1.16,
                    $producto->moneda,
                    ($producto->disponibilidad < 0) ? 0 : $producto->disponibilidad,
                    0,
                    $producto->refurbished
                ));
            }

            fclose($output);

            rename($file_name, "/home/" . $cliente->ftp . "/ftp/files/inventario.csv");
        }

        return 1;
    }

    public function rawinfo_contabilidad_encuesta()
    {
        set_time_limit(0);

        $encuestas = DB::select("SELECT data FROM encuesta");
        $preguntas_entorno = json_decode($encuestas[0]->data)->entorno;
        $preguntas_liderazgo = json_decode($encuestas[0]->data)->liderazgo->preguntas;
        $preguntas_traumatico = json_decode($encuestas[0]->data)->traumatico;

        $spreadsheet    = new Spreadsheet();
        $sheet          = $spreadsheet->getActiveSheet();
        $spreadsheet->getActiveSheet()->setTitle('ENCUESTAS');

        $contador_fila  = 1;

        foreach ($encuestas as $encuesta) {
            $data = json_decode($encuesta->data);

            foreach ($data->entorno as $index => $entorno) {
                $respuesta = str_replace(" ", "", strtolower($entorno->respuesta));

                if (property_exists($preguntas_entorno[$index], $respuesta)) {
                    $preguntas_entorno[$index]->$respuesta += 1;

                    continue;
                }

                $preguntas_entorno[$index]->$respuesta = new \stdClass();
                $preguntas_entorno[$index]->$respuesta = 1;
            }

            foreach ($data->liderazgo->preguntas as $index => $liderazgo) {
                $respuesta = str_replace(" ", "", strtolower($liderazgo->respuesta));

                if (property_exists($preguntas_liderazgo[$index], $respuesta)) {
                    $preguntas_liderazgo[$index]->$respuesta += 1;

                    continue;
                }

                $preguntas_liderazgo[$index]->$respuesta = new \stdClass();
                $preguntas_liderazgo[$index]->$respuesta = 1;
            }

            foreach ($data->traumatico as $index => $traumatico) {
                $respuesta = empty(str_replace(" ", "", strtolower($traumatico->respuesta))) ? 'vacio' : utf8_encode(str_replace(" ", "", strtolower($traumatico->respuesta)));
                $respuesta = preg_replace("/[^a-zA-Z0-9\_\-]+/", "", $respuesta);

                if (property_exists($preguntas_traumatico[$index], $respuesta)) {
                    $preguntas_traumatico[$index]->$respuesta += 1;

                    continue;
                }

                $preguntas_traumatico[$index]->$respuesta = new \stdClass();
                $preguntas_traumatico[$index]->$respuesta = 1;
            }
        }

        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica
        $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":F" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('2BCBB1');

        $sheet->setCellValue('A' . $contador_fila, 'ENTORNO ORGANIZACIONAL');
        $sheet->setCellValue('B' . $contador_fila, 'SIEMPRE');
        $sheet->setCellValue('C' . $contador_fila, 'CASI SIEMPRE');
        $sheet->setCellValue('D' . $contador_fila, 'ALGUNAS VECES');
        $sheet->setCellValue('E' . $contador_fila, 'CASI NUNCA');
        $sheet->setCellValue('F' . $contador_fila, 'NUNCA');

        $contador_fila++;

        foreach ($preguntas_entorno as $index => $pregunta) {
            $sheet->setCellValue('A' . $contador_fila,  $pregunta->pregunta);
            $sheet->setCellValue('B' . $contador_fila, property_exists($pregunta, 'siempre') ? $pregunta->siempre : 0);
            $sheet->setCellValue('C' . $contador_fila, property_exists($pregunta, 'casisiempre') ? $pregunta->casisiempre : 0);
            $sheet->setCellValue('D' . $contador_fila, property_exists($pregunta, 'algunasveces') ? $pregunta->algunasveces : 0);
            $sheet->setCellValue('E' . $contador_fila, property_exists($pregunta, 'casinunca') ? $pregunta->casinunca : 0);
            $sheet->setCellValue('F' . $contador_fila, property_exists($pregunta, 'nunca') ? $pregunta->nunca : 0);

            $contador_fila++;
        }

        $contador_fila++;

        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica
        $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":F" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('2BCBB1');

        $sheet->setCellValue('A' . $contador_fila, 'PERCEPCION DE CLIMA LABORAL');
        $sheet->setCellValue('B' . $contador_fila, 'SIEMPRE');
        $sheet->setCellValue('C' . $contador_fila, 'CASI SIEMPRE');
        $sheet->setCellValue('D' . $contador_fila, 'ALGUNAS VECES');
        $sheet->setCellValue('E' . $contador_fila, 'CASI NUNCA');
        $sheet->setCellValue('F' . $contador_fila, 'NUNCA');

        $contador_fila++;

        foreach ($preguntas_liderazgo as $index => $pregunta) {
            $sheet->setCellValue('A' . $contador_fila,  $pregunta->pregunta);
            $sheet->setCellValue('B' . $contador_fila, property_exists($pregunta, 'siempre') ? $pregunta->siempre : 0);
            $sheet->setCellValue('C' . $contador_fila, property_exists($pregunta, 'casisiempre') ? $pregunta->casisiempre : 0);
            $sheet->setCellValue('D' . $contador_fila, property_exists($pregunta, 'algunasveces') ? $pregunta->algunasveces : 0);
            $sheet->setCellValue('E' . $contador_fila, property_exists($pregunta, 'casinunca') ? $pregunta->casinunca : 0);
            $sheet->setCellValue('F' . $contador_fila, property_exists($pregunta, 'nunca') ? $pregunta->nunca : 0);

            $contador_fila++;
        }

        $contador_fila++;

        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A' . $contador_fila . ':F' . $contador_fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); # Alineación centrica
        $spreadsheet->getActiveSheet()->getStyle("A" . $contador_fila . ":F" . $contador_fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('2BCBB1');

        $sheet->setCellValue('A' . $contador_fila, 'ACONTECIMIENTO TRAUMATICO SEVERO');
        $sheet->setCellValue('B' . $contador_fila, 'SÍ');
        $sheet->setCellValue('C' . $contador_fila, 'NO');
        $sheet->setCellValue('D' . $contador_fila, 'SIN RESPUESTA');

        $contador_fila++;

        foreach ($preguntas_traumatico as $index => $pregunta) {
            $sheet->setCellValue('A' . $contador_fila,  $pregunta->pregunta);
            $sheet->setCellValue('B' . $contador_fila, property_exists($pregunta, 's') ? $pregunta->s : 0);
            $sheet->setCellValue('C' . $contador_fila, property_exists($pregunta, 'no') ? $pregunta->no : 0);
            $sheet->setCellValue('D' . $contador_fila, property_exists($pregunta, 'vacio') ? $pregunta->vacio : 0);

            $contador_fila++;
        }

        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save('encuestas.xlsx');
    }

    private function token($app_id, $secret_key)
    {
        $mp = new MP($app_id, $secret_key);
        $access_token = $mp->get_access_token();

        return $access_token;
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
}
