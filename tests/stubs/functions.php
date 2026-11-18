<?php

declare(strict_types=1);

// Small stand-ins for the WordPress functions this plugin calls. Behaviour
// follows WordPress closely enough for the cases under test; nothing here
// talks to a network or a database.

use IndianOpinion\Crosspost\Tests\Stubs\State;

final class WPDieException extends RuntimeException
{
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    State::$hooks[$hook][] = [$callback, $priority, $args];
    usort(State::$hooks[$hook], static fn (array $a, array $b): int => $a[1] <=> $b[1]);
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_all_filters(string $hook): void
{
    unset(State::$hooks[$hook]);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (State::$hooks[$hook] ?? [] as [$callback, , $count]) {
        $value = $callback(...array_slice([$value, ...$args], 0, max(1, $count)));
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    State::$actions[] = [$hook, $args];
    foreach (State::$hooks[$hook] ?? [] as [$callback, , $count]) {
        $callback(...array_slice($args, 0, $count));
    }
}

function register_activation_hook(string $file, callable $callback): void
{
}

function register_deactivation_hook(string $file, callable $callback): void
{
}

function is_admin(): bool
{
    return false;
}

// Options and transients.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, State::$options) ? State::$options[$name] : $default;
}

function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    State::$options[$name] = $value;
    if ($autoload !== null) {
        State::$autoload[$name] = (bool) $autoload;
    }
    State::$timeline[] = 'option:' . $name;
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
{
    if (array_key_exists($name, State::$options)) {
        return false;
    }
    return update_option($name, $value, $autoload);
}

function delete_option(string $name): bool
{
    unset(State::$options[$name]);
    return true;
}

function get_transient(string $name): mixed
{
    return State::$transients[$name][0] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    State::$transients[$name] = [$value, $ttl];
    return true;
}

function delete_transient(string $name): bool
{
    unset(State::$transients[$name]);
    return true;
}

// Posts, meta, terms.

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return State::$posts[(int) $post] ?? null;
}

/**
 * @param array<string, mixed> $args
 * @return list<WP_Post>
 */
function get_posts(array $args = []): array
{
    $types = (array) ($args['post_type'] ?? ['post']);
    $posts = array_values(array_filter(State::$posts, static fn (WP_Post $p): bool => $p->post_status === ($args['post_status'] ?? 'publish') && in_array($p->post_type, $types, true)));
    $after = $args['date_query'][0]['after'] ?? null;
    if (is_string($after)) {
        $posts = array_values(array_filter($posts, static fn (WP_Post $p): bool => $p->post_date_gmt >= $after));
    }
    usort($posts, static fn (WP_Post $a, WP_Post $b): int => ($args['order'] ?? 'DESC') === 'ASC' ? strcmp($a->post_date_gmt, $b->post_date_gmt) : strcmp($b->post_date_gmt, $a->post_date_gmt));
    return array_slice($posts, 0, (int) ($args['posts_per_page'] ?? 10));
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return State::$meta[$id][$key] ?? ($single ? '' : []);
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    State::$meta[$id][$key] = $value;
    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset(State::$meta[$id][$key]);
    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (State::$meta as $id => $meta) {
        unset(State::$meta[$id][$key]);
    }
    return true;
}

function get_permalink(mixed $post): string
{
    $post = get_post($post);
    return 'https://news.example/' . ($post !== null && $post->post_name !== '' ? $post->post_name : '?p=' . ($post->ID ?? 0));
}

function get_the_title(mixed $post): string
{
    $post = get_post($post);
    return $post === null ? '' : $post->post_title;
}

function get_bloginfo(string $show = ''): string
{
    return $show === 'name' ? 'Example News' : '';
}

function get_the_author_meta(string $field, int $user): string
{
    return 'A. Writer';
}

/** @return list<WP_Term> */
function get_the_category(int $id): array
{
    return State::$categories[$id] ?? [];
}

/** @return list<WP_Term>|false */
function get_the_tags(int $id): array|false
{
    return State::$tags[$id] ?? false;
}

function get_the_date(string $format = '', mixed $post = null): string
{
    $post = get_post($post);
    return $post === null ? '' : substr($post->post_date, 0, 10);
}

function get_locale(): string
{
    return State::$options['WPLANG'] ?? 'en_GB';
}

/**
 * @param array<string, string> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function home_url(string $path = ''): string
{
    return 'https://news.example' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://news.example/wp-admin/' . $path;
}

function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://news.example/wp-content/plugins/crosspost/' . $path;
}

function get_current_user_id(): int
{
    return State::$userId;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return State::$caps[$cap] ?? false;
}

function get_post_thumbnail_id(mixed $post): int
{
    $post = get_post($post);
    return $post === null ? 0 : (int) (State::$meta[$post->ID]['_thumbnail_id'] ?? 0);
}

/** @return array<int, WP_Post> */
function get_attached_media(string $type, int $postId): array
{
    return [];
}

