<?php

use App\Http\Services\Nexfira\ExternalMlgRoundingReconciler;

class ExternalMlgRoundingReconcilerTest extends PHPUnit\Framework\TestCase
{
    public function testExplainsRoundingFromAllSelectedOrderPrices()
    {
        $result = (new ExternalMlgRoundingReconciler())->reconcile($this->xml(), $this->documents());
        $this->assertSame('103960.00', $result['source_total']);
        $this->assertSame('103960.13', $result['fiscal_total']);
        $this->assertSame('0.13', $result['difference']);
        $this->assertSame(40, $result['document_count']);
    }

    /** @dataProvider unrelatedXmlCases */
    public function testDoesNotAcceptUnexplainedDifferences($from, $to)
    {
        $this->assertNull((new ExternalMlgRoundingReconciler())->reconcile(
            str_replace($from, $to, $this->xml()), $this->documents()));
    }

    public function unrelatedXmlCases()
    {
        return [['Cantidad="40.00"', 'Cantidad="39.00"'],
            ['ValorUnitario="2240.52"', 'ValorUnitario="2240.53"'],
            ['14339.328000', '14339.330000'], ['Total="103960.13"', 'Total="103960.14"'],
            ['MLG100224TC1', 'AAA010101AAA'], ['Moneda="MXN"', 'Moneda="USD"'],
            ['TotalImpuestosTrasladados="14339.33"', 'TotalImpuestosTrasladados="14339.34"'],
            ['ObjetoImp="02"', 'ObjetoImp="01"']];
    }

    public function testRejectsWrongSelectionEvenWithTheSameAggregateTotal()
    {
        $documents = $this->documents();
        $documents[0] = clone $documents[0]; $documents[0]->total = '2598.98';
        $documents[1] = clone $documents[1]; $documents[1]->total = '2599.02';
        $this->assertNull((new ExternalMlgRoundingReconciler())->reconcile($this->xml(), $documents));
        $documents = $this->documents();
        $documents[0] = clone $documents[0]; $documents[0]->rfc = 'XAXX010101000';
        $this->assertNull((new ExternalMlgRoundingReconciler())->reconcile($this->xml(), $documents));
    }

    private function documents()
    {
        return array_fill(0, 40, (object) ['id_tipo' => 2, 'rfc' => 'MLG100224TC1', 'currency' => 'MXN', 'total' => '2599.0000']);
    }

    private function xml() { return file_get_contents(__DIR__ . '/fixtures/mlg-external-rounded.xml'); }
}
