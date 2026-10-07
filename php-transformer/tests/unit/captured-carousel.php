<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = require dirname(__DIR__) . '/fixtures/captured-carousel.php';
$result = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . $source['html'])->toArray();
$carousels = array();
$visit = static function (array $blocks) use (&$visit, &$carousels): void {
    foreach ($blocks as $block) {
        if ('custom/authored-carousel' === ($block['blockName'] ?? null)) $carousels[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$assert(2 === count($carousels), 'both authored responsive variants remain carousels');
$topologies = array();
foreach ($carousels as $carousel) {
    $markup = (new Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime())->serializeBlocks(array($carousel));
    $topologies[] = '' !== $carousel['attrs']['sourceControlTopology'];
    $assert(str_contains($carousel['innerHTML'], 'margin-left:auto!important') && str_contains($carousel['innerHTML'], 'margin-right:auto!important'), 'PHP root geometry retains the authored important margins carried in block attributes');
    $assert(20 === substr_count($markup, 'class="wp-block-group frame-crop'), 'conditional source crop holders survive around every native image');
    $assert(20 === substr_count($markup, '<!-- wp:image '), 'mixed intrinsic shapes remain twenty native images');
    $assert(20 === substr_count($markup, 'style="margin-top:0;margin-bottom:0"'), 'synthetic figures add no block-axis margin to absolutely positioned crop images');
    $assert(str_contains($carousel['innerHTML'], 'blocks-engine-authored-carousel__controls frame-carousel') && str_contains($carousel['innerHTML'], 'style="position:absolute;inset:0;z-index:4;pointer-events:none;box-sizing:border-box;width:auto;height:auto;margin:0;padding:0"'), 'both control topology branches carry the same source wrapper presentation contract');
}
$assert(array(true, false) === $topologies, 'fixture exercises source-topology and independent-control save branches');
if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Captured carousel geometry and save contract regression passed\n";
