<?php

declare(strict_types=1);

namespace PeopleSuite;

use RuntimeException;

final class BusinessError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason);
    }
}
