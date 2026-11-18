<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Exception;

/**
 * Thrown for configuration errors: an unknown network, a missing setting, an invalid instance URL.
 */
final class ConfigurationException extends \RuntimeException implements CrosspostException
{
}
