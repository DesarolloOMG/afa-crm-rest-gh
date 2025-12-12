<?php
    namespace App\Models\Enums;

    class TicketEstado {
        const NUEVO = 'nuevo';
        const ASIGNADO = 'asignado';
        const EN_REVISION = 'en_revision';
        const RESUELTO = 'resuelto';
        const CERRADO = 'cerrado';
    };