<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Projection restates an image rule for both native leaves a source image
// could become, `.wp-block-image > img` and `.wp-block-image > a > img`.
// Once the plan's markup is final, a leaf no figure with those classes has
// matches nothing: the unlinked avatar never gets a linked arm, while the
// linked logo keeps its own.
$css = '.avatar{border-radius:50%;width:120px}.logo{height:40px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header><a href="index.html"><img class="logo" src="logo.png" width="120" height="40" alt="Logo"></a></header>'
    . '<main><h1>' . $title . '</h1><img class="avatar" src="avatar.png" width="120" height="120" alt="Avatar"><p>' . $title . ' copy.</p></main></body></html>';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'logo.png' => $png, 'avatar.png' => $png, 'index.html' => $page('Home'), 'about.html' => $page('About')),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()) as $unit) {
    $markup .= (string) ($unit['canonical_block_markup'] ?? '');
}
$assert(1 === preg_match('/<figure [^>]*class="[^"]*\bwp-block-image\b[^"]*\bavatar\b[^"]*"[^>]*><img/', $markup) || 1 === preg_match('/<figure [^>]*class="[^"]*\bavatar\b[^"]*\bwp-block-image\b[^"]*"[^>]*><img/', $markup), 'The avatar is an unlinked image figure.');
$assert(1 === preg_match('/<figure [^>]*class="[^"]*\blogo\b[^"]*"[^>]*><a /', $markup), 'The logo is a linked image figure.');

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(1 === preg_match('/\.avatar\.wp-block-image[^,{]*>\s*img[^{]*\{[^}]*border-radius:50%/', $sheets), 'The avatar keeps its image leaf rule.');
$assert(1 !== preg_match('/\.avatar\.wp-block-image[^,{]*>\s*a[^,{]*>\s*img/', $sheets), 'No arm requires a link no avatar figure has.');
$assert(1 === preg_match('/\.logo\.wp-block-image[^,{]*>\s*a[^,{]*>\s*img/', $sheets), 'The linked logo keeps its linked leaf arm.');

echo "absent-image-figure-selectors: ok\n";
