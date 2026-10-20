<?php

use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\PrintController;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuidePrintResponseTest extends TestCase
{
    private $oldDb;

    public function setUp()
    {
        parent::setUp();
        $this->oldDb = $this->app->make('db');
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->app->instance('db', $capsule->getDatabaseManager());
        DB::clearResolvedInstance('db');
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('documento', function ($t) {
            $t->integer('id');
            $t->integer('id_fase');
            $t->integer('id_paqueteria');
            $t->integer('guia_impresa');
        });
        $schema->create('impresora', function ($t) {
            $t->integer('id');
            $t->string('servidor');
        });
        $schema->create('documento_guia', function ($t) {
            $t->integer('id_documento');
        });
        $schema->create('paqueteria', function ($t) {
            $t->integer('id');
            $t->integer('guia');
        });
        DB::table('documento')->insert(['id' => 40397, 'id_fase' => 5, 'id_paqueteria' => 19, 'guia_impresa' => 0]);
        DB::table('impresora')->insert(['id' => 4, 'servidor' => 'http://printer.test']);
        DB::table('paqueteria')->insert(['id' => 19, 'guia' => 1]);
    }

    public function tearDown()
    {
        $this->app->instance('db', $this->oldDb);
        DB::clearResolvedInstance('db');
        parent::tearDown();
    }

    /** @dataProvider failedResponses */
    public function testReprintShowsRealFailureWithoutMarkingOrderPrinted($http, $body, $expected)
    {
        $before = (array) DB::table('documento')->first();
        $response = $this->reprint(new JsonResponse($body, $http))->getData();
        $this->assertSame(500, $response->code);
        $this->assertContains($expected, $response->message);
        $this->assertSame($before, (array) DB::table('documento')->first());
        $this->assertFalse(isset($response->solicitar_guia));
        $this->assertSame(0, DB::table('documento_guia')->count());
    }

    public function failedResponses()
    {
        return [
            'application error with HTTP 200' => [200, ['code' => 500, 'message' => 'No se encontró la etiqueta del envío'], 'No se encontró la etiqueta del envío'],
            'HTTP error wrapped by proxy' => [500, ['error' => 'Error desde el printserver', 'details' => ['code' => 500, 'message' => 'La impresora no recibió la guía completa.']], 'La impresora no recibió la guía completa.'],
            'connection error' => [500, ['error' => 'No se pudo contactar con el printserver', 'message' => 'Connection refused'], 'Connection refused'],
            'HTTP failure despite body success' => [503, ['code' => 200, 'message' => 'Servicio no disponible'], 'Servicio no disponible'],
            'missing application result' => [200, ['unexpected' => true], 'respuesta inválida'],
            'HTML instead of JSON object' => [200, '<html>Bad gateway</html>', 'respuesta inválida'],
        ];
    }

    public function testOnlyConfirmedSuccessMarksOrderPrintedAndRequestsScan()
    {
        $result = $this->reprint(new JsonResponse(['code' => 200, 'message' => 'Guías enviadas a impresión.']))->getData();
        $this->assertSame(200, $result->code);
        $this->assertSame(1, $result->solicitar_guia);
        $this->assertSame(1, (int) DB::table('documento')->value('guia_impresa'));
        $this->assertSame(5, (int) DB::table('documento')->value('id_fase'));
    }

    public function testConnectionErrorDoesNotExposeSessionToken()
    {
        $result = PrintController::validateGuideResponse(new JsonResponse([
            'message' => 'Unable to connect to "http://printer.test/api?token=secret-session": Connection refused',
        ], 500))->getData();
        $this->assertNotContains('secret-session', $result->message);
        $this->assertContains('Connection refused', $result->message);
    }

    private function reprint(JsonResponse $fixture): JsonResponse
    {
        $printer = new class($fixture) extends PrintController {
            private $fixture;
            public function __construct($fixture) { $this->fixture = $fixture; }
            public function print($documento, $impresora, Request $request): JsonResponse
            {
                return self::validateGuideResponse($this->fixture);
            }
        };
        $this->app->instance(PrintController::class, $printer);
        return (new AlmacenController())->almacen_packing_v2_reimprimir(Request::create('/', 'POST', [
            'data' => json_encode(['documento' => 40397, 'impresora' => 4]),
        ]));
    }
}
