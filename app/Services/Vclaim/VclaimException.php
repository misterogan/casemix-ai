<?php

namespace App\Services\Vclaim;

use RuntimeException;

class VclaimException extends RuntimeException
{
    public function __construct(public readonly string $metaCode, string $message)
    {
        parent::__construct("VClaim [{$metaCode}] {$message}");
    }
}
