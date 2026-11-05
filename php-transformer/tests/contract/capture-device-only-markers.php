<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Complete minimal capture from issue #4530: both variants share one document.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-only-markers.html');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = (string) $plan['pages'][0]['canonical_block_markup'];
$markup .= implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$assert(! str_contains($markup, '<!-- wp:html'), 'Device variants must lower natively, not to core/html.');
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
foreach (array('M' => 'data-blocks-engine-capture-device-only', 'D' => 'data-blocks-engine-capture-device-hide') as $id => $attribute) {
    $element = $document->getElementById($id);
    $assert($element instanceof DOMElement && 'mobile' === $element->getAttribute($attribute), '#' . $id . ' retains ' . $attribute . '="mobile" on the emitted element.');
    $assert('div' === $element->tagName, '#' . $id . ' retains its div host.');
    $assert(1 === substr_count($markup, 'id="' . $id . '"'), '#' . $id . ' retains exactly one anchor.');
    $assert($document->getElementById('H' . $id)?->parentNode?->isSameNode($element) ?? false, '#' . $id . ' retains its header child.');
}
$css = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $css .= (string) ($asset['content'] ?? '');
    }
}
$rule = 'html:not([data-blocks-engine-capture-selected-document]) [data-blocks-engine-capture-device-only]:not([data-blocks-engine-capture-device-only="desktop"]){display:none!important}';
$assert(str_contains($css, $rule), 'Capture device visibility rule still ships in frontend CSS.');
echo "Capture device-only/hide markers and visibility CSS retained.\n";
