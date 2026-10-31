<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for a nested block-level span inside a flex list item's
 * text run (kees-bcb.nl contact card shape).
 *
 * A list item laid out as flex — icon svg plus one text span — whose span
 * holds a nested `display:block` span carrying React's `<!-- -->` text-split
 * comments converts to one synthetic paragraph. The nested span is the
 * second line of the contact card: it must keep its block display (render on
 * its own line) and its muted color across the RichText lowering, with the
 * comment-run seams it holds kept as neutral carriers.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/flex-item-nested-block-span.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$page   = (string) ($result['serialized_blocks'] ?? '');
$blocks = (new Runtime())->parseBlocks($page);

$find = static function (array $blocks, string $needle) use (&$find): ?array {
    foreach ($blocks as $block) {
        $inner = $find($block['innerBlocks'] ?? array(), $needle);
        if (null !== $inner) {
            return $inner;
        }
        if (str_contains((string) ($block['innerHTML'] ?? ''), $needle)) {
            return $block;
        }
    }
    return null;
};

$paragraph = $find($blocks, 'Noord-Holland en omstreken');
$assert('core/paragraph' === ($paragraph['blockName'] ?? null), 'The flex list item\'s text run converts to one paragraph: ' . substr($page, 0, 2000));
$content = (string) ($paragraph['innerHTML'] ?? '');

// The second line lowers to a block-level carrier of its own: without its
// display declaration both lines run together in the editor and on the site.
$assert(
    1 === preg_match('#<mark class="block text-muted[^"]*" style="[^"]*display:block[^"]*color:rgb\(0 0 0 / 70%\)[^"]*"[^>]*>Gevestigd te#', $content),
    'The nested block span keeps its display and muted color on the RichText carrier, so the second line renders on its own line: ' . $content
);
$assert(! str_contains($content, 'omstreken</mark>Gevestigd te'), 'The second line does not run on from the first inside one inline run.');

// The React comment seams inside the nested span stay as neutral carriers,
// so each text run keeps the shaping boundary the browser gave it.
$assert(
    1 === preg_match('#<mark style="background-color:transparent;color:inherit" role="none">Anna Paulowna</mark></mark>#', $content),
    'The comment seam inside the nested block span stays a neutral carrier inside it.'
);

$validity = (new BlockValidityValidator())->validateBlocks($result['blocks'] ?? array());
$assert('pass' === ($validity['status'] ?? ''), 'The saved markup is Gutenberg-valid: ' . (string) json_encode($validity['summary'] ?? $validity));

// Converting the saved markup again is a fixed point: the block carrier and
// its nested seam carrier survive a re-import unchanged.
$savedContent = $content;
$second = (new HtmlTransformer())->transform(
    '<!doctype html><html><head><style>.block { display: block; }.text-muted { color: rgb(0 0 0 / 70%); }</style></head><body><main>' . $savedContent . '</main></body></html>',
    array()
)->toArray();
$secondContent = (string) ($second['serialized_blocks'] ?? '');
$assert(
    str_contains($secondContent, '<mark class="block text-muted" style="display:block;color:rgb(0 0 0 / 70%);background-color:transparent" role="none">Gevestigd te <mark style="background-color:transparent;color:inherit" role="none">Anna Paulowna</mark></mark>'),
    'Re-converting the saved RichText keeps the block carrier and its seam carrier unchanged: ' . $secondContent
);

echo "Flex item nested block span own-line contract passed.\n";
