<?php

namespace App\Http\Services\Nexfira;

use RuntimeException;

class NexfiraApiException extends RuntimeException
{
    private $httpStatus;
    private $apiCode;
    private $correlationId;
    private $validationErrors;

    public function __construct($message, $httpStatus = 502, $apiCode = null, $correlationId = null, array $validationErrors = [])
    {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
        $this->apiCode = $apiCode;
        $this->correlationId = $correlationId;
        $this->validationErrors = $validationErrors;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getApiCode()
    {
        return $this->apiCode;
    }

    public function getCorrelationId()
    {
        return $this->correlationId;
    }

    public function getValidationErrors()
    {
        return $this->validationErrors;
    }
}
