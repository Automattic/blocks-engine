<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Identical structure, different instance CSS. The adopting route must load the
// chosen part's rule, not depend on the rule for its own discarded header.
$files = array('site.css' => '.site-header nav{color:var(--nav-color);font-weight:var(--nav-weight);gap:var(--nav-gap)}');
foreach (array('index' => '', 'other' => '--nav-hover:teal;') as $slug => $extra) {
    $files[$slug . '.html'] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body>'
        . '<header class="site-header" style="--nav-color:purple;--nav-weight:500;--nav-gap:23px;' . $extra . '">'
        . '<a href="index.html">Example</a><nav><a href="index.html">Home</a><a href="other.html">Other</a></nav></header>'
        . '<main><h1>' . $slug . '</h1><p>Unique route content</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Structurally identical headers share one canonical part despite an extra custom property.');
$markup = $headers[0]['canonical_block_markup'];
$assert(1 === preg_match('/be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', $markup, $match), 'The shared part carries instance geometry.');
$class = $match[0];
$chrome = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains($asset['target_path'], '/shared-chrome-') && 'editor' !== ($asset['stylesheet_target'] ?? 'both') && str_contains($asset['content'] ?? '', '.' . $class . '{')));
$assert(1 === count($chrome), 'The canonical part geometry rule is emitted once in shared chrome.');
$asset = $chrome[0];
$assert(array(array('kind' => 'global')) === $asset['scopes'], 'Part CSS is not scoped to a capturing page.');
$assert(1 === preg_match('/\.' . preg_quote($class, '/') . '\{([^}]+)\}/', $asset['content'], $rule), 'Shared chrome defines the exact part class.');
foreach (array('--nav-color:purple', '--nav-weight:500', '--nav-gap:23px') as $declaration) {
    $assert(str_contains($rule[1], $declaration), 'Part resolves ' . $declaration);
}
$writes = array_column($plan['writes'], null, 'target_path');
$target = $asset['target_path'];
// Engine-only rules need no canvas rewrite and are shared by both targets;
// author projections may instead have a separate editor sibling.
$editorTarget = 'both' === ($asset['stylesheet_target'] ?? 'both') ? $target : substr($target, 0, -4) . '.editor.css';
$assert(isset($writes[$editorTarget]) && str_contains($writes[$editorTarget]['payload']['data'], $rule[1]), 'Editor stylesheet carries the part instance declarations.');
$bootstrap = $writes['functions.php']['payload']['data'];
$frontend = (string) strstr($bootstrap, "add_action( 'wp_enqueue_scripts'");
$frontend = substr($frontend, 0, (int) strpos($frontend, '}, 1 );'));
preg_match_all('/^    if \( (.+?) \) \{\n((?:        [^\n]+\n)+)    \}$/m', $frontend, $branches, PREG_SET_ORDER);
$assert(count($branches) >= 2, 'Both routes have enqueue branches.');
$globalEnqueue = "if ( true ) wp_enqueue_style( '";
$globalLines = array_filter(explode("\n", $frontend), static fn(string $line): bool => str_contains($line, $globalEnqueue));
$globalCss = implode("\n", $globalLines);
foreach ($branches as $branch) {
    $assert(str_contains($globalCss . $branch[2], "get_theme_file_uri( '" . $target . "' )"), 'Every route loads the canonical part custom properties: ' . $branch[1]);
}
$editor = (string) strstr($bootstrap, "add_action( 'enqueue_block_editor_assets'");
$assert(str_contains($editor, $editorTarget), 'Editor enqueues the shared instance stylesheet.');
$templates = array_column($plan['templates'], null, 'slug');
foreach (array('front-page', 'page') as $slug) {
    $assert(str_contains($templates[$slug]['canonical_block_markup'], 'wp:template-part {"slug":"header",'), 'Every route template adopts the shared header.');
}

echo "shared-part-instance-css contract passed\n";
