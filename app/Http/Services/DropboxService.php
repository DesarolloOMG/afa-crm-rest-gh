<?php

namespace App\Http\Services;

use Exception;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;

class DropboxService
{
    protected $uploadUrl;
    protected $apiUrl;
    protected $token;

    public function __construct()
    {
        $this->uploadUrl = config("webservice.dropbox") . '2/files/upload';
        $this->apiUrl = config("webservice.dropbox_api");
        $this->token = config("keys.dropbox");
    }

    /**
     * @throws ConnectionErrorException
     * @throws Exception
     */
    public function uploadImage(string $base64, string $nombreArchivo, string $tipoMime): string
    {
        $archivo_data = base64_decode(preg_replace('#^data:' . $tipoMime . '/\w+;base64,#i', '', $base64));

        $upload = Request::post($this->uploadUrl)
            ->addHeader('Authorization', "Bearer " . $this->token)
            ->addHeader('Dropbox-API-Arg', json_encode([
                "path" => "/" . $nombreArchivo,
                "mode" => "add",
                "autorename" => true
            ]))
            ->addHeader('Content-Type', 'application/octet-stream')
            ->body($archivo_data)
            ->send();

        if (property_exists($upload->body, 'error')) {
            throw new Exception(self::logVariableLocation() . " Error al subir imagen: " . $upload->body->error_summary);
        }

        return $nombreArchivo;
    }

    private static function logVariableLocation(): string
    {
        $sis = 'BE'; //Front o Back
        $ini = 'DS'; //Primera letra del Controlador y Letra de la segunda Palabra: Controller, service
        $fin = 'BOX'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }

    /**
     * @throws ConnectionErrorException
     * @throws Exception
     */
    public function getPublicLink(string $nombreArchivo): string
    {
        $createLink = Request::post($this->apiUrl . 'sharing/create_shared_link_with_settings')
            ->addHeader('Authorization', "Bearer " . $this->token)
            ->addHeader('Content-Type', 'application/json')
            ->body(json_encode([
                "path" => "/" . $nombreArchivo,
                "settings" => ["requested_visibility" => "public"]
            ]))
            ->send();

        if (property_exists($createLink->body, 'error')) {
            if ($createLink->body->error_summary === 'shared_link_already_exists/') {
                $listLink = Request::post($this->apiUrl . 'sharing/list_shared_links')
                    ->addHeader('Authorization', "Bearer " . $this->token)
                    ->addHeader('Content-Type', 'application/json')
                    ->body(json_encode(["path" => "/" . $nombreArchivo]))
                    ->send();

                if (property_exists($listLink->body, 'error')) {
                    throw new Exception(self::logVariableLocation() . " Error al obtener link existente: " . $listLink->body->error_summary);
                }

                return substr($listLink->body->links[0]->url, 0, -4) . "raw=1";
            } else {
                throw new Exception(self::logVariableLocation() . " Error al crear link público: " . $createLink->body->error_summary);
            }
        }

        return substr($createLink->body->url, 0, -4) . "raw=1";
    }
}
