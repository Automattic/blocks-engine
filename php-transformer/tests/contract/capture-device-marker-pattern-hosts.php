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

// Keeping a marked host off the buttons recognizer must not stop other
// recognizers from producing their native widgets for marked containers.
$compileBody = static function (string $body): string {
    $page = '<!DOCTYPE html><html><head><title>T</title></head><body><div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-device-document="desktop" data-blocks-engine-capture-document-scope="">' . $body . '</div></body></html>';
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page)))->toWordPressSitePlanView()['wordpress_site_plan'];
    return (string) $plan['pages'][0]['canonical_block_markup'] . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
};
$marker = 'data-blocks-engine-capture-device-only="mobile"';
$tabs = $compileBody('<div class="tabs" ' . $marker . '><div role="tablist"><button role="tab" aria-controls="p1" aria-selected="true">One</button><button role="tab" aria-controls="p2" aria-selected="false">Two</button></div><div id="p1" role="tabpanel"><p>First panel</p></div><div id="p2" role="tabpanel" hidden><p>Second panel</p></div></div>');
$assert(str_contains($tabs, '<!-- wp:tabs'), 'A marked tabs container still lowers to native tabs. Got: ' . $tabs);
$social = $compileBody('<div class="social-links" ' . $marker . '><a href="https://github.com/example">GitHub</a><a href="https://instagram.com/example">Instagram</a></div>');
$assert(str_contains($social, '<!-- wp:social-links'), 'A marked social row still lowers to native social links. Got: ' . $social);
echo "Capture device markers survive on pattern-recognized hosts.\n";
