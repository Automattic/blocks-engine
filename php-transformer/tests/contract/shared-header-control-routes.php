<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$fixture = require dirname(__DIR__) . '/fixtures/shared-header-control-routes.php';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
foreach (array('active snapshots' => array(true, true), 'identical snapshots' => array(true, false), 'no snapshots' => array(false, true)) as $variant => $options) {
    $result = (new ArtifactCompiler())->compile($fixture(...$options))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), $variant . ': both routes share one header.');
    $runtime = new Runtime();
    $header = $runtime->renderBlocks($runtime->parseBlocks($headers[0]['canonical_block_markup']));
    foreach ($plan['pages'] as $page) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<html class="' . $page['document_metadata']['root_attributes']['class'] . '"><body>' . $header . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $brand = $xpath->query('//a[.//img]')->item(0);
        $cta = $xpath->query('//a[contains(concat(" ",normalize-space(@class)," ")," wp-block-button__link ")][contains(.,"Free Estimate")]')->item(0);
        $assert($brand instanceof DOMElement && $cta instanceof DOMElement, 'Brand and CTA retain their links.');
        $hits = array('brand' => false, 'cta' => false, 'mobile' => false);
        $responsiveHide = false;
        foreach ($plan['assets'] as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            if (preg_match('/@media\\s*\\(max-width:\\s*980px\\)\\s*\\{[^@]*?blocks-engine-control-[^@]*?display:none/s', $asset['content'])) $responsiveHide = true;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($brand, $cta, &$hits): void {
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {

                    $parsed = CssSelectorMatcher::parse($selector);
                    if (CssSelectorMatcher::matches($brand, $parsed)['matches'] && str_contains($body, 'display:flex')) $hits['brand'] = true;
                    if (CssSelectorMatcher::matches($cta, $parsed)['matches']) {
                        if (str_contains($body, 'display:inline-flex')) $hits['cta'] = true;
                        if (str_contains($body, 'display:none')) $hits['mobile'] = true;
                    }
                }
            });
        }
        $assert($responsiveHide, $variant . ': CTA hiding retains its source breakpoint.');
        $assert(!in_array(false, $hits, true), $variant . ' ' . $page['source_path'] . ': brand flex, CTA layout and responsive hiding must match the shared controls: ' . json_encode($hits));
    }
}
echo "Shared header control routes contract passed.\n";
