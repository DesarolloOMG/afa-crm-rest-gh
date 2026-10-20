<?php

namespace App\Http\Services;

use RuntimeException;

class MercadolibrePackService
{
    /** Resolve the complete pack, including orders omitted by search filters or pages. */
    public static function resolver($packId, array $ventas, $sellerId, callable $fetch): array
    {
        $known = [];
        foreach ($ventas as $venta) {
            $known[(string) $venta->id] = $venta;
        }
        $ids = array_keys($known);
        if (count($ids) !== 1 || (string) $packId !== (string) $ids[0]) {
            $pack = $fetch('packs/' . rawurlencode((string) $packId));
            if (empty($pack->orders) || (string) ($pack->id ?? '') !== (string) $packId) {
                throw new RuntimeException('No se pudo verificar el contenido completo del paquete ' . $packId);
            }
            $ids = [];
            foreach ($pack->orders as $order) {
                if (empty($order->id)) {
                    throw new RuntimeException('El paquete contiene una orden sin identificador');
                }
                $ids[(string) $order->id] = (string) $order->id;
            }
            foreach (array_keys($known) as $id) {
                if (!isset($ids[$id])) {
                    throw new RuntimeException('Una orden de la busqueda no pertenece al paquete');
                }
            }
        }

        $resolved = [];
        foreach ($ids as $id) {
            $order = $fetch('orders/' . rawurlencode((string) $id));
            if (($order->error ?? '') === 'not_owned_order' && !isset($known[(string) $id])) {
                continue;
            }
            if (empty($order->id) || (string) $order->id !== (string) $id
                || (string) ($order->pack_id ?? $order->id) !== (string) $packId
                || empty($order->seller->id)) {
                throw new RuntimeException('No se pudo verificar la orden ' . $id . ' del paquete ' . $packId);
            }
            // ML permits packs with orders from different sellers.
            if ((string) $order->seller->id !== (string) $sellerId || ($order->status ?? '') === 'cancelled') {
                continue;
            }
            $order->shipping = isset($known[(string) $id])
                ? $known[(string) $id]->shipping
                : $fetch('orders/' . rawurlencode((string) $id) . '/shipments');
            $status = $order->shipping->status ?? null;
            $substatus = $order->shipping->substatus ?? null;
            $order->is_buffered = $status === 'pending' && $substatus === 'buffered';
            $order->is_manufacturing = $status === 'pending' && $substatus === 'manufacturing';
            $order->is_creating_route = $status === 'pending' && $substatus === 'creating_route';
            $order->is_ready_to_ship = $status === 'ready_to_ship' && in_array($substatus, ['ready_to_print', 'printed'], true);
            $order->is_delivered = $status === 'delivered';
            $resolved[] = $order;
        }
        if (!$resolved) {
            throw new RuntimeException('El paquete no contiene ordenes activas del vendedor');
        }
        return $resolved;
    }

    public static function importes(array $ventas): array
    {
        $total = 0;
        $paid = 0;
        $orders = [];
        $payments = [];
        foreach ($ventas as $venta) {
            $id = (string) ($venta->id ?? '');
            if ($id === '') {
                throw new RuntimeException('Orden sin identificador');
            }
            if (isset($orders[$id])) {
                continue;
            }
            $orders[$id] = true;
            if (($venta->status ?? '') !== 'paid' || ($venta->currency_id ?? '') !== 'MXN'
                || !isset($venta->total_amount, $venta->paid_amount)
                || !is_numeric($venta->total_amount) || !is_numeric($venta->paid_amount)) {
                throw new RuntimeException('La orden ' . $id . ' no tiene importes pagados verificables en MXN');
            }
            $orderTotal = (int) round($venta->total_amount * 100);
            $orderPaid = (int) round($venta->paid_amount * 100);
            if ($orderTotal <= 0 || $orderPaid !== $orderTotal) {
                throw new RuntimeException('El pago no coincide con el total de la orden ' . $id);
            }
            $total += $orderTotal;
            $paid += $orderPaid;
            $hasPayment = false;
            foreach ($venta->payments ?? [] as $payment) {
                if (($payment->status ?? '') !== 'approved' || empty($payment->id)) {
                    continue;
                }
                $hasPayment = true;
                $paymentId = (string) $payment->id;
                if (isset($payments[$paymentId])) {
                    throw new RuntimeException('El mismo pago aparece en mas de una orden: ' . $paymentId);
                }
                $payments[$paymentId] = clone $payment;
            }
            if (!$hasPayment) {
                throw new RuntimeException('No se encontro un pago aprobado de la orden ' . $id);
            }
        }
        if (!$orders) {
            throw new RuntimeException('No hay ordenes para calcular el ingreso');
        }
        return ['total_amount' => $total / 100, 'paid_amount' => $paid / 100, 'payments' => array_values($payments)];
    }
}
