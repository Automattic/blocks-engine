<?php

declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/design-system/hover-variant-buttons.php')->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup')) . implode('', array_column($plan['template_parts'], 'canonical_block_markup'));
$errors = array();
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons);
if (3 !== count($buttons[1])) $errors[] = 'Expected three buttons';
foreach ($buttons[1] as $json) {
    $attributes = json_decode($json, true);
    if (isset($attributes['style']['color']['background']) || isset($attributes['backgroundColor'])) $errors[] = 'Button must render its background through the class stylesheet';
}
$metrics = $plan['theme']['design_system']['metrics'];
if (0 !== $metrics['refusal_reasons']['state_variant']) $errors[] = 'Expected zero state_variant refusals';
if (0 !== $metrics['refusal_reasons']['alpha']) $errors[] = 'Opaque /1 and /var(--tw-bg-opacity,1) are not alpha refusals';
if (1.0 !== (float) $metrics['restyle_coverage']) $errors[] = 'Expected full restyle coverage';
echo json_encode(array('buttons' => count($buttons[1]), 'state_variant' => $metrics['refusal_reasons']['state_variant'], 'alpha' => $metrics['refusal_reasons']['alpha'], 'restyle_coverage' => $metrics['restyle_coverage'])) . "\n";
$system = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem::fromUsage(array('index.html' => array(
    'schema' => \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem::USAGE_SCHEMA,
    'text' => array(array('role' => 'body', 'color' => '#2563eb', 'font_size_px' => 16, 'count' => 1, 'weight' => 20)),
)));
$button = '<!-- wp:button {"className":"paint","style":{"color":{"background":"#2563eb"}}} --><div class="wp-block-button paint"><a class="wp-block-button__link has-background" style="background-color:#2563eb">Go</a></div><!-- /wp:button -->';
$bind = static function (string $css, string $markup) use ($system): string {
    return (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding($system, array(array('kind' => 'css', 'content' => $css))))->bind($markup);
};
$state = '.paint:hover{background-color:#1d4ed8}';
foreach (array(
    'no resting rule' => $state,
    'different resting colour' => '.paint{background-color:#fff}' . $state,
    'conditional-only resting rule' => '@media(min-width:800px){.paint{background-color:#2563eb}}' . $state,
    'important competitor' => '.paint{background-color:#2563eb}.paint{background-color:#fff!important}' . $state,
    'style text observer' => '.paint{background-color:#2563eb}[style*="background-color"]{background-color:#fff}' . $state,
) as $label => $css) {
    if ($button !== $bind($css, $button)) $errors[] = 'Must preserve literal: ' . $label;
}
$css = '.paint{background-color:#2563eb}' . $state;
$bound = $bind($css, $button);
if ($button === $bound || str_contains($bound, 'background-color:#2563eb')) $errors[] = 'Matching class must remove the duplicate literal';
if ($bound !== $bind($css, $bound)) $errors[] = 'Binding must be idempotent';
foreach ($errors as $error) fwrite(STDERR, "FAIL: $error\n");
if (array() !== $errors) exit(1);
echo "Hover-variant buttons passed\n";
