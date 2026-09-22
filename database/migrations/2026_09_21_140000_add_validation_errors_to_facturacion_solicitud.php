<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddValidationErrorsToFacturacionSolicitud extends Migration
{
    public function up()
    {
        Schema::table('facturacion_solicitud', function (Blueprint $table) {
            $table->mediumText('validation_errors')->nullable()->after('error_message');
        });
    }

    public function down()
    {
        Schema::table('facturacion_solicitud', function (Blueprint $table) {
            $table->dropColumn('validation_errors');
        });
    }
}
