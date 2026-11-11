<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;

$preflight = '@layer base{*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}*{border-color:var(--border)}}';
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>';
$item = static fn (string $number, string $title): string => '<a href="/' . strtolower($title) . '/"><span>' . $number . '</span><span><strong>' . $title . '</strong><small>Desc</small></span>' . $svg . '</a>';
foreach (array(
    'preflight rows' => array($preflight, 'border-bottom:1px solid var(--border)', false),
    'real outline' => array($preflight, 'border:1px solid #000;background:transparent', true),
) as $label => [$reset, $border, $outline]) {
    $css = $reset . ':root{--border:#d5d3cc}.links a{' . $border . ';grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:.75rem;padding:.9rem 0;display:grid}';
    $html = '<!doctype html><html><head><title>T</title><link rel="stylesheet" href="/s.css"></head><body><main><div class="links">' . $item('01', 'X') . $item('02', 'Y') . '</div></main></body></html>';
    $markup = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))
        ->toWordPressSitePlanView()['wordpress_site_plan']['pages'][0]['canonical_block_markup'];
    if ($outline && !str_contains($markup, '<!-- wp:button ')) {
        throw new RuntimeException($label . ': a real outline remains a native button.');
    }
    if ($outline !== str_contains($markup, 'is-style-outline')) {
        throw new RuntimeException($label . ': only a painted box may become an outline button.');
    }
    if (!$outline && str_contains($markup, '<!-- wp:button ')) {
        throw new RuntimeException($label . ': a zero-width reset must not make a link row a button.');
    }
    // A zero-width reset paints nothing, so it must not change how the rows classify.
    $blocks = static fn (string $markup): array => array_values(array_diff(preg_match_all('/<!-- wp:([a-z\/-]+)/', $markup, $m) ? $m[1] : array(), array('group')));
    $plain = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => str_replace($reset, '', $css))))
        ->toWordPressSitePlanView()['wordpress_site_plan']['pages'][0]['canonical_block_markup'];
    if (!$outline && $blocks($markup) !== $blocks($plain)) {
        throw new RuntimeException($label . ': the reset changed block classification.');
    }
}
// Exercise the same paint policy directly for explicit controls, including
// later shorthand resets and longhands that cancel only one side.
$resolver = new ButtonStyleResolver();
foreach (array(
    array('border:0 solid;border-color:#000;border-bottom:1px solid #000', array('bottom' => array('width' => '1px', 'style' => 'solid', 'color' => '#000'))),
    array('border:1px solid #000;border-width:0', array()),
    array('border:1px solid #000;border-style:hidden', array()),
    array('border-bottom:1px solid #000;border:0 solid', array()),
    array('border:0 solid;border-bottom:1px solid #000;border:0 solid', array()),
    array('border:1px solid #000', array('width' => '1px', 'style' => 'solid', 'color' => '#000')),
) as [$css, $expected]) {
    $actual = $resolver->nativeAttributes($css)['style']['border'] ?? array();
    if ($expected !== $actual) {
        throw new RuntimeException('Button border paint must follow the cascade: ' . $css);
    }
}
echo "Preflight zero border link row contract passed.\n";
