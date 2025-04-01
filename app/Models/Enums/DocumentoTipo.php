<?php
    namespace App\Models\Enums;

    class DocumentoTipo {
        const ORDEN_DE_COMPRA = 0;
        const COMPRA = 1;
        const VENTA = 2;
        const ENTRADA = 3;
        const SALIDA = 4;
        const TRASPASO = 5;
        const NOTA_CREDITO = 6;
        const NOTA_CREDITO_PROVEEDOR = 7;
        const PRETRANSFERENCIA = 9;
        const USO_INTERNO = 11;
    };