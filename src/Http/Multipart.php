<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Http;

/**
 * Builds a multipart/form-data body by hand, so file uploads go through the
 * WordPress HTTP API without a library.
 */
final class Multipart
{
    /** @var list<string> */
    private array $parts = [];

    private string $boundary;

    public function __construct(?string $boundary = null)
    {
        $this->boundary = $boundary ?? 'crosspost-' . bin2hex(random_bytes(12));
    }

    public function field(string $name, string $value): self
    {
        $this->parts[] = '--' . $this->boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name) . "\"\r\n\r\n"
            . $value . "\r\n";
        return $this;
    }

    public function file(string $name, string $filename, string $mimeType, string $bytes): self
    {
        $this->parts[] = '--' . $this->boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name) . '"; filename="' . self::quote($filename) . "\"\r\n"
            . 'Content-Type: ' . $mimeType . "\r\n\r\n"
            . $bytes . "\r\n";
        return $this;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function body(): string
    {
        return implode('', $this->parts) . '--' . $this->boundary . "--\r\n";
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);
    }
}
