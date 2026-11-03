<?php

use App\Http\Controllers\CompraController;
use App\Http\Services\OdcReceptionValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OdcReceptionValidationTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge();
        foreach ([
            'CREATE TABLE documento (id INTEGER PRIMARY KEY, id_tipo INTEGER, id_fase INTEGER, finished_at TEXT, id_almacen_principal_empresa INTEGER DEFAULT 1)',
            'CREATE TABLE modelo (id INTEGER PRIMARY KEY, serie INTEGER)',
            'CREATE TABLE movimiento (id INTEGER PRIMARY KEY, id_documento INTEGER, id_modelo INTEGER, cantidad INTEGER)',
            'CREATE TABLE producto (id INTEGER PRIMARY KEY, serie TEXT, status INTEGER, id_almacen INTEGER, id_modelo INTEGER, fecha_caducidad TEXT)',
            'CREATE TABLE movimiento_producto (id INTEGER PRIMARY KEY, id_movimiento INTEGER, id_producto INTEGER)',
            'CREATE TABLE seguimiento (id_documento INTEGER, id_usuario INTEGER, seguimiento TEXT)',
            'CREATE TABLE empresa_almacen (id INTEGER PRIMARY KEY, id_almacen INTEGER)',
            'CREATE TABLE modelo_existencias (id_modelo INTEGER, id_almacen INTEGER, stock_inicial INTEGER, stock INTEGER, stock_anterior INTEGER)',
            'CREATE TABLE documento_recepcion (id INTEGER PRIMARY KEY, id_usuario INTEGER, id_movimiento INTEGER, cantidad INTEGER, documento_erp TEXT, afectado INTEGER)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('documento')->insert([
            ['id' => 1, 'id_tipo' => 0, 'id_fase' => 606],
            ['id' => 2, 'id_tipo' => 0, 'id_fase' => 607],
            ['id' => 3, 'id_tipo' => 2, 'id_fase' => 5],
        ]);
        DB::table('modelo')->insert(['id' => 1, 'serie' => 1]);
        DB::table('empresa_almacen')->insert(['id' => 1, 'id_almacen' => 1]);
        DB::table('movimiento')->insert([
            ['id' => 10, 'id_documento' => 1, 'id_modelo' => 1, 'cantidad' => 2],
            ['id' => 11, 'id_documento' => 1, 'id_modelo' => 1, 'cantidad' => 2],
            ['id' => 20, 'id_documento' => 2, 'id_modelo' => 1, 'cantidad' => 100],
            ['id' => 30, 'id_documento' => 3, 'id_modelo' => 1, 'cantidad' => 2],
        ]);
    }

    private function data(array $serials = [], $finalize = false)
    {
        return (object)['id' => 1, 'finalizar' => $finalize, 'archivos' => [], 'productos' => [
            (object)['id' => 10, 'cantidad' => 999, 'serie' => 1, 'series' => array_map(function ($s) {
                return (object)['id' => $s[0], 'serie' => $s[1]];
            }, $serials)],
        ]];
    }

    private function link($id, $serial, $movement)
    {
        if (!DB::table('producto')->where('id', $id)->exists()) {
            DB::table('producto')->insert(['id' => $id, 'serie' => $serial, 'status' => 1]);
        }
        DB::table('movimiento_producto')->insert(['id_movimiento' => $movement, 'id_producto' => $id]);
    }

    private function submit($data)
    {
        $request = Request::create('/', 'POST', ['data' => json_encode($data)]);
        $request->auth = json_encode(['id' => 1]);
        return (new OdcReceptionTestController())->compra_orden_recepcion_guardar($request)->getData(true);
    }

    public function testReceivingAnExistingSerialFromAnotherOdcHasNoSideEffects()
    {
        $this->link(100, 'ABC', 20);
        $before = DB::table('producto')->first();
        $response = $this->submit($this->data([[0, ' abc ']]));
        $this->assertSame(409, $response['code']);
        $this->assertContains('ODC 2', $response['message']);
        $this->assertEquals($before, DB::table('producto')->first());
        $this->assertSame(1, DB::table('movimiento_producto')->count());
        $this->assertSame(0, DB::table('seguimiento')->count());
        $this->assertEquals(606, DB::table('documento')->where('id', 1)->value('id_fase'));
    }

    public function testFinalizingChecksSavedLinksEvenWhenNoProductsAreSubmitted()
    {
        $this->link(100, 'ABC', 20);
        $this->link(100, 'ABC', 10);
        $data = $this->data([], true);
        $data->productos = [];
        $this->assertSame(409, $this->submit($data)['code']);
        $this->assertEquals(606, DB::table('documento')->where('id', 1)->value('id_fase'));
        $this->assertSame(0, DB::table('seguimiento')->count());
    }

    public function testFinalizingRejectsDuplicateSavedLinksInTheSameOdc()
    {
        $this->link(100, 'ABC', 10);
        $this->link(100, 'ABC', 10);
        $this->assertSame(409, $this->submit($this->data([], true))['code']);
        $this->assertEquals(606, DB::table('documento')->where('id', 1)->value('id_fase'));
    }

    public function testDifferentProductIdsWithTheSameSerialAreStillDuplicates()
    {
        $this->link(100, 'ABC', 10);
        $this->link(101, ' abc ', 11);
        $this->assertSame(409, $this->submit($this->data([], true))['code']);
    }

    public function testRepeatedSerialInAnIncomingBatchIsRejected()
    {
        $this->assertSame(409, $this->submit($this->data([[0, 'ABC'], [0, ' abc ']]))['code']);
        $this->assertSame(0, DB::table('movimiento_producto')->count());
    }

    public function testRepeatedSerialAcrossIncomingMovementsIsRejected()
    {
        $data = $this->data([[0, 'ABC']]);
        $second = clone $data->productos[0];
        $second->id = 11;
        $data->productos[] = $second;
        $this->assertSame(409, $this->submit($data)['code']);
    }

    public function testRescanningAnAlreadyReceivedSerialInTheSameOdcIsRejected()
    {
        $this->link(100, 'ABC', 10);
        $this->assertSame(409, $this->submit($this->data([[0, 'ABC']]))['code']);
    }

    public function testForgedExistingSerialIdDoesNotBypassValidation()
    {
        $this->link(100, 'ABC', 20);
        $this->assertSame(409, $this->submit($this->data([[100, 'ABC']]))['code']);
    }

    public function testClientCannotDisableSerialValidationOrUseAnotherOdcsMovement()
    {
        $data = $this->data();
        $data->productos[0]->serie = 0;
        $this->assertSame(409, $this->submit($data)['code']);
        $data = $this->data();
        $data->productos[0]->id = 20;
        $this->assertSame(409, $this->submit($data)['code']);
    }

    public function testQuantityUsesDatabaseLimitRatherThanClientLimit()
    {
        $this->assertSame(409, $this->submit($this->data([[0, 'A'], [0, 'B'], [0, 'C']]))['code']);
    }

    public function testLegitimatePartialReceptionCanKeepItsExistingSerialAndAddANewOne()
    {
        $this->link(100, 'ABC', 10);
        $data = $this->data([[100, 'ABC'], [0, 'DEF']]);
        (new OdcReceptionValidator())->validate($data);
        $this->assertEquals(2, $data->productos[0]->cantidad);
    }

    public function testSalesLinksDoNotPreventAValidOdcFromFinalizing()
    {
        $this->link(100, 'ABC', 10);
        $this->link(100, 'ABC', 30);
        $this->assertSame(200, $this->submit($this->data([[100, 'ABC']], true))['code']);
        $this->assertEquals(607, DB::table('documento')->where('id', 1)->value('id_fase'));
    }

    public function testEmptySerialIsRejected()
    {
        $this->assertSame(409, $this->submit($this->data([[0, '  ']]))['code']);
    }

    public function testValidPartialReceptionActuallySavesStockAndCanBeContinued()
    {
        $first = $this->submit($this->data([[0, 'ABC']]));
        $this->assertSame(200, $first['code']);
        $this->assertEquals(0, $first['terminada']);
        $this->assertEquals(606, DB::table('documento')->where('id', 1)->value('id_fase'));
        $id = DB::table('producto')->where('serie', 'ABC')->value('id');
        $second = $this->submit($this->data([[$id, 'ABC'], [0, 'DEF']]));
        $this->assertSame(200, $second['code']);
        $this->assertSame(2, DB::table('movimiento_producto')->count());
        $this->assertEquals(2, DB::table('modelo_existencias')->value('stock'));
        $this->assertEquals(2, DB::table('documento_recepcion')->sum('cantidad'));
    }

    public function testNumericSerialsKeepTheirLeadingZeroes()
    {
        $this->link(100, '00123', 20);
        (new OdcReceptionValidator())->validate($this->data([[0, '123']]));
        $this->assertSame(409, $this->submit($this->data([[0, '00123']]))['code']);
    }

    public function testFailureAfterStockWasWrittenRevertsTheEntireReception()
    {
        OdcReceptionTestController::$failPdf = true;
        try {
            $this->assertSame(409, $this->submit($this->data([[0, 'ABC']]))['code']);
        } finally {
            OdcReceptionTestController::$failPdf = false;
        }
        foreach (['producto', 'movimiento_producto', 'modelo_existencias', 'documento_recepcion', 'seguimiento'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table . ' must be rolled back');
        }
        $this->assertEquals(606, DB::table('documento')->where('id', 1)->value('id_fase'));
    }

    public function testAnotherOdcsSerialIsRejectedEvenIfItHasADifferentProductId()
    {
        $this->link(100, 'ABC', 20);
        $this->link(101, 'ABC', 10);
        $this->assertSame(409, $this->submit($this->data([[101, 'ABC']], true))['code']);
    }

    public function testMysqlLockIsReleasedEvenWhenTheReceptionFails()
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->with('SELECT GET_LOCK(?, 15) AS acquired', ['afa.odc.recepcion.series'])
            ->once()->andReturn((object)['acquired' => 1]);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($operation) { return $operation(); });
        $connection->shouldReceive('selectOne')->with('SELECT RELEASE_LOCK(?) AS released', ['afa.odc.recepcion.series'])
            ->once()->andReturn((object)['released' => 1]);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(DomainException::class);
        (new OdcReceptionValidator())->run(function () { throw new DomainException('failed'); });
    }

    public function testBusyMysqlLockDoesNotStartTheReception()
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->with('SELECT GET_LOCK(?, 15) AS acquired', ['afa.odc.recepcion.series'])
            ->once()->andReturn((object)['acquired' => 0]);
        $connection->shouldNotReceive('transaction');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(DomainException::class);
        (new OdcReceptionValidator())->run(function () { throw new RuntimeException('must not run'); });
    }

    public function testFailureRollsBackTheWholeReception()
    {
        try {
            (new OdcReceptionValidator())->run(function () {
                DB::table('seguimiento')->insert(['id_documento' => 1, 'id_usuario' => 1]);
                throw new DomainException('invalid reception');
            });
            $this->fail('Expected reception failure');
        } catch (DomainException $e) {
            $this->assertSame(0, DB::table('seguimiento')->count());
        }
    }
}

class OdcReceptionTestController extends CompraController
{
    public static $failPdf = false;

    protected function ordenes_recepcion_pdf($documento, $recepcion_erp, $user_id): stdClass
    {
        if (self::$failPdf) {
            throw new DomainException('Receipt generation failed');
        }
        return (object)['data' => 'test receipt', 'name' => 'receipt.pdf'];
    }
}
