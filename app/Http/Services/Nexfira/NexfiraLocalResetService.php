<?php

namespace App\Http\Services\Nexfira;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Libera rechazos o incertidumbre confirmada manualmente. Nunca escribe en Nexfira. */
class NexfiraLocalResetService
{
    private $client;

    public function __construct(NexfiraClient $client)
    {
        $this->client = $client;
    }

    public function inspect($documentId)
    {
        $request = $this->latestRequest($documentId);
        if (!$request) {
            throw new InvalidArgumentException('El documento no tiene una solicitud de facturación registrada.');
        }
        return $this->review($request, $documentId);
    }

    public function reset($documentId, $requestId, $token, $reason, $userId, array $manualConfirmation = [])
    {
        if (!is_string($reason) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Indica un motivo de 10 a 500 caracteres para la auditoría.');
        }
        $lock = 'nexfira_sync_' . (int) $requestId;
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            $acquired = DB::select('SELECT GET_LOCK(?, 0) AS acquired', [$lock]);
            if (empty($acquired) || (int) $acquired[0]->acquired !== 1) {
                throw new InvalidArgumentException('La solicitud está siendo actualizada. Vuelve a consultar.');
            }
        }
        try {
            return DB::transaction(function () use ($documentId, $requestId, $token, $reason, $userId, $manualConfirmation) {
                $ids = $this->documentIds($requestId);
                // Mismo orden de bloqueo que el envío: documentos antes de solicitudes.
                DB::table('documento')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                $request = DB::table('facturacion_solicitud')->where('id', (int) $requestId)->lockForUpdate()->first();
                $latest = $this->latestRequest($documentId);
                if (!$request || !$latest || (int) $latest->id !== (int) $requestId || !in_array((int) $documentId, $ids, true)) {
                    throw new InvalidArgumentException('La solicitud cambió. Consulta de nuevo el documento.');
                }
                $review = $this->review($request, $documentId);
                $manual = !$review['can_reset'] && $review['can_manual_reset']
                    && ($manualConfirmation['rejected_confirmed'] ?? null) === true
                    && ($manualConfirmation['duplicate_risk_accepted'] ?? null) === true
                    && ($manualConfirmation['document_confirmation'] ?? '') === 'LIBERAR ' . (int) $documentId;
                if (!$review['can_reset'] && !$manual) {
                    throw new InvalidArgumentException(implode(' ', $review['blockers']));
                }
                if (!is_string($token) || !hash_equals($review['confirmation_token'], $token)) {
                    throw new InvalidArgumentException('Los datos cambiaron desde la consulta. Revisa nuevamente antes de liberar.');
                }
                $now = date('Y-m-d H:i:s');
                $audit = [
                    'localReset' => ['at' => $now, 'userId' => (int) $userId, 'reason' => trim($reason),
                        'documentIds' => $ids, 'previousRequest' => (array) $request, 'remoteVerification' => $review['remote'],
                        'mode' => $manual ? 'manual_rejection_confirmation' : 'verified_rejection',
                        'manualConfirmation' => $manual ? [
                            'rejected_confirmed' => true, 'duplicate_risk_accepted' => true,
                            'document_confirmation' => 'LIBERAR ' . (int) $documentId,
                        ] : null],
                ];
                DB::table('facturacion_solicitud')->where('id', $requestId)->update([
                    'status' => 'reset_local', 'response_payload' => json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_by' => (int) $userId, 'updated_at' => $now,
                ]);
                return ['request_id' => (int) $requestId, 'document_ids' => $ids, 'status' => 'reset_local',
                    'message' => 'Intento local archivado. Ya puedes solicitar nuevamente desde Facturación con los datos actuales, folio y clave de envío nuevos. No se modificó Nexfira ni se emitió una factura.'];
            });
        } finally {
            if ($mysql) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    private function review($request, $documentId)
    {
        $ids = $this->documentIds($request->id);
        $documents = DB::table('documento as d')->join('documento_entidad as de', 'de.id', '=', 'd.id_entidad')
            ->whereIn('d.id', $ids)->orderBy('d.id')
            ->get(['d.id', 'd.id_tipo', 'd.id_fase', 'd.status', 'd.uuid', 'd.deleted_at', 'd.id_entidad', 'de.rfc', 'de.razon_social']);
        $blockers = [];
        $uncertain = false;
        $remote = null;
        if ($request->proveedor !== 'nexfira') {
            $blockers[] = 'Sólo se pueden liberar intentos de Nexfira, no CFDI externos.';
        } elseif ($request->status === 'reset_local') {
            $blockers[] = 'Este intento ya fue liberado; no hace falta repetir la operación.';
        } elseif ($request->status === 'stamped' || $this->hasValue($request->fiscal_uuid)
            || $request->documents_status === 'retrieved') {
            $blockers[] = 'La solicitud tiene evidencia de timbrado. No se puede liberar.';
        } elseif ($request->remote_request_id) {
            // GET, nunca sync(): sync sin ID remoto puede reenviar el payload anterior.
            $remote = $this->client->getDocumentRequest($request->remote_request_id);
            if (($remote['requestId'] ?? '') !== $request->remote_request_id
                || ($remote['externalReference'] ?? '') !== $request->external_reference) {
                $blockers[] = 'La respuesta de Nexfira no corresponde a este intento.';
            }
            if (($remote['status'] ?? '') !== 'rejected'
                || (isset($remote['originalStatus']) && $remote['originalStatus'] !== 'rejected')) {
                if (($remote['status'] ?? '') === 'uncertain'
                    && in_array($request->status, ['uncertain', 'rejected'], true)) {
                    $uncertain = true;
                } else {
                    $blockers[] = 'Nexfira mantiene el estado ' . ($remote['status'] ?? 'desconocido')
                        . '. No se puede liberar una solicitud activa, timbrada o sustituida.';
                }
            }
            if ($this->hasValue($remote['fiscalUuid'] ?? null) || ($remote['documentsStatus'] ?? '') === 'retrieved'
                || !empty($remote['correctedRequestId']) || !empty($remote['replacementRequestId'])
                || !empty($remote['replacementExternalReference'])) {
                $blockers[] = 'Nexfira tiene un CFDI o un intento sustituto/corregido. Se requiere conciliación.';
            }
        } elseif ($request->status !== 'rejected' || $request->error_code !== 'validation_failed') {
            if ($request->status === 'uncertain') {
                $uncertain = true;
            } else {
                $blockers[] = 'No hay identificador remoto ni rechazo definitivo de validación. No se puede descartar un envío recibido.';
            }
        }
        if ($documents->count() !== count($ids) || empty($ids)) {
            $blockers[] = 'No se pudieron validar todos los documentos de la solicitud.';
        }
        foreach ($documents as $document) {
            if ((int) $document->status !== 1 || $document->deleted_at
                || !in_array((int) $document->id_tipo, [2, 6], true)
                || ((int) $document->id_tipo === 2 && (int) $document->id_fase !== 5)
                || $this->hasValue($document->uuid)) {
                $blockers[] = 'El documento ' . $document->id . ' ya está timbrado o no está pendiente de facturación.';
            }
        }
        if (DB::table('documento_factura')->whereIn('id_documento', $ids)->where(function ($q) {
            $q->whereRaw("TRIM(COALESCE(xml, '')) <> ''")->orWhereRaw("TRIM(COALESCE(pdf, '')) <> ''");
        })->exists()) {
            $blockers[] = 'Hay archivos fiscales relacionados. No se pueden descartar sus solicitudes.';
        }
        if (DB::table('facturacion_solicitud as fs')->join('facturacion_solicitud_documento as l', 'l.id_solicitud', '=', 'fs.id')
            ->whereIn('l.id_documento', $ids)->where('fs.id', '<>', $request->id)
            ->whereIn('fs.status', ['sending', 'uncertain', 'pending_approval', 'queued', 'processing', 'stamped'])->exists()) {
            $blockers[] = 'Un documento tiene otra solicitud activa o timbrada. Requiere conciliación.';
        }
        $payload = json_decode((string) $request->request_payload, true) ?: [];
        $previous = json_decode((string) $request->response_payload, true) ?: [];
        $canManualReset = $uncertain && empty($blockers);
        if ($uncertain) {
            $blockers[] = 'Nexfira no puede confirmar rejected automáticamente. Puedes liberar manualmente sólo si su equipo confirmó el rechazo y aceptas expresamente el riesgo de duplicidad.';
        }
        return [
            'document_id' => (int) $documentId, 'request_id' => (int) $request->id,
            'document_ids' => $ids, 'documents' => $documents->toArray(),
            'status' => $request->status, 'remote_status' => $remote['status'] ?? null,
            'remote_series' => $remote['series'] ?? null, 'remote_folio' => $remote['folio'] ?? null,
            'remote_uuid' => $remote['fiscalUuid'] ?? null,
            'remote_request_id' => $request->remote_request_id, 'series' => $request->serie, 'folio' => $request->folio,
            'sent_receiver' => $payload['content']['receiver'] ?? null,
            'provider_error' => $remote !== null ? ($remote['processingErrorDetails']['message'] ?? null)
                : ($previous['processingErrorDetails']['message'] ?? $request->error_message),
            'remote' => $remote, 'can_reset' => empty($blockers), 'can_manual_reset' => $canManualReset,
            'blockers' => array_values(array_unique($blockers)),
            'confirmation_token' => hash('sha256', json_encode([(array) $request, $ids, $documents->toArray(), $remote])),
        ];
    }

    private function latestRequest($documentId)
    {
        return DB::table('facturacion_solicitud as fs')->join('facturacion_solicitud_documento as l', 'l.id_solicitud', '=', 'fs.id')
            ->where('l.id_documento', (int) $documentId)->orderBy('fs.id', 'desc')->select('fs.*')->first();
    }

    private function documentIds($requestId)
    {
        return DB::table('facturacion_solicitud_documento')->where('id_solicitud', (int) $requestId)
            ->orderBy('id_documento')->pluck('id_documento')->map(function ($id) { return (int) $id; })->all();
    }

    private function hasValue($value)
    {
        return !in_array(strtoupper(trim((string) $value)), ['', 'N/A', 'NA', 'N.A.', 'NO APLICA'], true);
    }
}
