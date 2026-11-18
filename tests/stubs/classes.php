<?php

declare(strict_types=1);

// Minimal WordPress classes for tests.

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code] = [$message];
        }
    }

    public function get_error_message(): string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? '';
        }
        return '';
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'draft';
    public string $post_type = 'post';
    public string $post_password = '';
    public string $post_date = '';
    public string $post_date_gmt = '';
    public string $post_author = '1';
    public string $post_mime_type = '';
    public string $post_name = '';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';

    public function __construct(int $id, string $name)
    {
        $this->term_id = $id;
        $this->name = $name;
    }
}

class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params = [])
    {
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null)
    {
    }
}

/**
 * GD-backed stand-in for WordPress's image editor.
 */
class WP_Image_Editor_Test
{
    private \GdImage $image;
    private int $quality = 82;

    public function __construct(private string $file)
    {
        $data = file_get_contents($file);
        $image = $data === false ? false : imagecreatefromstring($data);
        if ($image === false) {
            throw new RuntimeException('cannot open');
        }
        $this->image = $image;
    }

    public function resize(int $maxW, int $maxH, bool $crop = false): bool
    {
        $w = imagesx($this->image);
        $h = imagesy($this->image);
        $ratio = min($maxW / $w, $maxH / $h, 1);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $scaled = imagescale($this->image, $nw, $nh);
        if ($scaled !== false) {
            $this->image = $scaled;
        }
        return true;
    }

    public function set_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    /**
     * @return array{path: string, file: string, width: int, height: int, mime-type: string}
     */
    public function save(string $filename, string $mime): array
    {
        imagejpeg($this->image, $filename, $this->quality);
        return ['path' => $filename, 'file' => basename($filename), 'width' => imagesx($this->image), 'height' => imagesy($this->image), 'mime-type' => 'image/jpeg'];
    }
}

/**
 * Base database object. Tests use subclasses.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $rows_affected = 0;
    public int $insert_id = 0;
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            if ($m[0] === '%f') {
                return (string) (float) $value;
            }
            return "'" . $this->escape((string) $value) . "'";
        }, str_replace('%%', '%%%%', $query));
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        return 0;
    }

    /** @return list<mixed> */
    public function get_col(string $query = '', int $x = 0): array
    {
        $this->queries[] = $query;
        return [];
    }

    public function get_var(string $query = ''): mixed
    {
        $this->queries[] = $query;
        return null;
    }

    public function get_row(string $query = '', string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $query;
        return null;
    }

    /** @return list<mixed> */
    public function get_results(string $query = '', string $output = 'OBJECT'): array
    {
        $this->queries[] = $query;
        return [];
    }

    public function dbDelta(string $query): void
    {
        $this->queries[] = $query;
    }
}
