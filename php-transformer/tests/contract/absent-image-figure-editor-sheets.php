<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Pruning carrier arms no emitted image figure takes is a front-end weight
// fix. An editor-only sheet (the editor anchor restatement of an id-scoped
// rule) is written against the editor canvas and weighs nothing on a route,
// so it keeps its arms while the front-end sheet loses the dead ones.
$css = '#hero .brand{height:44px}.logo{height:40px}.d-block{display:block!important}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header><a href="index.html"><img class="logo" src="a.png" width="120" height="40" alt="Logo"></a></header>'
    . '<main><h1>' . $title . '</h1><section id="hero"><div class="row">'
    . '<div class="col"><a href="#!"><img class="brand d-block" src="a.png" width="100" height="44" alt="One"></a></div>'
    . '<div class="col"><a href="#!"><img class="brand d-block" src="a.png" width="100" height="44" alt="Two"></a></div>'
    . '</div></section></main></body></html>';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'a.png' => $png, 'index.html' => $page('Home'), 'about.html' => $page('About')),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$frontend = '';
$editor = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    if ('editor' === ($asset['stylesheet_target'] ?? 'both')) $editor .= (string) ($asset['content'] ?? '');
    else $frontend .= (string) ($asset['content'] ?? '');
}
$inline = '/\.brand\.wp-block-image:where\(\.blocks-engine-synthetic-image-figure-inline\)\s*>/';
$assert(str_contains($frontend, '#hero .brand{height:44px}') && 1 === preg_match('/#hero \.brand\.wp-block-image\s*>\s*img/', $frontend), 'The front end keeps the brand rule and its figure leaf.');
$assert(1 !== preg_match($inline, $frontend), 'The front end drops the inline-carrier arm no brand figure takes.');
$assert(str_contains($editor, '.blocks-engine-editor-anchor-hero') && 1 === preg_match($inline, $editor), 'The editor-only anchor sheet keeps its inline-carrier arms.');

echo "absent-image-figure-editor-sheets: ok\n";
