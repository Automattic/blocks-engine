<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/equal-navigation-placements.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'Equal header and drawer menus across routes/devices share one entity; got ' . count($plan['menus']));
$menu = $plan['menus'][0];
$assert(str_contains($menu['source_path'], '#header'), 'The header owns the canonical entity.');
$runtime = new Runtime();
$labels = array_column(array_column($runtime->parseBlocks($menu['block_markup']), 'attrs'), 'label');
$assert(array('Home', 'About', 'Visit') === $labels, 'Entity labels are trimmed text, not wrapper markup.');
$markup = implode('', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
preg_match_all('/<!-- wp:navigation (\{.*?\}) \/-->/s', $markup, $placements);
$assert(4 <= count($placements[1]), 'Header and drawer placements survive both captured documents.');
foreach ($placements[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $assert('{{wordpress-site-plan:navigation:' . $menu['token'] . '}}' === ($attrs['ref'] ?? null), 'Every placement references the canonical entity.');
}
$assert(str_contains($markup, 'primary-desktop') && str_contains($markup, 'drawer'), 'Placement presentation stays on the navigation hosts.');
echo "equal-navigation-placements: ok\n";
