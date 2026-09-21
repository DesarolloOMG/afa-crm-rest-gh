<?php

namespace App\Http\Controllers;

use App\Http\Services\Nexfira\FacturacionService;
use App\Http\Services\Nexfira\NexfiraApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class FacturacionController extends Controller
{
    private $service;

    public function __construct(FacturacionService $service)
    {
        $this->service = $service;
    }

    public function pendientes(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $fulfillment = $request->has('fulfillment')
                ? filter_var($request->input('fulfillment'), FILTER_VALIDATE_BOOLEAN)
                : null;

            return [
                'code' => 200,
                'data' => $this->service->pendingDocuments($fulfillment),
            ];
        });
    }

    public function previsualizar($documento): JsonResponse
    {
        return $this->handle(function () use ($documento) {
            return [
                'code' => 200,
                'data' => $this->service->preview((int) $documento),
            ];
        });
    }

    public function individual(Request $request, $documento): JsonResponse
    {
        return $this->handle(function () use ($request, $documento) {
            $data = $this->payload($request);
            $result = $this->service->createIndividual(
                (int) $documento,
                $this->authId($request),
                $this->paymentOverrides($data)
            );

            return [
                'code' => 202,
                'message' => 'Solicitud individual enviada. La venta permanecerá en fase 5 hasta recuperar XML y PDF.',
                'request' => $result,
            ];
        });
    }

    public function global(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $data = $this->payload($request);
            $result = $this->service->createGlobal(
                isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                $this->authId($request),
                $this->paymentOverrides($data)
            );

            return [
                'code' => 202,
                'message' => 'Solicitud global enviada. Las ventas permanecerán en fase 5 hasta recuperar XML y PDF.',
                'request' => $result,
            ];
        });
    }

    public function actualizar(Request $request, $solicitud): JsonResponse
    {
        return $this->handle(function () use ($request, $solicitud) {
            $result = $this->service->sync((int) $solicitud, $this->authId($request));
            $completed = $result['status'] === 'stamped' && $result['documents_status'] === 'retrieved';

            return [
                'code' => 200,
                'message' => $completed
                    ? 'Factura recuperada y ventas movidas a fase 6.'
                    : 'Estado de Nexfira actualizado: ' . $result['status'] . '.',
                'request' => $result,
            ];
        });
    }

    public function externa(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $data = $this->payload($request);
            $result = $this->service->attachExternal(
                isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                isset($data['uuid']) ? $data['uuid'] : '',
                isset($data['pdf']) ? $data['pdf'] : '',
                isset($data['xml']) ? $data['xml'] : '',
                $this->authId($request)
            );

            return [
                'code' => 200,
                'message' => 'CFDI externo validado, relacionado y ventas movidas a fase 6.',
                'request' => $result,
            ];
        });
    }

    private function handle(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
            $status = isset($result['code']) && (int) $result['code'] === 202 ? 202 : 200;

            return response()->json($result, $status);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
            ], 422);
        } catch (NexfiraApiException $e) {
            $status = $e->getHttpStatus();
            if ($status < 400 || $status > 599) {
                $status = 502;
            }

            return response()->json([
                'code' => $status,
                'message' => $e->getMessage(),
                'nexfira_code' => $e->getApiCode(),
                'correlation_id' => $e->getCorrelationId(),
                'errors' => $e->getValidationErrors(),
            ], $status);
        } catch (Throwable $e) {
            Log::error('Facturación Nexfira: ' . $e->getMessage());

            return response()->json([
                'code' => 500,
                'message' => 'No fue posible completar la operación de facturación.',
            ], 500);
        }
    }

    private function payload(Request $request)
    {
        $data = $request->all();
        if (isset($data['data']) && is_string($data['data'])) {
            $decoded = json_decode($data['data'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($data) ? $data : [];
    }

    private function paymentOverrides(array $data)
    {
        $overrides = [];
        if (isset($data['paymentMethod']) && $data['paymentMethod'] !== '') {
            $overrides['paymentMethod'] = $data['paymentMethod'];
        }
        if (isset($data['paymentForm']) && $data['paymentForm'] !== '') {
            $overrides['paymentForm'] = $data['paymentForm'];
        }

        return $overrides;
    }

    private function authId(Request $request)
    {
        $auth = is_string($request->auth) ? json_decode($request->auth) : $request->auth;
        if (!$auth || empty($auth->id)) {
            throw new InvalidArgumentException('No se encontró el usuario autenticado.');
        }

        return (int) $auth->id;
    }
}
