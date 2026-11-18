<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Exception;

/**
 * Thrown when credentials are missing, malformed or cannot be refreshed.
 */
final class CredentialsException extends \RuntimeException implements CrosspostException
{
}
