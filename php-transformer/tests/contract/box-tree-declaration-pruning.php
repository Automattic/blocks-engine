<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeDeclarationPruner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;

// An engine carrier declares only what changes rendering relative to the
// cascade it lands in (#6344). Each row observes a box whose used styles are
// Chrome's computed initial values plus `styles`, below a parent observed as
// `parent`, and names declarations that must and must not reach the carrier.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$initial = array(
    'display' => 'block', 'position' => 'static', 'box-sizing' => 'content-box', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'row-gap' => 'normal', 'column-gap' => 'normal', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap',
    'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal',
    'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto', 'align-self' => 'auto', 'order' => '0',
    'grid-template-columns' => 'none', 'grid-template-rows' => 'none', 'grid-auto-columns' => 'auto', 'grid-auto-rows' => 'auto', 'grid-auto-flow' => 'row',
    'grid-column-start' => 'auto', 'grid-column-end' => 'auto', 'grid-row-start' => 'auto', 'grid-row-end' => 'auto', 'justify-items' => 'normal', 'justify-self' => 'auto',
    'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
    'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 3px', 'box-shadow' => 'none', 'mask-image' => 'none', 'mask-mode' => 'match-source',
    'filter' => 'none', 'opacity' => '1', 'mix-blend-mode' => 'normal', 'transform' => 'none', 'z-index' => 'auto',
    'width' => '320px', 'height' => '40px', 'min-width' => 'auto', 'max-width' => 'none', 'min-height' => 'auto', 'max-height' => 'none',
    'animation-name' => 'none', 'transition-property' => 'all', 'transition-duration' => '0s',
    'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'font-weight' => '400', 'font-style' => 'normal', 'line-height' => 'normal',
    'letter-spacing' => 'normal', 'word-spacing' => '0px', 'text-align' => 'start', 'text-transform' => 'none', 'text-indent' => '0px',
    'text-decoration-line' => 'none', 'text-decoration-color' => 'rgb(0, 0, 0)', 'text-decoration-style' => 'solid', 'text-shadow' => 'none',
    'white-space' => 'normal', 'word-break' => 'normal', 'overflow-wrap' => 'normal', 'text-overflow' => 'clip', 'vertical-align' => 'baseline',
    'list-style-type' => 'disc', 'list-style-position' => 'outside', 'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto',
    'float' => 'none', 'clear' => 'none', 'visibility' => 'visible', 'aspect-ratio' => 'auto', 'object-fit' => 'fill', 'object-position' => '50% 50%',
    'transform-origin' => '160px 20px', 'clip-path' => 'none', 'isolation' => 'auto', 'backdrop-filter' => 'none', 'pointer-events' => 'auto', 'cursor' => 'auto',
    'background-position-x' => '0%', 'background-position-y' => '0%', 'background-size' => 'auto', 'background-repeat' => 'repeat',
    'background-clip' => 'border-box', 'background-origin' => 'padding-box', 'background-attachment' => 'scroll', 'background-blend-mode' => 'normal',
);
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));

$carrier = static function (array $styles, array $parent = array(), string $block = 'core/group', array $mobile = array(), ?LayoutGeometryState $state = null) use ($initial, $viewports): array {
    $state ??= new LayoutGeometryState();
    $observations = array('desktop' => array('used_styles' => array_replace($initial, $styles)));
    $parents = array('desktop' => array('used_styles' => array_replace($initial, $parent)));
    if (array() !== $mobile) {
        $observations['mobile'] = array('used_styles' => array_replace($initial, $styles, $mobile));
        $parents['mobile'] = $parents['desktop'];
    }
    $attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $viewports, $state, $parents, $block);
    $class = (string) ($attrs['className'] ?? '');
    // Declarations reach the page through the carrier rule or block style attributes.
    $inline = (new StyleAttributeMapper())->serialize(is_array($attrs['style'] ?? null) ? $attrs['style'] : array())['style'];
    return array('class' => $class, 'css' => ('' === $class ? '' : $state->cssForSerializedBlocks($class)) . "\n" . str_replace(': ', ':', $inline));
};

