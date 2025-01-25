<?php
    namespace App\Models\Enums;

    class DocumentoFase {
        const PEDIDO = 1;
        const PENDIENTE_REVISION_SOPORTE = 2;
        const PENDIENTE_REMISION = 3;
        const PENDIENTE_ENVIO = 4;
        const PENDIENTE_FACTURA = 5;
        const VENTA_FINALIZADA = 6;

        const DOCUMENTO_FINALIZADO = 100;
        const PRETRANSFERENCIA_PENDIENTE = 401;
        const PRETRANSFERENCIA_PENDIENTE_AUTORIZAR = 402;
        const PRETRANSFERENCIA_PENDIENTE_ENVIO = 403;
        const PRETRANSFERENCIA_PENDIENTE_FINALIZAR = 404;
        const PRETRANSFERENCIA_CON_DIFERENCIAS = 405;
        
        const ODC_FASE_600 = 600;
        const ODC_FASE_601 = 601;
        const ODC_FASE_602 = 602;
        const ODC_FASE_603 = 603;
        const ODC_FASE_604 = 604;
        const ODC_FASE_605 = 605;
        const ODC_FASE_606 = 606;
        const ODC_FASE_607 = 607;
    };