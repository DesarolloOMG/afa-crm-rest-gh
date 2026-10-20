<?php

use App\Http\Services\MercadolibrePackService;
use App\Http\Services\MercadolibreService;
use PHPUnit\Framework\TestCase;

class MercadolibrePackTest extends TestCase
{
    private function order($id = '2000018978381786', $total = 6846.44, $payment = '184144146896')
    {
        return (object) [
            'id' => $id, 'pack_id' => '2000015523281679',
            'seller' => (object) ['id' => 1051840872],
            'status' => 'paid', 'currency_id' => 'MXN',
            'total_amount' => $total, 'paid_amount' => $total,
            'shipping' => (object) ['status' => 'ready_to_ship', 'substatus' => 'printed'],
            'payments' => [(object) ['id' => $payment, 'status' => 'approved']],
        ];
    }

    public function testCompletePackIncludesOrderMissingFromSearchAndSumsBothPayments()
    {
        $first = $this->order();
        $second = $this->order('2000018978392248', 3499, '183119870433');
        $calls = [];
        $fetch = function ($path) use ($first, $second, &$calls) {
            $calls[] = $path;
            $data = [
                'packs/2000015523281679' => (object) ['id' => '2000015523281679', 'orders' => [(object) ['id' => $first->id], (object) ['id' => $second->id]]],
                'orders/' . $first->id => $first,
                'orders/' . $second->id => $second,
                'orders/' . $second->id . '/shipments' => $second->shipping,
            ];
            return clone $data[$path];
        };
        $orders = MercadolibrePackService::resolver($first->pack_id, [$first, $first], 1051840872, $fetch);
        $totals = MercadolibrePackService::importes($orders);
        $this->assertCount(2, $orders);
        $this->assertEquals(10345.44, $totals['total_amount']);
        $this->assertEquals(10345.44, $totals['paid_amount']);
        $this->assertSame(['184144146896', '183119870433'], array_column($totals['payments'], 'id'));
        $this->assertEquals(6846.44, $first->paid_amount);
        $this->assertTrue($orders[1]->is_ready_to_ship);
        $this->assertContains('orders/' . $second->id . '/shipments', $calls);
    }

    public function testRepeatedSearchOrderDoesNotDuplicateAmount()
    {
        $order = $this->order();
        $totals = MercadolibrePackService::importes([$order, clone $order]);
        $this->assertEquals(6846.44, $totals['paid_amount']);
        $this->assertCount(1, $totals['payments']);
    }

    public function testSeveralApprovedPaymentsAndRejectedAttemptKeepTheirReferences()
    {
        $order = $this->order('123', 0.30, 'first');
        $order->payments[] = (object) ['id' => 'failed', 'status' => 'rejected'];
        $order->payments[] = (object) ['id' => 'second', 'status' => 'approved'];
        $totals = MercadolibrePackService::importes([$order]);
        $this->assertSame(['first', 'second'], array_column($totals['payments'], 'id'));
        $this->assertEquals(0.30, $totals['paid_amount']);
    }

    public function testIncompletePackStopsImportInsteadOfUsingOnlyTheKnownOrder()
    {
        $this->expectException(RuntimeException::class);
        MercadolibrePackService::resolver('2000015523281679', [$this->order()], 1051840872, function () {
            return (object) ['error' => 'not_available'];
        });
    }

    public function testExplicitNotOwnedOrderIsExcludedButUnknownErrorsStopImport()
    {
        $first = $this->order();
        $orders = MercadolibrePackService::resolver($first->pack_id, [$first], 1051840872, function ($path) use ($first) {
            if (strpos($path, 'packs/') === 0) {
                return (object) ['id' => $first->pack_id, 'orders' => [(object) ['id' => $first->id], (object) ['id' => 'external']]];
            }
            return $path === 'orders/external' ? (object) ['error' => 'not_owned_order'] : clone $first;
        });
        $this->assertCount(1, $orders);
        $this->expectException(RuntimeException::class);
        MercadolibrePackService::resolver($first->pack_id, [$first], 1051840872, function ($path) use ($first) {
            if (strpos($path, 'packs/') === 0) {
                return (object) ['id' => $first->pack_id, 'orders' => [(object) ['id' => $first->id], (object) ['id' => 'unknown']]];
            }
            return $path === 'orders/unknown' ? (object) ['error' => 'unavailable'] : clone $first;
        });
    }

    public function testOrderBelongingToAnotherPackIsRejected()
    {
        $first = $this->order();
        $this->expectException(RuntimeException::class);
        MercadolibrePackService::resolver($first->pack_id, [$first], 1051840872, function ($path) use ($first) {
            if (strpos($path, 'packs/') === 0) {
                return (object) ['id' => $first->pack_id, 'orders' => [(object) ['id' => $first->id]]];
            }
            $wrong = clone $first;
            $wrong->pack_id = 'different';
            return $wrong;
        });
    }

    public function testAnotherSellerAndCancelledOrdersAreExcluded()
    {
        $first = $this->order();
        $other = $this->order('2000018978392248', 3499, 'other');
        $other->seller = (object) ['id' => 999];
        $cancelled = $this->order('2000018978392249', 100, 'cancelled');
        $cancelled->status = 'cancelled';
        $byId = [$first->id => $first, $other->id => $other, $cancelled->id => $cancelled];
        $orders = MercadolibrePackService::resolver($first->pack_id, [$first], 1051840872, function ($path) use ($byId) {
            if (strpos($path, 'packs/') === 0) {
                return (object) ['id' => '2000015523281679', 'orders' => array_map(function ($id) { return (object) ['id' => $id]; }, array_keys($byId))];
            }
            return clone $byId[substr($path, 7)];
        });
        $this->assertCount(1, $orders);
        $this->assertEquals(6846.44, MercadolibrePackService::importes($orders)['paid_amount']);
    }

    public function invalidOrders()
    {
        return [
            ['status', 'cancelled'], ['currency_id', 'USD'], ['paid_amount', 100],
            ['paid_amount', null], ['payments', []], ['total_amount', -1],
        ];
    }

    /** @dataProvider invalidOrders */
    public function testUnverifiablePaymentsStopImport($property, $value)
    {
        $order = $this->order();
        $order->$property = $value;
        $this->expectException(RuntimeException::class);
        MercadolibrePackService::importes([$order]);
    }

    public function testSamePaymentCannotBeCountedAcrossTwoOrders()
    {
        $this->expectException(RuntimeException::class);
        MercadolibrePackService::importes([$this->order(), $this->order('second')]);
    }

    public function testGroupingSameModelPreservesBothPricesAndThePackTotal()
    {
        $products = [
            (object) ['id_modelo' => 43, 'cantidad' => 2, 'precio' => 171.161],
            (object) ['id_modelo' => 988, 'cantidad' => 2, 'precio' => 3252.059],
            (object) ['id_modelo' => 988, 'cantidad' => 1, 'precio' => 3499],
        ];
        $grouped = MercadolibreService::agruparProductos($products);
        $this->assertCount(2, $grouped);
        $this->assertEquals(3, $grouped[1]->cantidad);
        $this->assertEquals(10345.44, array_sum(array_map(function ($p) { return $p->cantidad * $p->precio; }, $grouped)), '', 0.00001);
        $this->assertEquals(3252.059, $products[1]->precio);
    }
}
