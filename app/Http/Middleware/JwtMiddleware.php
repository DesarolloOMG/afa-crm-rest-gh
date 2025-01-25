<?php
namespace App\Http\Middleware;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Exception;
use Closure;

class JwtMiddleware{

    public function handle($request, Closure $next, $guard = null){
        $token = $request->get('token');
        
        if(!$token) {
            // Unauthorized response if token not there
            return response()->json([
                'code' => 401,
                'message' => 'Token not provided.'
            ], 401);
        }
        try {
            $credentials = JWT::decode($token, env('JWT_SECRET'), ['HS256']);
        } catch(ExpiredException $e) {
            return response()->json([
                'code' => 401,
                'message' => 'Provided token has expired.'
            ], 401);
        } catch(Exception $e) {
            return response()->json([
                '404' => 401,
                'message' => 'An error ocurred while decoding token.'
            ], 401);
        }

        $request->auth = $credentials->sub;

        return $next($request);
    }
}