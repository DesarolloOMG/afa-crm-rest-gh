<?php

namespace App\Http\Services\Nexfira;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Aplica las reservas de saldo del contrato E sin adivinar versiones ni partidas fiscales. */
class CreditNoteBalanceAllocator
{
    private $client;

    public function __construct(NexfiraClient $client)
    {
        $this->client = $client;
    }

    public function apply($documentId, array $payload)
    {
        $context = (new CreditNoteContext())->resolve($documentId, true);
        try {
            $balance = $this->client->getPaymentBalance($context['request_id']);
        } catch (NexfiraApiException $exception) {
            // El Hub no administra saldo para ciertos antecedentes PUE históricos.
            if ($exception->getHttpStatus() === 404
                && empty($context['external_origin'])
                && ($context['source_payload']['content']['paymentMethod'] ?? '') === 'PUE') {
                return $payload;
            }
            throw $exception;
        }
        if (($balance['antecedentId'] ?? '') !== $context['request_id']
            || ($balance['currency'] ?? '') !== $context['currency']
            || (int) ($balance['version'] ?? 0) < 1
            || !isset($balance['availableBalance'], $balance['reservedAmount'])
            || (float) $balance['reservedAmount'] > 0) {
            throw new InvalidArgumentException('La factura origen no tiene saldo disponible suficiente o tiene otra reserva activa en Nexfira.');
        }
        $fiscal = $this->client->getPaymentBalance($context['request_id'], true);
        if (($fiscal['antecedentId'] ?? '') !== $context['request_id']
            || (int) ($fiscal['version'] ?? 0) !== (int) $balance['version']
            || (isset($fiscal['currency']) && $fiscal['currency'] !== $context['currency'])) {
            throw new InvalidArgumentException('El saldo fiscal cambió; actualiza y vuelve a solicitar la nota de crédito.');
        }
        $remaining = [];
        foreach ($fiscal['components'] ?? [] as $component) {
            $remaining[$component['lineId']] = FiscalArithmetic::round($component['netAmount'], 6);
        }
        $sourceItems = [];
        foreach ($context['source_payload']['content']['items'] as $item) {
            $sourceItems[$item['lineId']] = $item;
        }
        $originalMovements = DB::table('movimiento')->where('id_documento', $context['source_id'])->orderBy('id')->get();
        $creditMovements = DB::table('movimiento')->where('id_documento', (int) $documentId)->get()->keyBy('id');
        $adjustments = [];
        foreach ($payload['content']['items'] as $creditItem) {
            $creditId = (int) str_replace('movimiento-', '', $creditItem['lineId']);
            $movement = $creditMovements->get($creditId);
            $candidates = [];
            if (isset($sourceItems[(string) $context['source_id']])) {
                // Factura global por ventas: el antecedente es una sola partida por pedido.
                $candidates[] = (string) $context['source_id'];
            } elseif ($movement) {
                foreach ($originalMovements as $original) {
                    if ((int) $original->id_modelo !== (int) $movement->id_modelo
                        || abs((float) $original->precio - (float) $movement->precio) > 0.000001) {
                        continue;
                    }
                    foreach (['movimiento-' . $original->id, 'pedido-' . $context['source_id'] . '-partida-' . $original->id] as $lineId) {
                        if (isset($sourceItems[$lineId])) {
                            $candidates[] = $lineId;
                        }
                    }
                }
            }
            if (!empty($context['external_origin'])) {
                // Los IDs del XML no son IDs de movimiento del CRM. Sólo usar conceptos
                // verificables por descripción, clave SAT, unidad y precio; nunca por posición.
                foreach ($sourceItems as $lineId => $sourceItem) {
                    if (trim($sourceItem['description'] ?? '') === trim($creditItem['description'])
                        && ($sourceItem['productCode'] ?? '') === $creditItem['productCode']
                        && ($sourceItem['unitCode'] ?? '') === $creditItem['unitCode']
                        && count($sourceItem['taxes'] ?? []) === count($creditItem['taxes'])
                        && isset($sourceItem['unitPrice'])
                        && bccomp(FiscalArithmetic::round($sourceItem['unitPrice']), $creditItem['unitPrice'], 6) === 0) {
                        $candidates[] = (string) $lineId;
                    }
                }
            }
            $candidates = array_values(array_unique($candidates));
            $pending = FiscalArithmetic::net($creditItem);
            foreach ($candidates as $lineId) {
                // No atribuir IVA de la NC a una partida con un tratamiento fiscal distinto.
                $sourceTax = $sourceItems[$lineId]['taxes'][0] ?? [];
                $creditTax = $creditItem['taxes'][0];
                if (($sourceItems[$lineId]['taxObject'] ?? '') !== $creditItem['taxObject']
                    || ($sourceTax['taxCode'] ?? '') !== $creditTax['taxCode']
                    || ($sourceTax['factor'] ?? '') !== $creditTax['factor']
                    || ($sourceTax['rateOrQuota'] ?? '') !== $creditTax['rateOrQuota']) {
                    continue;
                }
                $available = $remaining[$lineId] ?? '0';
                $amount = bccomp($pending, $available, 6) < 0 ? $pending : $available;
                // No consumir fracciones de centavo de un saldo histórico.
                $amount = bcdiv($amount, '1', 2);
                if ($amount <= 0) {
                    continue;
                }
                $adjustments[] = [
                    'antecedentLineId' => $lineId,
                    'creditLineId' => $creditItem['lineId'],
                    'netAmount' => FiscalArithmetic::round($amount, 2),
                ];
                $remaining[$lineId] = bcsub($remaining[$lineId], $amount, 6);
                $pending = bcsub($pending, $amount, 6);
                if ($pending <= 0) {
                    break;
                }
            }
            if ($pending > 0.000001) {
                throw new InvalidArgumentException('No se pudo relacionar el saldo fiscal de la partida '
                    . $creditItem['lineId'] . ' con la factura origen. Revisa productos, precios y notas previas.');
            }
        }
        $payload['content']['items'] = FiscalArithmetic::creditItems($payload['content']['items'], $fiscal['components'] ?? [], $adjustments);
        $payload['content']['expectedTotals'] = FiscalArithmetic::totals($payload['content']['items']);
        $total = $payload['content']['expectedTotals']['total'];
        if (bccomp($total, '0', 6) <= 0 || bccomp($total, $balance['availableBalance'], 6) > 0) {
            throw new InvalidArgumentException('El total fiscal calculado supera el saldo disponible de la factura origen.');
        }
        $payload['content']['balanceAdjustments'] = [[
            'antecedentId' => $context['request_id'],
            'expectedBalanceVersion' => (int) $balance['version'],
            'amount' => $total,
            'lineAdjustments' => $adjustments,
        ]];

        return $payload;
    }
}
