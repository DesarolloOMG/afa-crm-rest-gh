<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Usuario;
use App\Models\Empresa;

class UsuarioEmpresa extends Model
{
    // use HasFactory;
    protected $table = 'usuario_empresa';

    protected $fillable = [
        "id_usuario",
        "id_empresa"
    ];

    public function usuario() {
        return $this->hasOne(Usuario::class, "id", "id_usuario");
    }
    
    public function empresa() {
        return $this->hasOne(Empresa::class, "id", "id_empresa");
    }
}
