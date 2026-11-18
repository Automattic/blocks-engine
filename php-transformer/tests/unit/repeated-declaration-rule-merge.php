<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Projection restates one source declaration block for every carrier it
// reaches (a class and its control twin), and font binding splits a list into
// one rule per arm. Each copy is published weight: a block ships once for all
// of its selectors, unless a rule between them competes for the same
// properties, a license comment would be lost, the lists differ in a pseudo
// that some browser drops the whole list for, or the rule is a document or
// reset surface (`:root`, `body`, `*`) that keeps its own selector.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p><p class="aside">Aside</p><input class="field" placeholder="x"></main></body></html>',
    'site.css' => "/*! Kit v1 | MIT */\n:root{--kit-gap:1rem;--kit-ink:#123456}\n"
        . ".lead{letter-spacing:.05em;padding:var(--kit-gap);border-color:var(--kit-ink)}\n.note{letter-spacing:.05em;padding:var(--kit-gap);border-color:var(--kit-ink)}\n"
        . ".lead{margin:3px}.note{margin:5px}.aside{margin:3px}\n"
        . ".field::placeholder{opacity:.4}.field::-moz-placeholder{opacity:.4}\n",
)))->toArray();
// The WordPress site plan carries each stylesheet in its published form.
$css = implode("\n", array_column(array_filter($result['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) $asset['content'], 'letter-spacing')), 'content'));
if ('' === $css) throw new RuntimeException('Author stylesheet missing');
$once = static function (string $needle) use ($css): void {
    if (1 !== substr_count($css, $needle)) throw new RuntimeException('A repeated declaration block must ship once: ' . $needle . "\n" . $css);
};
$once('letter-spacing:.05em');
if (!str_contains($css, ':root{--kit-gap:')) throw new RuntimeException("The document token block keeps its own selector:\n" . $css);
if (1 !== substr_count($css, 'Kit v1')) throw new RuntimeException("The license comment ships exactly once:\n" . $css);
if (2 !== substr_count($css, 'margin:3px')) throw new RuntimeException("A repeat that competes with a rule between keeps its place:\n" . $css);
if (2 !== substr_count($css, 'opacity:.4')) throw new RuntimeException("A vendor-prefixed pseudo never joins a list a browser would otherwise keep:\n" . $css);
// Compatibility replays have a :root specificity shim, not a root reset.
// Preset binding can split their selector list; the published plan rejoins it.
$navigation = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><link rel="stylesheet" href="site.css"></head><body><main><nav class="menu"><a href="/a">Alpha</a><a href="/b">Beta</a></nav></main></body></html>',
    'site.css' => '.menu a{letter-spacing:.037em;color:#123456}',
)))->toArray();
$compat = implode("\n", array_column(array_filter($navigation['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn(array $asset): bool => 'wordpress-compat' === ($asset['source'] ?? null)), 'content'));
if (1 !== substr_count($compat, 'letter-spacing:.037em')) throw new RuntimeException("Navigation replay declarations ship once:\n" . $compat);
echo "Repeated declaration blocks ship once without crossing a competing rule\n";
