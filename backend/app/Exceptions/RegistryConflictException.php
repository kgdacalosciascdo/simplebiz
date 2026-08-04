<?php

namespace App\Exceptions;

use RuntimeException;

class RegistryConflictException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
