<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$result = $transform('<style>.boundary{pointer-events:none;white-space:nowrap;padding:0;overflow:hidden}</style><div id="start" class="boundary" data-blocks-engine-capture-responsive-fragment="start" role="region" tabindex="-1" aria-label="Start"><span data-blocks-engine-richtext-marker="label">Start of page</span></div>');
$block = $result['blocks'][0];
$assert('core/paragraph' === $block['blockName'], 'An anonymous inline text run must not add a Group inside its source box.');
$assert('start' === ($block['attrs']['anchor'] ?? ''), 'The text box keeps its fragment identity.');
$assert(str_contains($block['attrs']['className'] ?? '', 'boundary') && str_contains($block['attrs']['className'] ?? '', 'blocks-engine-lowered-paragraph'), 'Source presentation and the margin reset stay on the text host.');
$assert(str_contains($result['serialized_blocks'], 'Start of page'), 'Inline content survives native lowering.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The paragraph remains editor-valid.');

foreach (array(
    '<div id="row" style="display:flex"><span data-blocks-engine-richtext-marker="first">First</span><span data-blocks-engine-richtext-marker="second">Second</span></div>',
    '<div id="region"><h2>Heading</h2><p>Copy</p></div>',
    '<div id="state" hidden><span data-blocks-engine-richtext-marker="state">Hidden state</span></div>',
) as $html) {
    $kept = $transform($html);
    $assert('core/paragraph' !== ($kept['blocks'][0]['blockName'] ?? ''), 'Independent layout, structured content and runtime boundaries must not collapse.');
}
echo "Source text box paragraph passed\n";
