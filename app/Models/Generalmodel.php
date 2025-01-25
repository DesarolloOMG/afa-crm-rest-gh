<?php 

namespace App\Models;

use Firebase\JWT\JWT;
class Generalmodel 
{

    public static function mensaje($tipo,$mensaje)
    {
        return response()->json([
            'code' => $tipo,
            'message' => $mensaje
        ], $tipo); 
    }
    
    public static  function jwt($usuario) {
        $payload = [
            'iss' => "lumen-api-jwt", // Issuer of the token.
            'sub' => $usuario, // Subject of the token.
            'iat' => time(), // Time when JWT was issued.
            'exp' => time() + 60 * 60 * 11 // El token expira cada 11 horas.
        ];
        
        // As you can see we are passing `JWT_SECRET` as the second parameter that will 
        // be used to decode the token in the future.
        return JWT::encode($payload, env('JWT_SECRET'));
    }
}