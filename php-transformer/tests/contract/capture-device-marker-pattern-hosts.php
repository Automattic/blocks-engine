<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A folded device variant that holds a single link matches the buttons
// recognizer. The marked host must stay the emitted root so the capture
// visibility CSS still has a carrier.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-marker-pattern-hosts.html');
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
$xpath = new DOMXPath($document);
foreach (array(
    'M' => array('data-blocks-engine-capture-device-only', '/call/'),
    'D' => array('data-blocks-engine-capture-device-hide', '/visit/'),
) as $id => [$attribute, $href]) {
    $hosts = $xpath->query('//div[@' . $attribute . '="mobile"][contains(concat(" ", normalize-space(@class), " "), " cta ")]');
    $assert(1 === $hosts->length, '#' . $id . ' keeps ' . $attribute . '="mobile" on exactly one emitted .cta host. Got: ' . $markup);
    $host = $hosts->item(0);
    $links = $xpath->query('.//a[@href="' . $href . '"]', $host);
    $assert(1 === $links->length, '#' . $id . ' keeps its link inside the marked host.');
}
echo "Capture device markers survive on pattern-recognized hosts.\n";
