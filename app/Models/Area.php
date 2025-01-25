<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\MarketplaceArea;

class Area extends Model {
    use SoftDeletes;

    protected $table = 'area';

    public function marketplaces() {
        return $this->belongsToMany(Marketplace::class, "marketplace_area", "id_area", "id_marketplace")->withPivot("id");
    }
}