$rows = array(
    // CSS initial values that WordPress base styles leave alone.
    array('initial longhands are omitted', array(), array(), 'core/group', array('display:block', 'width:320px'),
        array('flex-direction', 'flex-grow', 'grid-auto-flow', 'border-top', 'outline', 'mask-mode', 'transition-property', 'animation-name', 'object-position', 'text-overflow', 'vertical-align', 'background-clip', 'clip-path', 'min-width', 'top:')),
    array('non-initial longhands are kept', array('opacity' => '0.5', 'border-top' => '1px solid rgb(255, 0, 0)', 'border-radius' => '4px'), array(), 'core/group', array('opacity:0.5', 'border-top:1px solid rgb(255, 0, 0)', 'border-radius:4px'), array()),
    array('a zero-width border paints nothing', array('border-left' => '0px solid rgba(0, 0, 0, 0)'), array(), 'core/group', array(), array('border-left')),
    array('box model and sizing stay for user-agent and WordPress block spacing', array(), array(), 'core/paragraph', array('margin-top:0px', 'padding-left:0px', 'max-width:none'), array()),
    // Initial values WordPress base styles would set otherwise on the block.
    array('a separator keeps its absent border over core styles', array(), array(), 'core/separator', array('border-width:0px;border-style:none;border-color:rgb(0, 0, 0)'), array()),
    array('a cover keeps its observed minimum height over core styles', array('min-height' => '0px'), array(), 'core/cover', array('min-height:0px'), array()),
    array('a column keeps its flex sizing over core styles', array(), array('display' => 'flex'), 'core/column', array('flex-grow:0', 'flex-basis:auto', 'min-width:auto'), array()),
    // Inherited properties against the observed parent.
    array('inherited values equal to the parent are omitted', array(), array(), 'core/group', array(), array('font-family', 'color:', 'pointer-events')),
    array('inherited values that differ from the parent are kept', array('pointer-events' => 'auto', 'font-family' => 'Inter'), array('pointer-events' => 'none'), 'core/group', array('pointer-events:auto'), array()),
    // The block editor resets every block wrapper to pointer-events:auto, so an
    // inherited none never reaches it there and the layer swallows clicks (#5920).
    array('a fixed layer keeps the pointer pass-through equal to the parent', array('position' => 'fixed', 'pointer-events' => 'none'), array('pointer-events' => 'none'), 'core/group', array('pointer-events:none'), array()),
    array('an in-flow box inherits the pointer pass-through equal to the parent', array('pointer-events' => 'none'), array('pointer-events' => 'none'), 'core/group', array(), array('pointer-events')),
    array('a paragraph inherits typography equal to the parent', array(), array(), 'core/paragraph', array(), array('font-size', 'font-weight', 'color:')),
    // An element with its own user-agent or theme.json default would not inherit the parent's value.
    array('a heading keeps typography equal to the parent over its heading defaults', array(), array(), 'core/heading', array('font-size:16px', 'font-weight:400', 'font-family', 'line-height', 'color:'), array('pointer-events', 'white-space')),
    array('a button keeps control typography equal to the parent', array(), array(), 'core/button', array('font-size:16px', 'font-weight:400', 'color:'), array()),
    array('a code block keeps its monospace-reset font equal to the parent', array(), array(), 'core/code', array('font-family:Arial', 'white-space:normal'), array('font-weight')),
    array('a list keeps its marker style equal to the parent', array(), array(), 'core/list', array('list-style-type:disc'), array('font-size')),
    array('a transparent text shadow inherits like none', array('text-shadow' => 'rgba(0, 0, 0, 0) 0px 0px 0px, rgba(0, 0, 0, 0) 0px 0px 0px'), array(), 'core/group', array(), array('text-shadow')),
    // Longhands that do not apply to the box.
    array('flex container longhands need a flex box', array('justify-content' => 'normal'), array(), 'core/group', array(), array('flex-wrap', 'justify-content', 'row-gap')),
    array('a flex box keeps longhands core flex layout sets', array('display' => 'flex'), array(), 'core/group', array('flex-wrap:nowrap', 'align-items:normal', 'gap:normal'), array('align-content', 'grid-auto-flow')),
    array('a grid box keeps its template and gaps', array('display' => 'grid', 'grid-template-rows' => '40px'), array(), 'core/group', array('grid-template-columns:none', 'grid-template-rows:40px', 'gap:normal'), array('grid-auto-flow', 'justify-items', 'flex-wrap')),
    array('item longhands need a flex or grid parent', array('flex-basis' => '50%'), array(), 'core/group', array(), array('flex-basis', 'align-self', 'grid-column-start')),
    array('grid items keep placement but drop flex sizing', array('flex-basis' => '50%', 'grid-column-start' => '2', 'min-width' => '0px'), array('display' => 'grid'), 'core/group', array('grid-column-start:2', 'min-width:0px'), array('flex-basis', 'grid-row-start', 'order')),
    array('a zero minimum height resolves to zero in block flow', array('min-height' => '0px'), array('display' => 'block'), 'core/group', array(), array('min-height')),
    // With an aspect ratio `min-height: auto` is the content minimum and grows the box.
    array('a ratio-sized box keeps its zero minimum height in block flow', array('min-height' => '0px', 'aspect-ratio' => '1 / 0.9'), array('display' => 'block'), 'core/group', array('min-height:0px', 'aspect-ratio:1 / 0.9'), array()),
    array('offsets need a positioned box', array('top' => '12px'), array(), 'core/group', array(), array('top:')),
    array('zero offsets do not move a relative box', array('position' => 'relative', 'top' => '0px', 'left' => '4px'), array(), 'core/group', array('position:relative', 'left:4px'), array('top:')),
    array('an absolute box keeps zero offsets', array('position' => 'absolute', 'top' => '0px'), array(), 'core/group', array('position:absolute', 'top:0px'), array()),
    array('transform origin needs a transform', array(), array(), 'core/group', array(), array('transform-origin')),
    array('a transformed box keeps its origin', array('transform' => 'matrix(1, 0, 0, 1, 4, 0)'), array(), 'core/group', array('transform-origin:160px 20px'), array()),
    array('decoration color and style need a decoration line', array('text-decoration-color' => 'rgb(255, 0, 0)'), array(), 'core/group', array(), array('text-decoration')),
    array('background layers need a background image', array('background-size' => 'cover'), array(), 'core/group', array(), array('background-size', 'background-repeat')),
    array('an image background keeps its layer longhands', array('background-image' => 'url("a.png")', 'background-size' => 'cover'), array(), 'core/group', array('background-size:cover', 'background-repeat:repeat'), array()),
    array('a hidden box keeps its observed styles for revealed states', array('display' => 'none', 'opacity' => '0.5'), array(), 'core/group', array('display:none', 'opacity:0.5'), array('flex-wrap')),
);
// Matches a declaration or property prefix only at a property boundary.
$declares = static fn(string $css, string $prefix): bool => 1 === preg_match('/(?<![a-z-])' . preg_quote($prefix, '/') . '/', $css);
foreach ($rows as [$label, $styles, $parent, $block, $kept, $pruned]) {
    $css = $carrier($styles, $parent, $block)['css'];
    foreach ($kept as $declaration) $assert($declares($css, $declaration), "$label: keeps `$declaration`.");
    foreach ($pruned as $property) $assert(!$declares($css, $property), "$label: omits `$property`.");
}

