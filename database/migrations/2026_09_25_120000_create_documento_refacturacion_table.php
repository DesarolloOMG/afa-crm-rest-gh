<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentoRefacturacionTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('documento', 'es_refactura')) {
            Schema::table('documento', function (Blueprint $table) {
                $table->boolean('es_refactura')->default(false);
            });
        }
        if (!Schema::hasTable('documento_refacturacion')) {
            Schema::create('documento_refacturacion', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('id_documento_original')->unique();
            $table->unsignedBigInteger('id_documento_nuevo')->unique();
            $table->unsignedBigInteger('id_nota_credito')->unique();
            $table->unsignedInteger('id_entidad_nueva');
            $table->unsignedInteger('id_movimiento_nota')->unique();
            $table->unsignedInteger('created_by');
            $table->string('estado_fiscal', 40)->default('pendiente_timbrado');
            $table->string('request_hash', 64);
            $table->longText('auditoria');
            $table->timestamps();
            });
        }
    }

    public function down()
    {
        // No borrar la trazabilidad de operaciones contables ya realizadas.
        if (Schema::hasTable('documento_refacturacion')
            && \Illuminate\Support\Facades\DB::table('documento_refacturacion')->exists()) {
            throw new RuntimeException('La tabla contiene refacturaciones; no se puede eliminar.');
        }
        if (Schema::hasColumn('documento', 'es_refactura')
            && \Illuminate\Support\Facades\DB::table('documento')->where('es_refactura', 1)->exists()) {
            throw new RuntimeException('Hay documentos de refacturación; no se puede quitar la bandera.');
        }
        Schema::dropIfExists('documento_refacturacion');
        if (Schema::hasColumn('documento', 'es_refactura')) {
            Schema::table('documento', function (Blueprint $table) {
                $table->dropColumn('es_refactura');
            });
        }
    }
}
