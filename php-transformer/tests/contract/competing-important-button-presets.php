<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$css = <<<'CSS'
html { height: 100%; }
body { width: 100%; height: 100%; background: #ffffff; color: #000000; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 16px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
.wsite-button { display: inline-block; height: auto; padding: 0; background-image: none !important; background-color: transparent; color: #000000 !important; background-color: #E1E4E6 !important; -webkit-transition: all 300ms ease-in-out; -moz-transition: all 300ms ease-in-out; -ms-transition: all 300ms ease-in-out; transition: all 300ms ease-in-out; }
.wsite-button:hover { background-color: #c5cbcf !important; }
.wsite-button .wsite-button-inner { display: inline-block; height: auto; padding: 15px 32px; background: transparent; background-image: none !important; color: inherit; letter-spacing: 0.15em; font-family: "Josefin Sans", sans-serif; font-size: 11px; font-weight: 700; line-height: 1; text-transform: uppercase; }
.wsite-button-large { color: #000000 !important; background-color: #ffffff !important; }
.wsite-button-large:hover { background-color: rgba(255,255,255,0.4) !important; }
.wsite-button-highlight { color: #ffffff !important; background-color: #000000 !important; }
.wsite-button-highlight:hover { background-color: rgba(0,0,0,0.6) !important; }
.wsite-button-highlight.wsite-button-large:not(.wsite-buy-button) { color: #ffffff !important; background-color: rgba(255,255,255,0.5) !important; }
.wsite-button-highlight.wsite-button-large:not(.wsite-buy-button):hover { background-color: rgba(255,255,255,0.3) !important; }
CSS;
$html = '<html><head><link rel="stylesheet" href="style.css"></head><body><main><h1>T</h1><p>x</p><div style="text-align:center"><a class="wsite-button wsite-button-small wsite-button-highlight" href="/index.html"><span class="wsite-button-inner">home</span></a></div><div style="text-align:left"><a class="wsite-button wsite-button-small wsite-button-normal" href="/a.pdf"><span class="wsite-button-inner">sermon notes</span></a></div></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'style.css' => $css)))->toArray()['source_reports']['wordpress_site_plan'];
if (0 !== $plan['theme']['design_system']['metrics']['literal_preset_color_count']) throw new RuntimeException('Both button backgrounds must bind their exact palette presets.');
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
$expected = array('contrast' => '#000000', 'primary' => '#E1E4E6');
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons);
if (2 !== count($buttons[1])) throw new RuntimeException('Expected both buttons.');
foreach (array_values($buttons[1]) as $index => $json) {
    $attributes = json_decode($json, true);
    $slug = array_keys($expected)[$index];
    if ('var:preset|color|' . $slug !== ($attributes['style']['color']['background'] ?? null)) throw new RuntimeException('Each button binds its exact preset inline, without introducing a preset class.');
    if (isset($attributes['backgroundColor'])) throw new RuntimeException('A preset class would change projected paint ownership.');
}
$sheets = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$cascade = new StaticCssCascade($dom, $sheets);
$painted = array();
foreach ($dom->getElementsByTagName('a') as $link) {
    if (!str_contains($link->getAttribute('class'), 'wp-block-button__link')) continue;
    $painted[] = CssColor::hex($cascade->resolve($link, array('background-color'), array())['background-color'] ?? '');
}
if (array_map(static fn(string $color): ?string => CssColor::hex($color), array_values($expected)) !== $painted) throw new RuntimeException('Highlight must paint black and normal must paint primary: ' . json_encode($painted));
echo "Competing important button presets passed\n";
