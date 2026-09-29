<?php

use App\Http\Services\Nexfira\FacturacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FacturacionDraftLineTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge();
        Schema::create('documento', function ($t) {
            $t->increments('id'); $t->integer('id_tipo'); $t->integer('id_fase');
            $t->string('uuid')->nullable(); $t->decimal('total', 20, 2);
            $t->decimal('saldo', 20, 2); $t->integer('pagado');
        });
        Schema::create('movimiento', function ($t) {
            $t->increments('id'); $t->integer('id_documento'); $t->decimal('cantidad', 20, 2);
            $t->decimal('precio', 20, 2); $t->decimal('descuento', 20, 2);
        });
        Schema::create('facturacion_solicitud', function ($t) {
            $t->increments('id'); $t->string('status');
        });
        Schema::create('facturacion_solicitud_documento', function ($t) {
            $t->integer('id_solicitud'); $t->integer('id_documento');
        });
        Schema::create('seguimiento', function ($t) {
            $t->increments('id'); $t->integer('id_documento'); $t->integer('id_usuario');
            $t->text('seguimiento');
        });
        DB::table('documento')->insert(['id' => 1, 'id_tipo' => 2, 'id_fase' => 5,
            'uuid' => null, 'total' => 232, 'saldo' => 232, 'pagado' => 0]);
        DB::table('movimiento')->insert(['id' => 7, 'id_documento' => 1, 'cantidad' => 2,
            'precio' => 116, 'descuento' => 0]);
    }

    private function service()
    {
        return (new ReflectionClass(FacturacionService::class))->newInstanceWithoutConstructor();
    }

    public function testUpdatesOrderLineTotalAndBalanceTogether()
    {
        $result = $this->service()->updateDraftLine(1, 7, '120.00', '4.00', 3);
        $this->assertSame(236.0, $result['total']);
        $this->assertEquals(120, DB::table('movimiento')->where('id', 7)->value('precio'));
        $this->assertEquals(4, DB::table('movimiento')->where('id', 7)->value('descuento'));
        $this->assertEquals(236, DB::table('documento')->where('id', 1)->value('total'));
        $this->assertEquals(236, DB::table('documento')->where('id', 1)->value('saldo'));
        $this->assertSame(1, DB::table('seguimiento')->count());
    }

    public function testRejectsTotalBelowAppliedPaymentsWithoutChangingOrder()
    {
        DB::table('documento')->where('id', 1)->update(['saldo' => 0, 'pagado' => 1]);
        try {
            $this->service()->updateDraftLine(1, 7, '100.00', '0.00', 3);
            $this->fail('Se esperaba rechazo por pagos aplicados.');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('pagos aplicados', $e->getMessage());
        }
        $this->assertEquals(116, DB::table('movimiento')->where('id', 7)->value('precio'));
        $this->assertEquals(232, DB::table('documento')->where('id', 1)->value('total'));
    }
}
