<?php

namespace App\Http\Services\Nexfira;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoicePayloadBuilder
{
    const PHASE_PENDING_INVOICE = 5;

    public function buildIndividual($documentId, $externalReference, array $overrides = [])
    {
        $document = $this->getDocument($documentId);
        $items = $this->buildProductItems($document);
        $totals = $this->totalsFromItems($items);
        $this->assertStoredTotal($document, $totals['total']);

        return $this->basePayload($document, $externalReference, [
            'paymentMethod' => $this->paymentMethod($document, $overrides),
            'paymentForm' => $this->paymentForm($document, $overrides),
            'receiver' => $this->receiver($document),
            'items' => $items,
            'expectedTotals' => $totals,
        ]);
    }

    public function buildGlobal(array $documentIds, $externalReference, array $overrides = [])
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        sort($documentIds, SORT_NUMERIC);
        if (count($documentIds) < 2) {
            throw new InvalidArgumentException('Una factura global requiere al menos dos ventas.');
        }

        $documents = [];
        $items = [];
        foreach ($documentIds as $documentId) {
            $document = $this->getDocument($documentId);
            $documents[] = $document;
            $totals = $this->calculateDocumentTotals($document);
            $this->assertStoredTotal($document, $totals['total']);

            $base = (float) $totals['subtotal'] - (float) $totals['discount'];
            $tax = (float) $totals['transfers'];
            $descriptionReference = trim((string) $document->no_venta) !== ''
                ? trim((string) $document->no_venta)
                : (string) $document->id;

            $items[] = [
                'lineId' => 'documento-' . $document->id,
                'productCode' => (string) config('nexfira.global.product_code', '01010101'),
                'unitCode' => (string) config('nexfira.global.unit_code', 'ACT'),
                'description' => 'Folio ' . $descriptionReference,
                'quantity' => '1',
                'unitPrice' => $this->decimal($base, 2),
                'discount' => '0.00',
                'taxObject' => '02',
                'taxes' => [$this->transferTax($base, $tax)],
            ];
        }

        $currency = $documents[0]->currency;
        foreach ($documents as $document) {
            if ($document->currency !== $currency) {
                throw new InvalidArgumentException('Todas las ventas globalizadas deben usar la misma moneda.');
            }
        }

        $totals = $this->totalsFromItems($items);
        $first = $documents[0];

