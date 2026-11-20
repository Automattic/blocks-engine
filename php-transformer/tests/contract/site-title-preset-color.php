<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A promoted site title painted with a palette colour carries the preset,
// not the literal (core renders the self-closing block from attributes).
$files = array('s.css' => 'body{color:#222;font-family:Georgia}.brand{font-size:24px;color:#3a7d44}h1,.lead{color:#3a7d44}');
foreach (array('index', 'about', 'visit') as $route) {
    $files[$route . '.html'] = '<html><head><title>Garden Studio</title><link rel="stylesheet" href="s.css"></head><body><header><div class="logo"><a class="brand" href="index.html">Garden Studio</a></div><nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li><li><a href="visit.html">Visit</a></li></ul></nav></header><main><h1>' . $route . '</h1><p class="lead">Lead text.</p><p class="lead">More lead text.</p><p>Body.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = implode("\n", array_column(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']), 'canonical_block_markup'));
if (1 !== preg_match('/<!-- wp:site-title (\{.*?\}) \/-->/s', $markup, $match)) throw new RuntimeException('The shared wordmark is promoted to core/site-title.');
$attributes = json_decode($match[1], true);
$slug = $attributes['textColor'] ?? (preg_match('/^var:preset\|color\|(.+)$/', (string) ($attributes['style']['color']['text'] ?? ''), $var) ? $var[1] : null);
$theme = json_decode(array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
if (null === $slug || !isset($palette[$slug])) throw new RuntimeException('The site title colour binds to a palette preset: ' . $match[1]);
echo "site-title-preset-color: ok\n";
