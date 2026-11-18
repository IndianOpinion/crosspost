<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Text;

use IndianOpinion\Crosspost\Text\Counter;
use IndianOpinion\Crosspost\Text\Graphemes;
use IndianOpinion\Crosspost\Text\Hashtags;
use IndianOpinion\Crosspost\Text\PlainText;
use IndianOpinion\Crosspost\Text\Renderer;
use IndianOpinion\Crosspost\Text\RenderResult;
use PHPUnit\Framework\TestCase;

/**
 * Cases 45 to 53, acceptance tests 41, 42, 43, 45, 46.
 */
final class RendererTest extends TestCase
{
    private const URL = 'https://news.example/2026/11/monsoon-reaches-kerala';

    private static function renderer(int $limit, string $rule = Counter::GRAPHEMES, int $minBody = 40): Renderer
    {
        return new Renderer(static fn (string $t): int => Counter::count($rule, $t), $limit, $minBody);
    }

    /**
     * @return array<string, string>
     */
    private static function values(array $extra = []): array
    {
        return $extra + [
            'title' => 'Monsoon reaches Kerala early',
            'excerpt' => '',
            'url' => self::URL,
            'site_name' => 'Example News',
            'author' => 'A. Writer',
            'category' => 'Weather',
            'date' => '2026-11-15',
        ];
    }

    public function testDefaultTemplateIsTitleBlankLineUrl(): void
    {
        $result = self::renderer(300)->render("{title}\n\n{url}", self::values(), []);
        self::assertTrue($result->ok());
        self::assertSame("Monsoon reaches Kerala early\n\n" . self::URL, $result->text);
        self::assertSame([], $result->warnings);
    }

    public function testUnknownPlaceholderRendersEmptyAndWarns(): void
    {
        $result = self::renderer(300)->render("{title} {nonsense}\n{url}", self::values(), []);
        self::assertSame("Monsoon reaches Kerala early\n" . self::URL, $result->text);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('{nonsense}', $result->warnings[0]);
    }

    public function testPlainTextStripsHtmlShortcodesEntitiesAndControls(): void
    {
        $value = "<p>Rain &amp; <b>wind</b> [gallery ids=\"1\"] in\x07 Kochi&nbsp;today</p>";
        self::assertSame('Rain & wind in Kochi today', PlainText::line($value));
    }

    public function testThreeNewlinesCollapseToTwo(): void
    {
        $result = self::renderer(300)->render("{title}\n\n\n\n{excerpt}\n\n\n{url}", self::values(), []);
        self::assertSame("Monsoon reaches Kerala early\n\n" . self::URL, $result->text);
    }

    public function testHashtagsFromTagNames(): void
    {
        self::assertSame(['#NewDelhi', '#नईदिल्ली', '#CaféSociety', '#Café_Society'], Hashtags::fromNames(['New Delhi', '2026', 'नई दिल्ली', 'Café Society!', '...', 'Café_Society']));
        self::assertSame('', Hashtags::fromName('2026'));
    }

    public function testShortensExcerptFirstThenHashtagsThenTitle(): void
    {
        $excerpt = str_repeat('Long words keep coming here ', 20);
        $values = self::values(['excerpt' => trim($excerpt)]);
        $tags = ['#Weather', '#Kerala', '#Monsoon'];
        $template = "{title}\n{excerpt}\n{tags}\n\n{url}";

        // Room for title, tags, url and part of the excerpt.
        $result = self::renderer(160)->render($template, $values, $tags);
        self::assertTrue($result->ok(), $result->error);
        self::assertLessThanOrEqual(160, $result->count);
        self::assertStringContainsString('#Weather #Kerala #Monsoon', $result->text);
        self::assertStringContainsString('...', $result->text);
        self::assertStringEndsWith("\n\n" . self::URL, $result->text);

        // Less room: the excerpt goes, then hashtags from the end.
        $result = self::renderer(95)->render($template, $values, $tags);
        self::assertTrue($result->ok(), $result->error);
        self::assertStringContainsString('Monsoon reaches Kerala early', $result->text);
        self::assertStringNotContainsString('#Monsoon', $result->text);
        self::assertStringEndsWith(self::URL, $result->text);

        // Even less: the title is cut, never the URL.
        $result = self::renderer(75, Counter::GRAPHEMES, 10)->render($template, $values, $tags);
        self::assertTrue($result->ok(), $result->error);
        self::assertStringNotContainsString('#', $result->text);
        self::assertStringEndsWith("...\n\n" . self::URL, $result->text);
        self::assertLessThanOrEqual(75, $result->count);
    }

    public function testCutPrefersAWordBoundaryAndNeverSplitsAGrapheme(): void
    {
        $title = 'क्षत्रिय योद्धा 👨‍👩‍👧 परिवार के साथ दिल्ली पहुंचे और फिर आगे बढ़े';
        $values = self::values(['title' => $title]);
        for ($limit = 60; $limit < 110; $limit++) {
            $result = self::renderer($limit)->render("{title}\n\n{url}", $values, []);
            if (!$result->ok()) {
                continue;
            }
            [$line] = explode("\n", $result->text);
            $kept = substr($line, 0, -3);
            if (str_ends_with($line, '...')) {
                // Every kept grapheme is a whole grapheme of the original.
                self::assertSame(Graphemes::split($kept), array_slice(Graphemes::split($title), 0, Graphemes::count($kept)));
                // Cut at a word boundary: the next original character is a space.
                $next = Graphemes::split($title)[Graphemes::count($kept)] ?? ' ';
                self::assertSame(' ', $next, 'Cut inside a word at limit ' . $limit);
            }
            self::assertStringEndsWith("\n\n" . self::URL, $result->text);
        }
    }

    public function testCutNeverBreaksALinkInsideAValue(): void
    {
        $values = self::values(['excerpt' => 'See https://docs.example/a/long/report/path for the full figures and more words after']);
        $result = self::renderer(100)->render("{excerpt}\n{url}", $values, []);
        self::assertTrue($result->ok());
        self::assertDoesNotMatchRegularExpression('#https://docs\.example/a/lo(?!ng/report/path)#', $result->text);
    }

    public function testTemplateLeavingNoRoomFails(): void
    {
        $template = str_repeat('Fixed words that never change. ', 9) . "{title}\n{url}";
        $result = self::renderer(300)->render($template, self::values(), []);
        self::assertSame(RenderResult::NO_ROOM, $result->error);
    }

    public function testAutoExcerptPrefersASentenceEnd(): void
    {
        $values = self::values(['excerpt' => 'First sentence here. Second sentence is longer and keeps going on and on and on.']);
        $result = self::renderer(110)->render("{title}: {excerpt}\n{url}", $values, [], true);
        self::assertTrue($result->ok());
        self::assertStringContainsString('First sentence here.' . "\n", $result->text);
    }
}
