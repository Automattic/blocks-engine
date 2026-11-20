<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A navigation keeps its links' font size as a `style.typography.fontSize`
 * literal. When a font-size preset renders exactly that size, the
 * navigation names the preset through its inline variable, as any other
 * block's exact size does, instead of keeping the literal outside the
 * editable design system. Core renders a navigation's typography from its
 * attributes and turns a `var:preset|` size into an `!important` class, so
 * the preset is either the `fontSize` attribute, where that class is safe,
 * or the preset's CSS variable kept as the same inline declaration.
 */
$html = <<<'HTML'
<!doctype html><html><head><style>
body{font-size:16px}
.nav-links{display:flex;gap:24px}
.nav-links a{color:#151515;font-size:11px;font-weight:700;text-decoration:none}
.fine{font-size:11px}
</style></head><body>
<header><a href="/">Site</a><nav class="nav-links"><a href="/about">About</a><a href="/writing">Writing</a><a href="/contact">Contact</a></nav></header>
<main><h1>Title</h1><p>Body copy that sets the base size of the page.</p><p class="fine">Fine print one.</p><p class="fine">Fine print two.</p><p class="fine">Fine print three.</p></main>
</body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
foreach ($plan['writes'] as $write) {
    if (is_string($write['payload']['data'] ?? null) && str_ends_with($write['target_path'], '.html')) $markup .= $write['payload']['data'];
}
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
$slug = array_search('11px', $sizes, true);
$assert(is_string($slug), 'An 11px font-size preset must exist: ' . json_encode($sizes));
$assert(1 === preg_match_all('/<!-- wp:navigation (\{(?:(?!-->).)*\}) \/?-->/s', $markup, $navigations), 'The page must carry one navigation: ' . $markup);
$attributes = json_decode($navigations[1][0], true, 512, JSON_THROW_ON_ERROR);
$inline = $attributes['style']['typography']['fontSize'] ?? null;
$assert($slug === ($attributes['fontSize'] ?? null) && null === $inline || 'var(--wp--preset--font-size--' . $slug . ')' === $inline && !isset($attributes['fontSize']), 'The navigation must name the exact font-size preset, not the 11px literal: ' . $navigations[1][0]);

echo "navigation font size preset: ok\n";