// Narrower viewports reset what the wider rule declared but they prune.
$css = $carrier(array('display' => 'flex', 'opacity' => '0.5', 'color' => 'rgb(255, 0, 0)', 'flex-direction' => 'column'), array(), 'core/group', array('display' => 'block', 'opacity' => '1', 'color' => 'rgb(0, 0, 0)', 'flex-direction' => 'row'))['css'];
$media = substr($css, (int) strpos($css, '@media (max-width:390px)'));
$assert($declares($media, 'opacity:1'), 'A narrower initial value resets the wider declaration to its observed value.');
$assert($declares($media, 'color:inherit'), 'A narrower value equal to the parent resets to inheritance.');
$assert(!str_contains($media, 'flex-direction'), 'A longhand that no longer applies is not reset.');

// One rule per distinct declaration set, not per node.
$state = new LayoutGeometryState();
$first = $carrier(array('opacity' => '0.5'), array(), 'core/group', array(), $state);
$second = $carrier(array('opacity' => '0.5', 'transform-origin' => '1px 2px'), array(), 'core/group', array(), $state);
$third = $carrier(array('opacity' => '0.25'), array(), 'core/group', array(), $state);
$assert($first['class'] === $second['class'], 'Nodes with the same declarations share one carrier.');
$assert($first['class'] !== $third['class'], 'Nodes with different declarations get distinct carriers.');
$assert(1 === substr_count($state->cssForSerializedBlocks($first['class'] . ' ' . $second['class']), 'opacity:0.5'), 'A shared carrier emits its rule once.');

