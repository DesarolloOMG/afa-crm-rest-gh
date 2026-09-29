<?php

namespace App\Http\Controllers;

use App\Http\Services\Nexfira\FacturacionService;
use App\Http\Services\Nexfira\CancelacionFacturaService;
use App\Http\Services\WhatsAppService;
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
    private $cancellation;

    public function __construct(FacturacionService $service, CancelacionFacturaService $cancellation = null)
    {
        $this->service = $service;
        $this->cancellation = $cancellation ?: app(CancelacionFacturaService::class);
    }

    public function previsualizarCancelacion(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->authorizedUserId($request);
            return ['code' => 200, 'data' => $this->cancellation->preview($request->input('folio'), $request->input('serie', ''))];
        });
    }

    public function cancelar(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $code = trim((string) ($data['auth_code'] ?? ''));
            if (!preg_match('/^[0-9]{6}$/D', $code)) {
                throw new InvalidArgumentException('Ingresa el código de seis dígitos de tu aplicación autenticadora.');
            }
            $verification = WhatsAppService::validateCode($userId, $code);
            if ($verification->error) {
                throw new AuthorizationException(strip_tags($verification->mensaje));
            }
            return ['code' => 202, 'message' => 'Cancelación solicitada. Las ventas permanecen facturadas hasta su aprobación.',
                'data' => $this->cancellation->request($data['folio'] ?? '', $data['serie'] ?? '',
                    $data['motivo'] ?? '', $data['uuid_sustitucion'] ?? '', $userId)];
        });
    }

    public function actualizarCancelacion(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            return ['code' => 200, 'data' => $this->cancellation->refresh($data['folio'] ?? '', $data['serie'] ?? '', $userId)];
        });
    }

    public function pendientes(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->authorizedUserId($request);
            $fulfillment = $request->has('fulfillment')
                ? filter_var($request->input('fulfillment'), FILTER_VALIDATE_BOOLEAN)
                : null;
            $page = max(1, (int) $request->input('page', 1));
            $perPage = (int) $request->input('per_page', 25);
            $search = trim((string) $request->input('search', ''));

            return [
                'code' => 200,
                'data' => $this->service->pendingDocuments($fulfillment, $page, $perPage, $search, (int) $request->input('document_type', 2)),
            ];
        });
    }

    public function seleccion(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->authorizedUserId($request);
            $data = $this->payload($request);
            $fulfillment = array_key_exists('fulfillment', $data)
                ? filter_var($data['fulfillment'], FILTER_VALIDATE_BOOLEAN)
                : null;

            return [
                'code' => 200,
                'data' => $this->service->pendingDocumentsByIds(
                    isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                    $fulfillment,
                    (int) ($data['document_type'] ?? 2)
                ),
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

    public function revisar(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->authorizedUserId($request);
            $data = $this->payload($request);
            $overrides = $this->invoiceOverrides($data);
            if (isset($data['informacionGlobal'])) {
                if (!is_array($data['informacionGlobal'])) { throw new InvalidArgumentException('La información global debe ser un objeto.'); }
                $overrides['globalInformation'] = $data['informacionGlobal'];
            }
            $ids = isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [];
            $review = ($data['modo'] ?? '') === 'global'
                ? $this->service->reviewGlobal($ids, $data['agrupacion'] ?? 'ventas', $data['informacionGlobal'] ?? [], $overrides)
                : $this->service->reviewIndividual((int) ($ids[0] ?? 0), $overrides);
            return ['code' => 200, 'data' => $review];
        });
    }

    public function editarImporte(Request $request, $documento, $partida): JsonResponse
    {
        return $this->handle(function () use ($request, $documento, $partida) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            return ['code' => 200, 'data' => $this->service->updateDraftLine((int) $documento, (int) $partida,
                $data['precio'] ?? null, $data['descuento'] ?? null, $userId)];
        });
    }

    public function individual(Request $request, $documento): JsonResponse
    {
        return $this->handle(function () use ($request, $documento) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $overrides = $this->invoiceOverrides($data);
            if (isset($data['review_hash'])) { $overrides['reviewHash'] = $data['review_hash']; }
            if (array_key_exists('informacionGlobal', $data)) {
                if (!is_array($data['informacionGlobal'])) {
                    throw new InvalidArgumentException('La información global debe ser un objeto con periodicidad, mes y año.');
                }
                $overrides['globalInformation'] = $data['informacionGlobal'];
            }
            $result = $this->service->createIndividual(
                (int) $documento,
                $userId,
                $overrides
            );

            return [
                'code' => 202,
                'message' => 'Solicitud individual enviada con serie ' . $result['series']
                    . ' y folio ' . $result['folio']
                    . '. El documento quedará pendiente de timbrado hasta recuperar UUID, XML y PDF.',
                'request' => $result,
            ];
        });
    }

    public function global(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $userId = $this->authorizedUserId($request);
            $data = $this->payload($request);
            $overrides = $this->invoiceOverrides($data);
            if (isset($data['review_hash'])) { $overrides['reviewHash'] = $data['review_hash']; }
            $result = $this->service->createGlobal(
                isset($data['documentos']) && is_array($data['documentos']) ? $data['documentos'] : [],
                $userId,
                isset($data['agrupacion']) ? $data['agrupacion'] : 'ventas',
                isset($data['informacionGlobal']) && is_array($data['informacionGlobal'])
                    ? $data['informacionGlobal']
                    : [],
                $overrides
            );

            return [
                'code' => 202,
                'message' => 'Solicitud global enviada con serie ' . $result['series']
                    . ' y folio ' . $result['folio']
                    . '. Las ventas permanecerán en fase 5 hasta recuperar XML y PDF.',
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
                    ? 'CFDI timbrado; folio, UUID, XML y PDF guardados en los documentos relacionados.'
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
                'message' => 'CFDI externo timbrado; serie ' . $result['series']
                    . ', folio ' . $result['folio']
                    . ', UUID, XML y PDF guardados en los documentos seleccionados.',
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

    private function invoiceOverrides(array $data)
    {
        $overrides = [];
        foreach (['series', 'folio'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $overrides[$field] = $data[$field];
            }
        }
        if (isset($data['relationshipCode'])) {
            $overrides['relationshipCode'] = $data['relationshipCode'];
        }
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
