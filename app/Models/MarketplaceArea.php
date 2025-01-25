<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\MarketplaceApi;
use App\Models\MarketplaceAreaEmpresa;
use App\Models\Marketplace;
use App\Models\Area;

class MarketplaceArea extends Model
{
    use SoftDeletes;

    protected $table = 'marketplace_area';

    protected $fillable = [
        "id_marketplace",
        "id_area",
        "serie",
        "serie_nota",
        "publico"
    ];

    public function marketplace() {
        return $this->hasOne(Marketplace::class, "id", "id_marketplace");
    }

    public function area() {
        return $this->hasOne(Area::class, "id", "id_area");
    }

    public function api() {
        return $this->hasOne(MarketplaceApi::class, "id_marketplace_area", "id");
    }

    public function empresa() {
        return $this->hasOne(MarketplaceAreaEmpresa::class, "id_marketplace_area", "id");
    }
}
