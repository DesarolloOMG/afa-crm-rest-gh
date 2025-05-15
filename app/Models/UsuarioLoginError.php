<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(array $array)
 */
class UsuarioLoginError extends Model {

    protected $table = 'usuario_login_error';

    protected $fillable = [
        'email',
        'password',
        'mensaje'
    ];
}
