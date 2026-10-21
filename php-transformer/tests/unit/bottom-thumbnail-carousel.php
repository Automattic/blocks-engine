<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(require dirname(__DIR__) . '/fixtures/bottom-thumbnail-carousel.php')->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
};
$carousel = $result['blocks'][0];
$assert('custom/authored-carousel' === $carousel['blockName'], 'neutral gallery compiles to the generic carousel');
$attrs = $carousel['attrs'];
$assert('slideshow' === $attrs['presentation'] && 'bottom' === $attrs['thumbnailPosition'], 'single-stage bottom rail detected');
$assert(3 === count($carousel['innerBlocks']) && 3 === count($attrs['thumbnails']), 'all source slides and thumbnails retained');
$definition = $result['source_reports']['generated_blocks'][0];
$all = $definition['assets']['style.css'] . implode("\n", array_column($result['assets'], 'content'));
$assert(str_contains($all, '--thumbnails-bottom{display:grid;position:relative;grid-template-columns:minmax(0,1fr);'), 'bottom rail resets inherited three-column grid');
$assert('{index} / {count}' === ($attrs['statusFormat'] ?? ''), 'source counter separator and spacing survive');
$assert(str_contains($carousel['innerHTML'], 'aria-live="polite" aria-atomic="true"'), 'status remains an atomic live region');
$assert(str_contains($carousel['innerHTML'], '&quot;statusFormat&quot;:&quot;{index} / {count}&quot;') && str_contains($definition['assets']['index.js'], 'context.statusFormat = attributes.statusFormat'), 'PHP and editor saves both carry the counter format to runtime');
$assert(str_contains($all, '@media (min-width:768px){.be-inline-geometry-') && str_contains($all, '>[aria-live]{position:absolute!important;width:1px!important'), 'source hidden breakpoint becomes accessible visual hiding');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'saved carousel remains editor-valid');
if (isset($argv[1])) file_put_contents($argv[1], json_encode($result, JSON_THROW_ON_ERROR));
echo "Bottom-thumbnail carousel regression passed\n";
