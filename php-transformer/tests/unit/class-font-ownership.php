<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A body-copy utility does not yield its font declaration to Global Styles.
// Even when it initially matches the root, it needs a block setting.
$html = '<!doctype html><html><head><style>body{font-family:Georgia,serif}.font-body{font-family:Georgia,serif}</style></head><body><main><p class="font-body">Explicit body font</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!str_contains($markup, '"fontFamily":"body"') || !str_contains($markup, 'has-body-font-family')) {
    throw new RuntimeException('A retained utility font must be owned by a block preset: ' . $markup);
}
// A container's matching body font must not become a block override.
// Its descendants can have independent typography; only the paragraph that
// owns the retained utility should acquire the preset.
$nested = '<!doctype html><html><head><style>body{font-family:Georgia,serif}.font-body{font-family:Georgia,serif}.font-other{font-family:Arial,sans-serif}</style></head><body><main><div class="font-body"><p class="font-body">Body copy</p><div class="font-other"><p>Other copy</p></div></div></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $nested),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $markup, $groups);
if (array() === $groups[1]) throw new RuntimeException('Expected a container in the regression fixture');
foreach ($groups[1] as $json) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (isset($attributes['fontFamily'])) {
        throw new RuntimeException('Container defaults must not acquire a font override: ' . $json);
    }
}
if (!str_contains($markup, '"fontFamily":"body"')) {
    throw new RuntimeException('The body-copy paragraph still needs its font preset: ' . $markup);
}
// Heading font defaults must retain their existing projection rather than
// adding a block override alongside responsive typography.
$artifact = require __DIR__ . '/../fixtures/design-system/tailwind-v3.php';
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
foreach ($plan['pages'] as $page) {
    preg_match_all('/<!-- wp:heading (\{.*?\}) -->/', $page['canonical_block_markup'], $headings);
    foreach ($headings[1] as $json) {
        $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (1 !== ($attributes['level'] ?? null)) continue;
        if (isset($attributes['fontFamily']) || isset($attributes['fontSize'])) {
            throw new RuntimeException('Responsive heading keeps its projected defaults: ' . $json);
        }
    }
}
echo "PASS: body utility fonts bind without overriding responsive heading defaults\n";
