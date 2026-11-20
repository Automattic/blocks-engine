<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The fallback must choose the widest body prose, not merely the widest
// paragraph that happens to be inside a fractional grid.
$html = '<html><head><style>
.panels{display:grid;grid-template-columns:1fr 1fr;max-width:1000px;margin:auto}
.story{max-width:900px;margin:auto}
</style></head><body><main><section class="panels"><p>One panel.</p><p>Another panel.</p></section><section class="story"><p>The full body story.</p></section></main></body></html>';
$artifact = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
$plan = $artifact->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'] ?? '', '"contentSize":"900px"') || str_contains($templates['page'] ?? '', 'margin-left:')) {
    fwrite(STDERR, "FAIL: normal-flow body prose must take precedence over narrower grid panels\n");
    exit(1);
}
echo "prose-column-normal-flow-precedence: passed\n";
