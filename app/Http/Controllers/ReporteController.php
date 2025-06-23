<?php

namespace App\Http\Controllers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Http\Request;
use Crabbly\Fpdf\Fpdf;
use Mailgun\Mailgun;
use Exception;
use Validator;
use MP;
use DB;

class ReporteController extends Controller
{
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
