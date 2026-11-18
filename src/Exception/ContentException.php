<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Exception;

/**
 * Thrown when text or media breaks a network rule at the point of sending.
 */
final class ContentException extends \RuntimeException implements CrosspostException
{
}
