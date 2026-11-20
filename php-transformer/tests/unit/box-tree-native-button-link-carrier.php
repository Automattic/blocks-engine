<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// A source `<button>` lowers to core/button with tagName "button", which saves
// its link as `<button class="wp-block-button__link">`. The box-tree carrier
// holding the control's observed presentation (a Wix skip button's
// opacity:0, #5860) must target that element, not an `<a>` the block never
// renders, or the hidden control paints its label.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900));
$observations = array('desktop' => array('used_styles' => array('display' => 'block', 'opacity' => '0', 'width' => '48px', 'height' => '0px', 'pointer-events' => 'none')));
$carrier = static function (string $block, array $attrs, string $sourceTag) use ($viewports, $observations): string {
    $state = new LayoutGeometryState();
    $attrs = (new BoxTreeStyleProjector())->project($attrs, $observations, $viewports, $state, array(), $block, array(), array(), $sourceTag);
    return $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
};

$css = $carrier('core/button', array('tagName' => 'button', 'text' => 'Skip to Main Content'), 'button');
$assert(1 === preg_match('/>\s*\.wp-block-button__link\{[^}]*opacity:0/', $css), 'A native button carrier styles the rendered <button> by its class: ' . $css);
$assert(!str_contains($css, 'a.wp-block-button__link'), 'A native button carrier does not target a link core/button never renders: ' . $css);

$css = $carrier('core/button', array('text' => 'Read more', 'url' => '/more/'), 'a');
$assert(1 === preg_match('/>\s*a\.wp-block-button__link\{[^}]*opacity:0/', $css), 'A link button carrier still styles its <a>: ' . $css);

// The core/buttons wrapper cannot see its child's tagName: a source button
// may be saved as either element.
$css = $carrier('core/buttons', array(), 'button');
$assert(1 === preg_match('/>\s*\.wp-block-button\s*>\s*\.wp-block-button__link\{[^}]*opacity:0/', $css), 'A wrapper of a source button styles whichever link core/button saved: ' . $css);
$css = $carrier('core/buttons', array(), 'a');
$assert(1 === preg_match('/>\s*\.wp-block-button\s*>\s*a\.wp-block-button__link\{/', $css), 'A wrapper of a source link still styles its <a>: ' . $css);

echo "Box-tree native button link carrier passed\n";
