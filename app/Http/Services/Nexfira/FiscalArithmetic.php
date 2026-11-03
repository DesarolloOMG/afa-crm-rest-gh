<?php

namespace App\Http\Services\Nexfira;

use InvalidArgumentException;

/** Cálculos decimales compartidos: partidas a centavos, impuestos a seis decimales. */
class FiscalArithmetic
{
    public static function round($value, $scale = 2)
    {
        if (!function_exists('bcadd')) {
            throw new InvalidArgumentException('El cálculo fiscal requiere la extensión BCMath de PHP.');
        }
        $value = is_float($value) ? sprintf('%.12F', $value) : (string) $value;
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Importe decimal fiscal inválido.');
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        return bcadd($value, ($value[0] === '-' ? '-' : '') . $half, $scale);
    }

    public static function divide($value, $divisor, $scale)
    {
        if (bccomp((string) $divisor, '0', 12) <= 0) {
            throw new InvalidArgumentException('El divisor fiscal debe ser positivo.');
        }
        return self::round(bcdiv((string) $value, (string) $divisor, 24), $scale);
    }

    public static function net(array $item)
    {
        return bcadd(self::round(bcsub(bcmul($item['quantity'], $item['unitPrice'], 18),
            $item['discount'] ?? '0', 18), 2), '0', 6);
    }

    public static function totals(array $items, $roundLineAmounts = true)
    {
        $subtotal = $discount = '0';
        $groups = [];
        foreach ($items as $item) {
            $lineSubtotal = bcmul($item['quantity'], $item['unitPrice'], 18);
            $subtotal = bcadd($subtotal, $roundLineAmounts ? self::round($lineSubtotal) : $lineSubtotal, 12);
            $discount = bcadd($discount, $item['discount'] ?? '0', 6);
            foreach ($item['taxes'] as $tax) {
                if ($tax['factor'] === 'Exento') { continue; }
                $key = $tax['direction'] === 'withholding'
                    ? 'withholding|' . $tax['taxCode'] : self::taxKey($tax);
                if (!isset($groups[$key])) { $groups[$key] = ['direction' => $tax['direction'], 'amount' => '0']; }
                $groups[$key]['amount'] = bcadd($groups[$key]['amount'], $tax['amount'], 6);
            }
        }
        $transfers = $withholdings = '0';
        foreach ($groups as $group) {
            // Primero sumar el grupo de impuesto; después redondear a moneda.
            if ($group['direction'] === 'transfer') {
                $transfers = bcadd($transfers, self::round($group['amount']), 2);
            } else {
                $withholdings = bcadd($withholdings, self::round($group['amount']), 2);
            }
        }
        $subtotal = self::round($subtotal);
        $discount = self::round($discount);
        $transfers = self::round($transfers);
        $withholdings = self::round($withholdings);
        return ['subtotal' => $subtotal, 'discount' => $discount, 'transfers' => $transfers,
            'withholdings' => $withholdings,
            'total' => bcsub(bcadd(bcsub($subtotal, $discount, 2), $transfers, 2), $withholdings, 2)];
    }

