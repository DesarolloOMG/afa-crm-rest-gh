<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFacturacionSolicitudesTables extends Migration
{
    public function up()
    {
        Schema::create('facturacion_solicitud', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('proveedor', 20);
            $table->string('modo', 20);
            $table->string('remote_request_id', 36)->nullable()->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->string('external_reference', 100)->unique();
            $table->string('status', 30);
            $table->unsignedInteger('version')->default(1);
            $table->string('fiscal_uuid', 36)->nullable();
            $table->string('documents_status', 20)->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('correlation_id', 36)->nullable();
            $table->text('error_message')->nullable();
            $table->mediumText('request_payload')->nullable();
            $table->mediumText('response_payload')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->index(['status', 'proveedor']);
            $table->index('fiscal_uuid');
        });

        Schema::create('facturacion_solicitud_documento', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('id_solicitud');
            $table->unsignedBigInteger('id_documento');
            $table->timestamps();

            $table->unique(['id_solicitud', 'id_documento'], 'fact_sol_doc_unique');
            $table->index('id_documento');
            $table->foreign('id_solicitud')
                ->references('id')
                ->on('facturacion_solicitud')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('facturacion_solicitud_documento');
        Schema::dropIfExists('facturacion_solicitud');
    }
}
