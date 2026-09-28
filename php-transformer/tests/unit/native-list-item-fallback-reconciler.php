<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\NativeListItemFallbackReconciler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$drawer = '<details><summary>Menu</summary><ul>';
foreach (array('Home', 'About', 'Services', 'Team', 'Projects') as $index => $label) {
    $drawer .= '<li role="menuitem"><a href="/item-' . ($index + 1) . '">' . $label . '</a></li>';
}
$drawer .= '</ul><applet>Unsupported child</applet></details>';
$result = (new HtmlTransformer())->transform($drawer)->toArray();
$nativeItems = array();
$collectNativeItems = static function (array $blocks) use (&$collectNativeItems, &$nativeItems): void {
    foreach ($blocks as $block) {
        if (! is_array($block)) {
            continue;
        }
        if ('core/list-item' === ($block['blockName'] ?? null)) {
            $nativeItems[] = $block;
        }
        $collectNativeItems(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }
};
$collectNativeItems($result['blocks'] ?? array());
if (
    5 !== count($nativeItems)
    || 1 !== count($result['fallbacks'] ?? array())
    || 'applet' !== ($result['fallbacks'][0]['tag'] ?? '')
) {
    fwrite(STDERR, "The captured drawer fixture must emit five native list items and retain its unsupported child finding.\n");
    exit(1);
}

$fallbacks = array();
for ($index = 1; $index <= 5; ++$index) {
    $fallbacks[] = array(
        'diagnostic_code' => 'html_unsupported_element',
        'tag' => 'li',
        'selector' => 'dialog:nth-of-type(1) > li:nth-of-type(' . $index . ')',
        'conversion_classification' => 'unsupported_loss',
    );
}
$fallbacks[] = array(
    'diagnostic_code' => 'html_unsupported_element',
    'tag' => 'legacy-control',
    'selector' => 'dialog:nth-of-type(1) > li:nth-of-type(3) > legacy-control:nth-of-type(1)',
    'conversion_classification' => 'unsupported_loss',
);

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $drawer . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$nativeListItemSelectors = array();
$index = 0;
foreach ($document->getElementsByTagName('li') as $item) {
    if (++$index > 5) {
        break;
    }
    $nativeListItemSelectors[SourceDom::elementSelector($item)] = true;
}
for ($index = 1; $index <= 5; ++$index) {
    // The five fallback rows use the drawer's actual source selectors.
    $fallbacks[$index - 1]['selector'] = array_keys($nativeListItemSelectors)[$index - 1];
}

NativeListItemFallbackReconciler::reconcile($fallbacks, $nativeListItemSelectors);

if (
    1 !== FallbackDiagnostic::countableFallbackCount($fallbacks)
    || 'native_conversion' !== ($fallbacks[0]['conversion_classification'] ?? '')
    || 'unsupported_loss' !== ($fallbacks[5]['conversion_classification'] ?? '')
) {
    fwrite(STDERR, "Native list-item fallback reconciliation did not preserve the unsupported descendant finding.\n");
    exit(1);
}

echo "Native list-item fallback reconciler: five items reconciled; unsupported descendant retained\n";
