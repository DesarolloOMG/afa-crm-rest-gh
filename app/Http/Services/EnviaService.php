<?php

namespace App\Http\Services;

class EnviaService {
    public static function cotizar(){
        $data = array(
            "origin" => array(
                "name" => "JESUS ALBERTO GONZALEZ",
                "company" => "JESUS ALBERTO GONZALEZ",
                "email" => "alberto@omg.com.mx",
                "phone" => "6692558616",
                "street" => "Industria Maderera",
                "number" => "226A",
                "district" => "Industrial Zapopan Norte",
                "city" => "Zapopan",
                "state" => "JA",
                "country" => "MX",
                "postalCode" => "45130",
                "reference" => "Edificio blanco"
            ),
            "destination" => array(
                "name" => "JESUS ALBERTO GONZALEZ",
                "company" => "JESUS ALBERTO GONZALEZ",
                "email" => "alberto@omg.com.mx",
                "phone" => "6692558616",
                "street" => "Rigoberto Astorga",
                "number" => "349",
                "district" => "Lomas del Ebano",
                "city" => "Mazatlan",
                "state" => "SI",
                "country" => "MX",
                "postalCode" => "82198",
                "reference" => "Casa color tinto"
            ),
            "packages" => array(
                "0" => array(
                    "content" => "Celular",
                    "amount" => 1,
                    "type" => "box",
                    "dimensions" => array(
                        "length" => 10,
                        "width" => 10,
                        "height" => 10
                    ),
                    "weight" => 1,
                    "insurance" => 0,
                    "declaredValue" => 0,
                    "weightUnit" => "KG",
                    "lengthUnit" => "CM"
                )
            ),
            "shipment" => array(
                "carrier" => "redpack"
            )
        );

        $request = \Httpful\Request::post(config("webservice.envia_ship") . "ship/rate")
        ->addHeader('Content-Type' , 'application/json')
        ->addHeader('Authorization', "Bearer " . config("webservice.envia_token"))
        ->body(json_encode($data), \Httpful\Mime::FORM)
        ->send();

        return json_decode($request->raw_body);
    }
}