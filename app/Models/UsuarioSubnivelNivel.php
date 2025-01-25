<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\SubnivelNivel;
use App\Models\Nivel;

class UsuarioSubnivelNivel extends Model
{
    // use HasFactory;
    protected $table = 'usuario_subnivel_nivel';

    protected $fillable = [
        "id_usuario",
        "id_subnivel_nivel"
    ];
}
