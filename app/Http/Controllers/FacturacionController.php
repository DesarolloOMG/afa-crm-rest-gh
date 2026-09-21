<?php

namespace App\Http\Controllers;

use App\Http\Services\Nexfira\FacturacionService;
use App\Http\Services\Nexfira\NexfiraApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class FacturacionController extends Controller
{
    private const ACCOUNTING_LEVEL_ID = 11;
    private const BILLING_SUBLEVEL_ID = 36;

    private $service;

    public function __construct(FacturacionService $service)
    {
        $this->service = $service;
    }

    public function pendientes(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->authorizedUserId($request);
            $fulfillment = $request->has('fulfillment')
                ? filter_var($request->input('fulfillment'), FILTER_VALIDATE_BOOLEAN)
                : null;

            return [
                'code' => 200,
                'data' => $this->service->pendingDocuments($fulfillment),
            ];
        });
    }

    public function previsualizar(Request $request, $documento): JsonResponse
    {
        return $this->handle(function () use ($request, $documento) {
            $this->authorizedUserId($request);
            return [
                'code' => 200,
                'data' => $this->service->preview((int) $documento),
            ];
        });
    }

    public function individual(Request $request, $documento): JsonResponse
    {
        return $this->handle(function () use ($request, $documento) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $result = $this->service->createIndividual(
                (int) $documento,
                $userId,
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
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $result = $this->service->createGlobal(
                isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                $userId,
                isset($data['agrupacion']) ? $data['agrupacion'] : 'ventas'
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
            $userId = $this->authorizedUserId($request);
            $result = $this->service->sync((int) $solicitud, $userId);
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
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $result = $this->service->attachExternal(
                isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                isset($data['uuid']) ? $data['uuid'] : '',
                isset($data['pdf']) ? $data['pdf'] : '',
                isset($data['xml']) ? $data['xml'] : '',
                $userId
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
        } catch (AuthorizationException $e) {
            return response()->json([
                'code' => 403,
                'message' => $e->getMessage(),
            ], 403);
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

    private function authorizedUserId(Request $request)
    {
        $userId = $this->authId($request);
        $authorized = DB::table('usuario_subnivel_nivel as usn')
            ->join('subnivel_nivel as snn', 'snn.id', '=', 'usn.id_subnivel_nivel')
            ->join('subnivel as sn', 'sn.id', '=', 'snn.id_subnivel')
            ->where('usn.id_usuario', $userId)
            ->where('snn.id_nivel', self::ACCOUNTING_LEVEL_ID)
            ->where('snn.id_subnivel', self::BILLING_SUBLEVEL_ID)
            ->where('sn.status', 1)
            ->exists();

        if (!$authorized) {
            throw new AuthorizationException(
                'No tienes el permiso de Contabilidad: Facturación y Timbrado.'
            );
        }

        return $userId;
    }
}
