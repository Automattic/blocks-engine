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
