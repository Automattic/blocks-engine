<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<style>.icon{display:none}</style><main><h1>Title</h1><div class="icon"><img src="logo.jpg" alt=""><img src="logo2.jpg" alt=""></div><p>After the hidden icon.</p></main>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $source),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = implode("\n", array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
$css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if ( ! str_contains($markup, '<!-- wp:gallery {"className":"icon"}') ) {
    throw new RuntimeException('The source icon must still lower to a native gallery.');
}
// Two root pseudo-classes outrank body .is-layout-flex (0,1,1).
// The zero-specificity block gate leaves ordinary .icon boxes untouched.
if ( ! str_contains($css, ':root:root .icon:where(.wp-block-gallery){display:none}') ) {
    throw new RuntimeException('Authored display must outrank Core layout display on the lowered box.');
}
if ( in_array('--json', $argv, true) ) {
    echo json_encode(array('source' => $source, 'blocks' => $markup, 'css' => $css));
} else {
    echo "Author layout display contract passed\n";
}
