<?php

namespace App\Http\Services\Venta\Ventas;

use App\Http\Services\DropboxService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VentaVentasService
{
    protected $dropboxService;

    public function __construct(DropboxService $dropboxService)
    {
        $this->dropboxService = $dropboxService;
    }

    public function relacionar_pdf_xml($data, $auth): JsonResponse
    {
        if (!$this->datosCompletos($data)) {
            return response()->json([
                'code' => 400,
                'message' => 'Datos incompletos para procesar la solicitud.'
            ]);
        }

        $idDocumento = $data->documento;
        $uuid = strtoupper($data->uuid);
        $pdfBase64 = $data->pdf;
        $xmlBase64 = $data->xml;

        try {
            DB::beginTransaction();

            $relacion = DB::table('documento_factura')->where('id_documento', $idDocumento)->first();
            if ($relacion) {
                DB::table('documento_factura')->where('id_documento', $idDocumento)->update(['updated_by' => $auth->id]);
                $relacionId = $relacion->id;
            } else {
                $relacionId = DB::table('documento_factura')->insertGetId([
                    'id_documento' => $idDocumento,
                    'updated_by' => $auth->id,
                ]);
            }

            $pdfContent = $this->decodeBase64File($pdfBase64, 'pdf');
            $xmlContent = $this->decodeBase64File($xmlBase64, 'xml');

            $responsePDF = $this->dropboxService->uploadFile('/pdf_' . $uuid . '.pdf', $pdfContent, false);
            $responseXML = $this->dropboxService->uploadFile('/xml_' . $uuid . '.xml', $xmlContent, false);

            DB::table('documento_factura')->where('id', $relacionId)->update([
                'pdf' => $responsePDF['id'],
                'xml' => $responseXML['id']
            ]);

            DB::table('documento')->where('id', $idDocumento)->update(['uuid' => $uuid]);
            DB::table('documento_updates_by')->insert([
                'id_documento' => $idDocumento,
                'id_usuario' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'code' => 200,
                'message' => 'Archivos relacionados correctamente'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'message' => "No fue posible subir los archivos a dropbox, Proceso cancelado, favor de contactar a un administrador. "
                    . self::logVariableLocation() .
                    " Mensaje de error: " . $e->getMessage()
            ]);
        }
    }

    public function descargar_pdf_xml($type, $document): JsonResponse
    {
        $relacion = DB::table('documento_factura')
            ->where('id_documento', $document)
            ->first();

        $archivo = $this->dropboxService->getTemporaryLink($relacion->{$type});
        return response()->json($archivo['link'] ?? null);
    }

    private function datosCompletos($data): bool
    {
        return !empty($data->documento)
            && !empty($data->uuid)
            && !empty($data->pdf)
            && !empty($data->xml);
    }

    private function decodeBase64File(string $file, string $type): string
    {
        $prefixes = [
            'pdf' => '/^data:application\/pdf;base64,/',
            'xml' => '/^data:text\/xml;base64,/',
        ];
        $cleaned = preg_replace($prefixes[$type] ?? '/^data:.*;base64,/', '', $file);
        return base64_decode($cleaned);
    }

    private static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'VS'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'TAS'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }
}