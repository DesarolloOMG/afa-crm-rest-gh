<?php

use App\Http\Services\RefacturacionService;
use App\Http\Services\Nexfira\CreditNoteContext;
use App\Http\Services\InventarioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefacturacionServiceTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge();
        $this->schema();
    }

    public function testPublicSaleReassignsItsIncomeCreatesCompensatingCreditNoteAndNeverMovesStock()
    {
        $ids = $this->seedSale();
        $recipient = $this->recipient();
        $service = new RefacturacionService();
        $result = $service->create($ids['sale'], $recipient, 9, function () {
            throw new Exception('Una venta del mes no debe pedir autenticador.');
        });

        $this->assertSame('pendiente_timbrado', $result['estado_fiscal']);
        $this->assertSame($result['nota_credito'], (int) DB::table('documento')->where('id', $ids['sale'])->value('nota'));
        $this->assertSame('923e4567-e89b-42d3-a456-426614174000', DB::table('documento')->where('id', $ids['sale'])->value('uuid'));
        $this->assertSame(1, (int) DB::table('documento')->where('id', $ids['sale'])->value('refacturado'));

        $new = DB::table('documento')->where('id', $result['documento_nuevo'])->first();
        $note = DB::table('documento')->where('id', $result['nota_credito'])->first();
        $income = DB::table('movimiento_contable')->where('id', $ids['income'])->first();
        $this->assertSame(2, (int) $new->id_tipo);
        $this->assertSame(6, (int) $note->id_tipo);
        $this->assertSame(1, (int) $new->es_refactura);
        $this->assertSame(1, (int) $note->es_refactura);
        $this->assertSame(5, (int) $new->id_fase);
        $this->assertEquals(0, $new->saldo);
        $this->assertSame(1, (int) $new->pagado);
        $this->assertFalse($result['contabilidad']['sin_ingresos']);
        $this->assertSame((int) $ids['entity'], (int) $note->id_entidad);
        $this->assertSame((int) $new->id_entidad, (int) $income->entidad_origen);
        $this->assertSame('CLIENTE NUEVO SA DE CV', $income->nombre_entidad_origen);
        $this->assertSame('N/A', $note->uuid);
        $this->assertSame(0, (int) DB::table('movimiento_contable_documento')->where('id', $ids['allocation'])->value('status'));
        $this->assertSame(1, DB::table('movimiento_contable_documento')->where('id_movimiento_contable', $ids['income'])
            ->where('id_documento', $new->id)->where('status', 1)->count());
        $this->assertSame(1, DB::table('movimiento_contable_documento as a')
            ->join('movimiento_contable as mc', 'mc.id', '=', 'a.id_movimiento_contable')
            ->where('a.id_documento', $ids['sale'])->where('a.status', 1)->where('mc.id_tipo_afectacion', 4)->count());
        $this->assertSame(1, DB::table('movimiento')->where('id_documento', $note->id)->count());
        $this->assertSame(1, DB::table('movimiento')->where('id_documento', $new->id)->count());
        $this->assertSame(0, DB::table('modelo_existencias')->count());
        $this->assertSame(0, DB::table('modelo_kardex')->count());
        $this->assertSame(0, DB::table('movimiento_producto')->count());
        $inventoryResult = InventarioService::aplicarMovimiento($note->id);
        $this->assertSame(200, $inventoryResult->code);
        $this->assertSame(0, DB::table('modelo_kardex')->count());
        $this->assertSame([], RefacturacionService::fiscalBlocks([$new->id, $note->id]));
        $this->assertArrayHasKey($new->id, RefacturacionService::fiscalBlocks([$new->id], true));
        $this->assertArrayHasKey($note->id, RefacturacionService::fiscalBlocks([$note->id], true));

        $again = $service->create($ids['sale'], $recipient, 9, function () {});
        $this->assertTrue($again['reutilizada']);
        $this->assertSame($result['id'], $again['id']);
        $this->assertSame(3, DB::table('documento')->count());
        $this->assertSame(2, DB::table('movimiento_contable')->count());
    }

    public function testSharedIncomeCannotBeRenamedAndTransactionLeavesAllRowsUntouched()
    {
        $ids = $this->seedSale();
        $other = DB::table('documento')->insertGetId([
            'id_tipo' => 2, 'id_fase' => 6, 'status' => 1, 'uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'id_entidad' => $ids['entity'], 'total' => '10.0000', 'nota' => 'N/A', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('movimiento_contable')->where('id', $ids['income'])->update(['monto' => '126.0000']);
        DB::table('movimiento_contable_documento')->insert([
            'id_movimiento_contable' => $ids['income'], 'id_documento' => $other,
            'monto_aplicado' => '10.0000', 'moneda' => 3, 'status' => 1,
        ]);
        try {
            (new RefacturacionService())->create($ids['sale'], $this->recipient(), 9, function () {});
            $this->fail('Se cambió el cliente de un ingreso compartido.');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('otro pedido', $e->getMessage());
        }
        $this->assertSame(0, DB::table('documento_refacturacion')->count());
        $this->assertSame(0, (int) DB::table('documento')->where('id', $ids['sale'])->value('refacturado'));
        $this->assertSame((int) $ids['entity'], (int) DB::table('movimiento_contable')->where('id', $ids['income'])->value('entidad_origen'));
        $this->assertSame(1, (int) DB::table('movimiento_contable_documento')->where('id', $ids['allocation'])->value('status'));
    }

    public function testSaleWithoutIncomeIsSettledByCreditNoteAndReplacementRemainsUnpaid()
    {
        $ids = $this->seedSale();
        DB::table('movimiento_contable_documento')->delete();
        DB::table('movimiento_contable')->delete();
        // Reproduce 37524: el flag histórico dice pagado, pero no existe ingreso.
        DB::table('documento')->where('id', $ids['sale'])->update(['saldo' => '116.0000', 'pagado' => 1]);
        $service = new RefacturacionService();
        $preview = $service->preview($ids['sale']);
        $this->assertTrue($preview['puede_refacturar']);
        $this->assertTrue($preview['contabilidad']['sin_ingresos']);
        $this->assertEquals(116, $preview['contabilidad']['saldo_nuevo']);
        $result = $service->create($ids['sale'], $this->recipient(), 9, function () {});
        $source = DB::table('documento')->where('id', $ids['sale'])->first();
        $new = DB::table('documento')->where('id', $result['documento_nuevo'])->first();
        $note = DB::table('documento')->where('id', $result['nota_credito'])->first();
        $this->assertEquals(0, $source->saldo);
        $this->assertSame(1, (int) $source->pagado);
        $this->assertSame('923e4567-e89b-42d3-a456-426614174000', $source->uuid);
        $this->assertEquals(116, $new->saldo);
        $this->assertSame(0, (int) $new->pagado);
        $this->assertSame(5, (int) $new->id_fase);
        $this->assertSame(1, (int) $new->es_refactura);
        $this->assertSame(1, (int) $note->es_refactura);
        $this->assertEquals(0, $note->saldo);
        $this->assertTrue($result['contabilidad']['sin_ingresos']);
        $this->assertEquals(116, $result['contabilidad']['saldo_nuevo']);
        $this->assertSame(0, DB::table('movimiento_contable')->where('id_tipo_afectacion', 1)->count());
        $this->assertSame(1, DB::table('movimiento_contable')->count());
        $this->assertSame(4, (int) DB::table('movimiento_contable')->value('id_tipo_afectacion'));
        $this->assertSame(1, DB::table('movimiento_contable_documento')->count());
        $this->assertEquals(116, DB::table('movimiento_contable_documento')->where('id_documento', $source->id)->value('monto_aplicado'));
        $this->assertSame(0, DB::table('movimiento_contable_documento')->where('id_documento', $new->id)->count());
        $this->assertSame([], RefacturacionService::fiscalBlocks([$new->id, $note->id]));
        $this->assertSame('pendiente_timbrado', $result['estado_fiscal']);
        foreach (['movimiento_producto', 'modelo_kardex', 'modelo_existencias'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $again = $service->create($ids['sale'], $this->recipient(), 9, function () {});
        $this->assertSame($result['id'], $again['id']);
        $this->assertTrue($again['reutilizada']);
        $this->assertTrue($again['contabilidad']['sin_ingresos']);
        $this->assertSame(3, DB::table('documento')->count());
        $this->assertSame(1, DB::table('movimiento_contable')->count());
    }

    /** @dataProvider incompletePayments */
    public function testPartialOrExcessIncomeIsNeverSilentlyDiscarded($amount)
    {
        $ids = $this->seedSale();
        DB::table('movimiento_contable')->where('id', $ids['income'])->update(['monto' => $amount]);
        DB::table('movimiento_contable_documento')->where('id', $ids['allocation'])->update(['monto_aplicado' => $amount]);
        $service = new RefacturacionService();
        $this->assertFalse($service->preview($ids['sale'])['puede_refacturar']);
        try {
            $service->create($ids['sale'], $this->recipient(), 9, function () {});
            $this->fail('Los pagos parciales/excedentes no se deben ignorar.');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('parciales o excedentes', $e->getMessage());
        }
        $this->assertSame(1, DB::table('documento')->count());
        $this->assertSame(0, DB::table('documento_refacturacion')->count());
        $this->assertEquals((float) $amount, (float) DB::table('movimiento_contable')->value('monto'));
    }

    public function incompletePayments()
    {
        return [['50.0000'], ['117.0000']];
    }

    public function testInactiveIncomeApplicationIsNotMovedToTheNewOrder()
    {
        $ids = $this->seedSale();
        DB::table('movimiento_contable_documento')->where('id', $ids['allocation'])->update(['status' => 0]);
        DB::table('movimiento_contable')->where('id', $ids['income'])->update(['status' => 0]);
        $result = (new RefacturacionService())->create($ids['sale'], $this->recipient(), 9, function () {});
        $this->assertTrue($result['contabilidad']['sin_ingresos']);
        $this->assertEquals(116, $result['contabilidad']['saldo_nuevo']);
        $this->assertSame(0, (int) DB::table('movimiento_contable')->where('id', $ids['income'])->value('status'));
        $this->assertSame($ids['entity'], (int) DB::table('movimiento_contable')->where('id', $ids['income'])->value('entidad_origen'));
    }

    public function testCreditNoteUsesPublicReceiverOfOriginalGlobalInvoiceEvenWhenCrmCustomerDiffers()
    {
        $ids = $this->seedSale();
        DB::table('documento_entidad')->where('id', $ids['entity'])->update([
            'razon_social' => 'MASTER LOYALTY GROUP', 'rfc' => 'MLG100224TC1',
        ]);
        DB::table('movimiento_contable')->where('id', $ids['income'])->update([
            'nombre_entidad_origen' => 'MASTER LOYALTY GROUP',
        ]);
        $requestId = DB::table('facturacion_solicitud')->insertGetId([
            'proveedor' => 'nexfira', 'status' => 'stamped',
            'fiscal_uuid' => '923e4567-e89b-42d3-a456-426614174000',
            'remote_request_id' => 'remote-global',
            'request_payload' => json_encode(['kind' => 'CFDI_I', 'content' => [
                'currency' => 'MXN', 'receiver' => ['rfc' => 'XAXX010101000', 'postalCode' => '44100'],
            ]]),
        ]);
        DB::table('facturacion_solicitud_documento')->insert([
            'id_solicitud' => $requestId, 'id_documento' => $ids['sale'],
        ]);
        $result = (new RefacturacionService())->create($ids['sale'], $this->recipient(), 9, function () {});
        $note = DB::table('documento')->where('id', $result['nota_credito'])->first();
        $this->assertNotEquals($ids['entity'], $note->id_entidad);
        $this->assertSame('XAXX010101000', DB::table('documento_entidad')->where('id', $note->id_entidad)->value('rfc'));
        $this->assertSame($ids['entity'], (int) DB::table('documento')->where('id', $ids['sale'])->value('id_entidad'));
        $context = (new CreditNoteContext())->resolve($note->id, true);
        $this->assertSame('923E4567-E89B-42D3-A456-426614174000', $context['source_uuid']);
        $this->assertSame('XAXX010101000', $context['receiver_rfc']);
    }

    public function testSameRecipientRefacturationStillCreatesSeparateFiscalSaleAndRelatesOriginalUuid()
    {
        $ids = $this->seedSale();
        $recipient = $this->recipient();
        DB::table('marketplace_area')->where('id', 1)->update(['publico' => 0]);
        DB::table('documento_entidad')->where('id', $ids['entity'])->update([
            'razon_social' => strtoupper($recipient['razon_social']), 'rfc' => $recipient['rfc'],
        ]);
        $service = new RefacturacionService();
        $this->assertTrue($service->preview($ids['sale'])['puede_refacturar']);
        $result = $service->create($ids['sale'], $recipient, 9, function () {});
        $note = DB::table('documento')->where('id', $result['nota_credito'])->first();
        $new = DB::table('documento')->where('id', $result['documento_nuevo'])->first();
        $this->assertSame($ids['entity'], (int) $note->id_entidad);
        $this->assertNotEquals($ids['entity'], $new->id_entidad);
        $this->assertSame('923E4567-E89B-42D3-A456-426614174000',
            (new CreditNoteContext())->resolve($note->id)['source_uuid']);
    }

    public function testAmbiguousExternalPublicInvoiceCannotCreateCreditNoteUnderTheWrongReceiver()
    {
        $ids = $this->seedSale();
        DB::table('documento_entidad')->where('id', $ids['entity'])->update([
            'razon_social' => 'MASTER LOYALTY GROUP', 'rfc' => 'MLG100224TC1',
        ]);
        $service = new RefacturacionService();
        $this->assertFalse($service->preview($ids['sale'])['puede_refacturar']);
        try {
            $service->create($ids['sale'], $this->recipient(), 9, function () {});
            $this->fail('Se creó una NC sin receptor fiscal comprobado.');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('XML original', $e->getMessage());
        }
        $this->assertSame(0, DB::table('documento_refacturacion')->count());
        $this->assertSame(1, DB::table('documento_entidad')->count());
    }

    public function testFiscalOperationClosesOnlyAfterBothXmlAndPdfAndUuidAreStored()
    {
        $ids = $this->seedSale();
        $result = (new RefacturacionService())->create($ids['sale'], $this->recipient(), 9, function () {});
        DB::table('documento')->where('id', $result['nota_credito'])->update([
            'uuid' => 'A23E4567-E89B-42D3-A456-426614174000',
        ]);
        DB::table('documento_factura')->insert(['id_documento' => $result['nota_credito'],
            'xml' => 'xml-nc', 'pdf' => 'pdf-nc']);
        RefacturacionService::markFiscalCompleted([$result['nota_credito']]);
        $this->assertSame('pendiente_timbrado', DB::table('documento_refacturacion')->value('estado_fiscal'));
        DB::table('documento')->where('id', $result['documento_nuevo'])->update([
            'uuid' => 'B23E4567-E89B-42D3-A456-426614174000', 'id_fase' => 6,
        ]);
        DB::table('documento_factura')->insert(['id_documento' => $result['documento_nuevo'],
            'xml' => 'xml-venta', 'pdf' => 'pdf-venta']);
        RefacturacionService::markFiscalCompleted([$result['documento_nuevo']]);
        $this->assertSame('timbradas', DB::table('documento_refacturacion')->value('estado_fiscal'));
        $this->assertSame([], RefacturacionService::fiscalBlocks([$result['documento_nuevo'], $result['nota_credito']]));
    }

    private function recipient()
    {
        return ['rfc' => 'CNU010101AB1', 'razon_social' => 'Cliente Nuevo SA de CV',
            'codigo_postal_fiscal' => '44100', 'regimen' => '601', 'id_cfdi' => 3];
    }

    private function seedSale()
    {
        $entity = DB::table('documento_entidad')->insertGetId([
            'razon_social' => 'PUBLICO EN GENERAL', 'rfc' => 'XAXX010101000', 'codigo_postal_fiscal' => '44100',
        ]);
        $sale = DB::table('documento')->insertGetId([
            'id_tipo' => 2, 'id_fase' => 6, 'status' => 1, 'id_entidad' => $entity,
            'uuid' => '923e4567-e89b-42d3-a456-426614174000', 'total' => '116.0000',
            'saldo' => '0.0000', 'pagado' => 1, 'nota' => 'N/A', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('movimiento')->insert(['id_documento' => $sale, 'id_modelo' => 12, 'cantidad' => 1,
            'precio' => '116.000000', 'descuento' => '0.0000', 'addenda' => '']);
        $income = DB::table('movimiento_contable')->insertGetId([
            'id_tipo_afectacion' => 1, 'status' => 1, 'monto' => '116.0000', 'id_moneda' => 3,
            'origen_tipo' => 1, 'entidad_origen' => $entity, 'nombre_entidad_origen' => 'PUBLICO EN GENERAL',
            'destino_tipo' => 2, 'entidad_destino' => 2,
        ]);
        $allocation = DB::table('movimiento_contable_documento')->insertGetId([
            'id_movimiento_contable' => $income, 'id_documento' => $sale,
            'monto_aplicado' => '116.0000', 'moneda' => 3, 'status' => 1,
        ]);
        return compact('entity', 'sale', 'income', 'allocation');
    }

    public function testPhaseFiveClientEditKeepsOrderStockAndPaymentsButUsesItsOwnFiscalReceiver()
    {
        $ids = $this->seedSale();
        DB::table('documento')->where('id', $ids['sale'])->update(['id_fase' => 5, 'uuid' => 'N/A']);
        $service = new RefacturacionService();
        $this->assertTrue($service->previewClient($ids['sale'])['puede_refacturar']);
        $result = $service->updateClient($ids['sale'], $this->recipient(), 9);
        $this->assertTrue($result['cliente_actualizado']);
        $this->assertSame(1, DB::table('documento')->count());
        $this->assertSame(0, DB::table('documento_refacturacion')->count());
        $this->assertSame(5, (int) DB::table('documento')->value('id_fase'));
        $this->assertSame('XAXX010101000', DB::table('documento_entidad')->where('id', $ids['entity'])->value('rfc'));
        $this->assertSame($result['entidad_nueva'], (int) DB::table('movimiento_contable')->value('entidad_origen'));
        $this->assertSame(1, (int) DB::table('movimiento_contable_documento')->where('id', $ids['allocation'])->value('status'));
        $this->assertTrue(RefacturacionService::hasExplicitRecipient($ids['sale']));
        $this->assertSame(0, DB::table('modelo_kardex')->count());
    }

    public function testClientEditRejectsStampedSaleAndActiveInvoiceRequest()
    {
        $ids = $this->seedSale();
        $service = new RefacturacionService();
        $this->assertFalse($service->previewClient($ids['sale'])['puede_refacturar']);
        DB::table('documento')->where('id', $ids['sale'])->update(['id_fase' => 5, 'uuid' => 'N/A']);
        $request = DB::table('facturacion_solicitud')->insertGetId(['status' => 'queued', 'fiscal_uuid' => '']);
        DB::table('facturacion_solicitud_documento')->insert(['id_solicitud' => $request, 'id_documento' => $ids['sale']]);
        try {
            $service->updateClient($ids['sale'], $this->recipient(), 9);
            $this->fail('No debe cambiar un receptor ya enviado al Hub.');
        } catch (InvalidArgumentException $e) { $this->assertContains('solicitud fiscal activa', $e->getMessage()); }
        $this->assertSame($ids['entity'], (int) DB::table('documento')->value('id_entidad'));
        $this->assertSame(1, DB::table('documento_entidad')->count());
    }

    private function schema()
    {
        Schema::create('documento', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_tipo'); $t->integer('id_fase'); $t->integer('status')->default(1);
            $t->integer('id_entidad'); $t->string('uuid')->default('N/A'); $t->string('nota')->default('N/A');
            $t->integer('refacturado')->default(0); $t->string('refacturado_at')->nullable();
            $t->decimal('total', 20, 4); $t->decimal('saldo', 20, 4)->nullable(); $t->integer('pagado')->default(0);
            $t->integer('id_almacen_principal_empresa')->default(1); $t->integer('id_almacen_secundario_empresa')->default(0);
            $t->integer('id_marketplace_area')->default(1); $t->integer('id_moneda')->default(3);
            $t->decimal('tipo_cambio', 4, 2)->default(1); $t->integer('id_periodo')->default(1);
            $t->integer('id_paqueteria')->default(6); $t->integer('fulfillment')->default(0);
            $t->integer('sandbox')->default(0); $t->string('no_venta')->default('MP-1');
            $t->string('factura_serie')->nullable(); $t->string('factura_folio')->nullable();
            $t->string('referencia')->nullable(); $t->string('observacion')->nullable();
            $t->string('comentario')->nullable(); $t->string('info_extra')->nullable();
            $t->integer('id_cfdi')->default(1); $t->integer('id_usuario')->default(1);
            $t->string('finished_at')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        require_once __DIR__ . '/../database/migrations/2026_09_25_120000_create_documento_refacturacion_table.php';
        (new CreateDocumentoRefacturacionTable())->up();
        Schema::create('documento_entidad', function (Blueprint $t) {
            $t->increments('id'); $t->string('id_erp')->nullable(); $t->integer('tipo')->nullable();
            $t->string('razon_social'); $t->string('rfc'); $t->string('codigo_postal_fiscal');
            $t->string('regimen_id')->nullable(); $t->string('regimen')->nullable();
            $t->string('regimen_letra')->nullable(); $t->string('pais')->nullable();
            $t->string('correo')->nullable(); $t->string('telefono')->nullable(); $t->string('info_extra')->nullable();
            $t->integer('status')->nullable(); $t->integer('created_by_user')->nullable();
            $t->integer('updated_by_user')->nullable(); $t->timestamps();
        });
        Schema::create('movimiento', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_documento'); $t->integer('id_modelo');
            $t->integer('cantidad'); $t->decimal('precio', 25, 6); $t->decimal('descuento', 25, 4)->default(0);
            $t->string('garantia')->nullable(); $t->boolean('regalo')->default(0); $t->boolean('retencion')->default(0);
            $t->string('modificacion')->nullable(); $t->string('comentario')->nullable(); $t->string('addenda')->nullable();
            $t->timestamps();
        });
        Schema::create('movimiento_contable', function (Blueprint $t) {
            $t->increments('id'); $t->string('folio')->nullable(); $t->integer('id_tipo_afectacion');
            $t->string('fecha_operacion')->nullable(); $t->string('fecha_afectacion')->nullable();
            $t->integer('id_moneda'); $t->decimal('tipo_cambio', 10, 2)->default(1); $t->decimal('monto', 20, 4);
            $t->integer('origen_tipo'); $t->integer('entidad_origen'); $t->string('nombre_entidad_origen')->nullable();
            $t->integer('destino_tipo'); $t->integer('entidad_destino'); $t->string('nombre_entidad_destino')->nullable();
            $t->integer('id_forma_pago')->nullable(); $t->string('referencia_pago')->nullable();
            $t->string('descripcion_pago')->nullable(); $t->string('comentarios')->nullable();
            $t->integer('creado_por')->nullable(); $t->integer('status')->default(1); $t->timestamps();
        });
        Schema::create('movimiento_contable_documento', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_movimiento_contable'); $t->integer('id_documento');
            $t->decimal('monto_aplicado', 20, 4); $t->decimal('saldo_documento', 20, 4)->nullable();
            $t->integer('moneda'); $t->decimal('tipo_cambio', 20, 2)->nullable();
            $t->integer('parcialidad')->nullable(); $t->string('created_at')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('cat_regimen', function (Blueprint $t) {
            $t->increments('id'); $t->string('codigo'); $t->string('regimen'); $t->string('condicion');
        });
        DB::table('cat_regimen')->insert(['codigo' => '601', 'regimen' => 'General de Ley Personas Morales', 'condicion' => 'M']);
        Schema::create('documento_uso_cfdi', function (Blueprint $t) {
            $t->increments('id'); $t->string('codigo'); $t->string('descripcion');
        });
        DB::table('documento_uso_cfdi')->insert([
            ['id' => 2, 'codigo' => 'G02', 'descripcion' => 'Devoluciones'],
            ['id' => 3, 'codigo' => 'G03', 'descripcion' => 'Gastos'],
        ]);
        Schema::create('cat_forma_pago', function (Blueprint $t) {
            $t->increments('id'); $t->string('codigo_sat');
        });
        DB::table('cat_forma_pago')->insert(['id' => 12, 'codigo_sat' => '17']);
        Schema::create('facturacion_solicitud', function (Blueprint $t) {
            $t->increments('id'); $t->string('status'); $t->string('fiscal_uuid');
            $t->string('proveedor')->nullable(); $t->string('remote_request_id')->nullable();
            $t->text('request_payload')->nullable();
        });
        Schema::create('facturacion_solicitud_documento', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_solicitud'); $t->integer('id_documento');
        });
        Schema::create('documento_factura', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_documento');
            $t->string('xml'); $t->string('pdf');
        });
        Schema::create('seguimiento', function (Blueprint $t) {
            $t->increments('id'); $t->integer('id_documento'); $t->integer('id_usuario'); $t->text('seguimiento');
        });
        Schema::create('marketplace_area', function (Blueprint $t) {
            $t->increments('id'); $t->integer('publico')->default(1);
        });
        DB::table('marketplace_area')->insert(['id' => 1, 'publico' => 1]);
        Schema::create('moneda', function (Blueprint $t) {
            $t->increments('id'); $t->string('moneda');
        });
        DB::table('moneda')->insert(['id' => 3, 'moneda' => 'MXN']);
        foreach (['modelo_existencias', 'modelo_kardex', 'movimiento_producto'] as $name) {
            Schema::create($name, function (Blueprint $t) { $t->increments('id'); });
        }
    }
}
