<?php

use App\Http\Services\MercadolibrePackService;
use App\Http\Services\MercadolibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MercadolibrePackImportTest extends TestCase
{
    private $addressRoot;

    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge();
        $tables = [
            'documento' => 'id_cfdi id_almacen_principal_empresa id_marketplace_area id_usuario id_paqueteria id_fase id_entidad id_modelo_proveedor no_venta referencia observacion info_extra shipping_null fulfillment comentario saldo total mkt_publicacion mkt_total mkt_fee mkt_coupon mkt_shipping_total mkt_created_at started_at pagado',
            'seguimiento' => 'id_documento id_usuario seguimiento',
            'documento_direccion' => 'id_documento id_direccion_pro contacto calle numero numero_int colonia ciudad estado codigo_postal referencia',
            'movimiento' => 'id_documento id_modelo cantidad precio garantia modificacion regalo',
            'movimiento_contable' => 'folio id_tipo_afectacion fecha_operacion id_moneda tipo_cambio monto origen_tipo entidad_origen nombre_entidad_origen destino_tipo entidad_destino nombre_entidad_destino id_forma_pago referencia_pago descripcion_pago comentarios creado_por',
            'movimiento_contable_documento' => 'id_movimiento_contable id_documento monto_aplicado saldo_documento moneda tipo_cambio parcialidad',
            'marketplace_cuenta' => 'id_marketplace_area id_entidad_financiera',
            'cat_entidad_financiera' => 'nombre',
            'paqueteria' => 'paqueteria',
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function ($table) use ($name, $columns) {
                $table->increments('id');
                $table->integer('status')->default(1);
                if ($name === 'documento') {
                    $table->integer('id_tipo')->default(2);
                }
                foreach (explode(' ', $columns) as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        DB::table('marketplace_cuenta')->insert(['id_marketplace_area' => 1, 'id_entidad_financiera' => 5]);
        DB::table('cat_entidad_financiera')->insert(['id' => 5, 'nombre' => 'Test account']);
        $this->addressRoot = sys_get_temp_dir() . '/ml-pack-test-' . uniqid();
        mkdir($this->addressRoot . '/Consultas/CP', 0775, true);
        file_put_contents($this->addressRoot . '/Consultas/CP/00000', '{"code":400}');
        config(['webservice.url' => $this->addressRoot . '/']);
    }

    public function tearDown()
    {
        if ($this->addressRoot) {
            unlink($this->addressRoot . '/Consultas/CP/00000');
            rmdir($this->addressRoot . '/Consultas/CP');
            rmdir($this->addressRoot . '/Consultas');
            rmdir($this->addressRoot);
        }
        parent::tearDown();
    }

    private function sale()
    {
        $first = (object) ['id' => '2000018978381786', 'status' => 'paid', 'currency_id' => 'MXN', 'total_amount' => 6846.44, 'paid_amount' => 6846.44,
            'payments' => [(object) ['id' => '184144146896', 'status' => 'approved']]];
        $second = (object) ['id' => '2000018978392248', 'status' => 'paid', 'currency_id' => 'MXN', 'total_amount' => 3499, 'paid_amount' => 3499,
            'payments' => [(object) ['id' => '183119870433', 'status' => 'approved']]];
        $venta = clone $first;
        foreach (MercadolibrePackService::importes([$first, $second]) as $key => $value) {
            $venta->$key = $value;
        }
        $venta->pack = '2000015523281679';
        $venta->almacen = 1;
        $venta->proveedor = 0;
        $venta->error = 0;
        $venta->fase = 3;
        $venta->date_created = '2026-10-14T13:32:08.000-04:00';
        $venta->shipping = (object) ['receiver_address' => (object) ['zip_code' => '00000']];
        foreach (['is_delivered', 'is_ready_to_ship', 'is_buffered', 'is_creating_route', 'is_manufacturing'] as $key) {
            $venta->$key = false;
        }
        $venta->productos = [
            (object) ['id_modelo' => 43, 'cantidad' => 2, 'precio' => 171.161, 'garantia' => 0, 'regalo' => 0],
            (object) ['id_modelo' => 988, 'cantidad' => 2, 'precio' => 3252.059, 'garantia' => 90, 'regalo' => 0],
            (object) ['id_modelo' => 988, 'cantidad' => 1, 'precio' => 3499, 'garantia' => 90, 'regalo' => 0],
        ];
        return $venta;
    }

    public function testImportPersistsFullIncomeApplicationReferencesAndProductsOnlyOnce()
    {
        $sale = $this->sale();
        $result = MercadolibreService::importarVenta($sale, 1, 1);
        $this->assertSame(0, $result->error, $result->mensaje);
        $document = DB::table('documento')->first();
        $income = DB::table('movimiento_contable')->first();
        $application = DB::table('movimiento_contable_documento')->first();
        $this->assertEquals(10345.44, $document->total);
        $this->assertEquals(10345.44, $income->monto);
        $this->assertEquals(10345.44, $application->monto_aplicado);
        $this->assertEquals(0, $document->saldo);
        $this->assertEquals(1, $document->pagado);
        $this->assertSame('184144146896 / 183119870433', $income->referencia_pago);
        $lineTotal = DB::table('movimiento')->selectRaw('SUM(cantidad*precio) total')->first()->total;
        $this->assertEquals(10345.44, $lineTotal, '', 0.00001);
        MercadolibreService::importarVenta($sale, 1, 1);
        $this->assertSame(1, DB::table('documento')->count());
        $this->assertSame(1, DB::table('movimiento_contable')->count());
        $this->assertSame(1, DB::table('movimiento_contable_documento')->count());
    }

    public function testMissingDestinationRollsBackDocumentProductsAndIncome()
    {
        DB::table('marketplace_cuenta')->delete();
        $result = MercadolibreService::importarVenta($this->sale(), 1, 1);
        $this->assertSame(1, $result->error);
        foreach (['documento', 'movimiento', 'movimiento_contable', 'movimiento_contable_documento', 'seguimiento', 'documento_direccion'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function testInconsistentProductTotalDoesNotCreateAnIncome()
    {
        $sale = $this->sale();
        $sale->productos[2]->precio = 1;
        $result = MercadolibreService::importarVenta($sale, 1, 1);
        $this->assertSame(1, $result->error);
        $this->assertSame(0, DB::table('documento')->count());
        $this->assertSame(0, DB::table('movimiento_contable')->count());
    }

    public function testApplicationFailureRollsBackTheIncomeAndItsDocument()
    {
        DB::statement("CREATE TRIGGER reject_test_payment BEFORE INSERT ON movimiento_contable_documento BEGIN SELECT RAISE(ABORT, 'test application failure'); END");
        $result = MercadolibreService::importarVenta($this->sale(), 1, 1);
        $this->assertSame(1, $result->error);
        foreach (['documento', 'movimiento', 'movimiento_contable', 'movimiento_contable_documento'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function testAnIncomeAlreadyContainingTheSecondPaymentPreventsDuplication()
    {
        DB::table('movimiento_contable')->insert([
            'folio' => 'previous', 'id_tipo_afectacion' => 1,
            'referencia_pago' => '183119870433', 'monto' => 3499,
        ]);
        $result = MercadolibreService::importarVenta($this->sale(), 1, 1);
        $this->assertSame(1, $result->error);
        $this->assertSame(0, DB::table('documento')->count());
        $this->assertSame(1, DB::table('movimiento_contable')->count());
    }

    public function testPackAlreadyImportedIsFoundEvenWhenThePrincipalOrderChanges()
    {
        $sale = $this->sale();
        $first = MercadolibreService::importarVenta($sale, 1, 1);
        $this->assertSame(0, $first->error);
        $sale->id = '2000018978392248';
        $repeat = MercadolibreService::importarVenta($sale, 1, 1);
        $this->assertSame(1, $repeat->error);
        $this->assertSame((int) $first->documento, (int) $repeat->documento);
        $this->assertSame(1, DB::table('documento')->count());
        $this->assertSame(1, DB::table('movimiento_contable')->count());
    }
}
