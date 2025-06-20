<?php

namespace App\Http\Controllers;

use Httpful\Request as HttpfulRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        return $this->forwardRequest('GET', 'api/etiquetas/data', $request);
    }

    private function forwardRequest(string $method, string $endpoint, Request $request): JsonResponse
    {
        try {
            $token = $request->get('token');
            $url = rtrim(config('webservice.printserver'), '/') . '/' . ltrim($endpoint, '/');

            if ($token) {
                $url .= '?token=' . urlencode($token);
            }

            if (strtoupper($method) === 'GET') {
                $httpful = HttpfulRequest::get($url);
            } elseif (strtoupper($method) === 'POST') {
                $httpful = HttpfulRequest::post($url)
                    ->sendsJson()
                    ->body(['data' => $request->input('data')]);
            } else {
                throw new \InvalidArgumentException("Método HTTP no soportado: $method");
            }

            $response = $httpful->send();

            if ($response->code === 200) {
                return response()->json($response->body);
            }

            return response()->json([
                'error' => 'Error desde el printserver',
                'details' => $response->body,
            ], $response->code);

        } catch (\Exception $e) {
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
}
