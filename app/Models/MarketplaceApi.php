<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\MarketplaceArea;

class MarketplaceApi extends Model {
    protected $table = 'marketplace_api';

    protected $fillable = [
        "id_marketplace_area",
        "extra_1",
        "extra_2",
        "app_id",
        "secret",
        "guia",
        "token",
        "token_created_at",
        "token_expired_at"
    ];

    public function marketplace_area() {
        return $this->belongsTo(MarketplaceArea::class, "id_marketplace_area");
    }
}
