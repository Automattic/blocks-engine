<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<header><div class="bar">
<ul class="nav"><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/blog/">Blog</a></li></ul>
<select class="menu-select"><option value="#">Navigate to...</option><option value="/">Home</option><option value="/about/">About</option><option value="/blog/">Blog</option></select>
</div></header><main><p>Hi</p></main>';
$css = '.menu-select{display:none!important}@media(max-width:767px){.bar .nav{display:none}.menu-select{display:block!important;box-sizing:border-box;width:100%;height:30px;padding:4px;margin-bottom:2px;border:3px solid red;background:yellow;appearance:none}}';
$compile = static function (string $html, string $css): string {
    return json_encode((new ArtifactCompiler())->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array('website/index.html' => '<html><head><style>' . $css . '</style></head><body>' . $html . '</body></html>'),
    ))->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
};
$result = $compile($html, $css);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
};
$assert(str_contains($result, '\\"overlayMenu\\":\\"mobile\\"'), 'the mirrored jump menu enables Core responsive navigation');
$assert(str_contains($result, '@media(min-width:600px) and (max-width:767px)'), 'the source tablet boundary bridges Core\'s fixed breakpoint');
$assert(str_contains($result, '>.wp-block-navigation__responsive-container-open{display:flex!important}'), 'the native opener remains reachable throughout the collapsed range');
preg_match('/>\\.wp-block-navigation__responsive-container-open\\{([^}]+)\\}/', $result, $opener);
foreach (array('box-sizing:border-box', 'width:100%', 'height:30px', 'padding:4px', 'margin-bottom:2px') as $geometry) {
    $assert(str_contains($opener[1] ?? '', $geometry . '!important'), 'the collapsed opener carries select geometry: ' . $geometry);
}
$assert(!preg_match('/(?:^|;)(?:border[^:]*|background[^:]*|appearance|color):/', $opener[1] ?? ''), 'select paint is not carried onto the Core opener');
$assert(!str_contains($opener[1] ?? '', 'min-height:44px'), 'a usable select height replaces the fallback height');
$compiled = json_decode($result, true)['source_reports']['compiled_site'];
$markup = $compiled['pages'][0]['block_markup'];
$assert(!str_contains($markup, '<select'), 'no duplicate jump menu is emitted');
$assert(!str_contains($markup, 'wp:html'), 'no raw HTML fallback');
$assert(!str_contains($markup, 'blocks-engine-navigation-list-box-'), 'the author list-hide projection is not carried onto the native navigation host');
$assert(str_contains($result, '@media(max-width:599px){.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}}'), 'phone-width navigation host stays visible');
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode(array(
        'source' => '<style>' . $css . '</style>' . $html,
        'markup' => $markup,
        'css' => implode("\n", array_column($compiled['assets'] ?? array(), 'content')),
    ), JSON_THROW_ON_ERROR));
}
$renamed = str_replace(array('menu-select', 'class="nav"', 'bar'), array('alternate', 'class="destinations"', 'row'), str_replace(array('<div class="bar">', '</div></header>'), array('<nav class="bar">', '</nav></header>'), $html));
$renamedCss = str_replace(array('menu-select', 'nav', 'bar'), array('alternate', 'destinations', 'row'), $css);
$assert(str_contains($compile($renamed, $renamedCss), '\\"overlayMenu\\":\\"mobile\\"'), 'recognition does not depend on source class names');
$assert(!str_contains($compile(str_replace('<select ', '<select disabled ', $html), $css), '\\"overlayMenu\\":\\"mobile\\"'), 'a disabled select cannot stand in for navigation');
$assert(!str_contains($compile(str_replace('<select ', '<select multiple ', $html), $css), '\\"overlayMenu\\":\\"mobile\\"'), 'a multiple choice field is not a jump menu');
$assert(!str_contains($compile(str_replace('value="/blog/"', 'value="/other/"', $html), $css), '\\"overlayMenu\\":\\"mobile\\"'), 'unrelated option inventories do not enable an overlay');
$assert(!str_contains($compile($html, '.menu-select{display:none}'), '\\"overlayMenu\\":\\"mobile\\"'), 'matching destinations without a media swap are insufficient');
echo "responsive select navigation passed\n";