function get_post_mime_type(int $id): string|false
{
    return isset(State::$attachments[$id]) ? State::$attachments[$id]['mime'] : false;
}

function get_attached_file(int $id): string|false
{
    return isset(State::$attachments[$id]) ? State::$attachments[$id]['file'] : false;
}

/** @return array<string, mixed>|false */
function wp_get_attachment_metadata(int $id): array|false
{
    return isset(State::$attachments[$id]) ? State::$attachments[$id]['meta'] : false;
}

/** @return array{0: string, 1: int, 2: int, 3: bool}|false */
function wp_get_attachment_image_src(int $id, string $size = 'thumbnail'): array|false
{
    if (!isset(State::$attachments[$id])) {
        return false;
    }
    return [State::$attachments[$id]['url'], 0, 0, false];
}

function wp_get_attachment_caption(int $id): string|false
{
    return isset(State::$attachments[$id]) ? State::$attachments[$id]['caption'] : false;
}

function wp_get_image_editor(string $path): WP_Image_Editor_Test|WP_Error
{
    try {
        return new WP_Image_Editor_Test($path);
    } catch (RuntimeException) {
        return new WP_Error('image_no_editor', 'No editor could be selected.');
    }
}

/** @param array<string, string> $args */
function wp_image_editor_supports(array $args = []): bool
{
    return true;
}

function wp_delete_file(string $file): bool
{
    return is_file($file) && unlink($file);
}

function get_temp_dir(): string
{
    return sys_get_temp_dir() . '/';
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function strip_shortcodes(string $content): string
{
    return (string) preg_replace('/\[(\/?)(gallery|caption|embed)[^\]]*\]/', '', $content);
}

// HTTP: every request must be answered by a test through pre_http_request.

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
    State::$requests[] = ['method' => (string) ($args['method'] ?? 'GET'), 'url' => $url, 'headers' => $headers, 'body' => $args['body'] ?? null, 'args' => $args];
    State::$timeline[] = 'request:' . ($args['method'] ?? 'GET') . ' ' . $url;
    $response = apply_filters('pre_http_request', false, $args, $url);
    if ($response === false) {
        throw new LogicException('Unexpected HTTP request in a test: ' . $url);
    }
    return $response;
}

/** @param array<string, mixed>|WP_Error $response */
function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

/** @param array<string, mixed>|WP_Error $response */
function wp_remote_retrieve_body(array|WP_Error $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

/**
 * @param array<string, mixed>|WP_Error $response
 * @return array<string, string>
 */
function wp_remote_retrieve_headers(array|WP_Error $response): array
{
    return is_array($response) && is_array($response['headers'] ?? null) ? $response['headers'] : [];
}

// Cron.

function wp_next_scheduled(string $hook): int|false
{
    $times = [];
    foreach (State::$events as $event) {
        if ($event['hook'] === $hook) {
            $times[] = $event['time'];
        }
    }
    return $times === [] ? false : min($times);
}

function wp_schedule_single_event(int $time, string $hook): bool
{
    State::$events[] = ['hook' => $hook, 'time' => $time, 'recurrence' => ''];
    return true;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    State::$events[] = ['hook' => $hook, 'time' => $time, 'recurrence' => $recurrence];
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $before = count(State::$events);
    State::$events = array_values(array_filter(State::$events, static fn (array $e): bool => $e['hook'] !== $hook));
    return $before - count(State::$events);
}

// Sanitising and escaping.

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
}

function sanitize_email(string $email): string
{
    return trim($email);
}

function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function esc_url_raw(string $url): string
{
    return preg_match('#^https?://#', $url) === 1 ? $url : '';
}

function esc_url(string $url): string
{
    return htmlspecialchars(esc_url_raw($url), ENT_QUOTES);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

function esc_html__(string $text, string $domain = ''): string
{
    return esc_html($text);
}

function esc_attr__(string $text, string $domain = ''): string
{
    return esc_attr($text);
}

function wp_specialchars_decode(string $text, int $flags = ENT_NOQUOTES): string
{
    return htmlspecialchars_decode($text, $flags);
}

function wp_kses_post(string $html): string
{
    return $html;
}

function selected(mixed $a, mixed $b, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a === $b ? ' checked="checked"' : '';
}

// Nonces, capability, flow control.

function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
{
    return State::$nonceValid && $nonce === 'valid-' . $action ? 1 : false;
}

function wp_create_nonce(string|int $action = -1): string
{
    return 'valid-' . $action;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
}

/**
 * @param array<string, mixed> $args
 */
function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WPDieException($message, (int) ($args['response'] ?? 500));
}

function wp_safe_redirect(string $url): bool
{
    State::$redirect = $url;
    return true;
}

function wp_redirect(string $url): bool
{
    State::$redirect = $url;
    return true;
}

function wp_get_referer(): string|false
{
    return 'https://news.example/wp-admin/options-general.php?page=crosspost';
}

function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

function wp_mail(string $to, string $subject, string $message): bool
{
    State::$mail[] = [$to, $subject];
    return true;
}
