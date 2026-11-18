<?php
/**
 * A capture marks each half of a per-viewport element pair (a Wix image's
 * desktop and phone renditions) with the device island classes, and its
 * device sheet hides the half the viewport does not show. Box-tree carriers
 * record only the boxes the capture rendered, so on an evidence route that
 * sheet is not author styling they replace: retiring it shows both halves
 * at every width and stacks them (#5861).
 */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$desktop = CaptureEvidence::DESKTOP_ONLY_CLASS;
$mobile = CaptureEvidence::MOBILE_ONLY_CLASS;
$html = (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"><link rel="stylesheet" href="device.css"></head>', $html);
$html = str_replace('</main>', '<div class="pic"><div class="rendition ' . $desktop . '"><img src="desktop.png" alt=""></div><div class="rendition ' . $mobile . '"><img src="phone.png" alt=""></div></div></main>', $html);
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'author.css' => 'h1{opacity:.25}.rendition{width:100%}',
    'device.css' => '@media(min-width:1024px){.' . $mobile . '{display:none!important}}@media(max-width:1023px){.' . $desktop . '{display:none!important}}',
    'box-tree/index.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];

$failures = array();
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) $failures[] = 'the route is not an evidence route, so this does not exercise stylesheet retirement';
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $delivered .= (string) ($assets[$row['target_path']]['content'] ?? '');
if (str_contains($delivered, 'h1{opacity')) $failures[] = 'resting author rules are still delivered on the evidence route';
foreach (array('1024px' => $mobile, '1023px' => $desktop) as $width => $class) {
    $rule = '/@media\s*\((?:min|max)-width:\s*' . $width . '\)\s*\{[^{}]*\.' . preg_quote($class, '/') . '[^{}]*\{\s*display\s*:\s*none\s*!important/';
    if (1 !== preg_match($rule, $delivered)) $failures[] = "the .{$class} visibility rule does not reach the route under its viewport condition";
}

if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "PASS: device island visibility survives author sheet retirement on evidence routes\n";
