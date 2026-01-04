<?php

namespace App\Http\Services;

use Exception;
use GuzzleHttp\Client;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FacturoPorTiService
{
    protected $endpoint_prod;
    protected $endpoint_sandbox;

    public function __construct()
    {
        $this->endpoint_prod = config('webservice.facturoporti_endpoint_production');
        $this->endpoint_sandbox = config('webservice.facturoporti_endpoint_sandbox');
    }

    public function urlendpoint(){
        return $this->endpoint_sandbox;
    }
}