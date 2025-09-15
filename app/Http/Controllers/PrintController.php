<?php

namespace App\Http\Controllers;

use Exception;
use Httpful\Request as HttpfulRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PrintController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        return $this->forwardRequest('GET', 'api/etiquetas/data', $request);
    }

    private function forwardRequest(string $method, string $endpoint, Request $request, ?array $bodyOverride = null): JsonResponse
    {
        try {
            $token = $request->get('token');
            $url = rtrim(config('webservice.printserver'), '/') . '/' . ltrim($endpoint, '/');

            if ($token) {
                $url .= '?token=' . urlencode($token);
            }
            $method = strtoupper($method);

            if ($method === 'GET') {
                $httpful = HttpfulRequest::get($url);
            } elseif ($method === 'POST') {
                $body = $bodyOverride ?? [
                    'data' => $request->input('data'),
                ];

                if ($request->has('tipo')) {
                    $body['tipo'] = $request->input('tipo');
                }

                $httpful = HttpfulRequest::post($url)
                    ->sendsJson()
                    ->body($body);
            } else {
                throw new InvalidArgumentException("Método HTTP no soportado: $method");
            }

            $response = $httpful->send();

            if ($response->code === 200) {
                return response()->json($response->body);
            }

            return response()->json([
                'error' => 'Error desde el printserver',
                'details' => $response->body,
            ], $response->code);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'No se pudo contactar con el printserver',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function etiquetas(Request $request): JsonResponse
    {
        return $this->forwardRequest('POST', 'api/etiquetas', $request);
    }

    public function serie(Request $request): JsonResponse
    {
        return $this->forwardRequest('POST', 'api/etiquetas/serie', $request);
    }

    public function busqueda(Request $request): JsonResponse
    {
        return $this->forwardRequest('POST', 'api/etiquetas/busqueda', $request);
    }

    public function print($documento, $impresora, Request $request): JsonResponse
    {
        return $this->forwardRequest('GET', "api/guias/print/" . $documento . "/" . $impresora, $request);
    }

    public function manifiestoSalida($array, Request $request): JsonResponse
    {
        return $this->forwardRequest('POST', "api/manifiesto/salida", $request, $array);
    }

    public function impresoras(): JsonResponse
    {
        $impresoras = DB::table('impresora')->where('status', 1)->where('id', '!=', 0)->select('id', 'nombre', 'tamanio')->get();
        return response()->json($impresoras);
    }
}