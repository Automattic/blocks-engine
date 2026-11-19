<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// A lifted author base value that restates the property's initial value is
// pruned like an observed one, unless a narrower band switches it (#6766).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
$used = array(
    'display' => 'block', 'position' => 'static', 'height' => '312px', 'width' => '600px', 'max-width' => '600px',
    'margin-left' => '12px', 'margin-right' => '12px', 'column-gap' => '8px', 'grid-template-columns' => '600px', 'opacity' => '0.5',
);
$parent = array('display' => 'block');
$css = static function (array $fluid, string $block = 'core/group', array $styles = array(), array $attrs = array()) use ($viewports, $used, $parent): string {
    $state = new LayoutGeometryState();
    $styles = array_replace($used, $styles);
    $observations = array('desktop' => array('used_styles' => $styles), 'mobile' => array('used_styles' => $styles));
    $parents = array('desktop' => array('used_styles' => $parent), 'mobile' => array('used_styles' => $parent));
    $out = (new BoxTreeStyleProjector())->project($attrs, $observations, $viewports, $state, $parents, $block, $fluid);
    $class = (string) ($out['className'] ?? '');
    return '' === $class ? '' : $state->cssForSerializedBlocks($class);
};
$declares = static fn(string $css, string $declaration): bool => 1 === preg_match('/(?<![a-z-])' . preg_quote($declaration, '/') . '(?=[;}])/', $css);

$rows = array(
    // label, block, property, lifted bands, base observed styles override, expect base declared
    array('a lifted `height:auto` with no band', 'core/group', 'height', array(array(null, 'auto')), array(), false),
    array('a lifted `margin-right:0%` with no band', 'core/group', 'margin-right', array(array(null, '0%')), array(), false),
    array('a lifted `max-width:none` with no band', 'core/group', 'max-width', array(array(null, 'none')), array(), false),
    array('a lifted `column-gap:normal` on a block box', 'core/group', 'column-gap', array(array(null, 'normal')), array(), false),
    array('a lifted one-track template on a block box', 'core/group', 'grid-template-columns', array(array(null, 'minmax(0px, 1fr)')), array(), false),
    array('a lifted `height:auto` with a narrower band', 'core/group', 'height', array(array(null, 'auto'), array(781.0, '200px')), array(), true),
    array('a lifted `margin-right:0%` with a narrower band', 'core/group', 'margin-right', array(array(null, '0%'), array(781.0, '5%')), array(), true),
    array('a lifted one-track template on a grid box', 'core/group', 'grid-template-columns', array(array(null, '1fr')), array('display' => 'grid'), true),
    array('a lifted `column-gap:normal` on a flex box', 'core/group', 'column-gap', array(array(null, 'normal')), array('display' => 'flex'), true),
    array('a protected column `flex-basis:auto`', 'core/column', 'flex-basis', array(array(null, 'auto')), array('flex-basis' => '300px'), true),
    array('a paragraph `margin-left:0px` over user-agent margins', 'core/paragraph', 'margin-left', array(array(null, '0px')), array(), true),
    array('an image `max-width:none` over core image styles', 'core/image', 'max-width', array(array(null, 'none')), array(), true),
    array('a lifted non-initial `width:50%`', 'core/group', 'width', array(array(null, '50%')), array(), true),
);
foreach ($rows as [$label, $block, $property, $bands, $styles, $kept]) {
    $rule = $css(array($property => $bands), $block, $styles);
    $base = false === strpos($rule, '@media') ? $rule : substr($rule, 0, (int) strpos($rule, '@media'));
    $value = $bands[0][1];
    $assert($kept === $declares($base, $property . ':' . $value), $label . ($kept ? ' keeps its base.' : ' is not emitted.'));
    $assert(!$declares($rule, $property . ':' . array_replace($used, $styles)[$property]), "$label: the observed pixels never return.");
}
$assert($declares($css(array('height' => array(array(null, 'auto')))), 'opacity:0.5'), 'Other declarations stay in the carrier.');
