<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// Box-tree evidence records every side and axis as its own longhand. A carrier
// must not restate margin-top/right/bottom/left, grid-row-start/end, ... when
// one shorthand sets exactly those longhands (#6145): on a Wix home page these
// families were a tenth of a 110 KB engine stylesheet.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844));
$carrier = static function (array $desktop, array $mobile = array()) use ($viewports): string {
    $state = new LayoutGeometryState();
    $observations = array('desktop' => array('used_styles' => $desktop));
    if (array() !== $mobile) $observations['mobile'] = array('used_styles' => array_replace($desktop, $mobile));
    $attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $viewports, $state, array('desktop' => array('used_styles' => array('display' => 'grid')), 'mobile' => array('used_styles' => array('display' => 'grid'))), 'core/group');
    return $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
};
$box = array(
    'display' => 'grid', 'position' => 'absolute',
    'padding-top' => '0px', 'padding-right' => '4px', 'padding-bottom' => '0px', 'padding-left' => '4px',
    'margin-top' => 'max(0.5px,0.2 * (100vw - 0px))', 'margin-right' => '0%', 'margin-bottom' => '0%', 'margin-left' => '4%',
    'row-gap' => 'normal', 'column-gap' => 'normal', 'overflow-x' => 'clip', 'overflow-y' => 'hidden',
    'align-self' => 'start', 'grid-template-columns' => '1fr',
    'grid-column-start' => '1', 'grid-column-end' => 'span 2', 'grid-row-start' => '1', 'grid-row-end' => '2',
    'justify-self' => 'stretch', 'width' => '100%',
    'top' => '0px', 'right' => '10px', 'bottom' => '10px', 'left' => '0px',
);

// Spacing observed identically at every capture becomes a block attribute,
// so the narrower capture varies each family to keep it in the carrier.
$narrow = array(
    'padding-top' => '1px', 'padding-right' => '2px', 'padding-bottom' => '1px', 'padding-left' => '2px',
    'margin-top' => '1%', 'margin-right' => '1%', 'margin-bottom' => '1%', 'margin-left' => '1%',
    'top' => '1px', 'right' => '1px', 'bottom' => '1px', 'left' => '1px',
);
$css = $carrier($box, $narrow);
foreach (array(
    'padding:0px 4px', 'margin:max(0.5px,0.2 * (100vw - 0px)) 0% 0% 4%', 'gap:normal', 'overflow:clip hidden',
    'grid-column:1 / span 2', 'grid-row:1 / 2', 'inset:0px 10px 10px 0px', 'place-self:start stretch',
) as $shorthand) {
    $assert(str_contains($css, $shorthand), "A full longhand family folds into `$shorthand`: " . $css);
}
$assert(!preg_match('/(?<![a-z-])(?:margin|padding)-(?:top|right|bottom|left):|(?<![a-z-])(?:row|column)-gap:|overflow-[xy]:|grid-(?:row|column)-(?:start|end):|(?<![a-z-])(?:top|right|bottom|left):|(?<![a-z-])(?:align|justify)-self:/', $css), 'No folded longhand is restated: ' . $css);
$assert(str_contains($css, '{display:grid;position:absolute;padding:0px 4px;margin:'), 'A shorthand takes its first longhand\'s place: ' . $css);

// A narrower capture that changes only some sides overrides only those sides.
$css = $carrier($box, array_replace($narrow, array('margin-top' => $box['margin-top'], 'margin-bottom' => $box['margin-bottom'])));
$assert(1 === preg_match('/@media \(max-width:402px\)\{[^{]*\{[^}]*margin-right:1%;margin-left:1%/', $css) && !str_contains($css, 'margin:') && str_contains($css, 'padding:1px 2px;'), 'A per-viewport side change stays a side override after the base shorthand: ' . $css);

// A partial family, a substitution that may expand to several tokens, or a
// property overlapping the family between its longhands keeps the longhands.
$varied = static fn(array $styles): array => array_map(static fn(string $value): string => str_ends_with($value, 'px') ? '3px' : str_replace('--p', '--q', $value), $styles);
$partial = array('display' => 'block', 'margin-top' => '1px', 'margin-bottom' => '1px', 'padding-top' => 'var(--p)', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px');
$css = $carrier($partial, $varied($partial));
$assert(str_contains($css, 'margin-top:1px') && str_contains($css, 'padding-top:var(--p)') && !str_contains($css, 'margin:') && !str_contains($css, 'padding:'), 'Partial or substituted families stay as longhands: ' . $css);
$overlap = array('position' => 'absolute', 'top' => '0px', 'inset-inline-start' => '2px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px');
$css = $carrier($overlap, $varied($overlap));
$assert(str_contains($css, 'top:0px') && !str_contains($css, 'inset:'), 'An overlapping logical offset between the physical offsets keeps them in order: ' . $css);

echo "Box-tree side shorthand carrier passed\n";
