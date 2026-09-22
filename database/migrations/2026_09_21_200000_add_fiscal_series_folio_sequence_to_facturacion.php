<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddFiscalSeriesFolioSequenceToFacturacion extends Migration
{
    public function up()
    {
        Schema::table('facturacion_solicitud', function (Blueprint $table) {
            $table->string('serie', 25)->nullable()->after('modo');
            $table->string('folio', 40)->nullable()->after('serie');
            $table->index(['serie', 'folio'], 'fact_sol_serie_folio_index');
        });

        Schema::create('facturacion_folio_consecutivo', function (Blueprint $table) {
            $table->string('clave', 50)->primary();
            $table->unsignedBigInteger('siguiente_folio');
            $table->timestamps();
        });

        DB::table('facturacion_folio_consecutivo')->insert([
            'clave' => 'nexfira_cfdi_ingreso',
            'siguiente_folio' => 40000,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('facturacion_folio_consecutivo');

        Schema::table('facturacion_solicitud', function (Blueprint $table) {
            $table->dropIndex('fact_sol_serie_folio_index');
            $table->dropColumn(['serie', 'folio']);
        });
    }
}
