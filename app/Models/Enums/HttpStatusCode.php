<?php
    namespace App\Models\Enums;

    class HttpStatusCode {
        const BAD_REQUEST = 400;
        const UNAUTHORIZED = 401;
        const FORBIDDEN = 403;
        const NOT_FOUND = 404;
        const NOT_ACCEPTABLE = 406;
        const INTERNAL_ERROR = 500;
    };