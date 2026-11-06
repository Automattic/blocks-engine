<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Different landmark identities form proper member subsets, but their
// ancestors carry the same state on every route. They must still participate
// in site-wide reconciliation, especially the universal reset's exclusions.
foreach (array(false, true) as $nested) {
    $files = array('site.css' => '*{margin:0}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.cta{border:1px solid}');
    foreach (array('index', 'a', 'b', 'c') as $index => $slug) {
        $header = '<header><a class="cta" href="index.html">' . ($index < 2 ? 'Start' : 'Continue') . '<span class="sr-only"> a project</span></a></header>';
        $files[$slug . '.html'] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body>'
            . ($nested ? '<div id="app" class="site">' : '') . $header
            . '<main><h1>' . $slug . '</h1></main>' . ($nested ? '</div>' : '') . '</body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $stylesheets = array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null));
    $css = implode("\n", array_column($stylesheets, 'content'));
    if (!str_contains($css, '*:not(:where(.blocks-engine-role-richtext)):not(.be-c-0)')
        || !str_contains($css, ':where(.blocks-engine-role-richtext.blocks-engine-subject):not(.be-c-0){margin:0}')) {
        throw new RuntimeException('Landmark subsets with site-common ancestors retain the reset role exclusion and subject arm.');
    }
}

echo "shared-landmark-common-ancestor-reconciliation: ok\n";
