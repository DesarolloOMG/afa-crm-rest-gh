<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

//use Illuminate\Database\Eloquent\Eloquent\HasFactory;

/**
 * @method static where(string $string, $usuario)
 * @method static find($id)
 * @method static v_existe_usuario(array|string|null $user_id)
 * @method static existe_usuario(array|string|null $email)
 */
class Usuario extends Model
{
    // use HasFactory;
    use SoftDeletes;

    protected $table = 'usuario';

    protected $hidden = ['password'];

    protected $fillable = [
        'nombre',
        'email',
        'tag',
        'celular',
        'contrasena',
        'authy'
    ];

    public function marketplaces() {
        return $this->hasMany(UsuarioMarketplaceArea::class, "id_usuario");
    }

    public function empresas() {
        return $this->hasMany(UsuarioEmpresa::class, "id_usuario");
    }

    public function subniveles() {
        return $this->hasMany(UsuarioSubnivelNivel::class, "id_usuario");
    }

    public function subnivelesbynivel() {
        return $this->belongsToMany(SubnivelNivel::class, "usuario_subnivel_nivel", "id_usuario", "id_subnivel_nivel")->withPivot("id");
    }
}
