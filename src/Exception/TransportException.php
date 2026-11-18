<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Exception;

/**
 * Thrown when a transport problem cannot be expressed as an outcome (programmer error paths only).
 */
final class TransportException extends \RuntimeException implements CrosspostException
{
}
