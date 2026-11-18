<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Value;

/**
 * The connected account, as the network reports it: its id and display name.
 */
final class Account
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
