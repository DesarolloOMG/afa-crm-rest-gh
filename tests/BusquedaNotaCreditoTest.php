<?php

use App\Http\Controllers\GeneralController;
use App\Http\Services\BusquedaNotaCreditoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusquedaNotaCreditoTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge();
        Schema::create('documento', function ($table) {
            $table->integer('id')->primary();
            foreach (['id_tipo', 'id_entidad', 'id_usuario', 'id_marketplace_area', 'id_moneda', 'status'] as $field) {
                $table->integer($field)->nullable();
            }
            foreach (['referencia', 'observacion', 'factura_serie', 'factura_folio', 'uuid', 'nota', 'created_at'] as $field) {
                $table->string($field)->nullable();
            }
            $table->decimal('tipo_cambio', 12, 4)->default(1);
        });
        foreach ([
            'documento_entidad' => ['razon_social', 'rfc', 'correo', 'telefono'],
            'usuario' => ['nombre'], 'marketplace' => ['marketplace'],
            'area' => ['area'], 'moneda' => ['moneda'], 'modelo' => ['sku', 'descripcion'],
        ] as $name => $fields) {
            Schema::create($name, function ($table) use ($fields) {
                $table->integer('id')->primary();
                foreach ($fields as $field) {
                    $table->string($field)->nullable();
                }
            });
        }
        Schema::create('marketplace_area', function ($table) {
            $table->integer('id')->primary();
            $table->integer('id_marketplace');
            $table->integer('id_area');
        });
        Schema::create('movimiento', function ($table) {
            $table->integer('id')->primary();
            $table->integer('id_documento');
            $table->integer('id_modelo');
            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio', 12, 6);
        });
        DB::table('documento_entidad')->insert([
            ['id' => 1, 'razon_social' => 'Cliente de la nota', 'rfc' => 'XAXX010101000'],
            ['id' => 2, 'razon_social' => 'Otro cliente del pedido', 'rfc' => 'XEXX010101000'],
        ]);
        DB::table('documento')->insert([
            ['id' => 38195, 'id_tipo' => 6, 'id_entidad' => 1, 'status' => 1, 'nota' => 'N/A'],
            ['id' => 35629, 'id_tipo' => 2, 'id_entidad' => 2, 'status' => 1, 'nota' => '38195'],
            ['id' => 35630, 'id_tipo' => 2, 'id_entidad' => 2, 'status' => 1, 'nota' => '38195'],
        ]);
        DB::table('modelo')->insert(['id' => 1, 'sku' => 'SKU-NC', 'descripcion' => 'Producto de la nota']);
        DB::table('movimiento')->insert([
            ['id' => 1, 'id_documento' => 38195, 'id_modelo' => 1, 'cantidad' => 2, 'precio' => 116],
            ['id' => 2, 'id_documento' => 35629, 'id_modelo' => 1, 'cantidad' => 10, 'precio' => 999],
        ]);
    }

    public function testSearchReturnsTheOriginalNoteOnceEvenWithoutShippingCatalogs()
    {
        $notes = (new BusquedaNotaCreditoService())->buscar('38195');
        $this->assertCount(1, $notes);
        $this->assertSame(38195, (int) $notes[0]->id);
        $this->assertSame(6, (int) $notes[0]->id_tipo);
        $this->assertSame('Cliente de la nota', $notes[0]->cliente);
        $this->assertCount(0, (new BusquedaNotaCreditoService())->buscar('35629'));
    }

    public function testDetailUsesOnlyTheNotesCustomerAndProducts()
    {
        $response = (new GeneralController())->general_busqueda_venta_nota_informacion(
            new Request(['data' => json_encode(['documento' => 38195])])
        );
        $note = json_decode($response->getContent())->nota;
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Cliente de la nota', $note->cliente);
        $this->assertCount(1, $note->productos);
        $this->assertEquals(2, $note->productos[0]->cantidad);
        $this->assertEquals(232, $note->total);
        $this->assertEquals(200, $note->subtotal);
        $this->assertEquals(32, $note->iva);
    }

    public function testCancelledOrUnlinkedNotesRemainAvailableForConsultation()
    {
        DB::table('documento')->where('id', 38195)->update(['status' => 0]);
        DB::table('documento')->where('id_tipo', 2)->delete();
        $service = new BusquedaNotaCreditoService();
        $this->assertCount(1, $service->buscar('3819'));
        $this->assertSame(0, (int) $service->detalle(38195)->status);
    }

    public function testMissingModelDoesNotDropTheNotesLineOrAmount()
    {
        DB::table('modelo')->delete();
        $note = (new BusquedaNotaCreditoService())->detalle(38195);
        $this->assertCount(1, $note->productos);
        $this->assertNull($note->productos[0]->sku);
        $this->assertEquals(232, $note->total);
    }

    public function testSalesAndUnknownIdsCannotBeOpenedAsCreditNotes()
    {
        foreach ([35629, 999999] as $id) {
            $response = (new GeneralController())->general_busqueda_venta_nota_informacion(
                new Request(['data' => json_encode(['documento' => $id])])
            );
            $this->assertSame(404, $response->getStatusCode());
        }
        foreach ([null, 0, -1, '38195x', ['38195']] as $id) {
            $response = (new GeneralController())->general_busqueda_venta_nota_informacion(
                new Request(['data' => json_encode(['documento' => $id])])
            );
            $this->assertSame(422, $response->getStatusCode());
        }
    }
}
