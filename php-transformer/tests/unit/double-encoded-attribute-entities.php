<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

// The reduced page from #3140, including both linked image and button paths.
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Entities</title></head><body><main>
<a href="https://example.com/story?a=1&amp;amp;b=2" target="_blank"><img src="photo.jpg" alt="&amp;#039;Quote&amp;#039; headline" width="400" height="300"></a>
<p><a class="wp-element-button" href="/go?x=1&amp;amp;y=2">Read</a></p>
</main></body></html>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$blocks = $result['blocks'] ?? array();
// This is the repository's structural PHP validator, NOT Gutenberg's JS
// save()/validateBlock(). It does not itself detect attribute entity mismatches.
$assert('pass' === ((new BlockValidityValidator())->validateBlocks($blocks)['status'] ?? ''), 'structural block validity passes');
$byName = array();
$visit = static function (array $items) use (&$visit, &$byName): void {
    foreach ( $items as $block ) {
        $byName[$block['blockName']][] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($blocks);
$image = $byName['core/image'][0] ?? array();
// This reduced page's paragraph-wrapped button-styled anchor lowers as
// paragraph RichText, so cover its href as well as core/button below.
$paragraph = $byName['core/paragraph'][0] ?? array();
$assert('https://example.com/story?a=1&b=2' === ($image['attrs']['href'] ?? null), 'image href is fully decoded');
$assert("'Quote' headline" === ($image['attrs']['alt'] ?? null), 'image alt is fully decoded');
$assert(str_contains($paragraph['innerHTML'] ?? '', 'href="/go?x=1&amp;y=2"'), 'button-styled RichText link has one encoding layer');
$assert(str_contains($image['innerHTML'] ?? '', 'href="https://example.com/story?a=1&amp;b=2"'), 'image markup has one encoding layer');
$assert(!str_contains($result['serialized_blocks'] ?? '', '&amp;amp;'), 'saved markup has no double-encoded ampersands');

$runtime = new Runtime();
foreach ( array(
    '&amp;amp;amp;' => '&',
    '&amp;#039;Quote&amp;#x27;' => "'Quote'",
    '&amp;quot;title&amp;quot;' => '"title"',
    '&amp;copy;' => '©',
    '&unknown; &bare ?a=1&b=2' => '&unknown; &bare ?a=1&b=2',
) as $input => $expected ) {
    $actual = $runtime->normalizeAttributeValue($input);
    $assert($expected === $actual, 'normalizes named, decimal and hexadecimal references: ' . $input);
    $assert($actual === $runtime->normalizeAttributeValue($actual), 'normalization is idempotent');
}

// Schema-based normalization also covers title and rel, but must leave
// RichText and non-markup/comment-only attributes alone.
$block = (new BlockFactory())->create('core/button', array(
    'url' => '/?a=1&amp;amp;b=2',
    'title' => '&quot;Title&quot;',
    'rel' => 'tag&#x20;next',
    'text' => 'Literal &amp;copy;',
    'metadata' => array('name' => '&amp;copy;'),
));
$assert('/?a=1&b=2' === ($block['attrs']['url'] ?? null), 'core/button URL is normalized');
$assert('"Title"' === ($block['attrs']['title'] ?? null), 'markup-sourced title is normalized');
$assert('tag next' === ($block['attrs']['rel'] ?? null), 'markup-sourced rel is normalized');
$assert('Literal &amp;copy;' === ($block['attrs']['text'] ?? null), 'RichText is not normalized');
$assert('&amp;copy;' === ($block['attrs']['metadata']['name'] ?? null), 'comment-only data is not normalized');

if ( $failures ) {
    exit(1);
}
echo "double-encoded attribute entities passed\n";
