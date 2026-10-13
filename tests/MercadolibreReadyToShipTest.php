<?php

use App\Http\Services\MercadolibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MercadolibreReadyToShipTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge();

        Schema::create('documento', function ($table) {
            $table->increments('id');
            $table->string('no_venta');
            $table->integer('status');
            $table->integer('id_fase');
            $table->integer('packing_by')->nullable();
            $table->integer('id_tipo')->default(2);
            $table->string('uuid')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('movimiento', function ($table) {
            $table->increments('id');
            $table->integer('id_documento');
        });

        Schema::create('movimiento_producto', function ($table) {
            $table->increments('id');
            $table->integer('id_movimiento');
        });
    }

    public function testReadyToShipPromotesAnUnprocessedPackToPhaseThree()
    {
        $documento = $this->insertDocumento('pack-elegible', 1, null);

        MercadolibreService::actualizarRTS_com((object) ['id' => $documento]);

        $this->assertSame(3, $this->faseDocumento($documento));
    }

    public function testReadyToShipDoesNotRegressPackedOrAdvancedDocuments()
    {
        $faseSeis = $this->insertDocumento('venta-procesada', 6, 165);
        $faseCinco = $this->insertDocumento('venta-procesada', 5, 165);
        $faseElegibleEmpacada = $this->insertDocumento('venta-procesada', 1, 165);

        MercadolibreService::actualizarRTS_doc_o('venta-procesada');

        $this->assertSame(6, $this->faseDocumento($faseSeis));
        $this->assertSame(5, $this->faseDocumento($faseCinco));
        $this->assertSame(1, $this->faseDocumento($faseElegibleEmpacada));
    }

    public function testReadyToShipDoesNotChangeAnEligiblePhaseWhenSeriesExist()
    {
        $documento = $this->insertDocumento('venta-con-series', 1, 0);
        $movimiento = DB::table('movimiento')->insertGetId([
            'id_documento' => $documento,
        ]);

        DB::table('movimiento_producto')->insert([
            'id_movimiento' => $movimiento,
        ]);

        MercadolibreService::actualizarRTS_doc('venta-con-series');

        $this->assertSame(1, $this->faseDocumento($documento));
    }

    public function testReadyToShipIgnoresCancelledDocumentsWithTheSameSaleNumber()
    {
        $activo = $this->insertDocumento('venta-repetida', 7, null, 1);
        $cancelado = $this->insertDocumento('venta-repetida', 1, null, 0);

        MercadolibreService::actualizarRTS_doc('venta-repetida');

        $this->assertSame(3, $this->faseDocumento($activo));
        $this->assertSame(1, $this->faseDocumento($cancelado));
    }

    public function deliveredHandlers()
    {
        return [['actualizarDelivered_com'], ['actualizarDelivered_doc'], ['actualizarDelivered_doc_o']];
    }

    /** @dataProvider deliveredHandlers */
    public function testDeliveredPreservesFiscalAndInactiveDocuments($handler)
    {
        foreach ([
            [6, 1, null, null, 2],
            [5, 1, '548DB0B3-F6AD-473C-B7DD-704B8F3A0439', null, 2],
            [3, 1, '548DB0B3-F6AD-473C-B7DD-704B8F3A0439', null, 2],
            [3, 0, null, null, 2],
            [3, 1, null, '2026-10-01 00:00:00', 2],
            [100, 1, null, null, 2],
            [3, 1, null, null, 6],
        ] as $case) {
            $id = $this->insertDocumento('delivered-protected', $case[0], null, $case[1]);
            DB::table('documento')->where('id', $id)->update([
                'uuid' => $case[2], 'deleted_at' => $case[3], 'id_tipo' => $case[4],
            ]);
            $before = DB::table('documento')->where('id', $id)->first();
            // Sin movimientos: también debe evitar reconstruir productos de una venta protegida.
            MercadolibreService::$handler($handler === 'actualizarDelivered_com'
                ? (object) ['id' => $id] : 'delivered-protected');
            $this->assertEquals($before, DB::table('documento')->where('id', $id)->first());
        }
        $this->assertSame(0, DB::table('movimiento')->count());
    }

    /** @dataProvider deliveredHandlers */
    public function testDeliveredPromotesPendingSalesAndIsIdempotent($handler)
    {
        foreach ([1, 2, 3, 4, 5, 7] as $phase) {
            $id = $this->insertDocumento('delivered-pending', $phase, null);
            DB::table('documento')->where('id', $id)->update(['uuid' => 'N/A']);
            DB::table('movimiento')->insert(['id_documento' => $id]);
            $arg = $handler === 'actualizarDelivered_com' ? (object) ['id' => $id] : 'delivered-pending';
            MercadolibreService::$handler($arg);
            MercadolibreService::$handler($arg);
            $this->assertSame(5, $this->faseDocumento($id));
            $this->assertSame('N/A', DB::table('documento')->where('id', $id)->value('uuid'));
        }
        $this->assertSame(6, DB::table('movimiento')->count());
    }

    private function insertDocumento($venta, $fase, $packingBy, $status = 1)
    {
        return DB::table('documento')->insertGetId([
            'no_venta' => $venta,
            'status' => $status,
            'id_fase' => $fase,
            'packing_by' => $packingBy,
        ]);
    }

    private function faseDocumento($documento)
    {
        return (int) DB::table('documento')
            ->where('id', $documento)
            ->value('id_fase');
    }
}
