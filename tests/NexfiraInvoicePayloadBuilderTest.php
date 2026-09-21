<?php

use App\Http\Services\Nexfira\InvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NexfiraInvoicePayloadBuilderTest extends TestCase
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
            'nexfira.issuer_id' => '123e4567-e89b-42d3-a456-426614174000',
            'nexfira.expedition_postal_code' => '44100',
            'nexfira.global.receiver_postal_code' => '44100',
            'nexfira.global.payment_method' => 'PUE',
            'nexfira.global.payment_form' => '31',
            'nexfira.tax_rate' => '0.160000',
        ]);

        DB::purge();
        $this->createSchema();
        $this->seedCatalogs();
    }

    public function testBuildsIndividualCfdiFromTaxIncludedMovementPrice()
    {
        $document = $this->insertDocument('VENTA-100', 232.00, 1);
        $this->insertMovement($document, 2, 116.00);

        $payload = (new InvoicePayloadBuilder())->buildIndividual($document, 'afa-' . $document);

        $this->assertSame('CFDI_I', $payload['kind']);
        $this->assertSame('PUBLICO EN GENERAL', $payload['content']['receiver']['name']);
        $this->assertSame('100', $payload['content']['items'][0]['unitPrice']);
        $this->assertSame('200.00', $payload['content']['expectedTotals']['subtotal']);
        $this->assertSame('32.00', $payload['content']['expectedTotals']['transfers']);
        $this->assertSame('232.00', $payload['content']['expectedTotals']['total']);
    }

    public function testBuildsGlobalInvoiceUsingPublicSaleContract()
    {
        $first = $this->insertDocument('FOLIO-A', 116.00, 1);
        $second = $this->insertDocument('FOLIO-B', 116.00, 1);
        $this->insertMovement($first, 1, 116.00);
        $this->insertMovement($second, 1, 116.00);

        $payload = (new InvoicePayloadBuilder())->buildGlobal([$second, $first], 'afa-global-test');

        $this->assertCount(2, $payload['content']['items']);
        $this->assertSame((string) $first, $payload['content']['items'][0]['lineId']);
        $this->assertSame((string) $second, $payload['content']['items'][1]['lineId']);
        $this->assertSame('01010101', $payload['content']['items'][0]['productCode']);
        $this->assertSame('ACT', $payload['content']['items'][0]['unitCode']);
        $this->assertSame('Venta', $payload['content']['items'][0]['description']);
        $this->assertSame('PUE', $payload['content']['paymentMethod']);
        $this->assertSame('31', $payload['content']['paymentForm']);
        $this->assertSame('232.00', $payload['content']['expectedTotals']['total']);
    }

    public function testGlobalInvoiceUsesInternalIdsEvenWhenMarketplaceFoliosAreDuplicated()
    {
        $first = $this->insertDocument('FOLIO-DUPLICADO', 116.00, 1);
        $second = $this->insertDocument('FOLIO-DUPLICADO', 116.00, 1);
        $this->insertMovement($first, 1, 116.00);
        $this->insertMovement($second, 1, 116.00);

        $payload = (new InvoicePayloadBuilder())->buildGlobal([$first, $second], 'afa-global-duplicate');

        $this->assertSame((string) $first, $payload['content']['items'][0]['lineId']);
        $this->assertSame((string) $second, $payload['content']['items'][1]['lineId']);
    }

    public function testGlobalSaleTaxesKeepSixDecimalPrecisionBeforeTotalsAreRounded()
    {
        $first = $this->insertDocument('MARKETPLACE-7999', 7999.00, 1);
        $second = $this->insertDocument('MARKETPLACE-3249', 3249.00, 1);
        $this->insertMovement($first, 1, 7999.00);
        $this->insertMovement($second, 1, 3249.00);

        $payload = (new InvoicePayloadBuilder())->buildGlobal([$first, $second], 'afa-global-decimals');

        $this->assertSame('1103.3104', $payload['content']['items'][0]['taxes'][0]['amount']);
        $this->assertSame('448.1376', $payload['content']['items'][1]['taxes'][0]['amount']);
        $this->assertSame('1551.45', $payload['content']['expectedTotals']['transfers']);
        $this->assertSame('11248.00', $payload['content']['expectedTotals']['total']);
    }

    public function testBuildsGlobalInvoiceByProductsWithoutMergingRepeatedProducts()
    {
        DB::table('documento_entidad')->where('id', 1)->update([
            'rfc' => 'MLG100224TC1',
            'razon_social' => 'MASTER LOYALTY GROUP',
            'regimen_id' => '601',
            'regimen' => '601',
            'regimen_letra' => '601 - General de Ley Personas Morales',
            'codigo_postal_fiscal' => '11700',
        ]);
        $first = $this->insertDocument('MLG-UNO', 116.00, 0);
        $second = $this->insertDocument('MLG-DOS', 232.00, 0);
        $this->insertMovement($first, 1, 116.00);
        $this->insertMovement($second, 1, 232.00);

        $payload = (new InvoicePayloadBuilder())->buildGlobal(
            [$first, $second],
            'afa-global-products',
            InvoicePayloadBuilder::GLOBAL_GROUP_PRODUCTS
        );

        $this->assertSame('MLG100224TC1', $payload['content']['receiver']['rfc']);
        $this->assertCount(2, $payload['content']['items']);
        $this->assertStringStartsWith('pedido-' . $first . '-partida-', $payload['content']['items'][0]['lineId']);
        $this->assertStringStartsWith('pedido-' . $second . '-partida-', $payload['content']['items'][1]['lineId']);
        $this->assertSame('43211503', $payload['content']['items'][0]['productCode']);
        $this->assertSame('43211503', $payload['content']['items'][1]['productCode']);
        $this->assertSame('100', $payload['content']['items'][0]['unitPrice']);
        $this->assertSame('200', $payload['content']['items'][1]['unitPrice']);
        $this->assertSame('348.00', $payload['content']['expectedTotals']['total']);
    }

    public function testRejectsProductGlobalForDifferentFiscalReceivers()
    {
        DB::table('marketplace_area')->where('id', 1)->update(['publico' => 0]);
        DB::table('documento_entidad')->insert([
            'id' => 2,
            'rfc' => 'AAA010101AAA',
            'razon_social' => 'CLIENTE DIFERENTE',
            'regimen_id' => '601',
            'regimen' => '601',
            'regimen_letra' => '601 - General de Ley Personas Morales',
            'codigo_postal_fiscal' => '44100',
        ]);
        $first = $this->insertDocument('CLIENTE-UNO', 116.00, 0);
        $second = $this->insertDocument('CLIENTE-DOS', 116.00, 0);
        DB::table('documento')->where('id', $second)->update(['id_entidad' => 2]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mismo receptor fiscal');

        (new InvoicePayloadBuilder())->buildGlobal(
            [$first, $second],
            'afa-global-different-receivers',
            InvoicePayloadBuilder::GLOBAL_GROUP_PRODUCTS
        );
    }

    public function testRejectsDocumentWhoseStoredTotalDoesNotMatchCfdi()
    {
        $document = $this->insertDocument('VENTA-DIFERENTE', 300.00, 1);
        $this->insertMovement($document, 1, 116.00);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no cuadra');
        (new InvoicePayloadBuilder())->buildIndividual($document, 'afa-' . $document);
    }

    public function testResolvesFiscalRegimeFromLegacyCatalogLabel()
    {
        DB::table('documento_entidad')->where('id', 1)->update([
            'rfc' => 'MLG100224TC1',
            'razon_social' => 'MASTER LOYALTY GROUP',
            'regimen_id' => '1',
            'regimen' => '1',
            'regimen_letra' => '601 - General de Ley Personas Morales',
            'codigo_postal_fiscal' => '11700',
        ]);
        $document = $this->insertDocument('VENTA-LEGACY', 116.00, 0);
        $this->insertMovement($document, 1, 116.00);

        $payload = (new InvoicePayloadBuilder())->buildIndividual($document, 'afa-' . $document);

        $this->assertSame('601', $payload['content']['receiver']['fiscalRegime']);
        $this->assertSame('11700', $payload['content']['receiver']['postalCode']);
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
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('modelo', function ($table) {
            $table->increments('id');
            $table->string('descripcion');
            $table->string('clave_sat');
            $table->string('clave_unidad');
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
        Schema::create('documento_pago', function ($table) {
            $table->increments('id');
            $table->integer('id_metodopago');
        });
        Schema::create('documento_pago_re', function ($table) {
            $table->increments('id');
            $table->integer('id_pago');
            $table->integer('id_documento');
        });
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
            'regimen' => '616',
            'regimen_letra' => '616 - Sin obligaciones fiscales',
            'codigo_postal_fiscal' => '44100',
        ]);
        DB::table('modelo')->insert([
            'id' => 1,
            'descripcion' => 'Producto de prueba',
            'clave_sat' => '43211503',
            'clave_unidad' => 'H87',
        ]);
    }

    private function insertDocument($folio, $total, $publico)
    {
        DB::table('marketplace_area')->where('id', 1)->update(['publico' => $publico]);

        return DB::table('documento')->insertGetId([
            'id_marketplace_area' => 1,
            'id_cfdi' => 1,
            'id_moneda' => 1,
            'id_entidad' => 1,
            'id_tipo' => 2,
            'id_fase' => 5,
            'id_periodo' => 1,
            'fulfillment' => 0,
            'status' => 1,
            'no_venta' => $folio,
            'total' => $total,
            'deleted_at' => null,
        ]);
    }

    private function insertMovement($document, $quantity, $grossUnitPrice)
    {
        DB::table('movimiento')->insert([
            'id_documento' => $document,
            'id_modelo' => 1,
            'cantidad' => $quantity,
            'precio' => $grossUnitPrice,
            'descuento' => 0,
            'retencion' => 0,
        ]);
    }
}
