<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

class CreateUsuarioTotpTable extends Migration
{
    public function up()
    {
        Schema::create('usuario_totp', function ($table) {
            $table->increments('id');
            $table->unsignedTinyInteger('usuario_id')->unique();
            $table->text('secret');
            $table->dateTime('pending_expires_at')->nullable();
            $table->dateTime('enabled_at')->nullable();
            $table->bigInteger('last_used_step')->nullable();
            $table->unsignedInteger('fail_count')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('usuario_totp');
    }
}
