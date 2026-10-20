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
        return self::validateGuideResponse(
            $this->forwardRequest('GET', "api/guias/print/" . $documento . "/" . $impresora, $request)
        );
    }

    public static function validateGuideResponse(JsonResponse $response): JsonResponse
    {
        $body = $response->getData();
        $httpStatus = $response->getStatusCode();

        // A successful HTTP request can still contain a printserver error.
        if ($httpStatus === 200 && is_object($body) && isset($body->code) && (string)$body->code === '200') {
            return $response;
        }

        $message = 'El servidor de impresión devolvió una respuesta inválida.';
        if (is_object($body)) {
            $details = isset($body->details) && is_object($body->details) ? $body->details : $body;
            foreach ([$details->message ?? null, $details->error ?? null, $body->message ?? null, $body->error ?? null] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $message = $candidate;
                    break;
                }
            }
        }

        // Connection exceptions may include the authenticated URL.
        $message = preg_replace('/([?&]token=)[^&\s\"<>]+/i', '$1[REDACTED]', $message);
        $code = is_object($body) && isset($body->code) && is_numeric($body->code) ? (int)$body->code : 0;
        if ($code < 400 || $code > 599) {
            $code = $httpStatus >= 400 && $httpStatus <= 599 ? $httpStatus : 502;
        }

        return new JsonResponse(['code' => $code, 'message' => $message], $code);
    }

    public function pickingDocumento($documento, $usuario, Request $request): JsonResponse
    {
        return $this->forwardRequest('POST', 'api/picking/documento', $request, [
            'documento' => $documento,
            'usuario' => $usuario
        ]);
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
