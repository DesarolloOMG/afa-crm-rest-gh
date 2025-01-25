<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class ExampleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    private function make_json($json){
        header('Content-Type: application/json');
        
        return json_encode($json);
    }
}
