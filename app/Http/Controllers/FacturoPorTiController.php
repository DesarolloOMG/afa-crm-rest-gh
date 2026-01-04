<?php

namespace App\Http\Controllers;

use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use DOMDocument;
use Validator;
use Exception;
use DB;

use App\Http\Services\FacturoPorTiService;

class FacturoPorTiController extends Controller
{
    protected $facturoporti;

    public function __construct(FacturoPorTiService $facturoporti)
    {
        $this->facturoporti = $facturoporti;
    }

    public function endpointurl(){
        //return "asd";
        return $this->facturoporti->urlendpoint();
    }
}