        return $this->basePayload($first, $externalReference, [
            'paymentMethod' => $this->validatedPaymentMethod(
                isset($overrides['paymentMethod']) ? $overrides['paymentMethod'] : config('nexfira.global.payment_method', 'PUE')
            ),
            'paymentForm' => $this->validatedPaymentForm(
                isset($overrides['paymentForm']) ? $overrides['paymentForm'] : config('nexfira.global.payment_form', '01')
            ),
            'receiver' => $this->publicReceiver(),
            'items' => $items,
            'expectedTotals' => $totals,
        ]);
    }

    public function preview($documentId)
    {
        try {
            $payload = $this->buildIndividual($documentId, 'preview-' . (int) $documentId);

            return [
                'valid' => true,
                'blockers' => [],
                'payload' => $payload,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'valid' => false,
                'blockers' => [$e->getMessage()],
                'payload' => null,
            ];
        }
    }

    private function basePayload($document, $externalReference, array $content)
    {
        $issuerId = trim((string) config('nexfira.issuer_id'));
        $expeditionPostalCode = trim((string) config('nexfira.expedition_postal_code'));
        if (!$this->isUuid($issuerId)) {
            throw new InvalidArgumentException('Falta configurar un NEXFIRA_ISSUER_ID válido.');
        }
        if (!preg_match('/^[0-9]{5}$/', $expeditionPostalCode)) {
            throw new InvalidArgumentException('Falta configurar NEXFIRA_EXPEDITION_POSTAL_CODE.');
        }

        return [
            'schemaVersion' => '1.0',
            'issuerId' => $issuerId,
            'externalReference' => substr((string) $externalReference, 0, 100),
            'kind' => 'CFDI_I',
            'subtype' => 'sale',
            'content' => [
                'cfdiVersion' => '4.0',
                'issuedAtLocal' => Carbon::now((string) config('nexfira.fiscal_timezone', 'America/Mexico_City'))->format('Y-m-d\TH:i:s'),
                'fiscalTimeZone' => (string) config('nexfira.fiscal_timezone', 'America/Mexico_City'),
                'expeditionPostalCode' => $expeditionPostalCode,
                'currency' => $document->currency,
                'paymentMethod' => $content['paymentMethod'],
                'paymentForm' => $content['paymentForm'],
                'exportCode' => '01',
                'receiver' => $content['receiver'],
                'items' => $content['items'],
                'expectedTotals' => $content['expectedTotals'],
            ],
        ];
    }

    private function getDocument($documentId)
    {
        $document = DB::table('documento as d')
            ->join('marketplace_area as ma', 'ma.id', '=', 'd.id_marketplace_area')
            ->join('marketplace as mk', 'mk.id', '=', 'ma.id_marketplace')
            ->join('documento_uso_cfdi as uc', 'uc.id', '=', 'd.id_cfdi')
            ->join('moneda as mn', 'mn.id', '=', 'd.id_moneda')
            ->leftJoin('documento_entidad as de', 'de.id', '=', 'd.id_entidad')
            ->where('d.id', (int) $documentId)
            ->where('d.id_tipo', 2)
            ->where('d.status', 1)
            ->whereNull('d.deleted_at')
            ->select([
                'd.id', 'd.id_fase', 'd.id_periodo', 'd.fulfillment', 'd.no_venta', 'd.total',
                'd.id_marketplace_area', 'ma.publico', 'mk.marketplace', 'mn.moneda as currency',
                'uc.codigo as cfdi_use', 'de.rfc', 'de.razon_social', 'de.regimen_id',
                'de.codigo_postal_fiscal',
            ])
            ->first();

        if (!$document) {
            throw new InvalidArgumentException('No se encontró la venta activa ' . (int) $documentId . '.');
        }
        if ((int) $document->id_fase !== self::PHASE_PENDING_INVOICE) {
            throw new InvalidArgumentException('La venta ' . $document->id . ' no está en fase 5 pendiente de factura.');
        }
        if (!preg_match('/^[A-Z]{3}$/', (string) $document->currency)) {
            throw new InvalidArgumentException('La venta ' . $document->id . ' no tiene una moneda ISO válida.');
        }

        return $document;
    }

    private function receiver($document)
    {
        if ((int) $document->publico === 1) {
            return $this->publicReceiver();
        }

        $receiver = [
            'rfc' => strtoupper(trim((string) $document->rfc)),
            'name' => trim((string) $document->razon_social),
            'fiscalRegime' => trim((string) $document->regimen_id),
            'postalCode' => trim((string) $document->codigo_postal_fiscal),
            'cfdiUse' => trim((string) $document->cfdi_use),
        ];
        $this->assertReceiver($receiver, $document->id);

        return $receiver;
    }

    private function publicReceiver()
    {
        $postalCode = trim((string) config('nexfira.global.receiver_postal_code'));
        if ($postalCode === '') {
            $postalCode = trim((string) config('nexfira.expedition_postal_code'));
        }

        $receiver = [
            'rfc' => strtoupper(trim((string) config('nexfira.global.receiver_rfc', 'XAXX010101000'))),
            'name' => trim((string) config('nexfira.global.receiver_name', 'PUBLICO EN GENERAL')),
            'fiscalRegime' => trim((string) config('nexfira.global.receiver_fiscal_regime', '616')),
            'postalCode' => $postalCode,
            'cfdiUse' => trim((string) config('nexfira.global.cfdi_use', 'S01')),
        ];
        $this->assertReceiver($receiver, 'global');

        return $receiver;
    }

    private function assertReceiver(array $receiver, $documentId)
    {
        if (!preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $receiver['rfc'])) {
            throw new InvalidArgumentException('El receptor de la venta ' . $documentId . ' no tiene RFC válido.');
        }
        if ($receiver['name'] === '') {
            throw new InvalidArgumentException('El receptor de la venta ' . $documentId . ' no tiene razón social.');
        }
        if (!preg_match('/^[0-9]{3}$/', $receiver['fiscalRegime'])) {
            throw new InvalidArgumentException('El receptor de la venta ' . $documentId . ' no tiene régimen fiscal válido.');
        }
        if (!preg_match('/^[0-9]{5}$/', $receiver['postalCode'])) {
            throw new InvalidArgumentException('El receptor de la venta ' . $documentId . ' no tiene código postal fiscal válido.');
        }
        if (!preg_match('/^[A-Z0-9]{3,4}$/', $receiver['cfdiUse'])) {
            throw new InvalidArgumentException('El receptor de la venta ' . $documentId . ' no tiene uso CFDI válido.');
        }
    }

    private function buildProductItems($document)
    {
        $movements = $this->movements($document->id);
        $items = [];
        foreach ($movements as $movement) {
            if ((int) $movement->retencion === 1) {
                throw new InvalidArgumentException('La venta ' . $document->id . ' tiene retenciones sin mapeo definido para Nexfira.');
            }
            if (!preg_match('/^[0-9]{8}$/', trim((string) $movement->clave_sat))) {
                throw new InvalidArgumentException('La partida ' . $movement->id . ' no tiene clave SAT de 8 dígitos.');
            }
            if (!preg_match('/^[A-Z0-9]{2,3}$/', trim((string) $movement->clave_unidad))) {
                throw new InvalidArgumentException('La partida ' . $movement->id . ' no tiene clave de unidad SAT válida.');
            }

            $quantity = (float) $movement->cantidad;
            $grossUnitPrice = (float) $movement->precio;
            $grossDiscount = (float) $movement->descuento;
            if ($quantity <= 0 || $grossUnitPrice < 0 || $grossDiscount < 0) {
                throw new InvalidArgumentException('La partida ' . $movement->id . ' tiene cantidades o importes inválidos.');
            }

            $divisor = 1 + $this->taxRate();
            $unitPrice = round($grossUnitPrice / $divisor, 6);
            $discount = round($grossDiscount / $divisor, 2);
            $base = round(($quantity * $unitPrice) - $discount, 2);
            if ($base < 0) {
                throw new InvalidArgumentException('El descuento de la partida ' . $movement->id . ' excede su importe.');
            }
            $tax = round($base * $this->taxRate(), 2);

            $items[] = [
                'lineId' => 'movimiento-' . $movement->id,
                'productCode' => trim((string) $movement->clave_sat),
                'unitCode' => trim((string) $movement->clave_unidad),
                'description' => mb_substr(trim((string) $movement->descripcion), 0, 1000, 'UTF-8'),
                'quantity' => $this->decimal($quantity, 6, true),
                'unitPrice' => $this->decimal($unitPrice, 6, true),
                'discount' => $this->decimal($discount, 2),
                'taxObject' => '02',
                'taxes' => [$this->transferTax($base, $tax)],
            ];
        }

        return $items;
    }

    private function movements($documentId)
    {
        $movements = DB::table('movimiento as mv')
            ->join('modelo as md', 'md.id', '=', 'mv.id_modelo')
            ->where('mv.id_documento', (int) $documentId)
            ->orderBy('mv.id')
            ->select([
                'mv.id', 'mv.cantidad', 'mv.precio', 'mv.descuento', 'mv.retencion',
                'md.descripcion', 'md.clave_sat', 'md.clave_unidad',
            ])
            ->get();

        if ($movements->isEmpty()) {
            throw new InvalidArgumentException('La venta ' . $documentId . ' no tiene partidas.');
        }

        return $movements;
    }

    private function calculateDocumentTotals($document)
    {
        $items = [];
        foreach ($this->movements($document->id) as $movement) {
            if ((int) $movement->retencion === 1) {
                throw new InvalidArgumentException('La venta ' . $document->id . ' tiene retenciones sin mapeo definido para Nexfira.');
            }
            $divisor = 1 + $this->taxRate();
            $unitPrice = round((float) $movement->precio / $divisor, 6);
            $discount = round((float) $movement->descuento / $divisor, 2);
            $base = round(((float) $movement->cantidad * $unitPrice) - $discount, 2);
            $tax = round($base * $this->taxRate(), 2);
            $items[] = [
                'quantity' => $this->decimal((float) $movement->cantidad, 6, true),
                'unitPrice' => $this->decimal($unitPrice, 6, true),
                'discount' => $this->decimal($discount, 2),
                'taxes' => [$this->transferTax($base, $tax)],
            ];
        }

        return $this->totalsFromItems($items);
    }

    private function totalsFromItems(array $items)
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $transfers = 0.0;
        foreach ($items as $item) {
            $subtotal += round((float) $item['quantity'] * (float) $item['unitPrice'], 2);
            $discount += isset($item['discount']) ? (float) $item['discount'] : 0.0;
            foreach ($item['taxes'] as $tax) {
                if ($tax['direction'] === 'transfer') {
                    $transfers += (float) ($tax['amount'] ?? 0);
                }
            }
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $transfers = round($transfers, 2);
        $total = round($subtotal - $discount + $transfers, 2);

        return [
            'subtotal' => $this->decimal($subtotal, 2),
            'discount' => $this->decimal($discount, 2),
            'transfers' => $this->decimal($transfers, 2),
            'withholdings' => '0.00',
            'total' => $this->decimal($total, 2),
        ];
    }

    private function transferTax($base, $amount)
    {
        return [
            'direction' => 'transfer',
            'taxCode' => '002',
            'factor' => 'Tasa',
            'base' => $this->decimal($base, 2),
            'rateOrQuota' => $this->decimal($this->taxRate(), 6),
            'amount' => $this->decimal($amount, 2),
        ];
    }

    private function paymentMethod($document, array $overrides)
    {
        if (isset($overrides['paymentMethod'])) {
            return $this->validatedPaymentMethod($overrides['paymentMethod']);
        }

        return (int) $document->id_periodo === 1 ? 'PUE' : 'PPD';
    }

    private function paymentForm($document, array $overrides)
    {
        if (isset($overrides['paymentForm'])) {
            return $this->validatedPaymentForm($overrides['paymentForm']);
        }

        $forms = DB::table('documento_pago as dp')
            ->join('documento_pago_re as dpr', 'dpr.id_pago', '=', 'dp.id')
            ->where('dpr.id_documento', $document->id)
            ->distinct()
            ->pluck('dp.id_metodopago')
            ->toArray();

        if (count($forms) !== 1) {
            return '99';
        }

        return $this->validatedPaymentForm($forms[0]);
    }

    private function validatedPaymentMethod($value)
    {
        $value = strtoupper(trim((string) $value));
        if (!in_array($value, ['PUE', 'PPD'], true)) {
            throw new InvalidArgumentException('El método de pago debe ser PUE o PPD.');
        }

        return $value;
    }

    private function validatedPaymentForm($value)
    {
        $value = str_pad(trim((string) $value), 2, '0', STR_PAD_LEFT);
        if (!preg_match('/^[0-9]{2}$/', $value)) {
            throw new InvalidArgumentException('La forma de pago debe ser una clave SAT de dos dígitos.');
        }

        return $value;
    }

    private function assertStoredTotal($document, $calculatedTotal)
    {
        if ($document->total === null) {
            return;
        }

        $difference = abs((float) $document->total - (float) $calculatedTotal);
        if ($difference > 0.05) {
            throw new InvalidArgumentException(
                'La venta ' . $document->id . ' no cuadra: total CRM $' .
                $this->decimal($document->total, 2) . ' contra CFDI $' .
                $this->decimal($calculatedTotal, 2) . '.'
            );
        }
    }

    private function taxRate()
    {
        return (float) config('nexfira.tax_rate', '0.160000');
    }

    private function decimal($value, $scale, $trim = false)
    {
        $formatted = number_format((float) $value, $scale, '.', '');
        if ($trim && strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }

    private function isUuid($value)
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
