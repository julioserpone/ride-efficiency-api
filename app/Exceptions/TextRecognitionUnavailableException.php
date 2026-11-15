<?php

namespace App\Exceptions;

use RuntimeException;

class TextRecognitionUnavailableException extends RuntimeException
{
    public function errorCode(): string
    {
        return 'provider_unavailable';
    }
}
