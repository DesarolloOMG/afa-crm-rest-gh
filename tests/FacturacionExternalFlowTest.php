<?php

use App\Http\Services\DropboxService;
use App\Http\Controllers\FacturacionController;
use App\Http\Services\Nexfira\CfdiAttachmentValidator;
use App\Http\Services\Nexfira\FacturacionService;
use App\Http\Services\Nexfira\InvoicePayloadBuilder;
use App\Http\Services\Nexfira\NexfiraApiException;
use App\Http\Services\Nexfira\NexfiraClient;
use App\Console\Commands\SyncNexfiraInvoices;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Symfony\Component\Console\Tester\CommandTester;

class FacturacionExternalFlowTest extends TestCase
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
            'nexfira.base_url' => 'https://hub.example.test',
            'nexfira.integration_token' => 'hub_int_test.secret',
            'nexfira.issuer_id' => '223e4567-e89b-42d3-a456-426614174000',
            'nexfira.expedition_postal_code' => '44100',
            'nexfira.global.receiver_postal_code' => '44100',
            'nexfira.folio.sequence_key' => 'nexfira_cfdi_ingreso',
            'nexfira.folio.initial' => 40000,
            'nexfira.series' => [
                'ELEKTRA' => 'ELK',
                'MLG' => 'MLG',
                'PISO DE VENTA' => 'PV',
                'MERCADOLIBRE' => 'F-ML',
                'CYBERPUERTA' => 'C',
            ],
        ]);

        DB::purge();
        $this->createSchema();
        $this->seedCatalogs();
    }

    public function tearDown()
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testExternalGlobalCfdiFinalizesEveryLinkedSaleWithSameArtifacts()
    {
        $first = $this->insertDocument('FULL-100', 116.00);
        $second = $this->insertDocument('FULL-101', 232.00);

        $uuid = '123E4567-E89B-42D3-A456-426614174000';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="AFA" Folio="9001" Total="348.00">'
            . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuid . '" /></cfdi:Complemento>'
            . '</cfdi:Comprobante>';
        $pdf = "%PDF-1.4\nCFDI de prueba";

        $dropbox = Mockery::mock(DropboxService::class);
        $dropbox->shouldReceive('uploadFile')
            ->once()
            ->with('/facturacion/' . strtolower($uuid) . '/9001.pdf', $pdf, false)
            ->andReturn(['id' => 'id:pdf-global']);
        $dropbox->shouldReceive('uploadFile')
            ->once()
            ->with('/facturacion/' . strtolower($uuid) . '/9001.xml', $xml, false)
            ->andReturn(['id' => 'id:xml-global']);

        $service = new FacturacionService(
            Mockery::mock(NexfiraClient::class),
            Mockery::mock(InvoicePayloadBuilder::class),
            $dropbox,
            new CfdiAttachmentValidator()
        );

        $result = $service->attachExternal(
            [$second, $first],
            strtolower($uuid),
            'data:application/pdf;base64,' . base64_encode($pdf),
            'data:application/xml;base64,' . base64_encode($xml),
            7
        );

        $this->assertSame('stamped', $result['status']);
        $this->assertSame('AFA', $result['series']);
        $this->assertSame('9001', $result['folio']);
        $this->assertSame($uuid, $result['fiscal_uuid']);
        $this->assertSame('retrieved', $result['documents_status']);

        $documents = DB::table('documento')->orderBy('id')->get();
        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertSame(6, (int) $document->id_fase);
            $this->assertSame($uuid, $document->uuid);
            $this->assertSame('AFA', $document->factura_serie);
            $this->assertSame('9001', $document->factura_folio);
            $this->assertNotNull($document->invoice_date);
        }

        $relations = DB::table('documento_factura')->orderBy('id_documento')->get();
        $this->assertCount(2, $relations);
        foreach ($relations as $relation) {
            $this->assertSame('id:pdf-global', $relation->pdf);
            $this->assertSame('id:xml-global', $relation->xml);
        }

        $this->assertSame(2, DB::table('facturacion_solicitud_documento')->count());
        $this->assertSame(2, DB::table('documento_updates_by')->where('id_usuario', 7)->count());
        $this->assertSame(2, DB::table('seguimiento')->count());

        $retry = $service->attachExternal(
            [$first, $second],
            $uuid,
            'contenido-ignorado-en-reintento',
            'contenido-ignorado-en-reintento',
            7
        );
        $this->assertSame($result['id'], $retry['id']);
        $this->assertSame(1, DB::table('facturacion_solicitud')->count());
    }

    public function testNexfiraAcceptedRequestStaysInPhaseFiveUntilDocumentsAreRetrieved()
    {
        $document = $this->insertDocument('DROP-200', 116.00, 0);
        DB::table('movimiento')->insert([
            'id_documento' => $document,
            'id_modelo' => 1,
            'cantidad' => 1,
            'precio' => 116.00,
            'descuento' => 0,
            'retencion' => 0,
        ]);

        $remoteRequestId = '323e4567-e89b-42d3-a456-426614174000';
        $fiscalUuid = '423E4567-E89B-42D3-A456-426614174000';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="F-ML" Folio="40000" Total="116.00">'
            . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $fiscalUuid . '" /></cfdi:Complemento>'
            . '</cfdi:Comprobante>';
        $pdf = "%PDF-1.4\nCFDI Nexfira de prueba";
        $handler = new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'requestId' => $remoteRequestId,
                'status' => 'pending_approval',
                'version' => 1,
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'requestId' => $remoteRequestId,
                'status' => 'stamped',
                'version' => 2,
                'fiscalUuid' => $fiscalUuid,
                'documentsStatus' => 'retrieved',
                'xmlSha256' => hash('sha256', $xml),
                'pdfSha256' => hash('sha256', $pdf),
            ])),
            new Response(200, ['Content-Type' => 'application/xml'], $xml),
            new Response(200, ['Content-Type' => 'application/pdf'], $pdf),
        ]);
        $client = new NexfiraClient(new Client(['handler' => HandlerStack::create($handler)]));

        $dropbox = Mockery::mock(DropboxService::class);
        $dropbox->shouldReceive('uploadFile')->once()->with(
            '/facturacion/' . strtolower($fiscalUuid) . '/40000.pdf',
            $pdf,
            false
        )->andReturn(['id' => 'id:pdf-nexfira']);
        $dropbox->shouldReceive('uploadFile')->once()->with(
            '/facturacion/' . strtolower($fiscalUuid) . '/40000.xml',
            $xml,
            false
        )->andReturn(['id' => 'id:xml-nexfira']);

        $service = new FacturacionService(
            $client,
            new InvoicePayloadBuilder(),
            $dropbox,
            new CfdiAttachmentValidator()
        );

        $accepted = $service->createIndividual($document, 9);
        $this->assertSame('pending_approval', $accepted['status']);
        $this->assertSame('F-ML', $accepted['series']);
        $this->assertSame('40000', $accepted['folio']);
        $storedPayload = json_decode(DB::table('facturacion_solicitud')->value('request_payload'), true);
        $this->assertSame('F-ML', $storedPayload['content']['series']);
        $this->assertSame('40000', $storedPayload['content']['folio']);
        $this->assertSame(40001, (int) DB::table('facturacion_folio_consecutivo')->value('siguiente_folio'));
        $this->assertSame(5, (int) DB::table('documento')->where('id', $document)->value('id_fase'));
        $this->assertNull(DB::table('documento')->where('id', $document)->value('uuid'));

        $completed = $service->sync($accepted['id'], 9);
        $this->assertSame('stamped', $completed['status']);
        $this->assertSame('retrieved', $completed['documents_status']);
        $this->assertSame(6, (int) DB::table('documento')->where('id', $document)->value('id_fase'));
        $this->assertSame($fiscalUuid, DB::table('documento')->where('id', $document)->value('uuid'));
        $this->assertSame('id:pdf-nexfira', DB::table('documento_factura')->where('id_documento', $document)->value('pdf'));
        $this->assertSame('id:xml-nexfira', DB::table('documento_factura')->where('id_documento', $document)->value('xml'));
    }

    public function testSaleWithExistingUuidCannotBeSentToNexfiraAgain()
    {
        $document = $this->insertDocument('DROP-ALREADY-INVOICED', 116.00, 0);
        $uuid = '523E4567-E89B-42D3-A456-426614174000';
        DB::table('documento')->where('id', $document)->update(['uuid' => $uuid]);

        $builder = Mockery::mock(InvoicePayloadBuilder::class);
        $builder->shouldNotReceive('preview');
        $builder->shouldNotReceive('buildIndividual');

        $service = new FacturacionService(
            Mockery::mock(NexfiraClient::class),
            $builder,
            Mockery::mock(DropboxService::class),
            new CfdiAttachmentValidator()
        );

        $pending = $service->pendingDocuments(false);
        $this->assertCount(1, $pending['documents']);
        $this->assertTrue($pending['documents'][0]['already_invoiced']);
        $this->assertFalse($pending['documents'][0]['can_hub']);
        $this->assertContains($uuid, $pending['documents'][0]['blockers'][0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ya está facturada con UUID ' . $uuid);

        $service->createIndividual($document, 9);
    }

    public function testNAUuidIsTreatedAsNotInvoiced()
    {
        $document = $this->insertDocument('DROP-NOT-INVOICED', 116.00, 0);
        DB::table('documento')->where('id', $document)->update(['uuid' => 'N/A']);

        $builder = Mockery::mock(InvoicePayloadBuilder::class);
        $builder->shouldReceive('preview')->once()->with($document)->andReturn([
            'valid' => true,
            'blockers' => [],
        ]);

        $service = new FacturacionService(
            Mockery::mock(NexfiraClient::class),
            $builder,
            Mockery::mock(DropboxService::class),
            new CfdiAttachmentValidator()
        );

        $pending = $service->pendingDocuments(false);
        $this->assertCount(1, $pending['documents']);
        $this->assertFalse($pending['documents'][0]['already_invoiced']);
        $this->assertTrue($pending['documents'][0]['can_hub']);
        $this->assertSame('F-ML', $pending['documents'][0]['billing_series']);
        $this->assertSame([], $pending['documents'][0]['blockers']);
    }

    public function testNexfiraValidationErrorsArePersistedAndReturned()
    {
        $document = $this->insertDocument('DROP-INVALID', 116.00, 0);
        DB::table('movimiento')->insert([
            'id_documento' => $document,
            'id_modelo' => 1,
            'cantidad' => 1,
            'precio' => 116.00,
            'descuento' => 0,
            'retencion' => 0,
        ]);

        $validationErrors = [[
            'path' => 'content.paymentMethod',
            'code' => 'invalid_combination',
            'message' => 'La combinación de método y forma de pago no es válida.',
        ]];
        $handler = new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'code' => 'validation_failed',
                'message' => 'La solicitud no cumple el contrato del documento.',
                'correlationId' => '623e4567-e89b-42d3-a456-426614174000',
                'errors' => $validationErrors,
                'providerTrace' => 'trace-preserved-for-audit',
            ])),
        ]);
        $service = new FacturacionService(
            new NexfiraClient(new Client(['handler' => HandlerStack::create($handler)])),
            new InvoicePayloadBuilder(),
            Mockery::mock(DropboxService::class),
            new CfdiAttachmentValidator()
        );

        try {
            $service->createIndividual($document, 9);
            $this->fail('NexfiraApiException was not thrown.');
        } catch (NexfiraApiException $exception) {
            $this->assertSame('validation_failed', $exception->getApiCode());
        }

        $stored = DB::table('facturacion_solicitud')->first();
        $this->assertSame('rejected', $stored->status);
        $this->assertSame($validationErrors, json_decode($stored->validation_errors, true));
        $storedResponse = json_decode($stored->response_payload, true);
        $this->assertSame($validationErrors, $storedResponse['errors']);
        $this->assertSame('trace-preserved-for-audit', $storedResponse['providerTrace']);

        $pending = $service->pendingDocuments(false);
        $this->assertSame($validationErrors, $pending['documents'][0]['request']['errors']);
        $this->assertSame(
            '623e4567-e89b-42d3-a456-426614174000',
            $pending['documents'][0]['request']['correlation_id']
        );
    }

    public function testBillingEndpointRejectsUserWithoutDedicatedPermission()
    {
        $service = Mockery::mock(FacturacionService::class);
        $service->shouldNotReceive('pendingDocuments');
        $controller = new FacturacionController($service);
        $request = Request::create('/venta/venta/facturacion/pendientes', 'GET');
        $request->auth = (object) ['id' => 9];

        $response = $controller->pendientes($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'No tienes el permiso de Contabilidad: Facturación y Timbrado.',
            json_decode($response->getContent(), true)['message']
        );
    }

    public function testBillingEndpointAllowsUserWithDedicatedPermission()
    {
        DB::table('subnivel')->insert([
            'id' => 36,
            'subnivel' => 'FACTURACION Y TIMBRADO',
            'status' => 1,
        ]);
        DB::table('subnivel_nivel')->insert([
            'id' => 75,
            'id_nivel' => 11,
            'id_subnivel' => 36,
        ]);
        DB::table('usuario_subnivel_nivel')->insert([
            'id_usuario' => 9,
            'id_subnivel_nivel' => 75,
        ]);

        $service = Mockery::mock(FacturacionService::class);
        $service->shouldReceive('pendingDocuments')->once()->with(null)->andReturn([
            'documents' => [],
            'counts' => ['drop' => 0, 'full' => 0],
            'configured' => true,
        ]);
        $controller = new FacturacionController($service);
        $request = Request::create('/venta/venta/facturacion/pendientes', 'GET');
        $request->auth = (object) ['id' => 9];

        $response = $controller->pendientes($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBillingEndpointPassesProductGroupingToService()
    {
        DB::table('subnivel')->insert([
            'id' => 36,
            'subnivel' => 'FACTURACION Y TIMBRADO',
            'status' => 1,
        ]);
        DB::table('subnivel_nivel')->insert([
            'id' => 75,
            'id_nivel' => 11,
            'id_subnivel' => 36,
        ]);
        DB::table('usuario_subnivel_nivel')->insert([
            'id_usuario' => 9,
            'id_subnivel_nivel' => 75,
        ]);

        $service = Mockery::mock(FacturacionService::class);
        $service->shouldReceive('createGlobal')
            ->once()
            ->with([41, 42], 9, 'productos', [])
            ->andReturn(['status' => 'pending_approval', 'series' => 'F-ML', 'folio' => '40000']);
        $controller = new FacturacionController($service);
        $request = Request::create('/venta/venta/facturacion/global', 'POST', [
            'documentos' => [41, 42],
            'agrupacion' => 'productos',
        ]);
        $request->auth = (object) ['id' => 9];

        $response = $controller->global($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending_approval', json_decode($response->getContent(), true)['request']['status']);
    }

    public function testProductGlobalModeIsPersistedForAudit()
    {
        $first = $this->insertDocument('DROP-PRODUCT-1', 116.00, 0);
        $second = $this->insertDocument('DROP-PRODUCT-2', 232.00, 0);
        $payload = [
            'content' => [
                'expectedTotals' => ['total' => '348.00'],
            ],
        ];
        $builder = Mockery::mock(InvoicePayloadBuilder::class);
        $builder->shouldReceive('buildGlobal')
            ->once()
            ->with([$first, $second], Mockery::type('string'), 'productos', [])
            ->andReturn($payload);
        $client = Mockery::mock(NexfiraClient::class);
        $client->shouldReceive('createDocumentRequest')
            ->once()
            ->with(Mockery::on(function ($sentPayload) {
                return $sentPayload['content']['series'] === 'F-ML'
                    && $sentPayload['content']['folio'] === '40000';
            }), Mockery::type('string'))
            ->andReturn([
                'requestId' => '723e4567-e89b-42d3-a456-426614174000',
                'status' => 'pending_approval',
                'version' => 1,
            ]);
        $service = new FacturacionService(
            $client,
            $builder,
            Mockery::mock(DropboxService::class),
            new CfdiAttachmentValidator()
        );

        $result = $service->createGlobal([$second, $first], 9, 'productos');

        $this->assertSame('global_productos', $result['mode']);
        $this->assertSame('F-ML', $result['series']);
        $this->assertSame('40000', $result['folio']);
        $this->assertSame('global_productos', DB::table('facturacion_solicitud')->value('modo'));
    }

    public function testGlobalInvoiceRejectsMixedFiscalSeriesWithoutConsumingFolio()
    {
        $first = $this->insertDocument('DROP-MELI', 116.00, 0);
        $second = $this->insertDocument('DROP-ELEKTRA', 232.00, 0);
        DB::table('marketplace')->insert(['id' => 2, 'marketplace' => 'ELEKTRA']);
        DB::table('marketplace_area')->insert([
            'id' => 2,
            'id_marketplace' => 2,
            'publico' => 1,
        ]);
        DB::table('documento')->where('id', $second)->update(['id_marketplace_area' => 2]);

        $builder = Mockery::mock(InvoicePayloadBuilder::class);
        $builder->shouldReceive('buildGlobal')->once()->andReturn([
            'content' => ['expectedTotals' => ['total' => '348.00']],
        ]);
        $client = Mockery::mock(NexfiraClient::class);
        $client->shouldNotReceive('createDocumentRequest');
        $service = new FacturacionService(
            $client,
            $builder,
            Mockery::mock(DropboxService::class),
            new CfdiAttachmentValidator()
        );

        try {
            $service->createGlobal([$first, $second], 9, 'productos');
            $this->fail('La factura global mezcló series fiscales distintas.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('mismo marketplace y serie fiscal', $exception->getMessage());
        }

        $this->assertSame(40000, (int) DB::table('facturacion_folio_consecutivo')->value('siguiente_folio'));
        $this->assertSame(0, DB::table('facturacion_solicitud')->count());
    }

    public function testBillingEndpointPassesGlobalSatInformationToService()
    {
        DB::table('subnivel')->insert([
            'id' => 36,
            'subnivel' => 'FACTURACION Y TIMBRADO',
            'status' => 1,
        ]);
        DB::table('subnivel_nivel')->insert([
            'id' => 75,
            'id_nivel' => 11,
            'id_subnivel' => 36,
        ]);
        DB::table('usuario_subnivel_nivel')->insert([
            'id_usuario' => 9,
            'id_subnivel_nivel' => 75,
        ]);

        $globalInformation = [
            'periodicity' => '04',
            'months' => '09',
            'year' => 2026,
        ];
        $service = Mockery::mock(FacturacionService::class);
        $service->shouldReceive('createGlobal')
            ->once()
            ->with([41, 42], 9, 'ventas', $globalInformation)
            ->andReturn(['status' => 'pending_approval', 'series' => 'F-ML', 'folio' => '40000']);
        $controller = new FacturacionController($service);
        $request = Request::create('/venta/venta/facturacion/global', 'POST', [
            'documentos' => [41, 42],
            'agrupacion' => 'ventas',
            'informacionGlobal' => $globalInformation,
        ]);
        $request->auth = (object) ['id' => 9];

        $response = $controller->global($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending_approval', json_decode($response->getContent(), true)['request']['status']);
    }

    public function testScheduledCommandSynchronizesOnlyActiveNexfiraRequestsWithPhaseFiveSales()
    {
        $pendingDocument = $this->insertDocument('DROP-AUTO-PENDING', 116.00, 0);
        $completedDocument = $this->insertDocument('DROP-AUTO-COMPLETED', 232.00, 0);
        DB::table('documento')->where('id', $completedDocument)->update(['id_fase' => 6]);

        $pendingRequest = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira',
            'modo' => 'global_ventas',
            'remote_request_id' => '823e4567-e89b-42d3-a456-426614174000',
            'idempotency_key' => 'auto-pending-key',
            'external_reference' => 'auto-pending-reference',
            'status' => 'pending_approval',
            'version' => 1,
            'updated_by' => 9,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $completedRequest = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira',
            'modo' => 'individual',
            'remote_request_id' => '923e4567-e89b-42d3-a456-426614174000',
            'idempotency_key' => 'auto-completed-key',
            'external_reference' => 'auto-completed-reference',
            'status' => 'stamped',
            'version' => 1,
            'documents_status' => 'retrieved',
            'updated_by' => 9,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('facturacion_solicitud_documento')->insert([
            [
                'id_solicitud' => $pendingRequest,
                'id_documento' => $pendingDocument,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id_solicitud' => $completedRequest,
                'id_documento' => $completedDocument,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $service = Mockery::mock(FacturacionService::class);
        $service->shouldReceive('sync')
            ->once()
            ->with($pendingRequest, 9)
            ->andReturn([
                'status' => 'pending_approval',
                'documents_status' => null,
            ]);

        $command = new SyncNexfiraInvoices($service);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 procesada(s)', $tester->getDisplay());
        $this->assertStringContainsString('1 pendiente(s)', $tester->getDisplay());
    }

    private function insertDocument($folio, $total, $fulfillment = 1)
    {
        return DB::table('documento')->insertGetId([
            'id_marketplace_area' => 1,
            'id_cfdi' => 1,
            'id_moneda' => 1,
            'id_entidad' => 1,
            'id_tipo' => 2,
            'id_fase' => 5,
            'id_periodo' => 1,
            'fulfillment' => $fulfillment,
            'status' => 1,
            'no_venta' => $folio,
            'total' => $total,
            'uuid' => null,
            'invoice_date' => null,
            'deleted_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedCatalogs()
    {
        DB::table('marketplace')->insert(['id' => 1, 'marketplace' => 'MERCADOLIBRE']);
        DB::table('marketplace_area')->insert(['id' => 1, 'id_marketplace' => 1, 'publico' => 1]);
        DB::table('documento_uso_cfdi')->insert(['id' => 1, 'codigo' => 'S01']);
        DB::table('moneda')->insert(['id' => 1, 'moneda' => 'MXN']);
        DB::table('documento_entidad')->insert([
            'id' => 1,
            'rfc' => 'XAXX010101000',
            'razon_social' => 'PUBLICO EN GENERAL',
            'regimen_id' => '616',
            'codigo_postal_fiscal' => '44100',
        ]);
        DB::table('modelo')->insert([
            'id' => 1,
            'descripcion' => 'Producto de prueba',
            'clave_sat' => '43211503',
            'clave_unidad' => 'H87',
        ]);
    }

    private function createSchema()
    {
        Schema::create('marketplace', function ($table) {
            $table->increments('id');
            $table->string('marketplace');
        });
        Schema::create('marketplace_area', function ($table) {
            $table->increments('id');
            $table->integer('id_marketplace');
            $table->integer('publico');
        });
        Schema::create('documento_uso_cfdi', function ($table) {
            $table->increments('id');
            $table->string('codigo');
        });
        Schema::create('moneda', function ($table) {
            $table->increments('id');
            $table->string('moneda');
        });
        Schema::create('documento_entidad', function ($table) {
            $table->increments('id');
            $table->string('rfc')->nullable();
            $table->string('razon_social')->nullable();
            $table->string('regimen_id')->nullable();
            $table->string('regimen')->nullable();
            $table->string('regimen_letra')->nullable();
            $table->string('codigo_postal_fiscal')->nullable();
        });
        Schema::create('documento', function ($table) {
            $table->increments('id');
            $table->integer('id_marketplace_area');
            $table->integer('id_cfdi');
            $table->integer('id_moneda');
            $table->integer('id_entidad');
            $table->integer('id_tipo');
            $table->integer('id_fase');
            $table->integer('id_periodo');
            $table->integer('fulfillment');
            $table->integer('status');
            $table->string('no_venta');
            $table->decimal('total', 20, 4)->nullable();
            $table->string('uuid')->nullable();
            $table->string('factura_serie')->nullable();
            $table->string('factura_folio')->nullable();
            $table->timestamp('invoice_date')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('movimiento', function ($table) {
            $table->increments('id');
            $table->integer('id_documento');
            $table->integer('id_modelo');
            $table->decimal('cantidad', 20, 6);
            $table->decimal('precio', 20, 6);
            $table->decimal('descuento', 20, 4)->default(0);
            $table->integer('retencion')->default(0);
        });
        Schema::create('modelo', function ($table) {
            $table->increments('id');
            $table->string('descripcion');
            $table->string('clave_sat');
            $table->string('clave_unidad');
        });
        Schema::create('documento_pago', function ($table) {
            $table->increments('id');
            $table->integer('id_metodopago');
        });
        Schema::create('documento_pago_re', function ($table) {
            $table->increments('id');
            $table->integer('id_pago');
            $table->integer('id_documento');
        });
        Schema::create('facturacion_solicitud', function ($table) {
            $table->increments('id');
            $table->string('proveedor');
            $table->string('modo');
            $table->string('serie')->nullable();
            $table->string('folio')->nullable();
            $table->string('remote_request_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('external_reference')->unique();
            $table->string('status');
            $table->integer('version');
            $table->string('fiscal_uuid')->nullable();
            $table->string('documents_status')->nullable();
            $table->string('xml_sha256')->nullable();
            $table->string('pdf_sha256')->nullable();
            $table->string('error_code')->nullable();
            $table->string('correlation_id')->nullable();
            $table->text('error_message')->nullable();
            $table->text('validation_errors')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->integer('updated_by');
            $table->timestamps();
        });
        Schema::create('facturacion_solicitud_documento', function ($table) {
            $table->increments('id');
            $table->integer('id_solicitud');
            $table->integer('id_documento');
            $table->timestamps();
        });
        Schema::create('facturacion_folio_consecutivo', function ($table) {
            $table->string('clave')->primary();
            $table->integer('siguiente_folio');
            $table->timestamps();
        });
        DB::table('facturacion_folio_consecutivo')->insert([
            'clave' => 'nexfira_cfdi_ingreso',
            'siguiente_folio' => 40000,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Schema::create('documento_factura', function ($table) {
            $table->increments('id');
            $table->integer('id_documento');
            $table->string('pdf');
            $table->string('xml');
            $table->integer('updated_by');
            $table->timestamps();
        });
        Schema::create('documento_updates_by', function ($table) {
            $table->increments('id');
            $table->integer('id_documento');
            $table->integer('id_usuario');
        });
        Schema::create('subnivel', function ($table) {
            $table->increments('id');
            $table->string('subnivel');
            $table->integer('status')->default(1);
        });
        Schema::create('subnivel_nivel', function ($table) {
            $table->increments('id');
            $table->integer('id_nivel');
            $table->integer('id_subnivel');
        });
        Schema::create('usuario_subnivel_nivel', function ($table) {
            $table->increments('id');
            $table->integer('id_usuario');
            $table->integer('id_subnivel_nivel');
        });
        Schema::create('seguimiento', function ($table) {
            $table->increments('id');
            $table->integer('id_documento');
            $table->integer('id_usuario');
            $table->text('seguimiento');
        });
    }
}
