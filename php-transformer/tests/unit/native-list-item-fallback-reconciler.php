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

$sourceDocument = new DOMDocument();
$sourceDocument->loadHTML('<?xml encoding="utf-8" ?><body>' . $drawer . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$projectedItems = '';
$nativeSourceSelectors = array();
foreach ($sourceDocument->getElementsByTagName('li') as $item) {
    $projectedItems .= $sourceDocument->saveHTML($item);
    $nativeSourceSelectors[] = SourceDom::elementSelector($item);
}
$projectedDocument = new DOMDocument();
$projectedDocument->loadHTML('<?xml encoding="utf-8" ?><body><dialog>' . $projectedItems . '</dialog></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$fallbacks = array();
$index = 0;
foreach ($projectedDocument->getElementsByTagName('li') as $item) {
    ++$index;
    $projectedSelector = 'dialog:nth-of-type(1) > li:nth-of-type(' . $index . ')';
    $fallbacks[] = array(
        'diagnostic_code' => 'html_unsupported_element',
        'tag' => 'li',
        'selector' => $projectedSelector,
        'html' => $projectedDocument->saveHTML($item),
        'conversion_classification' => 'unsupported_loss',
    );
}
$fallbacks[] = array(
    'diagnostic_code' => 'html_unsupported_element',
    'tag' => 'li',
    'selector' => 'dialog:nth-of-type(1) > li:nth-of-type(6)',
    'html' => '<li role="menuitem"><a href="/not-emitted">Unmatched item</a></li>',
    'conversion_classification' => 'unsupported_loss',
);
$fallbacks[] = array(
    'diagnostic_code' => 'html_unsupported_element',
    'tag' => 'li',
    'selector' => 'dialog:nth-of-type(1) > li:nth-of-type(7)',
    'html' => '<li role="menuitem"><a href="/item-1">Home</a><custom-runtime-control></custom-runtime-control></li>',
    'conversion_classification' => 'unsupported_loss',
);

$fallbacks[] = $result['fallbacks'][0];
$nativeListItemMarkup = array_map(static fn (array $item): string => (string) ($item['attrs']['content'] ?? ''), $nativeItems);
if ($nativeSourceSelectors === array_column(array_slice($fallbacks, 0, 5), 'selector')) {
    fwrite(STDERR, "The dialog projection fixture must exercise selectors that differ from native source provenance.\n");
    exit(1);
}

NativeListItemFallbackReconciler::reconcile($fallbacks, $nativeListItemMarkup);

if (
    3 !== FallbackDiagnostic::countableFallbackCount($fallbacks)
    || 'native_conversion' !== ($fallbacks[0]['conversion_classification'] ?? '')
    || 'unsupported_loss' !== ($fallbacks[5]['conversion_classification'] ?? '')
    || 'unsupported_loss' !== ($fallbacks[6]['conversion_classification'] ?? '')
    || 'applet' !== ($fallbacks[7]['tag'] ?? '')
) {
    fwrite(STDERR, "Captured-dialog reconciliation must resolve five native links and retain unmatched item and unsupported descendant findings: " . json_encode(array('count' => FallbackDiagnostic::countableFallbackCount($fallbacks), 'items' => array_column($fallbacks, 'conversion_classification'), 'native' => $nativeListItemMarkup[0] ?? '', 'fallback' => $fallbacks[0]['html'] ?? '')) . "\n");
    exit(1);
}

echo "Captured-dialog list-item fallback reconciliation: five links resolved; unmatched item and unsupported descendant retained\n";
