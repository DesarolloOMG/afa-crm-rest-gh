<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

//use Illuminate\Database\Eloquent\Eloquent\HasFactory;

class Usuario extends Model
{
    // use HasFactory;
    use SoftDeletes;

    protected $table = 'usuario';

    protected $hidden = ['password'];

    protected $fillable = [
        'nombre',
        'email',
        'celular',
        'contrasena',
    ];

    public function marketplaces(): HasMany
    {
        return $this->hasMany(UsuarioMarketplaceArea::class, "id_usuario");
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(UsuarioEmpresa::class, "id_usuario");
    }

    public function subniveles(): HasMany
    {
        return $this->hasMany(UsuarioSubnivelNivel::class, "id_usuario");
    }

    public function subnivelesbynivel(): BelongsToMany
    {
        return $this->belongsToMany(SubnivelNivel::class, "usuario_subnivel_nivel", "id_usuario", "id_subnivel_nivel")->withPivot("id");
    }
}
