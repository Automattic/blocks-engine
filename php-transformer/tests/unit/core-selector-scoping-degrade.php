<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CoreSelectorScoping;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAssetStage;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = '.wp-block-group{color:red}.blocks-engine-x .wp-block-group,.wp-block-buttons{margin:0}@media (min-width:600px){.has-x-color{color:blue}}';
$diagnostics = array();
$result = (new CoreSelectorScoping())->dropUnscoped($css, 'direct', $diagnostics);
$assert('.blocks-engine-x .wp-block-group{margin:0}@media (min-width:600px){}' === $result, 'Only scoped arms and unchanged bodies survive.');
$assert(array('.wp-block-group', '.wp-block-buttons', '.has-x-color') === array_column($diagnostics, 'selector'), 'Report every dropped arm.');
foreach ($diagnostics as $diagnostic) {
    $assert('core_selector_unscoped_rule_dropped' === $diagnostic['code'] && 'warning' === $diagnostic['severity'] && 'direct' === $diagnostic['origin'], 'Stable diagnostic contract.');
}
$diagnostics = array();
$result = (new CoreSelectorScoping())->dropUnscoped('@supports (display:grid){.wp-block-group,.safe{  margin: 0; /* keep bytes */ }}.has-x-color{}.has-x-color{}', 'direct', $diagnostics);
$assert('@supports (display:grid){.safe{  margin: 0; /* keep bytes */ }}' === $result && 3 === count($diagnostics), 'Nested rules preserve body bytes and repeated drops each report.');

// The public session-backed stage is the smallest seam accepting generated CSS.
$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
$session->installAssetMaterializationState(new AssetMaterializationState('', array()));
(new StylesheetAssetStage($session))->generated(array($css), 'engine-support', 'after-author', 'engine-support-after-author');
$assets = $session->assetMaterializationState()->assets();
$diagnostics = $session->transformationEvidenceState()->conversionDiagnostics();
$assert(1 === count($assets) && !str_contains($assets[0]['content'], '.wp-block-buttons') && !str_contains($assets[0]['content'], '.has-x-color'), 'Generated CSS completes without unsafe selectors.');
$assert(3 === count($diagnostics) && array('generated:after-author') === array_values(array_unique(array_column($diagnostics, 'origin'))), 'Stage diagnostics reach transformation evidence.');

fwrite(STDOUT, "Core selector scoping degradation passed\n");
