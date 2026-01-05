<?php

namespace App\Http\Services;

use Exception;
use GuzzleHttp\Client;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FacturoPorTiService
{
    protected $endpoint_prod;
    protected $endpoint_sandbox;

    protected $users;

    public function __construct()
    {
        $this->endpoint_prod = config('webservice.facturoporti_endpoint_production');
        $this->endpoint_sandbox = config('webservice.facturoporti_endpoint_sandbox');
        $this->users = config('keys.facturoporti_users');
    }

    public function urlendpoint(){
        return $this->endpoint_sandbox;
    }

    public function getToken($production){

        $cacheKey = 'facturo_porti_token_'.($production)?'production':'sandbox';
        $cacheTtl = 180 * 24 * 60 * 60; // 6 meses
        //$cacheTtl = 1;

        $endpoint = ($production)? $this->endpoint_prod : $this->endpoint_sandbox ;

        $user = ($production)? $this->users["production"]["user"] : $this->users["sandbox"]["user"] ;
        $password = ($production)? $this->users["production"]["password"] : $this->users["sandbox"]["password"] ;

        try {
            return Cache::remember($cacheKey, $cacheTtl, function () use($endpoint, $user, $password) {
                Log::info('Cache miss: Solicitando nuevo token (cache por 6 meses)');

                $url = $endpoint."token/crear?".http_build_query([
                    'Usuario' => $user,
                    'Password' => $password
                ]);
        
                Log::info('Curl Init: '.$url);
                $curl = curl_init();

                curl_setopt_array($curl, [
                  CURLOPT_URL => $url,
                  CURLOPT_RETURNTRANSFER => true,
                  CURLOPT_ENCODING => "",
                  CURLOPT_MAXREDIRS => 10,
                  CURLOPT_TIMEOUT => 30,
                  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                  CURLOPT_CUSTOMREQUEST => "GET",
                  CURLOPT_HTTPHEADER => [
                    "accept: application/json"
                  ],
                ]);
                $response = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);
                if ($err) {
                    Log::error('Curl Error: '.$err);
                } else {
                    Log::info('Response: '.$response);
                    $response = json_decode($response, true);

                    return $response["token"];
                }
                
                throw new \Exception('No se pudo obtener token');
            });
            
        } catch (\Exception $e) {
           
            Log::error('Error en getToken: ' . $e->getMessage());
            return null;
        }

    }
}