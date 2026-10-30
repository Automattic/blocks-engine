<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = require dirname(__DIR__) . '/fixtures/gallery-stage-controls.php';
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
$assert(1 === count($carousels), 'one gallery owns the stage and thumbnail pager');
$attrs = $carousels[0]['attrs'] ?? array();
$assert(3 === ($attrs['slideCount'] ?? 0), 'navigation and current-slide status are not slides');
$assert(3 === count($attrs['thumbnails'] ?? array()), 'all thumbnail selectors survive');
$assert(str_contains($attrs['sourcePresentationClasses'] ?? '', 'catalog-gallery'), 'source root class stays on the carousel root');
$assert(str_contains($attrs['sourcePresentationClasses'] ?? '', 'blocks-engine-attribute-'), 'projected root sizing selector stays on the carousel root');
$assert(str_contains($attrs['stageTrackClassName'] ?? '', 'blocks-engine-attribute-'), 'projected stage aspect-ratio selector stays on the track');
$assert('' !== ($attrs['sourceControlTopology'] ?? ''), 'source navigation topology is retained');
$assert(str_contains($attrs['sourceIdentityAttributes']['id'] ?? '', 'catalog-gallery'), 'source root identity survives');
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Gallery stage/control ownership regression passed\n";