// Inline elements with user-agent defaults keep inherited values equal to the parent.
$pruner = new BoxTreeDeclarationPruner();
$same = array('font-weight' => '500', 'font-size' => '16px', 'font-style' => 'normal', 'color' => 'rgb(0, 0, 0)');
foreach (array('strong' => 'font-weight', 'b' => 'font-weight', 'small' => 'font-size', 'em' => 'font-style', 'a' => 'color', 'h1' => 'font-size') as $tag => $property) {
    $status = $pruner->classify($same, $same, '', $tag);
    $assert(BoxTreeDeclarationPruner::KEPT === $status[$property], "A `$tag` keeps `$property` equal to its parent.");
}
$status = $pruner->classify($same, $same, '', 'strong');
$assert(BoxTreeDeclarationPruner::INHERITED === $status['font-size'], 'A `strong` still inherits properties it has no default for.');
$assert(BoxTreeDeclarationPruner::INHERITED === $pruner->classify($same, $same, '', 'div')['font-weight'], 'A `div` inherits its weight from the parent.');

// Block attributes serialize inline and would beat every @media carrier rule,
// so a value that varies by viewport stays entirely in the carrier (#6377).
$state = new LayoutGeometryState();
$attrs = (new BoxTreeStyleProjector())->project(array('level' => 1), array(
    'desktop' => array('used_styles' => array_replace($initial, array('font-size' => '270px', 'line-height' => '216px', 'font-weight' => '500'))),
    'mobile' => array('used_styles' => array_replace($initial, array('font-size' => '75px', 'line-height' => '60px', 'font-weight' => '500'))),
), $viewports, $state, array('desktop' => array('used_styles' => $initial), 'mobile' => array('used_styles' => $initial)), 'core/heading');
$inline = (new StyleAttributeMapper())->serialize(is_array($attrs['style'] ?? null) ? $attrs['style'] : array())['style'];
$rule = $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
$media = substr($rule, (int) strpos($rule, '@media (max-width:390px)'));
$assert(!str_contains($inline, 'font-size') && !str_contains($inline, 'line-height'), 'A font size that varies by viewport is not a block attribute.');
$assert(str_contains($inline, 'font-weight'), 'A font weight observed identically in every viewport stays a block attribute.');
$assert($declares(substr($rule, 0, (int) strpos($rule, '@media')), 'font-size:270px'), 'The carrier base rule declares the widest font size.');
$assert($declares($media, 'font-size:75px') && $declares($media, 'line-height:60px'), 'The carrier @media rule overrides the font size on mobile.');
