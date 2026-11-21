<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<style>@keyframes slide{to{transform:translateX(-50%)}}.clip{overflow:hidden}.rail{display:flex;animation:slide 20s linear infinite}.item{flex:0 0 340px;min-height:112px;margin:0;padding:24px 36px 20px}.item blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}.item figcaption{font-size:9px}</style><main><div class="clip"><div class="rail"><figure class="item"><blockquote>“<!---->A quotation that wraps onto two lines in its rail.<!---->”</blockquote><figcaption>Publication</figcaption></figure></div></div></main>';
$compile = static fn(string $source): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$result = $compile($html);
$css = implode("\n", array_column($result['assets'], 'content'));
if (!preg_match('/>p\{[^}]*margin:0 0 12px[^}]*display:block/', $css)
    || !preg_match('/>cite\{[^}]*font-size:9px/', $css)) {
    throw new RuntimeException('Moving quotation and caption must retain their separate line boxes.');
}
if (!str_contains($result['serialized_blocks'], 'blocks-engine-synthetic-paragraph')) {
    throw new RuntimeException('Quotation RichText must remain one synthetic paragraph.');
}
$static = $compile(str_replace('animation:slide 20s linear infinite', '', $html));
$staticCss = implode("\n", array_column($static['assets'], 'content'));
if (preg_match('/>p\{[^}]*margin:0 0 12px/', $staticCss)) {
    throw new RuntimeException('Static quotation presentation must not change.');
}
echo "Moving figure quotation line boxes passed\n";