    public static function creditItems(array $items, array $components, array $adjustments)
    {
        $sources = [];
        foreach ($components as $component) { $sources[$component['lineId']] = $component; }
        $creditLines = [];
        foreach ($items as $item) { $creditLines[$item['lineId']] = $item; }
        $allocated = $taxes = $used = [];
        foreach ($adjustments as $adjustment) {
            $sourceId = $adjustment['antecedentLineId'];
            $creditId = $adjustment['creditLineId'];
            if (!isset($sources[$sourceId]) || !isset($sources[$sourceId]['taxes'])) {
                throw new InvalidArgumentException('Nexfira no devolvió la composición fiscal completa de ' . $sourceId . '.');
            }
            $source = $sources[$sourceId];
            if (!isset($creditLines[$creditId]) || ($source['taxObject'] ?? '') !== $creditLines[$creditId]['taxObject']) {
                throw new InvalidArgumentException('La asignación no corresponde al tratamiento fiscal de la partida de la NC.');
            }
            $net = $adjustment['netAmount'];
            $used[$sourceId] = bcadd($used[$sourceId] ?? '0', $net, 6);
            if (bccomp($net, '0', 6) <= 0 || bccomp($used[$sourceId], $source['netAmount'], 6) > 0) {
                throw new InvalidArgumentException('La asignación supera el neto fiscal disponible.');
            }
            $allocated[$creditId] = bcadd($allocated[$creditId] ?? '0', $net, 6);
            foreach ($source['taxes'] as $tax) {
                $key = self::taxKey($tax);
                $base = self::divide(bcmul($tax['base'], $net, 24), $source['netAmount'], 6);
                $amount = $tax['factor'] === 'Exento' ? null
                    : self::divide(bcmul($tax['amount'], $net, 24), $source['netAmount'], 6);
                if (!empty($source['taxRounding'])) {
                    $amount = self::externalCentTax($source, $tax, $net);
                    $base = self::round($net, 6);
                }
                if (!isset($taxes[$creditId][$key])) {
                    $taxes[$creditId][$key] = $tax;
                    $taxes[$creditId][$key]['base'] = '0';
                    if ($amount !== null) { $taxes[$creditId][$key]['amount'] = '0'; }
                }
                $taxes[$creditId][$key]['base'] = bcadd($taxes[$creditId][$key]['base'], $base, 6);
                if ($amount !== null) {
                    $taxes[$creditId][$key]['amount'] = bcadd($taxes[$creditId][$key]['amount'], $amount, 6);
                }
            }
        }
        foreach ($items as &$item) {
            $id = $item['lineId'];
            if (!isset($allocated[$id]) || bccomp(self::net($item), $allocated[$id], 6) !== 0) {
                throw new InvalidArgumentException('El neto de la NC no coincide con el reparto fiscal de ' . $id . '.');
            }
            $expectedKeys = array_map([self::class, 'taxKey'], $item['taxes']);
            $actual = $taxes[$id] ?? [];
            if (count($actual) !== count($expectedKeys) || array_diff($expectedKeys, array_keys($actual))) {
                throw new InvalidArgumentException('El tratamiento fiscal de la NC difiere del antecedente.');
            }
            $item['taxes'] = array_values($actual);
        }
        unset($item);
        return $items;
    }

    public static function assertPayload(array $payload)
    {
        if (!in_array($payload['kind'] ?? '', ['CFDI_I', 'CFDI_E'], true)) { return; }
        $content = $payload['content'];
        $totals = self::totals($content['items']);
        foreach ($totals as $field => $value) {
            if (!isset($content['expectedTotals'][$field])
                || bccomp($value, $content['expectedTotals'][$field], 6) !== 0) {
                throw new InvalidArgumentException('El total fiscal ' . $field . ' no coincide con las partidas e impuestos del JSON.');
            }
        }
        if (isset($content['balanceAdjustments'])) {
            $amount = '0';
            foreach ($content['balanceAdjustments'] as $adjustment) { $amount = bcadd($amount, $adjustment['amount'], 6); }
            if (bccomp($amount, $totals['total'], 6) !== 0) {
                throw new InvalidArgumentException('balanceAdjustments.amount no coincide con el total fiscal calculado.');
            }
        }
    }

    public static function taxKey(array $tax)
    {
        return implode('|', [$tax['direction'], $tax['taxCode'], $tax['factor'],
            self::round($tax['rateOrQuota'] ?? '0', 6)]);
    }

    private static function externalCentTax(array $source, array $tax, $net)
    {
        if ($source['taxRounding'] !== 'iva16_cents' || count($source['taxes']) !== 1
            || self::taxKey($tax) !== 'transfer|002|Tasa|0.160000') {
            throw new InvalidArgumentException('Modo de redondeo fiscal externo no soportado.');
        }
        foreach ([$net, $source['netAmount'], $tax['amount']] as $value) {
            if (bccomp($value, self::round($value), 6) !== 0) {
                throw new InvalidArgumentException('El saldo iva16_cents requiere importes a centavos.');
            }
        }
        if (bccomp($net, $source['netAmount'], 6) === 0) { return self::round($tax['amount'], 6); }
        $sourceCents = bcmul($source['netAmount'], '100', 0);
        $taxCents = bcmul($tax['amount'], '100', 0);
        $delta = bcsub(bcmul($taxCents, '100', 0), bcmul($sourceCents, '16', 0), 0);
        $numerator = bcmul(bcmul($net, '100', 0), '16', 0);
        if (bccomp($delta, '0', 0) >= 0) { $numerator = bcadd($numerator, '99', 0); }
        return bcdiv(bcdiv($numerator, '100', 0), '100', 6);
    }
}
