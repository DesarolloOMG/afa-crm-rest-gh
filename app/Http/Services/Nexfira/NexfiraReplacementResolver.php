<?php

namespace App\Http\Services\Nexfira;

/** Only reconciles a replacement explicitly declared by the authenticated Hub. Never submits a CFDI. */
class NexfiraReplacementResolver
{
    private $client;

    public function __construct(NexfiraClient $client)
    {
        $this->client = $client;
    }

    public function resolve($request, array $remote)
    {
        if (empty($remote['replacementRequestId'])) {
            return null;
        }
        $payload = json_decode((string) $request->request_payload, true) ?: [];
        $replacementId = (string) $remote['replacementRequestId'];
        if (($remote['requestId'] ?? '') !== $request->remote_request_id
            || ($remote['externalReference'] ?? '') !== $request->external_reference
            || $replacementId === $request->remote_request_id
            || !preg_match('/^[0-9a-f-]{36}$/iD', $replacementId)) {
            $this->fail('No se pudo verificar el vínculo de la solicitud original con su reemplazo.');
        }
        $replacement = $this->client->getDocumentRequest($replacementId);
        if (($replacement['requestId'] ?? '') !== $replacementId
            || empty($remote['replacementExternalReference'])
            || ($replacement['externalReference'] ?? '') !== $remote['replacementExternalReference']
            || !empty($replacement['replacementRequestId'])) {
            $this->fail('El reemplazo de Nexfira cambió; vuelve a consultar la solicitud original.');
        }
        // A different sale/group must never be accepted just because the amount matches.
        if (!preg_match('/^(.*)-v([1-9][0-9]*)$/D', $request->external_reference, $originalRef)
            || !preg_match('/^(.*)-v([1-9][0-9]*)$/D', $replacement['externalReference'], $newRef)
            || $originalRef[1] !== $newRef[1] || (int) $newRef[2] <= (int) $originalRef[2]) {
            $this->fail('La referencia del reemplazo no pertenece al mismo pedido o grupo original.');
        }
        foreach ([$remote, $replacement] as $state) {
            if (($state['status'] ?? '') !== 'stamped' || ($state['documentsStatus'] ?? '') !== 'retrieved'
                || empty($state['fiscalUuid']) || empty($state['series']) || empty($state['folio'])
                || empty($state['issuerRfc']) || empty($payload['issuerId'])
                || ($state['issuerId'] ?? '') !== $payload['issuerId']
                || ($state['kind'] ?? '') !== ($payload['kind'] ?? '')
                || ($state['subtype'] ?? '') !== ($payload['subtype'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/iD', $state['xmlSha256'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/iD', $state['pdfSha256'] ?? '')) {
                $this->fail('El reemplazo debe estar timbrado con XML/PDF, hashes y emisor verificados.');
            }
        }
        foreach (['series', 'folio', 'issuerRfc', 'fiscalUuid', 'xmlSha256', 'pdfSha256'] as $key) {
            if (strcasecmp((string) $remote[$key], (string) $replacement[$key]) !== 0) {
                $this->fail('Los datos de la solicitud original y del reemplazo no coinciden en Nexfira.');
            }
        }
        return ['original_response' => $remote, 'replacement_response' => $replacement];
    }

    public function validateXml($request, array $replacement, array $xml)
    {
        $payload = json_decode((string) $request->request_payload, true) ?: [];
        $content = $payload['content'] ?? [];
        $target = $replacement['replacement_response'];
        if (($xml['serie'] ?? '') !== $target['series'] || ($xml['folio'] ?? '') !== $target['folio']
            || strtoupper($xml['uuid'] ?? '') !== strtoupper($target['fiscalUuid'])
            || ($xml['issuer_rfc'] ?? '') !== strtoupper($target['issuerRfc'])
            || empty($content['receiver']['rfc'])
            || ($xml['receiver_rfc'] ?? '') !== strtoupper(trim($content['receiver']['rfc']))
            || empty($content['currency']) || ($xml['currency'] ?? '') !== $content['currency']
            || ($xml['type'] ?? '') !== (($payload['kind'] ?? '') === 'CFDI_E' ? 'E' : 'I')
            || !isset($content['expectedTotals']['total'])
            || abs((float) $xml['total'] - (float) $content['expectedTotals']['total']) > 0.05) {
            $this->fail('El XML del reemplazo no coincide en identidad fiscal, emisor, receptor, moneda, tipo o importe.');
        }
    }

    private function fail($message)
    {
        throw new NexfiraApiException($message, 503, 'replacement_reconciliation_failed');
    }

    /** Preserve the sent JSON; consumers of the stamped invoice use its verified XML receiver. */
    public static function fiscalPayload($request)
    {
        if (!$request) { return null; }
        $payload = json_decode((string) $request->request_payload, true);
        $response = json_decode((string) ($request->response_payload ?? ''), true) ?: [];
        $audit = $response['afa_reconciliation'] ?? null;
        if (is_array($payload) && $audit && ($request->status ?? '') === 'stamped'
            && strtoupper($audit['xml']['uuid'] ?? '') === strtoupper($request->fiscal_uuid ?? '')) {
            $xml = $audit['xml'];
            $payload['content']['receiver'] = $xml['receiver'];
            $payload['content']['series'] = $xml['serie'];
            $payload['content']['folio'] = $xml['folio'];
            foreach (['paymentMethod', 'paymentForm'] as $key) {
                if (!empty($xml[$key])) { $payload['content'][$key] = $xml[$key]; }
            }
        }
        return $payload;
    }

    public static function fiscalRequestId($request)
    {
        if (!$request) { return null; }
        $response = json_decode((string) ($request->response_payload ?? ''), true) ?: [];
        $audit = $response['afa_reconciliation'] ?? [];
        return ($request->status ?? '') === 'stamped'
            && strtoupper($audit['xml']['uuid'] ?? '') === strtoupper($request->fiscal_uuid ?? '')
            ? ($audit['replacement_response']['requestId'] ?? $request->remote_request_id)
            : $request->remote_request_id;
    }
}
