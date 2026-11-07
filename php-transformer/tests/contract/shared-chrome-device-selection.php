<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

// The phone frame is nested in the desktop document, not a sibling document.
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/nested-device-only-shared-chrome.html');
$head = '';
foreach (array('mobile', 'desktop') as $device) {
    $head .= '<link rel="stylesheet" href="/' . $device . '.css" media="not all" '
        . CaptureEvidence::DEVICE_STYLE . '="' . $device . '" ' . CaptureEvidence::SOURCE_MEDIA . '="all">';
}
$fixture = str_replace('</head>', $head . '</head>', $fixture);
$selector = ':where([data-blocks-engine-capture-device-document="desktop"]) [data-x="phone-grid"]';
$inlineFixture = str_replace(
    '<link rel="stylesheet" href="/desktop.css" media="not all" ' . CaptureEvidence::DEVICE_STYLE . '="desktop" ' . CaptureEvidence::SOURCE_MEDIA . '="all">',
    '<style media="not all" ' . CaptureEvidence::DEVICE_STYLE . '="desktop" ' . CaptureEvidence::SOURCE_MEDIA . '="all">' . $selector . '{--w:980px;--inline:1}</style>',
    $fixture
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => str_replace('TITLE', 'Home', $fixture),
    'about/index.html' => str_replace('TITLE', 'About', $fixture),
    'testimonials/index.html' => str_replace('TITLE', 'Testimonials', $inlineFixture),
    'mobile.css' => $selector . '{--w:320px}',
    'desktop.css' => $selector . '{--w:980px}',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (!is_array($plan)) throw new RuntimeException('Device selection fixture must compile a site plan.');
foreach (array('header', 'footer') as $area) {
    if (!array_filter($plan['template_parts'], static fn(array $part): bool => $area === ($part['area'] ?? null))) {
        throw new RuntimeException('Fixture must extract shared ' . $area . ' chrome.');
    }
}
$assets = array_column($plan['assets'], null, 'source_path');
$seen = array();
$seenInline = false;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? null)) continue;
    foreach (array('desktop' => '980px', 'mobile' => '320px') as $device => $value) {
        if (!str_contains((string) ($asset['content'] ?? ''), '--w:' . $value)) continue;
        $seen[$device] = true;
        if ('desktop' === $device && str_starts_with((string) $asset['source_path'], 'inline/')) $seenInline = true;
        $instances = $asset['stylesheet_instances'] ?? $assets[$asset['stylesheet_parent'] ?? '']['stylesheet_instances'] ?? array();
        if (array() === $instances) throw new RuntimeException('Device rules must have selection-controlled stylesheet instances: ' . $asset['target_path']);
        foreach ($instances as $instance) {
            if ($device !== ($instance[CaptureEvidence::DEVICE_STYLE] ?? null)
                || 'not all' !== $instance['media']
                || 'all' !== ($instance[CaptureEvidence::SOURCE_MEDIA] ?? null)) {
                throw new RuntimeException($asset['target_path'] . ' exposes ' . $value . ' outside its ' . $device . ' selection.');
            }
        }
    }
}
if (!isset($seen['desktop'], $seen['mobile'])) throw new RuntimeException('Both device values must survive compilation.');
if (!$seenInline) throw new RuntimeException('Inline desktop rules must survive as selection-controlled instances.');
echo "shared chrome device selection contract passed\n";
