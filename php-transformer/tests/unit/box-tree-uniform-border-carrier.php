<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// Box-tree evidence records a border per side. A carrier for a box with the
// same border on every side must not restate that value four times (#6145):
// it declares the twelve longhands once through border-width/style/color.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
$carrier = static function (array $desktop, array $mobile = array()) use ($viewports): string {
    $state = new LayoutGeometryState();
    $base = array('display' => 'flex', 'width' => '166px');
    $observations = array('desktop' => array('used_styles' => $base + $desktop));
    if (array() !== $mobile) $observations['mobile'] = array('used_styles' => array_replace($base + $desktop, $mobile));
    $attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $viewports, $state, array(), 'core/group');
    return $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
};
$color = 'var(--wp--preset--color--accent-2,oklch(0.28 0.008 60))';
$side = '1px solid ' . $color;
$uniform = array('border-top' => $side, 'border-right' => $side, 'border-bottom' => $side, 'border-left' => $side);

$css = $carrier($uniform);
$assert(str_contains($css, 'width:166px;border-width:1px;border-style:solid;border-color:' . $color . '}'), 'A uniform border folds into one declaration per component at its source position: ' . $css);
$assert(1 === substr_count($css, $color), 'The border color is stated once: ' . $css);
$assert(!preg_match('/border-(?:top|right|bottom|left):/', $css), 'No per-side border is restated: ' . $css);
$assert(!str_contains($css, 'border:'), 'The border shorthand, which would reset border-image, is not used: ' . $css);

// A narrower viewport that changes one side overrides only that side.
$css = $carrier($uniform, array('border-bottom' => '2px dashed rgb(255, 0, 0)'));
$assert(str_contains($css, 'border-style:solid') && str_contains($css, '@media (max-width:390px){') && str_contains($css, 'border-bottom:2px dashed rgb(255, 0, 0)'), 'A per-viewport side change stays a side override: ' . $css);

// Sides that differ, or a value that is not one width, style and color, stay per side.
$css = $carrier(array_replace($uniform, array('border-left' => '2px solid ' . $color)));
$assert(4 === preg_match_all('/border-(?:top|right|bottom|left):/', $css) && !str_contains($css, 'border-width'), 'Differing sides stay per side: ' . $css);
$opaque = array_fill_keys(array('border-top', 'border-right', 'border-bottom', 'border-left'), 'var(--edge) solid red');
$css = $carrier($opaque);
$assert(4 === substr_count($css, 'var(--edge) solid red') && !str_contains($css, 'border-width'), 'A substitution that could stand for any component stays per side: ' . $css);

echo "Box-tree uniform border carrier passed\n";
