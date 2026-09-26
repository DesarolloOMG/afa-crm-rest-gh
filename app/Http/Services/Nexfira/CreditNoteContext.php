<?php

namespace App\Http\Services\Nexfira;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Resuelve el vínculo CRM venta.nota -> documento NC, sin modificar la venta. */
class CreditNoteContext
{
    public function resolve($creditNoteId, $requireHub = false)
    {
        $note = DB::table('documento')->where('id', (int) $creditNoteId)
            ->where('id_tipo', 6)->where('status', 1)->whereNull('deleted_at')->first();
        $sources = DB::table('documento as d')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('documento_entidad as de', 'de.id', '=', 'd.id_entidad')
            ->join('moneda as mn', 'mn.id', '=', 'd.id_moneda')
            ->where('d.id_tipo', 2)->whereNull('d.deleted_at')
            ->whereRaw('TRIM(d.nota) = ?', [(string) (int) $creditNoteId])
            ->select('d.*', 'de.rfc', 'mn.moneda as currency')->get();
        if (!$note || $sources->count() !== 1) {
            throw new InvalidArgumentException('La NC ' . (int) $creditNoteId . ' debe tener una venta origen identificada en documento.nota.');
        }
        $source = $sources->first();
        $uuid = strtoupper(trim((string) $source->uuid));
        if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
            throw new InvalidArgumentException('Primero registra el UUID de la factura de la venta origen ' . $source->id . ' para relacionar la NC.');
        }
        $request = DB::table('facturacion_solicitud as fs')
            ->join('facturacion_solicitud_documento as fsd', 'fsd.id_solicitud', '=', 'fs.id')
            ->where('fsd.id_documento', $source->id)->where('fs.proveedor', 'nexfira')
            ->where('fs.status', 'stamped')->whereRaw('UPPER(fs.fiscal_uuid) = ?', [$uuid])
            ->orderBy('fs.id', 'desc')->select('fs.*')->first();
        $payload = $request ? json_decode((string) $request->request_payload, true) : null;
        $sameEntity = (int) $note->id_entidad === (int) $source->id_entidad;
        if (!$sameEntity && isset($note->es_refactura) && (int) $note->es_refactura === 1) {
            $noteRfc = DB::table('documento_entidad')->where('id', $note->id_entidad)->value('rfc');
            $sameEntity = strtoupper(trim((string) $noteRfc)) === 'XAXX010101000'
                && strtoupper(trim((string) ($payload['content']['receiver']['rfc'] ?? ''))) === 'XAXX010101000';
        }
        if (!$sameEntity || (int) $note->id_moneda !== (int) $source->id_moneda) {
            throw new InvalidArgumentException('La NC debe corresponder al receptor y moneda de la factura origen.');
        }
        if ($requireHub && (!$request || !$request->remote_request_id
            || ($payload['kind'] ?? null) !== 'CFDI_I' || empty($payload['content']['receiver']))) {
            throw new InvalidArgumentException('Nexfira sólo acepta NC sobre una factura origen timbrada en el Hub. La venta '
                . $source->id . ' no tiene ese antecedente; usa Fuera del Hub para una NC emitida externamente.');
        }
        if ($requireHub && ($payload['content']['currency'] ?? null) !== $source->currency) {
            throw new InvalidArgumentException('La moneda de la factura origen no coincide con la NC.');
        }

        return [
            'source_id' => (int) $source->id,
            'source_uuid' => $uuid,
            'currency' => $source->currency,
            'receiver_rfc' => $payload['content']['receiver']['rfc'] ?? strtoupper(trim($source->rfc)),
            'request_id' => $request ? $request->remote_request_id : null,
            'source_payload' => $payload,
        ];
    }
}
