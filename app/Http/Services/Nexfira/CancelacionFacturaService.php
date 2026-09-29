<?php

namespace App\Http\Services\Nexfira;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CancelacionFacturaService
{
    private $client;

    public function __construct(NexfiraClient $client)
    {
        $this->client = $client;
    }

    public function preview($folio, $serie = '')
    {
        $folio = trim((string) $folio);
        $serie = trim((string) $serie);
        if ($folio === '' || strlen($folio) > 40) {
            throw new InvalidArgumentException('Indica un folio válido.');
        }
        $query = DB::table('facturacion_solicitud')->where('proveedor', 'nexfira')
            ->where('folio', $folio)->whereIn('status', ['stamped', 'cancelled']);
        if ($serie !== '') { $query->where('serie', $serie); }
        $requests = $query->get();
        if ($requests->count() !== 1) {
            throw new InvalidArgumentException($requests->isEmpty()
                ? 'No se encontró un CFDI timbrado de Nexfira con ese folio.'
                : 'El folio existe en varias series. Indica también la serie.');
        }
        return $this->detail($requests->first());
    }

    public function request($folio, $serie, $reason, $replacementUuid, $userId)
    {
        $detail = $this->preview($folio, $serie);
        $invoice = $detail['factura'];
        if ($invoice->status !== 'stamped') {
            throw new InvalidArgumentException('Esta factura ya fue cancelada.');
        }
        if (!in_array($reason, ['01', '02', '03', '04'], true)) {
            throw new InvalidArgumentException('Indica un motivo SAT de cancelación válido.');
        }
        if ($reason === '01' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $replacementUuid)) {
            throw new InvalidArgumentException('El motivo 01 requiere el UUID del CFDI sustituto.');
        }
        if ($reason !== '01') { $replacementUuid = null; }
        if (empty($invoice->remote_request_id) || empty($invoice->fiscal_uuid) || !$detail['ventas']) {
            throw new InvalidArgumentException('La factura no tiene solicitud remota, UUID o ventas vinculadas.');
        }
        foreach ($detail['ventas'] as $sale) {
            if ((int) $sale->id_tipo !== 2 || (int) $sale->id_fase !== 6
                || strtoupper((string) $sale->uuid) !== strtoupper((string) $invoice->fiscal_uuid)) {
                throw new InvalidArgumentException('Las ventas ya no coinciden con el CFDI. Requieren revisión antes de cancelar.');
            }
        }
        if ($detail['cancelacion']) { return $detail; }
        $remote = $this->client->getDocumentRequest($invoice->remote_request_id);
        if (($remote['requestId'] ?? '') !== $invoice->remote_request_id
            || strtoupper((string) ($remote['fiscalUuid'] ?? '')) !== strtoupper((string) $invoice->fiscal_uuid)
            || ($remote['status'] ?? '') !== 'stamped' || (int) ($remote['version'] ?? 0) < 1) {
            throw new InvalidArgumentException('La identidad o versión de la factura ya cambió en Nexfira. Actualiza antes de cancelar.');
        }
        $remoteCancellation = $this->client->getCancellation($invoice->remote_request_id);
        if (($remoteCancellation['requestId'] ?? '') !== $invoice->remote_request_id
            || !($remoteCancellation['canCancel'] ?? false) || !empty($remoteCancellation['cancellation'])) {
            throw new InvalidArgumentException('Nexfira no permite una nueva cancelación para esta factura. Consulta su estado en el Hub.');
        }
        $payload = ['expectedVersion' => (int) $remote['version'], 'motive' => $reason,
            'replacementUuid' => $replacementUuid ? strtoupper($replacementUuid) : null];
        $now = date('Y-m-d H:i:s');
        DB::table('facturacion_cancelacion')->insert([
            'id_solicitud' => $invoice->id, 'status' => 'sending', 'motivo' => $reason,
            'folio_sustitucion' => $replacementUuid, 'request_payload' => json_encode($payload),
            'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $response = $this->client->createCancellation($invoice->remote_request_id, $payload,
                'afa-cancel-' . $invoice->id . '-' . strtolower($invoice->fiscal_uuid));
            if (($response['requestId'] ?? '') !== $invoice->remote_request_id
                || empty($response['cancellation']['id'])
                || ($response['cancellation']['motive'] ?? '') !== $reason
                || strtoupper((string) ($response['cancellation']['replacementUuid'] ?? '')) !== strtoupper((string) $replacementUuid)) {
                throw new NexfiraApiException('Respuesta de cancelación sin identidad verificable.', 503, 'cancellation_identity_missing');
            }
            $remoteStatus = $response['cancellation']['status'] ?? '';
            if (!in_array($remoteStatus, ['requested', 'pending', 'cancelled', 'rejected', 'uncertain', 'not_dispatched'], true)) {
                throw new NexfiraApiException('Nexfira devolvió un estado de cancelación desconocido.', 503, 'cancellation_status_unknown');
            }
            DB::table('facturacion_cancelacion')->where('id_solicitud', $invoice->id)->update([
                // El estado del Hub no acredita todavía la aceptación final del SAT.
                'status' => 'pending_approval',
                'provider_status' => $remoteStatus,
                'remote_cancellation_id' => $response['cancellation']['id'],
                'response_payload' => json_encode($response), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (NexfiraApiException $e) {
            DB::table('facturacion_cancelacion')->where('id_solicitud', $invoice->id)
                ->update(['status' => 'uncertain', 'updated_at' => date('Y-m-d H:i:s')]);
            throw $e;
        }
        return $this->detail($invoice);
    }

    public function refresh($folio, $serie, $userId)
    {
        $detail = $this->preview($folio, $serie);
        $invoice = $detail['factura'];
        $cancellation = $detail['cancelacion'];
        if (!$cancellation) { throw new InvalidArgumentException('Primero solicita la cancelación.'); }
        if ($cancellation->status === 'approved') { return $detail; }
        $response = $this->client->getCancellation($invoice->remote_request_id);
        if (($response['requestId'] ?? '') !== $invoice->remote_request_id
            || ($response['cancellation']['id'] ?? '') !== $cancellation->remote_cancellation_id
            || ($response['cancellation']['motive'] ?? '') !== $cancellation->motivo
            || strtoupper((string) ($response['cancellation']['replacementUuid'] ?? ''))
                !== strtoupper((string) $cancellation->folio_sustitucion)) {
            throw new NexfiraApiException('Nexfira devolvió otra cancelación.', 503, 'cancellation_identity_mismatch');
        }
        $status = strtolower(trim((string) ($response['cancellation']['status'] ?? '')));
        if (!in_array($status, ['requested', 'pending', 'cancelled', 'rejected', 'uncertain', 'not_dispatched'], true)) {
            throw new NexfiraApiException('Nexfira devolvió un estado de cancelación desconocido.', 503, 'cancellation_status_unknown');
        }
        DB::table('facturacion_cancelacion')->where('id', $cancellation->id)->update([
            'status' => 'pending_approval',
            'provider_status' => $status,
            'response_payload' => json_encode($response), 'updated_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->detail($invoice);
    }

    public function simulate($folio, $serie, $userId)
    {
        $detail = $this->preview($folio, $serie);
        if (!$detail['cancelacion'] || $detail['cancelacion']->status !== 'pending_approval') {
            throw new InvalidArgumentException('Sólo se puede simular una cancelación enviada y pendiente de aprobación.');
        }
        if ($detail['cancelacion']->provider_status !== 'cancelled') {
            throw new InvalidArgumentException('Primero actualiza la factura hasta que Nexfira reporte cancelled.');
        }
        $this->approve($detail['factura'], $userId);
        return $this->detail($detail['factura']);
    }

    private function approve($invoice, $userId)
    {
        DB::transaction(function () use ($invoice, $userId) {
            $cancellation = DB::table('facturacion_cancelacion')->where('id_solicitud', $invoice->id)
                ->lockForUpdate()->first();
            if (!$cancellation || $cancellation->status !== 'pending_approval'
                || $cancellation->provider_status !== 'cancelled') {
                throw new InvalidArgumentException('La cancelación ya cambió de estado.');
            }
            $links = DB::table('facturacion_solicitud_documento')->where('id_solicitud', $invoice->id)->pluck('id_documento');
            if ($links->isEmpty()) { throw new InvalidArgumentException('La factura ya no tiene ventas vinculadas.'); }
            $archivedFiles = [];
            foreach ($links as $id) {
                $sale = DB::table('documento')->where('id', $id)->lockForUpdate()->first();
                if (!$sale || (int) $sale->id_tipo !== 2 || (int) $sale->id_fase !== 6
                    || strtoupper((string) $sale->uuid) !== strtoupper((string) $invoice->fiscal_uuid)) {
                    throw new InvalidArgumentException('Una venta cambió de estado; no se liberó ninguna.');
                }
                $files = DB::table('documento_factura')->where('id_documento', $id)->first();
                $archivedFiles[$id] = ['pdf' => $files->pdf ?? null, 'xml' => $files->xml ?? null];
                DB::table('seguimiento')->insert([
                    'id_documento' => $id, 'id_usuario' => $userId,
                    'seguimiento' => 'Aprobación SAT simulada en DEV de la cancelación del CFDI '
                        . $invoice->serie . '-' . $invoice->folio . ' UUID ' . $invoice->fiscal_uuid
                        . '. Archivos anteriores: PDF ' . ($files->pdf ?? '') . ', XML ' . ($files->xml ?? '') . '.',
                ]);
                DB::table('documento')->where('id', $id)->update([
                    'id_fase' => 5, 'uuid' => '', 'factura_serie' => '', 'factura_folio' => '',
                    'invoice_date' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                DB::table('documento_factura')->where('id_documento', $id)->delete();
            }
            DB::table('facturacion_solicitud')->where('id', $invoice->id)->update([
                'status' => 'cancelled', 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            DB::table('facturacion_cancelacion')->where('id', $cancellation->id)->update([
                'status' => 'approved', 'approved_at' => date('Y-m-d H:i:s'),
                'approval_source' => 'dev_simulation',
                'archived_files' => json_encode($archivedFiles), 'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }, 3);
    }

    private function detail($invoice)
    {
        $sales = DB::table('facturacion_solicitud_documento as link')
            ->join('documento as d', 'd.id', '=', 'link.id_documento')
            ->leftJoin('documento_entidad as e', 'e.id', '=', 'd.id_entidad')
            ->leftJoin('documento_factura as df', 'df.id_documento', '=', 'd.id')
            ->where('link.id_solicitud', $invoice->id)
            ->select('d.id', 'd.no_venta', 'd.id_tipo', 'd.id_fase', 'd.total', 'd.uuid',
                'd.factura_serie', 'd.factura_folio', 'd.id_entidad', 'e.razon_social', 'e.rfc',
                'df.pdf', 'df.xml')
            ->get();
        $cancellation = DB::table('facturacion_cancelacion')->where('id_solicitud', $invoice->id)->first();
        if ($cancellation && $cancellation->archived_files) {
            $archived = json_decode($cancellation->archived_files, true) ?: [];
            foreach ($sales as $sale) {
                $sale->pdf = $sale->pdf ?: ($archived[$sale->id]['pdf'] ?? null);
                $sale->xml = $sale->xml ?: ($archived[$sale->id]['xml'] ?? null);
            }
        }
        return ['factura' => $invoice, 'ventas' => $sales, 'cancelacion' => $cancellation];
    }
}
