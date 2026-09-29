<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFacturacionCancelacionTable extends Migration
{
    public function up()
    {
        Schema::create('facturacion_cancelacion', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('id_solicitud')->unique();
            $table->string('status', 30);
            $table->string('provider_status', 30)->nullable();
            $table->string('motivo', 2);
            $table->string('folio_sustitucion', 36)->nullable();
            $table->string('remote_cancellation_id', 100)->nullable();
            $table->mediumText('request_payload')->nullable();
            $table->mediumText('response_payload')->nullable();
            $table->mediumText('archived_files')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_source', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('facturacion_cancelacion');
    }
}
