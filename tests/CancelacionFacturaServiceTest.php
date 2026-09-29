<?php

use App\Http\Services\Nexfira\CancelacionFacturaService;
use App\Http\Services\Nexfira\NexfiraClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CancelacionFacturaServiceTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'nexfira.cancellation.create_path' => '/cancel/{requestId}',
            'nexfira.cancellation.status_path' => '/cancel/{requestId}']);
        DB::purge();
        Schema::create('facturacion_solicitud', function ($table) {
            $table->increments('id'); $table->string('proveedor'); $table->string('modo');
            $table->string('serie'); $table->string('folio'); $table->string('status');
            $table->string('remote_request_id'); $table->string('fiscal_uuid');
            $table->integer('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('facturacion_solicitud_documento', function ($table) {
            $table->increments('id'); $table->integer('id_solicitud'); $table->integer('id_documento');
        });
        Schema::create('facturacion_cancelacion', function ($table) {
            $table->increments('id'); $table->integer('id_solicitud')->unique();
            $table->string('status'); $table->string('provider_status')->nullable();
            $table->string('motivo'); $table->string('folio_sustitucion')->nullable();
            $table->string('remote_cancellation_id')->nullable(); $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable(); $table->text('archived_files')->nullable(); $table->integer('created_by');
            $table->integer('updated_by'); $table->timestamp('approved_at')->nullable();
            $table->string('approval_source')->nullable(); $table->timestamps();
        });
        Schema::create('documento', function ($table) {
            $table->increments('id'); $table->string('no_venta'); $table->integer('id_tipo');
            $table->integer('id_fase'); $table->decimal('total'); $table->string('uuid');
            $table->string('factura_serie'); $table->string('factura_folio');
            $table->integer('id_entidad'); $table->timestamp('invoice_date')->nullable(); $table->timestamps();
        });
        Schema::create('documento_entidad', function ($table) {
            $table->increments('id'); $table->string('razon_social'); $table->string('rfc');
        });
        Schema::create('documento_factura', function ($table) {
            $table->increments('id'); $table->integer('id_documento'); $table->string('pdf'); $table->string('xml');
        });
        Schema::create('seguimiento', function ($table) {
            $table->increments('id'); $table->integer('id_documento'); $table->integer('id_usuario'); $table->text('seguimiento');
        });
    }

    public function tearDown()
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPendingDoesNotReleaseSalesAndDevApprovalReleasesOnlyLinkedInvoice()
    {
        $uuid = '923E4567-E89B-42D3-A456-426614174000';
        $requestId = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira', 'modo' => 'global', 'serie' => 'FML', 'folio' => '40062',
            'status' => 'stamped', 'remote_request_id' => 'remote-1', 'fiscal_uuid' => $uuid,
        ]);
        DB::table('documento_entidad')->insert(['id' => 1, 'razon_social' => 'Cyberpuerta', 'rfc' => 'AAA010101AAA']);
        foreach ([101, 102] as $id) {
            DB::table('documento')->insert(['id' => $id, 'no_venta' => 'V-' . $id, 'id_tipo' => 2,
                'id_fase' => 6, 'total' => 116, 'uuid' => $uuid, 'factura_serie' => 'FML',
                'factura_folio' => '40062', 'id_entidad' => 1]);
            DB::table('facturacion_solicitud_documento')->insert(['id_solicitud' => $requestId, 'id_documento' => $id]);
            DB::table('documento_factura')->insert(['id_documento' => $id, 'pdf' => 'pdf-' . $id, 'xml' => 'xml-' . $id]);
        }
        $client = Mockery::mock(NexfiraClient::class);
        $client->shouldReceive('getDocumentRequest')->once()->with('remote-1')->andReturn([
            'requestId' => 'remote-1', 'fiscalUuid' => $uuid, 'status' => 'stamped', 'version' => 3,
        ]);
        $client->shouldReceive('getCancellation')->twice()->with('remote-1')->andReturn(
            ['requestId' => 'remote-1', 'canCancel' => true, 'cancellation' => null],
            ['requestId' => 'remote-1', 'cancellation' => [
                'id' => 'cancel-1', 'status' => 'cancelled', 'motive' => '02', 'replacementUuid' => null,
            ]]
        );
        $client->shouldReceive('createCancellation')->once()
            ->with('remote-1', ['expectedVersion' => 3, 'motive' => '02', 'replacementUuid' => null], Mockery::type('string'))
            ->andReturn(['requestId' => 'remote-1', 'cancellation' => [
                'id' => 'cancel-1', 'status' => 'requested', 'motive' => '02', 'replacementUuid' => null,
            ]]);
        $service = new CancelacionFacturaService($client);
        $pending = $service->request('40062', 'FML', '02', '', 7);
        $this->assertSame('pending_approval', $pending['cancelacion']->status);
        $this->assertSame(2, DB::table('documento')->where('id_fase', 6)->count());
        try {
            $service->simulate('40062', 'FML', 7);
            $this->fail('DEV no debe liberar antes de que Nexfira indique cancelled.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nexfira reporte cancelled', $e->getMessage());
        }
        $service->refresh('40062', 'FML', 7);
        $this->assertSame(2, DB::table('documento')->where('id_fase', 6)->count());
        $this->assertSame('pending_approval', DB::table('facturacion_cancelacion')->value('status'));
        $this->assertSame('cancelled', DB::table('facturacion_cancelacion')->value('provider_status'));
        $approved = $service->simulate('40062', 'FML', 7);
        $this->assertSame('approved', $approved['cancelacion']->status);
        $this->assertSame('dev_simulation', $approved['cancelacion']->approval_source);
        $this->assertSame(2, DB::table('documento')->where('id_fase', 5)->where('uuid', '')->count());
        $this->assertSame(0, DB::table('documento_factura')->count());
        $this->assertSame(2, DB::table('seguimiento')->count());
        $this->assertSame('pdf-101', $approved['ventas'][0]->pdf);
    }

    public function testProviderCancelledStatusNeverReleasesSaleWithoutDevAction()
    {
        $uuid = '923E4567-E89B-42D3-A456-426614174000';
        $requestId = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira', 'modo' => 'individual', 'serie' => 'C', 'folio' => '40063',
            'status' => 'stamped', 'remote_request_id' => 'remote-2', 'fiscal_uuid' => $uuid,
        ]);
        DB::table('documento_entidad')->insert(['id' => 1, 'razon_social' => 'Cyberpuerta', 'rfc' => 'AAA010101AAA']);
        DB::table('documento')->insert(['id' => 103, 'no_venta' => 'V-103', 'id_tipo' => 2,
            'id_fase' => 6, 'total' => 116, 'uuid' => $uuid, 'factura_serie' => 'C',
            'factura_folio' => '40063', 'id_entidad' => 1]);
        DB::table('facturacion_solicitud_documento')->insert(['id_solicitud' => $requestId, 'id_documento' => 103]);
        DB::table('facturacion_cancelacion')->insert(['id_solicitud' => $requestId,
            'status' => 'pending_approval', 'provider_status' => 'pending',
            'motivo' => '02', 'remote_cancellation_id' => 'cancel-2',
            'created_by' => 7, 'updated_by' => 7]);
        $client = Mockery::mock(NexfiraClient::class);
        $client->shouldReceive('getCancellation')->once()->with('remote-2')->andReturn([
            'requestId' => 'remote-2', 'cancellation' => [
                'id' => 'cancel-2', 'status' => 'cancelled', 'motive' => '02', 'replacementUuid' => null,
            ],
        ]);
        $detail = (new CancelacionFacturaService($client))->refresh('40063', 'C', 7);
        $this->assertSame('pending_approval', $detail['cancelacion']->status);
        $this->assertSame('cancelled', $detail['cancelacion']->provider_status);
        $this->assertSame(6, (int) DB::table('documento')->where('id', 103)->value('id_fase'));
        $this->assertSame($uuid, DB::table('documento')->where('id', 103)->value('uuid'));
    }
}
