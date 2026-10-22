<?php

declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/design-system/non-palette-hover-buttons.php')->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup')) . implode('', array_column($plan['template_parts'], 'canonical_block_markup'));
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
$errors = array();
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons);
if (2 !== count($buttons[1])) $errors[] = 'Expected both button shapes';
foreach ($buttons[1] as $json) {
    $attributes = json_decode($json, true);
    if (isset($attributes['style']['color']['background']) || isset($attributes['backgroundColor'])) $errors[] = 'Background must render through the carried author rule';
    foreach (array('btn', 'bg-transparent', 'hover:bg-white/10') as $class) {
        if (!in_array(EngineMarker::subjectClassClass($class), explode(' ', $attributes['className'] ?? ''), true)) $errors[] = 'Missing class carrier: ' . $class;
    }
}
preg_match_all('/<a\b[^>]*class="[^"]*wp-block-button__link[^>]*>/', $markup, $links);
if (2 !== count($links[0])) $errors[] = 'Expected two links';
foreach ($links[0] as $link) {
    if (preg_match('/background(?:-color)?\s*:/', $link)) $errors[] = 'Link retains an inline background';
}
foreach (array('.bg-transparent', '.hover\\:bg-white\\/10') as $selector) {
    if (!str_contains($css, $selector)) $errors[] = 'Missing carried rule: ' . $selector;
}
if (!str_contains($css, ':hover') || !str_contains($css, 'transparent')) $errors[] = 'Missing resting or hover paint';
// Exercise the generic proof independently of fixture conversion and palette extraction.
$system = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem::fromUsage(array('index.html' => array(
    'schema' => \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem::USAGE_SCHEMA,
    'text' => array(array('role' => 'body', 'color' => '#2563eb', 'font_size_px' => 16, 'count' => 1, 'weight' => 20)),
)));
$bind = static function (string $css, string $markup) use ($system): string {
    return (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding($system, array(array('kind' => 'css', 'content' => $css))))->bind($markup);
};
$button = '<!-- wp:button {"className":"paint","style":{"color":{"background":"transparent"}}} --><div class="wp-block-button paint"><a class="wp-block-button__link has-background" style="background-color:transparent">Go</a></div><!-- /wp:button -->';
$state = '.paint:hover{background-color:#ffffff1a}';
foreach (array(
    'no resting rule' => $state,
    'different resting value' => '.paint{background-color:#fff}' . $state,
    'conflicting declarations' => '.paint{background-color:transparent}.paint{background-color:#fff}' . $state,
    'conditional-only rule' => '@media(min-width:800px){.paint{background-color:transparent}}' . $state,
    'contextual-only rule' => '.band .paint{background-color:transparent}' . $state,
    'unresolved value' => '.paint{background-color:var(--unknown)}' . $state,
    'important competitor' => '.paint{background-color:transparent}.paint{background-color:#fff!important}' . $state,
    'style text observer' => '.paint{background-color:transparent}[style*="background-color"]{color:red}' . $state,
) as $label => $rules) {
    if ($button !== $bind($rules, $button)) $errors[] = 'Must preserve literal: ' . $label;
}
foreach (array('transparent', 'rgba(0,0,0,0)') as $resting) {
    $rules = '.paint{background-color:' . $resting . '}' . $state;
    $bound = $bind($rules, $button);
    if ($button === $bound || str_contains($bound, 'background-color:transparent')) $errors[] = 'Matching class must remove literal: ' . $resting;
    if ($bound !== $bind($rules, $bound)) $errors[] = 'Binding must be idempotent';
}
foreach ($errors as $error) fwrite(STDERR, "FAIL: $error\n");
if (array() !== $errors) exit(1);
echo "Non-palette hover buttons passed\n";
