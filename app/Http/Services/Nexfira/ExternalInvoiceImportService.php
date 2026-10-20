<?php

namespace App\Http\Services\Nexfira;

use App\Http\Services\DropboxService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Importa evidencia externa sin cambiar la factura original ni confirmar saldos por el operador. */
class ExternalInvoiceImportService
{
    private $client;
    private $dropbox;
    private $validator;

    public function __construct(NexfiraClient $client, DropboxService $dropbox, CfdiAttachmentValidator $validator)
    {
        $this->client = $client;
        $this->dropbox = $dropbox;
        $this->validator = $validator;
    }

    private function evidence($noteId)
    {
        $context = (new CreditNoteContext())->resolve($noteId);
        $note = DB::table('documento')->where('id', (int) $noteId)->first();
        if (preg_match('/^[0-9a-f-]{36}$/iD', trim((string) $note->uuid))) {
            throw new InvalidArgumentException('La NC ya tiene UUID fiscal.');
        }
        $request = DB::table('facturacion_solicitud as s')
            ->join('facturacion_solicitud_documento as l', 'l.id_solicitud', '=', 's.id')
            ->where('l.id_documento', $context['source_id'])->where('s.proveedor', 'external')
            ->where('s.status', 'stamped')->whereRaw('UPPER(s.fiscal_uuid) = ?', [$context['source_uuid']])
            ->orderBy('s.id', 'desc')->select('s.*')->first();
        if (!$request) { throw new InvalidArgumentException('No se encontró la factura externa registrada de la venta origen.'); }
        $file = DB::table('documento_factura')->where('id_documento', $context['source_id'])->first();
        if (!$file || !$file->xml) { throw new InvalidArgumentException('Registra el XML original de la factura antes de preparar la NC.'); }
        $xml = $this->dropbox->downloadFile($file->xml);
        if (!is_string($xml) || $xml === '' || strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new InvalidArgumentException('El XML original debe ser legible, sin DTD y de hasta 1 MiB.');
        }
        // Se valida el XML sin volver a descargar ni registrar su PDF.
        $fiscal = $this->validator->validate('%PDF-', $xml, $context['source_uuid']);
        if ($fiscal['type'] !== 'I' || $fiscal['currency'] !== $context['currency']
            || $fiscal['receiver_rfc'] !== $context['receiver_rfc']) {
            throw new InvalidArgumentException('El XML no coincide con el tipo, receptor o moneda de la factura origen.');
        }
        return [$context, $request, $xml];
    }

    private function checkedPreview(array $context, $xml)
    {
        $preview = $this->client->previewExternalInvoice($xml);
        $payload = $preview['payload'] ?? [];
        if (strtoupper($preview['fiscalUuid'] ?? '') !== $context['source_uuid']
            || ($payload['kind'] ?? '') !== 'CFDI_I'
            || ($payload['issuerId'] ?? '') !== config('nexfira.issuer_id')
            || ($payload['content']['currency'] ?? '') !== $context['currency']
            || ($payload['content']['receiver']['rfc'] ?? '') !== $context['receiver_rfc']
            || empty($preview['lines'])) {
            throw new InvalidArgumentException('La vista previa de Nexfira no coincide con la factura origen.');
        }
        $preview['review_token'] = hash('sha256', $context['source_id'] . ':' . $context['source_uuid'] . ':' . hash('sha256', $xml));
        $preview['source_document_id'] = $context['source_id'];
        return $preview;
    }

    public function preview($noteId)
    {
        list($context, $request, $xml) = $this->evidence($noteId);
        return $this->checkedPreview($context, $xml);
    }

