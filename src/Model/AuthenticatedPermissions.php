<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class AuthenticatedPermissions
{
    public function __construct(public int $mask, public bool $authenticated)
    {
        if (!$authenticated) {
            throw new \InvalidArgumentException('Encrypted documents require successful authentication.');
        }
    }
}
