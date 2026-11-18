<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// A flex source control (`<a class="flex gap-2.5"><span class="flex">IMB</span>
// <span>Catering</span></a>`) becomes a core/button whose carrier lands on the
// `.wp-block-button` wrapper. Its children render inside the link, so the link
// must be the flex container too, or the items flow inline as "IMBCatering"
// (#5860).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
$carrier = static function (array $desktop, array $mobile, string $block) use ($viewports): string {
    $state = new LayoutGeometryState();
    $observations = array('desktop' => array('used_styles' => $desktop), 'mobile' => array('used_styles' => array_replace($desktop, $mobile)));
    $attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $viewports, $state, array(), $block);
    return $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
};
$flex = array('display' => 'flex', 'gap' => '10px', 'flex-direction' => 'row', 'align-items' => 'center', 'width' => '166px');

$css = $carrier($flex, array('gap' => '6px'), 'core/button');
$assert(1 === preg_match('/\.(be-inline-geometry-[0-9a-f]+)\.\1>\.wp-block-button__link\{display:flex;gap:10px;flex-direction:row;align-items:center\}/', $css), 'The link establishes the observed flex context: ' . $css);
$assert(1 === preg_match('/@media \(max-width:390px\)\{\.(be-inline-geometry-[0-9a-f]+)\.\1>\.wp-block-button__link\{gap:6px\}\}/', $css), 'Narrower captures restate the link container delta: ' . $css);
$assert(!preg_match('/__link\{[^}]*width/', $css), 'Box sizing stays with the wrapper carrier: ' . $css);

$css = $carrier(array('display' => 'inline-block', 'width' => '166px'), array(), 'core/button');
$assert(!str_contains($css, 'wp-block-button__link'), 'A control that is no container leaves the link alone: ' . $css);

$css = $carrier($flex, array(), 'core/group');
$assert(!str_contains($css, 'wp-block-button__link'), 'Only core/button carriers address the link: ' . $css);

echo "Box-tree button link container carrier passed\n";
