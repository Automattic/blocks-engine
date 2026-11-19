<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// A box-tree core/button keeps its look in a carrier rule on its link (#6708).
// A core/button with `tagName: button` renders `<button class="wp-block-button__link">`,
// so the carrier must target that element, not `a.wp-block-button__link` (#6787).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
$styles = array('font-family' => 'Helvetica', 'font-size' => '14px', 'color' => 'rgb(17, 17, 17)', 'background-color' => 'rgb(255, 255, 255)', 'padding-top' => '4px', 'padding-right' => '8px', 'padding-bottom' => '4px', 'padding-left' => '8px');
$observations = array('desktop' => array('used_styles' => $styles), 'mobile' => array('used_styles' => array_replace($styles, array('font-size' => '12px'))));
$parents = array('desktop' => array('used_styles' => array('display' => 'block')), 'mobile' => array('used_styles' => array('display' => 'block')));
$carrier = static function (string $blockName, array $attrs, string $sourceTag, array $innerBlocks = array()) use ($observations, $viewports, $parents): string {
    $state = new LayoutGeometryState();
    $projected = (new BoxTreeStyleProjector())->project($attrs, $observations, $viewports, $state, $parents, $blockName, array(), array(), $sourceTag, array(), $innerBlocks);
    return $state->cssForSerializedBlocks((string) ($projected['className'] ?? ''));
};

foreach (array(
    'link' => array(array('text' => 'Go', 'url' => '/go'), 'a', 'a'),
    'button' => array(array('text' => 'Skip to Main Content', 'tagName' => 'button'), 'button', 'button'),
) as $case => [$attrs, $sourceTag, $tag]) {
    $css = $carrier('core/button', $attrs, $sourceTag);
    $other = 'a' === $tag ? 'button' : 'a';
    $assert(str_contains($css, '>' . $tag . '.wp-block-button__link{') || str_contains($css, '> ' . $tag . '.wp-block-button__link{'), "core/button ($case) carrier targets the emitted <$tag>: " . $css);
    $assert(!str_contains($css, $other . '.wp-block-button__link'), "core/button ($case) carrier does not target <$other>: " . $css);
    $assert(str_contains($css, 'Helvetica') && str_contains($css, '14px'), "core/button ($case) carrier applies the captured font: " . $css);

    $css = $carrier('core/buttons', array(), $sourceTag, array(array('blockName' => 'core/button', 'attrs' => $attrs, 'innerBlocks' => array())));
    $assert(1 === preg_match('/\.wp-block-button\s*>\s*' . $tag . '\.wp-block-button__link\{/', $css), "core/buttons ($case) carrier targets the inner emitted <$tag>: " . $css);
    $assert(!str_contains($css, $other . '.wp-block-button__link'), "core/buttons ($case) carrier does not target <$other>: " . $css);
}

// A reduced-mode source `<button>` becomes a core/button without `tagName`: it renders `<a>`.
$css = $carrier('core/buttons', array(), 'button', array(array('blockName' => 'core/button', 'attrs' => array('text' => 'Go'), 'innerBlocks' => array())));
$assert(1 === preg_match('/\.wp-block-button\s*>\s*a\.wp-block-button__link\{/', $css), 'A tagName-less core/button inside core/buttons keeps the <a> carrier: ' . $css);

echo "Box-tree button tag carrier passed\n";
