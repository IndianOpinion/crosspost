<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost;

use IndianOpinion\Crosspost\Adapter\Adapter;
use IndianOpinion\Crosspost\Media\ImagePreparer;
use IndianOpinion\Crosspost\Media\ImageSelector;
use IndianOpinion\Crosspost\Text\Graphemes;
use IndianOpinion\Crosspost\Text\Hashtags;
use IndianOpinion\Crosspost\Text\PlainText;
use IndianOpinion\Crosspost\Text\Renderer;
use IndianOpinion\Crosspost\Text\RenderResult;
use IndianOpinion\Crosspost\Value\LinkCard;

/**
 * Builds the announcement for a post on a network. Makes no network call.
 */
final class PostComposer
{
    /** Longest automatic excerpt taken from the content, in graphemes. */
    public const AUTO_EXCERPT = 600;
    public const CARD_DESCRIPTION = 300;

    public function __construct(
        private ImageSelector $images,
        private ImagePreparer $preparer,
    ) {
    }

    public function compose(\WP_Post $post, Adapter $adapter, Settings $settings): Composition
    {
        $network = $adapter->id();
        $limits = $adapter->limits();

        [$values, $hashtags, $autoExcerpt] = $this->values($post, $network, $settings);

        $template = apply_filters('crosspost_template', $settings->template($network), $post, $network);
        $template = is_string($template) && trim($template) !== '' ? $template : Settings::DEFAULT_TEMPLATE;

        $renderer = new Renderer([$adapter, 'count'], $limits->textLimit, $settings->int('min_body'));
        $result = $renderer->render($template, $values, $hashtags, $autoExcerpt);
        $text = $result->text;
        $error = $result->error;
        $count = $result->count;
        if ($result->ok()) {
            $filtered = apply_filters('crosspost_text', $text, $post, $network);
            if (is_string($filtered)) {
                $text = Renderer::tidy($filtered);
            }
            // The check runs after the filter.
            $count = $adapter->count($text);
            if ($count > $limits->textLimit) {
                $error = RenderResult::TOO_LONG;
            } elseif (trim($text) === '') {
                $error = 'text is empty';
            }
        }

        $attachment = 0;
        $alt = '';
        $note = '';
        $mode = $settings->imageMode($network);
        if (!$limits->acceptsImages() || $mode === Settings::IMAGE_NONE) {
            $note = $limits->acceptsImages() ? 'image mode is none' : 'text only on this network';
        } else {
            $attachment = $this->images->select($post, $network);
            if ($attachment === 0) {
                $note = 'no image found';
            } else {
                $alt = $this->preparer->alt($attachment, $limits->altLimit, $network);
                if ($alt === '' && $settings->requireAlt($network) && $mode !== Settings::IMAGE_CARD) {
                    $note = 'no alt text (require alt text is on): text only';
                }
            }
        }

        $card = null;
        if ($network === Network::BLUESKY && ($mode === Settings::IMAGE_CARD || $attachment === 0 || $note !== '')) {
            $card = new LinkCard(
                $values['url'],
                $values['title'],
                Graphemes::head($values['excerpt'], self::CARD_DESCRIPTION),
            );
        }

        return new Composition(
            $network,
            $text,
            $count,
            $limits->textLimit,
            $result->warnings,
            $error,
            $attachment,
            $alt,
            $note,
            $this->language($post, $network, $settings),
            $card,
            $settings->outputHash($network),
        );
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>, 2: bool}
     */
    private function values(\WP_Post $post, string $network, Settings $settings): array
    {
        $manual = PlainText::line($post->post_excerpt);
        $autoExcerpt = $manual === '';
        $excerpt = $manual !== '' ? $manual : Graphemes::head(PlainText::line(self::withoutMore($post->post_content)), self::AUTO_EXCERPT);

        $url = (string) get_permalink($post);
        $filtered = apply_filters('crosspost_url', $url, $post, $network);
        $url = is_string($filtered) && $filtered !== '' ? $filtered : $url;
        if ($settings->utm($network)) {
            $url = add_query_arg([
                'utm_source' => $network,
                'utm_medium' => 'social',
                'utm_campaign' => $settings->utmCampaign($network),
            ], $url);
        }

        $category = '';
        $categories = get_the_category($post->ID);
        if (is_array($categories) && isset($categories[0]) && $categories[0] instanceof \WP_Term) {
            $category = PlainText::line($categories[0]->name);
        }

        $tagNames = [];
        $tags = get_the_tags($post->ID);
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if ($tag instanceof \WP_Term) {
                    $tagNames[] = $tag->name;
                }
            }
        }

        $date = get_the_date('', $post);
        $values = [
            'title' => PlainText::line(get_the_title($post)),
            'excerpt' => $excerpt,
            'url' => $url,
            'site_name' => PlainText::line((string) get_bloginfo('name')),
            'author' => PlainText::line((string) get_the_author_meta('display_name', (int) $post->post_author)),
            'category' => $category,
            'date' => PlainText::line(is_string($date) ? $date : ''),
        ];
        $hashtags = Hashtags::fromNames($tagNames);

        $extra = apply_filters('crosspost_placeholders', $values, $post, $network);
        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                if (is_string($name) && preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1 && is_scalar($value) && $name !== 'tags') {
                    $values[$name] = $name === 'url' ? (string) $value : PlainText::line((string) $value);
                }
            }
        }
        return [$values, $hashtags, $autoExcerpt];
    }

    private static function withoutMore(string $content): string
    {
        // Content before the more tag only; the "read more" text never appears.
        $parts = preg_split('/<!--more(.*?)?-->/', $content, 2);
        return is_array($parts) ? $parts[0] : $content;
    }

    private function language(\WP_Post $post, string $network, Settings $settings): string
    {
        $language = $settings->language($network);
        if ($language === '') {
            $language = str_replace('_', '-', (string) get_locale());
        }
        $filtered = apply_filters('crosspost_language', $language, $post, $network);
        $language = is_string($filtered) ? $filtered : $language;
        $language = preg_replace('/[^A-Za-z0-9-]/', '', $language) ?? '';
        if ($network === Network::MASTODON) {
            // Mastodon takes an ISO 639 code.
            return strtolower((string) strtok($language, '-'));
        }
        if ($network === Network::THREADS || $network === Network::X) {
            // No documented language field on these create calls.
            return '';
        }
        return $language;
    }
}
