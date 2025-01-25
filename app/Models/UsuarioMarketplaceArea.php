<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioMarketplaceArea extends Model
{
    // use HasFactory;
    protected $table = 'usuario_marketplace_area';

    protected $fillable = [
        "id_usuario",
        "id_marketplace_area"
    ];
}
