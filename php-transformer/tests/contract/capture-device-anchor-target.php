<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Keep the complete issue #4460 capture, including the capture-owned overlay
// script and the distinct mobile fragment.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-anchor-target.html');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'custom-orders/index.html',
    'files' => array('custom-orders/index.html' => $html),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = (string) $plan['pages'][0]['canonical_block_markup'];
$markup .= implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$assert(! str_contains($markup, '<!-- wp:html'), 'Device capture anchor must lower natively, not to core/html.');
foreach (array('anchors-ltgwp8xp4', 'anchors-ltgwp8xp4--data-blocks-engine-capture-mobile') as $id) {
    $assert(1 === preg_match('/href="[^"#]*#' . preg_quote($id, '/') . '"/', $markup), 'Link retains fragment ' . $id);
    $assert(1 === substr_count($markup, 'id="' . $id . '"'), 'Exactly one native target retains ' . $id);
}
$assert(str_contains($markup, '"data-blocks-engine-capture-device-inline":'), 'Native runtime carrier retains device inline binding.');
echo "Device capture anchors: native targets, desktop/mobile links, and inline binding retained.\n";
