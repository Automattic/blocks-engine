<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Projection restates an image rule for every figure carrier a source image
// could become, including the inline carrier. Once the plan's markup is
// final, a carrier arm whose classes no emitted figure carries together
// matches nothing: the block-level brand logos never become inline figures,
// while the header logo does and keeps its inline arm. An unlinked figure
// keeps its linked leaf: an editor can link any image.
$css = '.brand{height:44px}.logo{height:40px}.d-block{display:block!important}.avatar{border-radius:50%}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header><a href="index.html"><img class="logo" src="a.png" width="120" height="40" alt="Logo"></a></header>'
    . '<main><h1>' . $title . '</h1><div class="row">'
    . '<div class="col"><a href="#!"><img class="brand d-block" src="a.png" width="100" height="44" alt="One"></a></div>'
    . '<div class="col"><a href="#!"><img class="brand d-block" src="a.png" width="100" height="44" alt="Two"></a></div>'
    . '</div><img class="avatar" src="a.png" width="120" height="120" alt="Avatar"></main></body></html>';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'a.png' => $png, 'index.html' => $page('Home'), 'about.html' => $page('About')),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()) as $unit) {
    $markup .= (string) ($unit['canonical_block_markup'] ?? '');
}
preg_match_all('/<figure [^>]*class="([^"]*)"/', $markup, $figures);
$brands = array_filter($figures[1], static fn (string $classes): bool => str_contains(" $classes ", ' brand '));
$assert(array() !== $brands && array() === array_filter($brands, static fn (string $classes): bool => str_contains($classes, 'blocks-engine-synthetic-image-figure-inline')), 'The brand logos are block-level image figures.');
$assert(1 === preg_match('/<figure [^>]*class="[^"]*\blogo\b[^"]*blocks-engine-synthetic-image-figure-inline/', $markup), 'The header logo is an inline image figure.');

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(str_contains($sheets, '.brand{height:44px}') && 1 === preg_match('/\.brand\.wp-block-image\s*>\s*img/', $sheets), 'The brand keeps its own rule and its figure leaf.');
$assert(1 !== preg_match('/\.(?:brand|d-block)\.wp-block-image:where\(\.blocks-engine-synthetic-image-figure-inline\)/', $sheets), 'No inline-carrier arm requires classes no inline figure carries.');
$assert(1 === preg_match('/\.logo\.wp-block-image:where\(\.blocks-engine-synthetic-image-figure-inline\)\s*>\s*img/', $sheets), 'The inline header logo keeps its inline-carrier arm.');
$assert(1 === preg_match('/\.avatar\.wp-block-image[^,{]*>\s*a\s*>\s*img/', $sheets), 'An unlinked figure keeps its linked leaf arm.');

echo "absent-image-figure-selectors: ok\n";
