<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Stubs;

/**
 * The state behind the WordPress stubs. Reset before every test.
 */
final class State
{
    /** @var array<string, list<array{0: callable, 1: int, 2: int}>> */
    public static array $hooks = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool> option name => autoload */
    public static array $autoload = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    public static array $transients = [];
    /** @var array<int, \WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<int, list<\WP_Term>> */
    public static array $tags = [];
    /** @var array<int, list<\WP_Term>> */
    public static array $categories = [];
    /** @var array<int, array{file: string, mime: string, meta: array<string, mixed>, url: string, alt: string, caption: string}> */
    public static array $attachments = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed, args: array<string, mixed>}> */
    public static array $requests = [];
    /** @var list<array{hook: string, time: int, recurrence: string}> */
    public static array $events = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $mail = [];
    /** @var list<string> ordered record of writes and requests */
    public static array $timeline = [];
    /** @var array<string, bool> */
    public static array $caps = [];
    public static int $userId = 1;
    public static bool $nonceValid = true;
    public static string $redirect = '';
    public static int $actionsFired = 0;
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actions = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$posts = [];
        self::$meta = [];
        self::$tags = [];
        self::$categories = [];
        self::$attachments = [];
        self::$requests = [];
        self::$events = [];
        self::$mail = [];
        self::$timeline = [];
        self::$caps = ['manage_options' => true, 'edit_post' => true, 'publish_posts' => true, 'edit_posts' => true];
        self::$userId = 1;
        self::$nonceValid = true;
        self::$redirect = '';
        self::$actions = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * @return list<array{0: string, 1: list<mixed>}>
     */
    public static function actionsNamed(string $name): array
    {
        return array_values(array_filter(self::$actions, static fn (array $a): bool => $a[0] === $name));
    }
}
