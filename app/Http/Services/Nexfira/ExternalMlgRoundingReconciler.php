<?php

namespace App\Http\Services\Nexfira;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/** Conciliación de CFDI MLG agrupado por importe de pedido, sin alterar la venta. */
class ExternalMlgRoundingReconciler
{
    public function reconcile($xml, array $documents)
    {
        if (count($documents) < 2) { return null; }
        $buckets = [];
        $source = 0;
        foreach ($documents as $document) {
            if ((int) $document->id_tipo !== 2 || strtoupper(trim($document->rfc)) !== 'MLG100224TC1'
                || strtoupper(trim($document->currency)) !== 'MXN') { return null; }
            $gross = $this->units($document->total, 4);
            if ($gross <= 0) { return null; }
            // Total con IVA / 1.16, redondeado a centavos por pedido.
            $netCents = intdiv($gross + 58, 116);
            $buckets[$netCents] = ($buckets[$netCents] ?? 0) + 1;
            $source += $gross;
        }
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) { return null; }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('c', 'http://www.sat.gob.mx/cfd/4');
        $root = $xp->query('/c:Comprobante')->item(0);
        $receiver = $xp->query('/c:Comprobante/c:Receptor')->item(0);
        if (!$root || !$receiver || $root->getAttribute('TipoDeComprobante') !== 'I'
            || $root->getAttribute('Moneda') !== 'MXN' || $receiver->getAttribute('Rfc') !== 'MLG100224TC1'
            || $this->units($root->getAttribute('Descuento') ?: '0', 6) !== 0) { return null; }
        $concepts = $xp->query('/c:Comprobante/c:Conceptos/c:Concepto');
        $subtotalCents = 0;
        $taxMicros = 0;
        foreach ($concepts as $concept) {
            $quantity = $this->units($concept->getAttribute('Cantidad'), 6);
            $unit = $this->units($concept->getAttribute('ValorUnitario'), 6);
            if ($quantity <= 0 || $quantity % 1000000 || $unit <= 0 || $unit % 10000
                || $concept->getAttribute('ObjetoImp') !== '02'
                || $this->units($concept->getAttribute('Descuento') ?: '0', 6) !== 0) { return null; }
            $quantity = intdiv($quantity, 1000000);
            $netCents = intdiv($unit, 10000);
            if (!isset($buckets[$netCents]) || $buckets[$netCents] < $quantity) { return null; }
            $base = $netCents * $quantity;
            if ($this->units($concept->getAttribute('Importe'), 6) !== $base * 10000
                || $xp->query('c:Impuestos/c:Retenciones/*', $concept)->length) { return null; }
            $taxes = $xp->query('c:Impuestos/c:Traslados/c:Traslado', $concept);
            if ($taxes->length !== 1) { return null; }
            $tax = $taxes->item(0);
            if (!$this->isVat($tax) || $this->units($tax->getAttribute('Base'), 6) !== $base * 10000
                || $this->units($tax->getAttribute('Importe'), 6) !== $base * 1600) { return null; }
            $buckets[$netCents] -= $quantity;
            $subtotalCents += $base;
            $taxMicros += $base * 1600;
        }
        if (array_sum($buckets) !== 0) { return null; }
        $taxCents = intdiv($taxMicros + 5000, 10000);
        $taxRoot = $xp->query('/c:Comprobante/c:Impuestos')->item(0);
        $taxes = $xp->query('/c:Comprobante/c:Impuestos/c:Traslados/c:Traslado');
        if (!$taxRoot || $taxes->length !== 1 || $xp->query('/c:Comprobante/c:Impuestos/c:Retenciones/*')->length
            || !$this->isVat($taxes->item(0))
            || $this->units($taxes->item(0)->getAttribute('Base'), 6) !== $subtotalCents * 10000
            || $this->units($taxes->item(0)->getAttribute('Importe'), 6) !== $taxCents * 10000
            || $this->units($taxRoot->getAttribute('TotalImpuestosTrasladados'), 6) !== $taxCents * 10000
            || $this->units($root->getAttribute('SubTotal'), 6) !== $subtotalCents * 10000
            || $this->units($root->getAttribute('Total'), 6) !== ($subtotalCents + $taxCents) * 10000) { return null; }
        $sourceCents = intdiv($source + 50, 100);
        $fiscalCents = $subtotalCents + $taxCents;
        return ['rule' => 'mlg_order_net_price_2_decimals_vat_16',
            'document_count' => count($documents), 'concept_count' => $concepts->length,
            'source_total' => $this->money($sourceCents), 'fiscal_total' => $this->money($fiscalCents),
            'difference' => $this->money($fiscalCents - $sourceCents),
            'subtotal' => $this->money($subtotalCents), 'transfers' => $this->money($taxCents)];
    }

    private function isVat($tax)
    {
        return $tax->getAttribute('Impuesto') === '002' && $tax->getAttribute('TipoFactor') === 'Tasa'
            && $this->units($tax->getAttribute('TasaOCuota'), 6) === 160000;
    }

    private function units($value, $scale)
    {
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,6}))?$/D', (string) $value, $parts)) {
            throw new InvalidArgumentException('Importe inválido para conciliar el redondeo del CFDI.');
        }
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException('El importe excede la precisión admitida para conciliar el CFDI.');
        }
        return (int) $parts[1] * (10 ** $scale) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    private function money($cents)
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
