<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthoredMarqueeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<div style="--marquee-duration: 17.5s"><p><span data-marquee-animation="left"><span><span>Protecting what matters</span></span><span aria-hidden="true">Protecting what matters</span></span></p></div>';
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
$assert('custom/authored-marquee' === ($block['blockName'] ?? null), 'generic marquee metadata uses the authored marquee companion');
$assert('Protecting what matters' === ($block['attrs']['content'] ?? null), 'the first visible authored text remains directly editable');
$assert('left' === ($block['attrs']['direction'] ?? null) && 17.5 === ($block['attrs']['duration'] ?? null), 'direction and timing intent are preserved');
$assert(!str_contains((string) ($result['serialized_blocks'] ?? ''), '<!-- wp:html'), 'marquee content emits no raw HTML block');
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$render = (string) ($definition['assets']['render.php'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, 'RichText') && !str_contains($editor, 'RawHTML'), 'the companion edits one text value without duplicating editor content');
$assert(str_contains($render, 'overflow-x:clip') && str_contains($render, 'max-width:100%') && str_contains($render, 'aria-hidden="true"'), 'the runtime contract clips duplicate tracks inside a bounded viewport');
$assert(str_contains($render, 'prefers-reduced-motion:reduce') && str_contains($render, 'animation:none') && str_contains($render, 'display:none'), 'reduced motion leaves one readable static track');
$serialized = ( new Runtime() )->serializeBlocks(array($block));
$assert('custom/authored-marquee' === (new Runtime())->parseBlocks($serialized)[0]['blockName'], 'the companion reference persists through parse and serialize');

fwrite(STDOUT, "Authored marquee companion tests passed\n");
