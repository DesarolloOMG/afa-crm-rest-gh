<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Http\Services\DropboxService;

class DropboxController extends Controller
{
    protected $dropbox;

    public function __construct(DropboxService $dropbox)
    {
        $this->dropbox = $dropbox;
    }

    public function actualizarTokenDropbox()
    {
        set_time_limit(0);
        $dropbox = new DropboxService();
        try {
            $token = $dropbox->refreshAccessToken();

            return response()->json(['code' => 200, 'token' => $token]);
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'error' => $e->getMessage()]);
        }
    }

    public function getDropboxToken()
    {
        $dropbox = new DropboxService();
        $dropbox->ensureValidToken();
        $token = config('keys.dropbox');
        return response()->json(['token' => $token]);
    }

    /**
     * Obtener link temporal para previsualizar/descargar
     */
    public function getTemporaryLink(Request $request)
    {
        $path = $request->input('path');
        if (!$path) {
            return response()->json(['error' => true, 'message' => 'Parámetro "path" es requerido'], 400);
        }
        $result = $this->dropbox->getTemporaryLink($path);
        return response()->json($result);
    }

    /**
     * Descargar archivo desde Dropbox (retorna base64)
     */
    public function downloadFile(Request $request)
    {
        $path = $request->input('path');
        if (!$path) {
            return response()->json(['error' => true, 'message' => 'Parámetro "path" es requerido'], 400);
        }
        $file = $this->dropbox->downloadFile($path);

        if ($file === null) {
            return response()->json([
                'error' => true,
                'message' => 'No se pudo descargar el archivo de Dropbox'
            ], 500);
        }

        return response()->json(['file' => base64_encode($file)]);
    }

    /**
     * Eliminar archivo en Dropbox
     */
    public function deleteFile(Request $request)
    {
        $path = $request->input('path');
        if (!$path) {
            return response()->json(['error' => true, 'message' => 'Parámetro "path" es requerido'], 400);
        }
        $result = $this->dropbox->deleteFile($path);
        return response()->json($result);
    }

    /**
     * Subir archivo a Dropbox
     * Requiere: path (ruta/nombre) y file (base64)
     */
    public function uploadFile(Request $request)
    {
        $path = $request->input('path');
        $fileContent = $request->input('file');

        if (!$path || !$fileContent) {
            return response()->json(['error' => true, 'message' => 'Parámetros "path" y "file" son requeridos'], 400);
        }

        $result = $this->dropbox->uploadFile($path, $fileContent, true);

        if (isset($result['error']) && $result['error']) {
            return response()->json($result, 500);
        }

        return response()->json($result);
    }

    private static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'AS'; //Primera letra del Controlador y Letra de la segunda Palabra: Controller, service
        $fin = 'UTH'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }
}