<?php

use App\Http\Services\Nexfira\FiscalArithmetic;

class NexfiraFiscalArithmeticTest extends \PHPUnit\Framework\TestCase
{
    private function item($id, $net, $tax)
    {
        return ['lineId' => $id, 'quantity' => '1', 'unitPrice' => $net, 'discount' => '0.00',
            'taxObject' => '02', 'taxes' => [['direction' => 'transfer', 'taxCode' => '002',
                'factor' => 'Tasa', 'base' => $net, 'rateOrQuota' => '0.160000', 'amount' => $tax]]];
    }

    private function component($id, $net, $tax)
    {
        $item = $this->item($id, $net, $tax);
        return ['lineId' => $id, 'netAmount' => $net, 'taxObject' => '02', 'taxes' => $item['taxes']];
    }

    public function testProviderFeedbackGroupsProportionalIvaBeforeRoundingTotal()
    {
        $items = [$this->item('credit-1', '1651.18', '264.19'), $this->item('credit-2', '14860.66', '2377.71')];
        $components = [$this->component('origin-1', '1651.184483', '264.189517'),
            $this->component('origin-2', '14860.660345', '2377.705655')];
        $lines = [['antecedentLineId' => 'origin-1', 'creditLineId' => 'credit-1', 'netAmount' => '1651.180000'],
            ['antecedentLineId' => 'origin-2', 'creditLineId' => 'credit-2', 'netAmount' => '14860.660000']];
        $result = FiscalArithmetic::creditItems($items, $components, $lines);
        $this->assertSame(['264.188800', '2377.705600'],
            array_map(function ($item) { return $item['taxes'][0]['amount']; }, $result));
        $this->assertSame(['subtotal' => '16511.84', 'discount' => '0.00', 'transfers' => '2641.89',
            'withholdings' => '0.00', 'total' => '19153.73'], FiscalArithmetic::totals($result));
        $this->assertSame('264.19', $items[0]['taxes'][0]['amount']);
    }

    public function testHalfUpUsesDecimalStringsAtLargeValuesAndNegativeDifferences()
    {
        $this->assertSame('1000000000000.00', FiscalArithmetic::round('999999999999.995'));
        $this->assertSame('0.00', FiscalArithmetic::round('0.004999999999'));
        $this->assertSame('0.01', FiscalArithmetic::round('0.005000000000'));
        $this->assertSame('-0.01', FiscalArithmetic::round('-0.005000000000'));
    }

    public function testSeveralAntecedentComponentsCanCoverOneCreditLine()
    {
        $result = FiscalArithmetic::creditItems([$this->item('credit', '166.66', '0')],
            [$this->component('a', '100.00', '16.00'), $this->component('b', '100.00', '16.00')], [
                ['antecedentLineId' => 'a', 'creditLineId' => 'credit', 'netAmount' => '100.00'],
                ['antecedentLineId' => 'b', 'creditLineId' => 'credit', 'netAmount' => '66.66'],
            ]);
        $this->assertSame('26.665600', $result[0]['taxes'][0]['amount']);
        $this->assertSame('193.33', FiscalArithmetic::totals($result)['total']);
    }

    public function testExternalCentRemainderModeDoesNotInventProportionalSixDecimalTax()
    {
        $source = $this->component('a', '100.00', '15.99');
        $source['taxRounding'] = 'iva16_cents';
        $half = FiscalArithmetic::creditItems([$this->item('c', '50.00', '0')], [$source], [
            ['antecedentLineId' => 'a', 'creditLineId' => 'c', 'netAmount' => '50.00'],
        ]);
        $this->assertSame('8.000000', $half[0]['taxes'][0]['amount']);
        $full = FiscalArithmetic::creditItems([$this->item('c', '100.00', '0')], [$source], [
            ['antecedentLineId' => 'a', 'creditLineId' => 'c', 'netAmount' => '100.00'],
        ]);
        $this->assertSame('15.990000', $full[0]['taxes'][0]['amount']);
    }

    public function testEachTaxRateIsGroupedSeparatelyAndExemptTaxHasNoAmount()
    {
        $a = $this->item('a', '1.00', '0.004900');
        $b = $this->item('b', '1.00', '0.004900');
        $b['taxes'][0]['rateOrQuota'] = '0.080000';
        $exempt = $this->item('e', '1.00', '0');
        $exempt['taxes'] = [['direction' => 'transfer', 'taxCode' => '002', 'factor' => 'Exento', 'base' => '1.00']];
        $this->assertSame('0.00', FiscalArithmetic::totals([$a, $b, $exempt])['transfers']);
        $this->assertSame('3.00', FiscalArithmetic::totals([$a, $b, $exempt])['total']);
    }

    public function testUnknownSourceCannotBeReplacedByAnInventedTax()
    {
        $this->expectException(InvalidArgumentException::class);
        FiscalArithmetic::creditItems([$this->item('c', '100', '16')], [], [
            ['antecedentLineId' => 'missing', 'creditLineId' => 'c', 'netAmount' => '100'],
        ]);
    }

    public function testBalanceAmountMustEqualTheSameCalculatedFiscalTotal()
    {
        $items = [$this->item('a', '1651.18', '264.188800'), $this->item('b', '14860.66', '2377.705600')];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('balanceAdjustments.amount');
        FiscalArithmetic::assertPayload(['kind' => 'CFDI_E', 'content' => ['items' => $items,
            'expectedTotals' => FiscalArithmetic::totals($items), 'balanceAdjustments' => [['amount' => '19153.74']]]]);
    }
}
