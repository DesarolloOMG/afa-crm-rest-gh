<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceAreaEmpresa extends Model {
    protected $table = 'marketplace_area_empresa';

    protected $fillable = [
        "id_marketplace_area",
        "id_empresa",
        "utilidad"
    ];
}
