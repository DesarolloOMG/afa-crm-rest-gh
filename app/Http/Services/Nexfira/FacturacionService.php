<?php

namespace App\Http\Services\Nexfira;

use App\Http\Services\DropboxService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class FacturacionService
{
    private $client;
    private $builder;
    private $dropbox;
    private $validator;

    public function __construct(
        NexfiraClient $client,
        InvoicePayloadBuilder $builder,
        DropboxService $dropbox,
        CfdiAttachmentValidator $validator
    ) {
        $this->client = $client;
        $this->builder = $builder;
        $this->dropbox = $dropbox;
        $this->validator = $validator;
    }

    public function pendingDocuments($fulfillment = null)
    {
        $query = DB::table('documento as d')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('marketplace as mk', 'mk.id', '=', 'ma.id_marketplace')
            ->leftJoin('documento_entidad as de', 'de.id', '=', 'd.id_entidad')
            ->where('d.id_tipo', 2)
            ->where('d.id_fase', 5)
            ->where('d.status', 1)
            ->whereNull('d.deleted_at')
            ->orderBy('d.id', 'desc')
            ->limit(500)
            ->select([
                'd.id', 'd.no_venta', 'd.fulfillment', 'd.total', 'd.created_at', 'd.uuid',
                'mk.marketplace', 'ma.publico', 'de.razon_social', 'de.rfc',
            ]);

        if ($fulfillment !== null) {
            $query->where('d.fulfillment', (int) ((bool) $fulfillment));
        }

        $documents = $query->get();
        $requestMap = $this->latestRequestsForDocuments($documents->pluck('id')->toArray());
        $items = [];
        foreach ($documents as $document) {
            $alreadyInvoiced = $this->hasExistingInvoice($document);
            $requiresExternal = (int) $document->fulfillment === 1
                && strtoupper((string) $document->marketplace) === 'MERCADOLIBRE';
            if ($alreadyInvoiced) {
                $preview = [
                    'valid' => false,
                    'blockers' => ['La venta ya está facturada con UUID ' . strtoupper((string) $document->uuid) . '.'],
                ];
            } elseif ($requiresExternal) {
                $preview = [
                    'valid' => false,
                    'blockers' => ['Las ventas FULL de Mercado Libre se facturan fuera del Hub.'],
                ];
            } else {
                $preview = $this->builder->preview($document->id);
            }

            $items[] = [
                'id' => (int) $document->id,
                'folio' => $document->no_venta,
                'marketplace' => $document->marketplace,
                'fulfillment' => (bool) $document->fulfillment,
                'tipo_logistica' => (int) $document->fulfillment === 1 ? 'FULL' : 'DROP',
                'total' => $document->total === null ? null : (float) $document->total,
                'created_at' => $document->created_at,
                'cliente' => $document->razon_social,
                'rfc' => $document->rfc,
                'already_invoiced' => $alreadyInvoiced,
                'requires_external' => $requiresExternal,
                'can_hub' => !$alreadyInvoiced && !$requiresExternal && $preview['valid'],
                'blockers' => $preview['blockers'],
                'request' => isset($requestMap[$document->id]) ? $requestMap[$document->id] : null,
            ];
        }

        return [
            'documents' => $items,
            'counts' => [
                'drop' => $this->countPendingByFulfillment(0),
                'full' => $this->countPendingByFulfillment(1),
            ],
            'configured' => $this->isConfigured(),
        ];
    }

    public function preview($documentId)
    {
        $summary = $this->saleSummary($documentId);
        if ($this->hasExistingInvoice($summary)) {
            return [
                'valid' => false,
                'blockers' => ['La venta ya está facturada con UUID ' . strtoupper((string) $summary->uuid) . '.'],
                'payload' => null,
            ];
        }
        if ($this->isMeliFull($summary)) {
            return [
                'valid' => false,
                'blockers' => ['Las ventas FULL de Mercado Libre deben cargar el CFDI emitido por Mercado Libre.'],
                'payload' => null,
            ];
        }

        return $this->builder->preview($documentId);
    }

    public function createIndividual($documentId, $userId, array $overrides = [])
    {
        $summary = $this->saleSummary($documentId);
        $this->assertNotAlreadyInvoiced($summary);
        if ($this->isMeliFull($summary)) {
            throw new InvalidArgumentException('Las ventas FULL de Mercado Libre no se envían a Nexfira.');
        }

        $existing = $this->activeRequestForDocuments([(int) $documentId]);
        if ($existing) {
            if (in_array($existing->status, ['sending', 'uncertain'], true) && !$existing->remote_request_id) {
                return $this->resumeSubmission($existing);
            }

            return $this->publicRequest($existing);
        }

        $attempt = $this->nextAttempt([(int) $documentId]);
        $externalReference = 'afa-' . (int) $documentId . '-v' . $attempt;
        $idempotencyKey = 'afa-individual-' . (int) $documentId . '-v' . $attempt;
        $payload = $this->builder->buildIndividual($documentId, $externalReference, $overrides);

        return $this->submit('individual', [(int) $documentId], $payload, $externalReference, $idempotencyKey, $userId);
    }

    public function createGlobal(
        array $documentIds,
        $userId,
        $grouping = InvoicePayloadBuilder::GLOBAL_GROUP_SALES,
        array $globalInformation = []
    )
    {
        $grouping = strtolower(trim((string) $grouping));
        if (!in_array($grouping, [
            InvoicePayloadBuilder::GLOBAL_GROUP_SALES,
            InvoicePayloadBuilder::GLOBAL_GROUP_PRODUCTS,
        ], true)) {
            throw new InvalidArgumentException('La agrupación global debe ser por ventas o por productos.');
        }

        $documentIds = $this->normalizeDocumentIds($documentIds, 2);
        foreach ($documentIds as $documentId) {
            $summary = $this->saleSummary($documentId);
            $this->assertNotAlreadyInvoiced($summary);
            if ($this->isMeliFull($summary)) {
                throw new InvalidArgumentException('Las ventas FULL de Mercado Libre no pueden incluirse en una factura global del Hub.');
            }
        }

        $existing = $this->activeRequestForDocuments($documentIds);
        if ($existing) {
            throw new InvalidArgumentException(
                'La venta ' . $this->firstLinkedDocument($existing->id) . ' ya tiene una solicitud de facturación activa.'
            );
        }

        $attempt = $this->nextAttempt($documentIds);
        $groupHash = substr(hash('sha256', $grouping . ':' . implode('-', $documentIds)), 0, 20);
        $externalReference = 'afa-global-' . $grouping . '-' . $groupHash . '-v' . $attempt;
        $idempotencyKey = 'afa-global-' . $grouping . '-' . $groupHash . '-v' . $attempt;
        $payload = $this->builder->buildGlobal(
            $documentIds,
            $externalReference,
            $grouping,
            $globalInformation
        );
        $requestMode = $grouping === InvoicePayloadBuilder::GLOBAL_GROUP_PRODUCTS
            ? 'global_productos'
            : 'global_ventas';

        return $this->submit($requestMode, $documentIds, $payload, $externalReference, $idempotencyKey, $userId);
    }

    public function sync($requestId, $userId)
    {
        $request = DB::table('facturacion_solicitud')->where('id', (int) $requestId)->first();
        if (!$request) {
            throw new InvalidArgumentException('No se encontró la solicitud de facturación.');
        }
        if ($request->proveedor !== 'nexfira') {
            return $this->publicRequest($request);
        }
        if (!$request->remote_request_id) {
            if (in_array($request->status, ['sending', 'uncertain'], true)) {
                return $this->resumeSubmission($request);
            }
            throw new InvalidArgumentException('La solicitud no tiene identificador remoto de Nexfira.');
        }

        try {
            $remote = $this->client->getDocumentRequest($request->remote_request_id);
            $this->updateFromRemote($request->id, $remote, $userId);
            $request = DB::table('facturacion_solicitud')->where('id', $request->id)->first();

            if ($request->status === 'stamped' && $request->documents_status === 'retrieved') {
                if (!$this->alreadyFinalized($request)) {
                    $xml = $this->client->downloadDocument($request->remote_request_id, 'xml');
                    $pdf = $this->client->downloadDocument($request->remote_request_id, 'pdf');
                    $this->assertRemoteHash($xml, $request->xml_sha256, 'XML');
                    $this->assertRemoteHash($pdf, $request->pdf_sha256, 'PDF');

                    $payload = json_decode((string) $request->request_payload, true);
                    $expectedTotal = $payload['content']['expectedTotals']['total'] ?? null;
                    $validated = $this->validator->validate($pdf, $xml, $request->fiscal_uuid, $expectedTotal);
                    $this->finalizeDocuments($request, $pdf, $xml, $validated, $userId);
                    $request = DB::table('facturacion_solicitud')->where('id', $request->id)->first();
                }
            }
        } catch (NexfiraApiException $e) {
            $this->storeNexfiraError($request->id, $e);
            throw $e;
        }

        return $this->publicRequest($request);
    }

    public function attachExternal(array $documentIds, $uuid, $pdfData, $xmlData, $userId)
    {
        $documentIds = $this->normalizeDocumentIds($documentIds, 1);
        $normalizedUuid = strtoupper(trim((string) $uuid));
        $groupHash = substr(hash('sha256', implode('-', $documentIds)), 0, 20);
        $key = 'external-' . strtolower($normalizedUuid) . '-' . $groupHash;
        $existing = DB::table('facturacion_solicitud')->where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->publicRequest($existing);
        }

        foreach ($documentIds as $documentId) {
            $summary = $this->saleSummary($documentId);
            if ($this->hasExistingInvoice($summary)
                && strtoupper((string) $summary->uuid) !== $normalizedUuid) {
                throw new InvalidArgumentException(
                    'La venta ' . $summary->id . ' ya está facturada con un UUID diferente.'
                );
            }
        }
        if ($this->activeRequestForDocuments($documentIds)) {
            throw new InvalidArgumentException('Al menos una venta seleccionada ya tiene una solicitud de facturación activa.');
        }

        $pdf = $this->validator->decodeDataUrl($pdfData, 'pdf');
        $xml = $this->validator->decodeDataUrl($xmlData, 'xml');
        $expectedTotal = $this->expectedTotalForDocuments($documentIds);
        $validated = $this->validator->validate($pdf, $xml, $normalizedUuid, $expectedTotal);

        $now = date('Y-m-d H:i:s');
        $requestId = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'external',
            'modo' => count($documentIds) > 1 ? 'global' : 'individual',
            'idempotency_key' => $key,
            'external_reference' => substr('afa-external-' . strtolower($validated['uuid']) . '-' . $groupHash, 0, 100),
            'status' => 'processing',
            'version' => 1,
            'fiscal_uuid' => $validated['uuid'],
            'documents_status' => 'retrieved',
            'xml_sha256' => $validated['xml_sha256'],
            'pdf_sha256' => $validated['pdf_sha256'],
            'updated_by' => (int) $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->linkDocuments($requestId, $documentIds, $now);

        $request = DB::table('facturacion_solicitud')->where('id', $requestId)->first();
        $this->finalizeDocuments($request, $pdf, $xml, $validated, $userId);

        return $this->publicRequest(DB::table('facturacion_solicitud')->where('id', $requestId)->first());
    }

    private function submit($mode, array $documentIds, array $payload, $externalReference, $idempotencyKey, $userId)
    {
        $now = date('Y-m-d H:i:s');
        $requestId = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira',
            'modo' => $mode,
            'idempotency_key' => $idempotencyKey,
            'external_reference' => $externalReference,
            'status' => 'sending',
            'version' => 1,
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => (int) $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->linkDocuments($requestId, $documentIds, $now);
        $request = DB::table('facturacion_solicitud')->where('id', $requestId)->first();

        return $this->sendStoredRequest($request);
    }

    private function resumeSubmission($request)
    {
        return $this->sendStoredRequest($request);
    }

    private function sendStoredRequest($request)
    {
        $payload = json_decode((string) $request->request_payload, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('La solicitud guardada no contiene un payload válido.');
        }

        try {
            $remote = $this->client->createDocumentRequest($payload, $request->idempotency_key);
            $this->updateFromRemote($request->id, $remote, $request->updated_by);
        } catch (NexfiraApiException $e) {
            $this->storeNexfiraError($request->id, $e);
            throw $e;
        }

        return $this->publicRequest(DB::table('facturacion_solicitud')->where('id', $request->id)->first());
    }

    private function updateFromRemote($requestId, array $remote, $userId)
    {
        if (empty($remote['requestId']) || empty($remote['status'])) {
            throw new NexfiraApiException('Nexfira no devolvió requestId y status.', 502, 'invalid_response');
        }

        DB::table('facturacion_solicitud')->where('id', (int) $requestId)->update([
            'remote_request_id' => $remote['requestId'],
            'status' => $remote['status'],
            'version' => isset($remote['version']) ? (int) $remote['version'] : 1,
            'fiscal_uuid' => $remote['fiscalUuid'] ?? null,
            'documents_status' => $remote['documentsStatus'] ?? null,
            'xml_sha256' => $remote['xmlSha256'] ?? null,
            'pdf_sha256' => $remote['pdfSha256'] ?? null,
            'response_payload' => json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_code' => null,
            'correlation_id' => null,
            'error_message' => null,
            'validation_errors' => null,
            'updated_by' => (int) $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function finalizeDocuments($request, $pdf, $xml, array $validated, $userId)
    {
        $path = '/facturacion/' . strtolower($validated['uuid']);
        $pdfResponse = $this->dropbox->uploadFile($path . '/factura.pdf', $pdf, false);
        $xmlResponse = $this->dropbox->uploadFile($path . '/factura.xml', $xml, false);
        if (!is_array($pdfResponse) || !isset($pdfResponse['id']) || isset($pdfResponse['error'])) {
            throw new InvalidArgumentException('Dropbox no pudo guardar el PDF fiscal.');
        }
        if (!is_array($xmlResponse) || !isset($xmlResponse['id']) || isset($xmlResponse['error'])) {
            throw new InvalidArgumentException('Dropbox no pudo guardar el XML fiscal.');
        }

        $documentIds = $this->linkedDocumentIds($request->id);
        DB::beginTransaction();
        try {
            foreach ($documentIds as $documentId) {
                $document = DB::table('documento')->where('id', $documentId)->lockForUpdate()->first();
                if (!$document || (int) $document->status !== 1) {
                    throw new InvalidArgumentException('La venta ' . $documentId . ' ya no está activa.');
                }
                if ((int) $document->id_fase === 6 && strtoupper((string) $document->uuid) === $validated['uuid']) {
                    continue;
                }
                if ((int) $document->id_fase !== 5) {
                    throw new InvalidArgumentException('La venta ' . $documentId . ' dejó de estar en fase 5.');
                }

                $relation = DB::table('documento_factura')->where('id_documento', $documentId)->first();
                $invoiceData = [
                    'pdf' => $pdfResponse['id'],
                    'xml' => $xmlResponse['id'],
                    'updated_by' => (int) $userId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($relation) {
                    DB::table('documento_factura')->where('id', $relation->id)->update($invoiceData);
                } else {
                    $invoiceData['id_documento'] = $documentId;
                    $invoiceData['created_at'] = date('Y-m-d H:i:s');
                    DB::table('documento_factura')->insert($invoiceData);
                }

                DB::table('documento')->where('id', $documentId)->update([
                    'uuid' => $validated['uuid'],
                    'id_fase' => 6,
                    'invoice_date' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                DB::table('documento_updates_by')->insert([
                    'id_documento' => $documentId,
                    'id_usuario' => (int) $userId,
                ]);
                DB::table('seguimiento')->insert([
                    'id_documento' => $documentId,
                    'id_usuario' => (int) $userId,
                    'seguimiento' => 'Venta facturada. UUID: ' . $validated['uuid'] . '.',
                ]);
            }

            DB::table('facturacion_solicitud')->where('id', $request->id)->update([
                'status' => 'stamped',
                'fiscal_uuid' => $validated['uuid'],
                'documents_status' => 'retrieved',
                'xml_sha256' => $validated['xml_sha256'],
                'pdf_sha256' => $validated['pdf_sha256'],
                'updated_by' => (int) $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function latestRequestsForDocuments(array $documentIds)
    {
        if (empty($documentIds)) {
            return [];
        }

        $rows = DB::table('facturacion_solicitud_documento as fsd')
            ->join('facturacion_solicitud as fs', 'fs.id', '=', 'fsd.id_solicitud')
            ->whereIn('fsd.id_documento', $documentIds)
            ->orderBy('fs.id', 'desc')
            ->select('fsd.id_documento', 'fs.*')
            ->get();
        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->id_documento])) {
                $map[$row->id_documento] = $this->publicRequest($row);
            }
        }

        return $map;
    }

    private function publicRequest($request)
    {
        $activeStatuses = ['sending', 'uncertain', 'pending_approval', 'queued', 'processing', 'stamped'];

        return [
            'id' => (int) $request->id,
            'provider' => $request->proveedor,
            'mode' => $request->modo,
            'remote_request_id' => $request->remote_request_id,
            'external_reference' => $request->external_reference,
            'status' => $request->status,
            'is_active' => in_array($request->status, $activeStatuses, true),
            'fiscal_uuid' => $request->fiscal_uuid,
            'documents_status' => $request->documents_status,
            'error_code' => $request->error_code,
            'error_message' => $request->error_message,
            'correlation_id' => $request->correlation_id,
            'errors' => $this->decodeValidationErrors(
                isset($request->validation_errors) ? $request->validation_errors : null
            ),
            'updated_at' => $request->updated_at,
        ];
    }

    private function storeNexfiraError($requestId, NexfiraApiException $exception)
    {
        $recoverable = in_array($exception->getApiCode(), ['nexfira_unavailable'], true)
            || $exception->getHttpStatus() >= 500;
        $errors = $exception->getValidationErrors();
        $response = $exception->getResponsePayload();
        if (empty($response)) {
            $response = [
                'code' => $exception->getApiCode(),
                'message' => $exception->getMessage(),
                'correlationId' => $exception->getCorrelationId(),
                'errors' => $errors,
            ];
        }

        DB::table('facturacion_solicitud')->where('id', (int) $requestId)->update([
            'status' => $recoverable ? 'uncertain' : 'rejected',
            'error_code' => $exception->getApiCode(),
            'correlation_id' => $exception->getCorrelationId(),
            'error_message' => $exception->getMessage(),
            'validation_errors' => empty($errors)
                ? null
                : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function decodeValidationErrors($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $errors = json_decode((string) $value, true);

        return is_array($errors) ? $errors : [];
    }

    private function activeRequestForDocuments(array $documentIds)
    {
        return DB::table('facturacion_solicitud_documento as fsd')
            ->join('facturacion_solicitud as fs', 'fs.id', '=', 'fsd.id_solicitud')
            ->whereIn('fsd.id_documento', $documentIds)
            ->whereIn('fs.status', ['sending', 'uncertain', 'pending_approval', 'queued', 'processing', 'stamped'])
            ->orderBy('fs.id', 'desc')
            ->select('fs.*')
            ->first();
    }

    private function saleSummary($documentId)
    {
        $document = DB::table('documento as d')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('marketplace as mk', 'mk.id', '=', 'ma.id_marketplace')
            ->where('d.id', (int) $documentId)
            ->where('d.id_tipo', 2)
            ->where('d.status', 1)
            ->whereNull('d.deleted_at')
            ->select('d.id', 'd.id_fase', 'd.fulfillment', 'd.total', 'd.uuid', 'mk.marketplace')
            ->first();

        if (!$document) {
            throw new InvalidArgumentException('No se encontró la venta activa ' . (int) $documentId . '.');
        }
        if ((int) $document->id_fase !== 5) {
            throw new InvalidArgumentException('La venta ' . $document->id . ' no está en fase 5 pendiente de factura.');
        }

        return $document;
    }

    private function isMeliFull($document)
    {
        return (int) $document->fulfillment === 1
            && strtoupper((string) $document->marketplace) === 'MERCADOLIBRE';
    }

    private function hasExistingInvoice($document)
    {
        $uuid = strtoupper(trim((string) $document->uuid));

        return $uuid !== '' && !in_array($uuid, ['N/A', 'NA', 'N.A.', 'NO APLICA'], true);
    }

    private function assertNotAlreadyInvoiced($document)
    {
        if ($this->hasExistingInvoice($document)) {
            throw new InvalidArgumentException(
                'La venta ' . $document->id . ' ya está facturada con UUID '
                . strtoupper((string) $document->uuid) . '.'
            );
        }
    }

    private function countPendingByFulfillment($fulfillment)
    {
        return (int) DB::table('documento')
            ->where('id_tipo', 2)
            ->where('id_fase', 5)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where('fulfillment', (int) $fulfillment)
            ->count();
    }

    private function normalizeDocumentIds(array $documentIds, $minimum)
    {
        $documentIds = array_values(array_filter(array_unique(array_map('intval', $documentIds)), function ($id) {
            return $id > 0;
        }));
        sort($documentIds, SORT_NUMERIC);
        if (count($documentIds) < (int) $minimum) {
            throw new InvalidArgumentException(
                (int) $minimum === 1
                    ? 'Selecciona al menos una venta.'
                    : 'Selecciona al menos dos ventas para facturar en global.'
            );
        }

        return $documentIds;
    }

    private function nextAttempt(array $documentIds)
    {
        return (int) DB::table('facturacion_solicitud_documento')
                ->whereIn('id_documento', $documentIds)
                ->distinct()
                ->count('id_solicitud') + 1;
    }

    private function linkDocuments($requestId, array $documentIds, $now)
    {
        foreach ($documentIds as $documentId) {
            DB::table('facturacion_solicitud_documento')->insert([
                'id_solicitud' => (int) $requestId,
                'id_documento' => (int) $documentId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function linkedDocumentIds($requestId)
    {
        return DB::table('facturacion_solicitud_documento')
            ->where('id_solicitud', (int) $requestId)
            ->orderBy('id_documento')
            ->pluck('id_documento')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    private function firstLinkedDocument($requestId)
    {
        return (int) DB::table('facturacion_solicitud_documento')
            ->where('id_solicitud', (int) $requestId)
            ->orderBy('id_documento')
            ->value('id_documento');
    }

    private function expectedTotalForDocuments(array $documentIds)
    {
        $total = 0.0;
        foreach ($documentIds as $documentId) {
            $document = $this->saleSummary($documentId);
            if ($document->total !== null) {
                $total += (float) $document->total;
                continue;
            }
            $calculated = DB::table('movimiento')
                ->where('id_documento', $documentId)
                ->selectRaw('SUM((cantidad * precio) - descuento) AS total')
                ->value('total');
            $total += (float) $calculated;
        }

        return round($total, 2);
    }

    private function alreadyFinalized($request)
    {
        $documentIds = $this->linkedDocumentIds($request->id);
        if (empty($documentIds) || !$request->fiscal_uuid) {
            return false;
        }

        $count = DB::table('documento')
            ->whereIn('id', $documentIds)
            ->where('id_fase', 6)
            ->where('uuid', strtoupper((string) $request->fiscal_uuid))
            ->count();

        return $count === count($documentIds);
    }

    private function assertRemoteHash($contents, $expectedHash, $label)
    {
        if ($expectedHash && strtolower(hash('sha256', $contents)) !== strtolower((string) $expectedHash)) {
            throw new NexfiraApiException('El hash del archivo ' . $label . ' de Nexfira no coincide.', 503, 'document_hash_mismatch');
        }
    }

    private function isConfigured()
    {
        return trim((string) config('nexfira.integration_token')) !== ''
            && trim((string) config('nexfira.issuer_id')) !== ''
            && trim((string) config('nexfira.expedition_postal_code')) !== '';
    }
}
