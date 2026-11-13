<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<header class="site-header"><div class="site-header__main"><a class="site-logo" href="/"><span>Brand</span></a><nav class="site-header__nav"><a href="/a/">A</a><a href="/b/">B</a><a href="/c/">C</a></nav><div class="site-header__actions"><a class="icon-button" href="/search/" aria-label="Search"><svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="8"/></svg></a><a class="button button--primary" href="/weekly/">Get Weekly</a><button class="icon-button site-header__menu" type="button" aria-label="Open menu"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button></div></div></header>';
$css = '.site-header__main{display:flex;justify-content:space-between;align-items:center;gap:.75rem}.site-header__nav{display:none}.site-header__actions{display:flex;align-items:center;gap:.35rem}.icon-button{width:2.5rem;height:2.5rem;border:0;background:0 0;display:inline-grid;place-items:center}@media (min-width:1024px){.site-header__nav{display:flex}.site-header__menu{display:none}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>' . $html . '</body></html>'),
    array('path' => 'style.css', 'content' => $css),
)))->toArray();
$markup = $result['serialized_blocks'];
$assert = static function (bool $condition, string $message) use ($markup): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n$markup\n"); exit(1); }
};
preg_match_all('/<!-- wp:navigation (\{[^\n]*?\}) -->/', $markup, $matches, PREG_OFFSET_CAPTURE);
$assert(2 === count($matches[0]), 'two native menu occurrences');
$inline = json_decode($matches[1][0][0], true);
$toggle = json_decode($matches[1][1][0], true);
$assert('never' === $inline['overlayMenu'] && 'always' === $toggle['overlayMenu'], 'inline and overlay-only slots');
$assert($matches[0][1][1] > strpos($markup, 'Get Weekly'), 'overlay host follows the weekly control');
$assert(str_contains(substr($markup, 0, $matches[0][1][1]), 'site-header__actions'), 'overlay host is in actions');
$tail = substr($markup, $matches[0][1][1]);
$assert(1 === preg_match('~<!-- /wp:navigation -->\s*</div>\s*<!-- /wp:group -->~', $tail), 'overlay host is the last actions item');
$classes = $toggle['metadata']['blocksEngineNavigationOpener']['className'] ?? '';
$assert(str_contains($classes, 'icon-button') && str_contains($classes, 'site-header__menu'), 'native opener keeps source chrome classes');
$assert(!str_contains($markup, '<!-- wp:html') && !str_contains(strtolower($markup), '<script'), 'no HTML fallback or inline script');
$firstContent = substr($markup, $matches[0][0][1] + strlen($matches[0][0][0]));
$firstContent = strstr($firstContent, '<!-- /wp:navigation -->', true);
$secondContent = substr($markup, $matches[0][1][1] + strlen($matches[0][1][0]));
$secondContent = strstr($secondContent, '<!-- /wp:navigation -->', true);
$assert(isset($inline['ref']) ? $inline['ref'] === ($toggle['ref'] ?? null) : $firstContent === $secondContent, 'both occurrences share menu content or ref');
$assets = implode("\n", array_column($result['assets'], 'content'));
$assert(str_contains($assets, '@media(max-width:1023px)') && str_contains($assets, '@media(min-width:1024px)'), 'source collapse boundary, not Core default');
echo "navigation toggle in-flow actions slot passed\n";
