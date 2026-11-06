<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// #4721: a classed heading whose size is relative to a parent that changes
// size by breakpoint must not be bound to a fixed fontSize preset. Core prints
// the preset class `!important`, which would cut the inheritance and render
// the desktop size at every width (source: 80/160/200px at 390/1024/1440).
$responsiveVariable = '.hero-title{font-size:var(--responsive-font-size,200px);line-height:1}'
    . '@media (min-width:1px){.hero-title{--responsive-font-size:80px}}'
    . '@media (min-width:800px){.hero-title{--responsive-font-size:160px}}'
    . '@media (min-width:1280px){.hero-title{--responsive-font-size:200px}}';
$responsivePlain = '.hero-title{font-size:200px;line-height:1}'
    . '@media (max-width:1279px){.hero-title{font-size:160px}}'
    . '@media (max-width:799px){.hero-title{font-size:80px}}';
$constantParent = '.hero-title{font-size:200px;line-height:1}';

// A sibling keeps the parent a separate group instead of collapsing into the heading.
$sibling = '<p class="hero-lede">Lede</p>';

// Label => parent CSS, heading font-size, extra parent content, expect a preset.
$cases = array(
    'issue fixture: inherit under custom-property parent' => array($responsiveVariable, 'inherit', '', false),
    'unset under custom-property parent' => array($responsiveVariable, 'unset', '', false),
    '1em under custom-property parent' => array($responsiveVariable, '1em', '', false),
    '100% under custom-property parent' => array($responsiveVariable, '100%', '', false),
    'inherit under plain @media parent' => array($responsivePlain, 'inherit', $sibling, false),
    '1em under plain @media parent' => array($responsivePlain, '1em', $sibling, false),
    'constant authored size under responsive parent' => array($responsiveVariable, '200px', '', true),
    'inherit under constant parent' => array($constantParent, 'inherit', $sibling, true),
);

foreach ($cases as $label => [$parentCss, $headingSize, $siblings, $expectBound]) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Inherited responsive heading</title><style>body{margin:0;font-family:sans-serif}'
        . 'h1{font-size:' . $headingSize . ';margin:0}' . $parentCss . '</style></head><body>'
        . '<main><div class="hero-title"><h1 class="hero-heading">Hero title</h1>' . $siblings . '</div><p>A short line of introductory text.</p></main>'
        . '</body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
    ))->toArray();
    $markup = $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'];
    $assert(1 === preg_match('/<!-- wp:heading (\{.*?\}) -->(.*?)<!-- \/wp:heading -->/s', $markup, $heading), $label . ': heading survives compilation: ' . $markup);
    $attributes = json_decode($heading[1], true);
    $assert(in_array('hero-heading', explode(' ', (string) ($attributes['className'] ?? '')), true), $label . ': heading keeps its author class: ' . $heading[0]);
    $bound = isset($attributes['fontSize']) || 1 === preg_match('/has-[^\s"<>]+-font-size/', $heading[0]);
    $assert($expectBound === $bound, $label . ': ' . ($expectBound ? 'binds' : 'does not bind') . ' a fontSize preset: ' . $heading[0]);
}

echo "Relative font-size under responsive parent tests passed.\n";
