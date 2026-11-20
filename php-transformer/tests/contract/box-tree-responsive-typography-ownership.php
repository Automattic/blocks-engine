<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<style>:root{--title:36px}h1{font-size:var(--title)}@media(min-width:768px){h1{font-size:60px}}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n6']['viewports'] as $key => &$viewport) {
    $viewport['used_styles']['font-size'] = 'desktop' === $key ? '60px' : '36px';
}
unset($viewport);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!str_contains($markup, 'blocks-engine-responsive-typography-')) throw new RuntimeException('Captured typography must retain the responsive rescue.');
$css = implode("\n", array_column($plan['assets'], 'content'));
if (preg_match('/\.blocks-engine-responsive-typography-[^{]+\{font-size:(?:var\(--title\)|36px|var\(--wp--preset--font-size--[^,]+,36px\))/', $css)) throw new RuntimeException('Token-based sizes must stay with the captured carrier, not a duplicate rescue.');
if (!preg_match('/\.blocks-engine-responsive-typography-[^{]+\{font-size:(?:60px|var\(--wp--preset--font-size--[^,]+,60px\))/', $css)) throw new RuntimeException('Literal breakpoint sizes must remain unchanged.');
if (!str_contains($markup, 'be-inline-geometry-')) throw new RuntimeException('Captured typography must retain its carrier.');
// A wholly token-based sequence must not emit an empty competing rescue.
$html = str_replace('font-size:60px', 'font-size:var(--title)', $html);
$tree['source_hash'] = hash('sha256', $html);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
if (str_contains($plan['pages'][0]['canonical_block_markup'], 'blocks-engine-responsive-typography-')) throw new RuntimeException('Captured token-only typography must not acquire a second owner.');
// A layered reset is not a reason to emit a base-only rescue after every
// token breakpoint was discarded. This is the Tailwind heading cascade.
$html = str_replace(':root{--title:36px}h1{font-size:var(--title)}', ':root{--title:36px}@layer base{h1{font-size:inherit}}', $html);
$tree['source_hash'] = hash('sha256', $html);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
if (str_contains($plan['pages'][0]['canonical_block_markup'], 'blocks-engine-responsive-typography-')) throw new RuntimeException('A layered reset must not create a base-only captured typography rescue.');
echo "PASS: Captured token sizes retain one owner and literal breakpoints retain their rescue.\n";
