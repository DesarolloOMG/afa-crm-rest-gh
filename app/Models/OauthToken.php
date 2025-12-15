<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OauthToken extends Model
{
    protected $table = 'oauth_tokens';

    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'expires_at'
    ];

    protected $dates = ['expires_at'];

    protected $hidden = ['access_token', 'refresh_token'];
}
