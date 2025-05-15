<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(array $array)
 */
class UsuarioIP extends Model {

    protected $table = 'usuario_ip';

    protected $fillable = [
        'id_usuario',
        'ip'
    ];
}
