<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Subnivel;
use App\Models\SubnivelNivel;

class Nivel extends Model
{
    // use HasFactory;
    use SoftDeletes;
    
    protected $table = 'nivel';

    public function subniveles() {
        return $this->belongsToMany(Subnivel::class, "subnivel_nivel", "id_nivel", "id_subnivel")->withPivot("id");
    }
}