    public function confirm($noteId, array $input, $userId)
    {
        if (!isset($input['opening']) || !is_array($input['opening'])
            || !isset($input['opening']['remainingLines']) || !is_array($input['opening']['remainingLines'])
            || ($input['opening']['confirmedValid'] ?? null) !== true
            || ($input['confirmedBalances'] ?? null) !== true) {
            throw new InvalidArgumentException('Confirma la vigencia del XML y la conciliación de saldos por concepto.');
        }
        list($context, $request, $xml) = $this->evidence($noteId);
        $preview = $this->checkedPreview($context, $xml);
        if (!hash_equals($preview['review_token'], (string) ($input['review_token'] ?? ''))) {
            throw new InvalidArgumentException('El XML cambió. Revisa de nuevo la factura externa.');
        }
        $opening = $input['opening'];
        $installment = $opening['nextInstallment'] ?? null;
        $note = trim((string) ($opening['note'] ?? ''));
        if (!is_int($installment) || $installment < 1 || $installment > 1000000 || $note === '' || mb_strlen($note) > 500
            || (($preview['payload']['content']['paymentMethod'] ?? '') === 'PUE' && $installment !== 1)) {
            throw new InvalidArgumentException('Revisa la parcialidad y la nota de conciliación; PUE usa parcialidad 1.');
        }
        $original = [];
        foreach ($preview['lines'] as $line) { $original[(string) $line['lineId']] = $line['netAmount']; }
        $remaining = [];
        foreach ($opening['remainingLines'] ?? [] as $line) {
            $id = (string) ($line['lineId'] ?? '');
            $amount = $line['netAmount'] ?? null;
            if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,13})(\.[0-9]{1,6})?$/D', $amount)
                || !array_key_exists($id, $original) || isset($remaining[$id]) || (float) $amount > (float) $original[$id]) {
                throw new InvalidArgumentException('El saldo neto por concepto no es válido o supera el importe original.');
            }
            $remaining[$id] = $amount;
        }
        if (count($remaining) !== count($original)) { throw new InvalidArgumentException('Confirma el saldo de todos los conceptos.'); }
        $opening = ['confirmedValid' => true, 'nextInstallment' => $installment, 'note' => $note,
            'remainingLines' => array_map(function ($id) use ($remaining) {
                return ['lineId' => (string) $id, 'netAmount' => $remaining[$id]];
            }, array_keys($remaining))];
        $import = $this->client->importExternalInvoice($xml, $opening);
        if (strtoupper($import['fiscalUuid'] ?? '') !== $context['source_uuid']
            || ($import['issuerId'] ?? '') !== config('nexfira.issuer_id')
            || ($import['origin'] ?? '') !== 'external_xml'
            || !preg_match('/^[0-9a-f-]{36}$/iD', $import['requestId'] ?? '')
            || ($import['payload']['kind'] ?? '') !== 'CFDI_I'
            || ($import['payload']['content']['receiver'] ?? []) !== $preview['payload']['content']['receiver']
            || ($import['payload']['content']['currency'] ?? '') !== $context['currency']
            || ($import['payload']['content']['items'] ?? []) !== $preview['payload']['content']['items']
            || ($import['opening'] ?? []) != $opening || empty($import['balance'])) {
            throw new InvalidArgumentException('Nexfira no confirmó la identidad y el saldo de la importación. No envíes la NC.');
        }
        DB::transaction(function () use ($request, $import, $xml, $userId) {
            $current = DB::table('facturacion_solicitud')->where('id', $request->id)->lockForUpdate()->first();
            $response = json_decode((string) $current->response_payload, true) ?: [];
            if (isset($response['afa_external_import'])
                && $response['afa_external_import']['requestId'] !== $import['requestId']) {
                throw new InvalidArgumentException('La factura ya tiene otra importación. Actualiza y concilia su estado.');
            }
            if (isset($response['afa_external_import'])) { return; } // Conservar la confirmación original en un replay.
            $response['afa_external_import'] = $import;
            $response['afa_external_import']['xml_sha256'] = hash('sha256', $xml);
            $response['afa_external_import']['confirmed_by'] = (int) $userId;
            $response['afa_external_import']['confirmed_at'] = date('c');
            DB::table('facturacion_solicitud')->where('id', $request->id)->update([
                'response_payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_by' => (int) $userId, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        return ['request_id' => $import['requestId'], 'fiscal_uuid' => $import['fiscalUuid'], 'imported' => true];
    }
}
