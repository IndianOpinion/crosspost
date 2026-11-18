<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Feature;

use IndianOpinion\Crosspost\Adapter\BlueskyAdapter;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Media\JpegMeta;
use IndianOpinion\Crosspost\Network;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\FakeHttp;
use IndianOpinion\Crosspost\Tests\TestCase;

/**
 * Section 4.8, acceptance tests 53 to 60.
 */
final class ImageTest extends TestCase
{
    private static int $nextAttachment = 5000;

    /** @var list<string> */
    private static array $files = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::$files = [];
    }

    /**
     * A real JPEG on disk registered as an attachment. With $noise the
     * file is large for its size (random pixels compress badly).
     */
    public static function attachment(int $w, int $h, string $alt = '', bool $noise = false, string $mime = 'image/jpeg', ?string $bytes = null): int
    {
        $id = self::$nextAttachment++;
        $file = sys_get_temp_dir() . '/crosspost-test-' . $id . ($mime === 'image/gif' ? '.gif' : '.jpg');
        if ($bytes === null) {
            $img = imagecreatetruecolor($w, $h);
            if ($noise) {
                mt_srand($w * $h);
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
                    }
                }
            } else {
                imagefill($img, 0, 0, 0x3366AA);
            }
            imagejpeg($img, $file, 100);
        } else {
            file_put_contents($file, $bytes);
        }
        self::$files[] = $file;
        State::$attachments[$id] = [
            'file' => $file,
            'mime' => $mime,
            'meta' => ['width' => $w, 'height' => $h, 'sizes' => []],
            'url' => 'https://cdn.example/uploads/' . basename($file),
            'alt' => $alt,
            'caption' => '',
        ];
        State::$meta[$id]['_wp_attachment_image_alt'] = $alt;
        return $id;
    }

    private function withImage(int $attachment, string $content = ''): \WP_Post
    {
        $post = $this->post($content === '' ? [] : ['post_content' => $content]);
        if ($content === '') {
            update_post_meta($post->ID, '_thumbnail_id', $attachment);
        }
        return $post;
    }

    public function testImageChoiceOrderAndModeNone(): void
    {
        $featured = self::attachment(100, 100, 'featured');
        $inline = self::attachment(100, 100, 'inline');
        $override = self::attachment(100, 100, 'override');
        $selector = new ImageSelector();

        $post = $this->post(['post_content' => '<img class="wp-image-' . $inline . '" src="x">']);
        self::assertSame($inline, $selector->select($post, 'mastodon'));
        update_post_meta($post->ID, '_thumbnail_id', $featured);
        self::assertSame($featured, $selector->select($post, 'mastodon'));
        update_post_meta($post->ID, ImageSelector::OVERRIDE_META, $override);
        self::assertSame($override, $selector->select($post, 'mastodon'));
        add_filter('crosspost_image', static fn (int $id): int => 0);
        self::assertSame(0, $selector->select($post, 'mastodon'));

        remove_all_filters('crosspost_image');
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['image_mode' => 'none']]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $this->publish($post);
        $this->clock->advance(61);
        $this->mastodonPosts();
        $this->app->run();
        self::assertSame([], FakeHttp::requests('#/media#'));
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::MASTODON)->state);
    }

    public function testOversizedJpegIsReencodedUnderTheByteCapForBluesky(): void
    {
        $id = self::attachment(1000, 700, 'Crowds at the station', true);
        $original = (string) file_get_contents(State::$attachments[$id]['file']);
        self::assertGreaterThan(1000000, strlen($original));
        $limits = (new BlueskyAdapter($this->app->http, $this->clock, $this->clock))->limits();
        $file = $this->app->images->prepare($id, $limits, 'bluesky', true);
        self::assertInstanceOf(\IndianOpinion\Crosspost\Value\MediaFile::class, $file);
        self::assertTrue($file->temporary);
        self::assertLessThanOrEqual(1000000, filesize($file->path));
        self::assertLessThanOrEqual(2000, max($file->width, $file->height));
        self::assertNotSame(State::$attachments[$id]['file'], $file->path);
        $this->app->images->cleanup($file);
        self::assertFileDoesNotExist($file->path);
        self::assertSame($original, file_get_contents(State::$attachments[$id]['file']), 'The attachment is unchanged.');
    }

    public function testBlueskyUploadsTheBlobAndDeletesTheTemporaryCopy(): void
    {
        $this->connect(Network::BLUESKY);
        $id = self::attachment(1000, 700, 'Crowds at the station', true);
        $post = $this->withImage($id);
        $this->publish($post);
        $this->clock->advance(61);
        $uploaded = null;
        $this->http->on('POST', '#uploadBlob$#', function (array $args) use (&$uploaded): array {
            $uploaded = $args['body'];
            return FakeHttp::json(200, ['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafk'], 'mimeType' => 'image/jpeg', 'size' => 1]]);
        });
        $record = null;
        $this->http->on('POST', '#createRecord$#', function (array $args) use (&$record): array {
            $record = json_decode((string) $args['body'], true);
            return FakeHttp::json(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/' . $record['rkey'], 'cid' => 'c']);
        });
        $this->app->run();
        self::assertIsString($uploaded);
        self::assertLessThanOrEqual(1000000, strlen($uploaded));
        self::assertFalse(JpegMeta::hasExif($uploaded));
        self::assertSame('app.bsky.embed.images', $record['record']['embed']['$type']);
        self::assertSame('Crowds at the station', $record['record']['embed']['images'][0]['alt']);
        self::assertSame([], glob(sys_get_temp_dir() . '/crosspost-????????????????.jpg') ?: [], 'Temporary copies are deleted.');
    }

    public function testAnimatedGifGoesTextOnly(): void
    {
        $frame = "\x00\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C";
        $gif = 'GIF89a' . str_repeat("\x00", 7) . $frame . str_repeat("\x00", 20) . $frame . str_repeat("\x00", 20) . ';';
        $id = self::attachment(10, 10, 'Animated', false, 'image/gif', $gif);
        $this->connect(Network::MASTODON);
        $post = $this->withImage($id);
        $this->publish($post);
        $this->clock->advance(61);
        $this->mastodonPosts();
        $this->app->run();
        self::assertSame([], FakeHttp::requests('#/media#'));
        $messages = array_map(static fn ($e) => $e->message, $this->store->log(['event' => 'image_fallback']));
        self::assertStringContainsString('animated GIF', $messages[0]);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::MASTODON)->state);
    }

    public function testMissingAltTextMeansTextOnlyAndLongAltIsCutAtAGrapheme(): void
    {
        $this->connect(Network::MASTODON);
        $id = self::attachment(100, 100, '');
        $post = $this->withImage($id);
        $this->publish($post);
        $this->clock->advance(61);
        $this->mastodonPosts();
        $this->app->run();
        self::assertSame([], FakeHttp::requests('#/media#'));
        self::assertStringContainsString('no alt text', $this->store->log(['event' => 'image_fallback'])[0]->message);

        $long = self::attachment(100, 100, str_repeat('क्ष', 30) . '👨‍👩‍👧');
        $alt = $this->app->images->alt($long, 31, 'mastodon');
        self::assertSame(str_repeat('क्ष', 30) . '👨‍👩‍👧', $alt);
        self::assertSame(str_repeat('क्ष', 30), $this->app->images->alt($long, 30, 'mastodon'));
    }

    public function testThreadsRefusesAPrivateImageUrlAndAStuckContainerEndsTheImagePath(): void
    {
        $this->connect(Network::THREADS);
        $id = self::attachment(100, 100, 'Harbour');
        State::$attachments[$id]['url'] = 'https://media.intranet.example/uploads/a.jpg';
        $post = $this->withImage($id);
        $this->publish($post);
        $this->clock->advance(61);
        $this->threadsPosts('T1');
        $this->app->run();
        $container = FakeHttp::requests('#/1789/threads$#')[0];
        self::assertSame('TEXT', $container['body']['media_type']);
        self::assertStringContainsString('private address', $this->store->log(['event' => 'image_fallback'])[0]->message);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::THREADS)->state);

        // A container that stays in progress past the wait: text only.
        $this->settings(['networks' => ['threads' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::THREADS, 1);
        $ok = self::attachment(100, 100, 'Harbour');
        $second = $this->withImage($ok);
        $this->publish($second);
        $this->clock->advance(61);
        $this->http->on('POST', '#/1789/threads$#', FakeHttp::json(200, ['id' => 'IMG']));
        $this->http->on('GET', '#/IMG\?fields=status#', FakeHttp::json(200, ['status' => 'IN_PROGRESS']), 20);
        $this->threadsPosts('T2');
        $this->app->run();
        $bodies = array_map(static fn (array $r) => $r['body']['media_type'] ?? '', FakeHttp::requests('#/1789/threads$#'));
        self::assertSame(['TEXT', 'IMAGE', 'TEXT'], $bodies);
        self::assertSame('https://cdn.example/uploads/' . basename(State::$attachments[$ok]['file']), FakeHttp::requests('#/1789/threads$#')[1]['body']['image_url']);
        self::assertSame(Job::POSTED, $this->job($second->ID, Network::THREADS)->state);
        self::assertLessThanOrEqual(7, count(FakeHttp::requests('#/IMG\?#')), 'Bounded wait.');
    }

    public function testMastodonAsyncMediaIsPolledUntilProcessed(): void
    {
        $this->connect(Network::MASTODON);
        $post = $this->withImage(self::attachment(100, 100, 'Harbour'));
        $this->publish($post);
        $this->clock->advance(61);
        $this->http->on('POST', '#/api/v2/media$#', FakeHttp::json(202, ['id' => 'M9', 'url' => null]));
        $this->http->on('GET', '#/api/v1/media/M9$#', FakeHttp::json(206, ['id' => 'M9']));
        $this->http->on('GET', '#/api/v1/media/M9$#', FakeHttp::json(200, ['id' => 'M9', 'url' => 'https://social.example/m9.jpg']));
        $this->mastodonPosts();
        $this->app->run();
        $status = json_decode((string) FakeHttp::requests('#statuses$#', 'POST')[0]['body'], true);
        self::assertSame(['M9'], $status['media_ids']);
        self::assertCount(2, FakeHttp::requests('#/api/v1/media/M9#'));
    }

    public function testUploadFailureFallsBackToTextOrRetriesByPolicy(): void
    {
        $this->connect(Network::MASTODON);
        $this->settings(['networks' => ['mastodon' => ['spacing' => 0]]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $post = $this->withImage(self::attachment(100, 100, 'Harbour'));
        $this->publish($post);
        $this->clock->advance(61);
        $this->http->on('POST', '#/api/v2/media$#', FakeHttp::json(500, ['error' => 'boom']));
        $this->mastodonPosts();
        $this->app->run();
        $status = json_decode((string) FakeHttp::requests('#statuses$#', 'POST')[0]['body'], true);
        self::assertArrayNotHasKey('media_ids', $status);
        self::assertSame(Job::POSTED, $this->job($post->ID, Network::MASTODON)->state);

        $this->settings(['networks' => ['mastodon' => ['image_failure' => 'retry']]]);
        $this->app->confirmPreview(Network::MASTODON, 1);
        $second = $this->withImage(self::attachment(100, 100, 'Harbour'));
        $this->publish($second);
        $this->clock->advance(61);
        $this->http->on('POST', '#/api/v2/media$#', FakeHttp::json(500, ['error' => 'boom']));
        $this->app->run();
        $job = $this->job($second->ID, Network::MASTODON);
        self::assertSame(Job::PENDING, $job->state);
        self::assertSame($this->clock->now() + 300, $job->nextAttemptAt);
        self::assertCount(1, FakeHttp::requests('#statuses$#', 'POST'));
    }

    public function testReencodedCopyHasNoExif(): void
    {
        $id = self::attachment(1000, 700, 'Field', true);
        $file = State::$attachments[$id]['file'];
        $bytes = (string) file_get_contents($file);
        $exif = "\xFF\xE1" . pack('n', 2 + 6 + 10) . "Exif\x00\x00" . 'GPSDATA123';
        file_put_contents($file, substr($bytes, 0, 2) . $exif . substr($bytes, 2));
        self::assertTrue(JpegMeta::hasExif((string) file_get_contents($file)));
        $limits = (new BlueskyAdapter($this->app->http, $this->clock, $this->clock))->limits();
        $copy = $this->app->images->prepare($id, $limits, 'bluesky', true);
        self::assertInstanceOf(\IndianOpinion\Crosspost\Value\MediaFile::class, $copy);
        self::assertFalse(JpegMeta::hasExif((string) file_get_contents($copy->path)));
        self::assertSame('', JpegMeta::strip('not a jpeg') === 'not a jpeg' ? '' : 'changed');
        $this->app->images->cleanup($copy);
    }

    public function testBlueskyWithoutAnImageSendsALinkCardBuiltFromLocalData(): void
    {
        $this->connect(Network::BLUESKY);
        $post = $this->post(['post_excerpt' => 'Three days early.']);
        $this->publish($post);
        $this->clock->advance(61);
        $record = null;
        $this->http->on('POST', '#createRecord$#', function (array $args) use (&$record): array {
            $record = json_decode((string) $args['body'], true);
            return FakeHttp::json(200, ['uri' => 'at://x/app.bsky.feed.post/k', 'cid' => 'c']);
        });
        $this->app->run();
        $embed = $record['record']['embed'];
        self::assertSame('app.bsky.embed.external', $embed['$type']);
        self::assertSame(get_permalink($post), $embed['external']['uri']);
        self::assertSame('Monsoon reaches Kerala early', $embed['external']['title']);
        self::assertSame('Three days early.', $embed['external']['description']);
        self::assertSame([], FakeHttp::requests('#news\.example#'), 'No request to the site itself.');

        // With a featured image in card mode: the thumbnail is uploaded as a blob.
        $this->settings(['networks' => ['bluesky' => ['image_mode' => 'card', 'spacing' => 0]]]);
        $this->app->confirmPreview(Network::BLUESKY, 1);
        $card = $this->withImage(self::attachment(400, 300, 'Thumb'));
        $this->publish($card);
        $this->clock->advance(61);
        $this->http->on('POST', '#uploadBlob$#', FakeHttp::json(200, ['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'thumb']]]));
        $this->http->on('POST', '#createRecord$#', function (array $args) use (&$record): array {
            $record = json_decode((string) $args['body'], true);
            return FakeHttp::json(200, ['uri' => 'at://x/app.bsky.feed.post/k2', 'cid' => 'c']);
        });
        $this->app->run();
        self::assertSame('thumb', $record['record']['embed']['external']['thumb']['ref']['$link']);
        self::assertSame([], FakeHttp::requests('#news\.example#'));
    }
}
