<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$html = require dirname(__DIR__) . '/fixtures/scoped-mixed-dropdown.php';
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$files = static function (string $source): array {
    $state = array(
        'status' => 'captured',
        'trigger' => array('selector' => 'missing', 'label' => 'Open menu', 'tag' => 'div', 'ariaHaspopup' => 'menu'),
        'dialog' => array('html' => '<div>Report snapshot</div>', 'htmlBytes' => strlen('<div>Report snapshot</div>'), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
    );
    return array(
        array('path' => 'website/index.html', 'content' => $source),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array($state)))))),
    );
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files($html)))->toArray();
$markup = $result['serialized_blocks'];
$assert(0 === $result['metrics']['fallback_count'], 'mixed panels lower entirely to editable native blocks');
$assert(2 === substr_count($markup, '<!-- wp:custom/captured-dialog '), 'one existing captured dropdown block per source scope');
$assert(!str_contains(json_encode($result['assets']), 'function triggers()'), 'known generated helper is retired after all scoped controls bind');
$assert(str_contains($markup, 'data-blocks-engine-placement="in-place"') && str_contains($markup, 'data-dla-dialog-ancestor-unverified="ancestor-limit"'), 'source placement and unverified ancestor evidence remain explicit');
$assert(str_contains($markup, '<!-- wp:separator') && str_contains($markup, 'Ordinary action') && str_contains($markup, 'Social') && str_contains($markup, '<svg'), 'mixed separators, action, social artwork and opener artwork survive');
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<body>' . $markup . '</body>');
$xpath = new DOMXPath($dom);
$panels = $xpath->query('//*[@data-blocks-engine-triggers]');
$assert(2 === $panels->length, 'both scoped native endpoints survive');
foreach ($panels as $panel) {
    $assert('div' === $panel->tagName, 'source panel DOM tag remains unchanged');
    foreach (explode(' ', $panel->getAttribute('data-blocks-engine-triggers')) as $id) {
        $triggers = $xpath->query('//*[@id="' . $id . '"]');
        $assert(1 === $triggers->length && $triggers->item(0)->getAttribute('aria-controls') === $panel->getAttribute('id'), 'each unique native opener controls its actual endpoint');
    }
}
$assert('pass' === $result['source_reports']['runtime_dependency_parity']['status'], 'native scope target parity passes');
(new WordPressSitePlanResolver())->resolve($result['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));

$projector = new CapturedDialogProjector();
foreach (array(
    str_replace('data-dla-disclosure-runtime="true"', 'data-unknown-runtime="true"', $html),
    str_replace('</head>', '<script>document.querySelector("[data-dla-dialog-trigger]").addEventListener("click",function(){window.sourceAction=true;});</script></head>', $html),
    str_replace('role="button"', 'role="region"', $html),
    str_replace('<div id="menu-panel"', '<div id="menu-panel"></div><div id="menu-panel"', $html),
    str_replace('Ordinary action</button>', 'Ordinary action</button><button onclick="window.sourceAction=true">Source action</button>', $html),
) as $negative) {
    $projected = $projector->project($files($negative));
    $assert(0 === $projected['projected_count'] && str_contains($projected['files'][0]['content'], 'function triggers()'), 'unknown/competing runtime, non-control, ambiguous endpoint and native JS controls are not falsely adopted');
}
$reporter = new RuntimeDependencyParityReport();
$source = '<div id="control" role="button" aria-controls="target"></div><div id="target"><p>Panel</p></div>';
$missing = $reporter->fromArtifact(array(), $source, '<div id="control" role="button" aria-controls="target"></div>', 'index.html');
$assert('warning' === $missing['status'] && '#target' === $missing['findings'][0]['selector'] && 'idref' === $missing['findings'][0]['dependency_kind'], 'explicit DOM IDREF detects a missing panel without analyzing JS');
$scopedSource = '<div class="data-liberation-desktop-document"><div id="target"></div></div><div class="data-liberation-mobile-document">' . $source . '</div>';
$wrongScope = $reporter->fromArtifact(array(), $scopedSource, '<div class="data-liberation-desktop-document"><div id="target"></div></div><div class="data-liberation-mobile-document"><div id="control" aria-controls="target"></div></div>', 'index.html');
$assert('warning' === $wrongScope['status'], 'a target in the desktop copy cannot satisfy a mobile control');
echo "Scoped mixed-dropdown native binding and IDREF contracts passed\n";
