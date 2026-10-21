<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

// The first header is considered as navigation before its control makes that
// conversion decline. The second header really does become navigation. Their
// identical title boxes must not share speculative source correspondences.
$html = <<<'HTML'
<style>
.shared-title{letter-spacing:.18em;text-transform:uppercase}
@media (min-width:768px){.shared-title{font-size:33px}}
.visually-hidden{position:absolute;clip:rect(1px,1px,1px,1px);padding:0;border:0;height:1px;width:1px;overflow:hidden}
</style>
<header>
  <div class="nav-wrapper">
    <div><div class="shared-title"><a href="/">Example</a></div></div>
    <div><button><span class="visually-hidden">Open Menu</span></button></div>
  </div>
</header>
<header>
  <nav>
    <div><div class="shared-title"><a href="/">Example</a></div></div>
    <a href="/about">About</a>
  </nav>
</header>
HTML;

$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$assert(str_contains($markup, 'wp-block-group shared-title'), 'the first title remains an editable group');
$assert(str_contains($markup, 'wp:navigation ') && str_contains($markup, 'blocks-engine-navigation-box-'), 'the second title retains its native navigation box');
$assert(str_contains($markup, 'Open Menu') && str_contains($markup, 'About') && 2 === substr_count($markup, 'Example'), 'all source content survives');

$plainTypography = false;
$plainSize = false;
$nativeTypography = false;
$nativeSize = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body, array $conditions) use (&$plainTypography, &$plainSize, &$nativeTypography, &$nativeSize): void {
    $plain = in_array('.shared-title', CssStylesheetTransformer::splitSelectorList($prelude) ?? array(), true);
    $native = str_contains($prelude, '.wp-block-navigation ') && str_contains($prelude, 'blocks-engine-navigation-box-');
    if (str_contains($body, 'letter-spacing:.18em') && str_contains($body, 'text-transform:uppercase')) {
        $plainTypography = $plainTypography || $plain;
        $nativeTypography = $nativeTypography || $native;
    }
    if (str_contains($body, 'font-size:33px') && in_array('@media (min-width:768px)', $conditions, true)) {
        $plainSize = $plainSize || $plain;
        $nativeSize = $nativeSize || $native;
    }
});
$assert($plainTypography && $plainSize, 'the retained title keeps authored spacing, case and desktop size');
$assert($nativeTypography && $nativeSize, 'the converted title keeps the same conditional typography');

$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $markup . '</body></html>');
$title = (new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " shared-title ")]')->item(0);
$desktop = $title instanceof DOMElement
    ? (new StaticCssCascade($document, $css))->resolve($title, array('font-size', 'letter-spacing', 'text-transform'), array('width' => 1440))
    : array();
$assert('33px' === ($desktop['font-size'] ?? '') && '.18em' === ($desktop['letter-spacing'] ?? '') && 'uppercase' === ($desktop['text-transform'] ?? ''), 'the retained title resolves to authored typography at 1440px');

if ($failures) {
    foreach ($failures as $message) fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
echo "navigation shared title typography passed\n";
