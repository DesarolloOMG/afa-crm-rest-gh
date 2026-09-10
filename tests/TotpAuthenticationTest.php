<?php

use App\Http\Controllers\AuthController;
use App\Http\Services\TotpLoginService;
use App\Http\Services\WhatsAppService;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TotpAuthenticationTest extends TestCase
{
    private $oldDb;
    private $oldResolver;
    private $oldSecret;

    public function setUp()
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 9, 12, 0, 0));
        $this->oldSecret = getenv('JWT_SECRET');
        putenv('JWT_SECRET=local-test-only-key-not-used-outside-tests');
        $this->oldDb = $this->app->make('db');
        $this->oldResolver = Model::getConnectionResolver();

        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $manager = $capsule->getDatabaseManager();
        $this->app->instance('db', $manager);
        DB::clearResolvedInstance('db');
        Model::setConnectionResolver($manager);

        DB::connection()->getSchemaBuilder()->create('usuario', function ($table) {
            $table->integer('id')->primary();
            $table->integer('status');
            $table->integer('api')->default(0);
            $table->string('email');
            $table->string('contrasena');
            $table->string('nombre')->nullable();
            $table->string('celular')->nullable();
            $table->string('last_ip')->nullable();
            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('usuario')->insert([
            'id' => 1,
            'status' => 1,
            'api' => 0,
            'email' => 'usuario@afa.test',
            'contrasena' => password_hash('correct-password', PASSWORD_BCRYPT),
            'nombre' => 'Usuario TOTP',
            'celular' => '3312345678',
        ]);

        DB::connection()->getSchemaBuilder()->create('usuario_totp', function ($table) {
            $table->increments('id');
            $table->integer('usuario_id')->unique();
            $table->text('secret');
            $table->dateTime('pending_expires_at')->nullable();
            $table->dateTime('enabled_at')->nullable();
            $table->bigInteger('last_used_step')->nullable();
            $table->integer('fail_count')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->timestamps();
        });
        DB::connection()->getSchemaBuilder()->create('usuario_login_error', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('password');
            $table->string('mensaje');
            $table->timestamps();
        });
        DB::connection()->getSchemaBuilder()->create('usuario_ip', function ($table) {
            $table->increments('id');
            $table->integer('id_usuario');
            $table->string('ip')->nullable();
            $table->timestamps();
        });
        DB::connection()->getSchemaBuilder()->create('usuario_marketplace_area', function ($table) {
            $table->increments('id');
            $table->integer('id_usuario');
            $table->integer('id_marketplace_area');
        });
        DB::connection()->getSchemaBuilder()->create('usuario_empresa', function ($table) {
            $table->increments('id');
            $table->integer('id_usuario');
            $table->integer('id_empresa');
        });
        DB::connection()->getSchemaBuilder()->create('usuario_subnivel_nivel', function ($table) {
            $table->increments('id');
            $table->integer('id_usuario');
            $table->integer('id_subnivel_nivel');
        });
        DB::connection()->getSchemaBuilder()->create('subnivel_nivel', function ($table) {
            $table->increments('id');
            $table->integer('id_nivel');
            $table->integer('id_subnivel');
        });
    }

    public function tearDown()
    {
        Carbon::setTestNow();
        $this->app->instance('db', $this->oldDb);
        DB::clearResolvedInstance('db');
        Model::setConnectionResolver($this->oldResolver);
        putenv($this->oldSecret === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $this->oldSecret);
        parent::tearDown();
    }

    public function testKnownTotpVectorPreservesSixDigitsAndLeadingZeroes()
    {
        $service = new TotpLoginService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $service->codeForTime($secret, 59));
        $this->assertSame('081804', $service->codeForTime($secret, 1111111109));
    }

    public function testLoginRequiresPasswordThenBeginsEncryptedAuthenticatorEnrollment()
    {
        $wrong = $this->login(['email' => 'usuario@afa.test', 'password' => 'wrong-password']);
        $this->assertSame(404, $wrong->getStatusCode());
        $this->assertSame(0, DB::table('usuario_totp')->count());

        $response = $this->login(['email' => 'USUARIO@AFA.TEST ', 'password' => 'correct-password']);
        $body = $response->getData(true);
        $row = DB::table('usuario_totp')->where('usuario_id', 1)->first();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['mfa_setup']);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertStringStartsWith('otpauth://totp/', $body['otpauth_uri']);
        $this->assertStringContainsString('issuer=AFA%20Innovations', $body['otpauth_uri']);
        $this->assertNotSame(Crypt::decryptString($row->secret), $row->secret);
    }

    public function testValidAuthenticatorCodeEnablesLoginAndCannotBeReused()
    {
        $service = new TotpLoginService();
        $this->login(['email' => 'usuario@afa.test', 'password' => 'correct-password']);
        $secret = Crypt::decryptString(DB::table('usuario_totp')->where('usuario_id', 1)->value('secret'));
        $code = $service->codeForTime($secret, Carbon::now()->timestamp);

        $accepted = $this->login([
            'email' => 'usuario@afa.test',
            'password' => 'correct-password',
            'totp_code' => $code,
        ]);
        $reused = $this->login([
            'email' => 'usuario@afa.test',
            'password' => 'correct-password',
            'totp_code' => $code,
        ]);

        $this->assertSame(200, $accepted->getStatusCode());
        $this->assertArrayHasKey('token', $accepted->getData(true));
        $this->assertNotEmpty(DB::table('usuario_totp')->where('usuario_id', 1)->value('enabled_at'));
        $this->assertSame(422, $reused->getStatusCode());
        $this->assertArrayNotHasKey('token', $reused->getData(true));
    }

    public function testBusinessAuthorizationUsesTotpWithoutAuthCodesOrTwilio()
    {
        $service = new TotpLoginService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        DB::table('usuario_totp')->insert([
            'usuario_id' => 1,
            'secret' => Crypt::encryptString($secret),
            'enabled_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $ready = (new WhatsAppService())->sendCode(1, '3312345678');
        $code = $service->codeForTime($secret, Carbon::now()->timestamp);
        $accepted = WhatsAppService::validateCode(1, $code);
        $reused = WhatsAppService::validateCode(1, $code);

        $this->assertSame(200, $ready->getStatusCode());
        $this->assertStringContainsString('aplicación autenticadora', $ready->getData(true)['message']);
        $this->assertSame(0, $accepted->error);
        $this->assertSame(1, $reused->error);
    }

    public function testPasswordRecoveryRequestsAnEnrolledAuthenticatorWithoutTwilio()
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        DB::table('usuario_totp')->insert([
            'usuario_id' => 1,
            'secret' => Crypt::encryptString($secret),
            'enabled_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = (new AuthController())->auth_reset(Request::create('/auth/reset', 'POST', [
            'data' => json_encode(['email' => 'usuario@afa.test']),
        ]));
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['mfa_required']);
        $this->assertArrayNotHasKey('token', $body);
    }

    public function testFiveInvalidCodesLockTheAuthenticatorTemporarily()
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        DB::table('usuario_totp')->insert([
            'usuario_id' => 1,
            'secret' => Crypt::encryptString($secret),
            'enabled_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = WhatsAppService::validateCode(1, '000000');
            $this->assertSame(1, $result->error);
        }

        $status = (new TotpLoginService())->authorizationStatus(\App\Models\Usuario::find(1));
        $this->assertFalse($status['ready']);
        $this->assertTrue($status['locked']);
        $this->assertSame(5, (int)DB::table('usuario_totp')->where('usuario_id', 1)->value('fail_count'));
    }

    private function login(array $data)
    {
        return (new AuthController())->auth_login(Request::create('/auth/login', 'POST', [
            'data' => json_encode($data),
        ]));
    }
}
