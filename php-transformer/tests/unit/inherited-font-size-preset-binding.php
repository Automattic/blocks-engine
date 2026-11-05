<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ResponsiveCorrespondence;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// #4409: font shorthand is not interpreted by the usage collector. Its
// inherited size must not become a confident 10px class-style observation.
foreach (array(
    CaptureEvidence::RESPONSIVE_COUNTERPART_CLASS_PREFIX . '0123456789ab',
    ResponsiveCorrespondence::TOKEN_CLASS_PREFIX . '0123456789ab',
    'hero',
) as $class) {
    $html = '<style>html{font-size:10px}.rich{font:var(--heading-1-font)}'
        . ':root{--heading-1-font:normal normal normal 78px/1em serif}'
        . '.rich h1{font-size:inherit}</style>'
        . '<div class="rich"><h1 class="' . $class . '">Hero</h1></div>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
    ))->toArray();
    $markup = $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'];
    $assert(1 === preg_match('/<!-- wp:heading\b.*?<!-- \/wp:heading -->/s', $markup, $heading), $class . ': heading survives compilation');
    preg_match('/<!-- wp:heading (.*?) -->/s', $heading[0], $delimiter);
    $attributes = json_decode($delimiter[1], true);
    $assert(!isset($attributes['fontSize']), $class . ': inherited heading has no fontSize preset: ' . $heading[0]);
    $assert(0 === preg_match('/has-[^\s"<>]+-font-size/', $heading[0]), $class . ': inherited heading has no font-size preset class');
}

// Unknown sizes propagate through intermediate descendants, but an absolute
// longhand restores static knowledge. Exercise every parent-relative spelling.
foreach (array('font:var(--missing)', 'font-size:var(--missing)') as $parentStyle) {
    $children = '';
    $expected = array('inherit' => '!value', 'unset' => '!value', '120%' => '!value', '1.2em' => '!value', 'smaller' => '!value', 'larger' => '!value', '24px' => '24px', '2rem' => '20px');
    foreach (array_keys($expected) as $index => $size) $children .= '<p class="size-' . $index . '" style="font-size:' . $size . '">Text</p>';
    $result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => '<style>html{font-size:10px}</style><div style="' . $parentStyle . '"><section>' . $children . '</section></div>')))->toArray();
    $rows = $result['source_reports']['compiled_site']['pages'][0]['design_usage']['class_styles'];
    $properties = array_column($rows, 'properties', 'classes');
    foreach (array_values($expected) as $index => $observation) {
        $assert(array($observation) === ($properties['size-' . $index]['font-size'] ?? null), $parentStyle . ': size-' . $index . ' records ' . $observation);
    }
}

echo "Inherited font-size preset binding tests passed.\n";
