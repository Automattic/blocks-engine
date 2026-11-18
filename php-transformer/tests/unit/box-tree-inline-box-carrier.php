<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005).
// imb-culinary-craft header brand (#6445): `<a class="flex items-center
// gap-2.5">` became a core/button holding a `flex h-11 w-11 border
// rounded-full` badge span and the "Catering" label. The anchor's flex row
// and gap stayed on the button wrapper, not the link that holds the badge,
// and the badge span kept only typography: it collapsed to its text and the
// label rendered 25px left on every route and width.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
$both = static fn(array $styles): array => array('desktop' => array('used_styles' => $styles), 'mobile' => array('used_styles' => $styles));
$text = array('color' => 'rgb(28, 54, 43)', 'font-size' => '16px', 'line-height' => '24px', 'font-family' => 'Inter');
$edge = '1px solid rgba(201, 162, 39, 0.7)';
$anchor = $text + array('display' => 'flex', 'align-items' => 'center', 'row-gap' => '10px', 'column-gap' => '10px', 'width' => 'auto');
$badge = $text + array('display' => 'flex', 'align-items' => 'center', 'justify-content' => 'center', 'width' => '44px', 'height' => '44px', 'padding-top' => '2px', 'padding-right' => '2px', 'padding-bottom' => '2px', 'padding-left' => '2px', 'border-top' => $edge, 'border-right' => $edge, 'border-bottom' => $edge, 'border-left' => $edge, 'border-radius' => '9999px');

// The anchor's own content layout reaches the link core/button paints it as.
$state = new LayoutGeometryState();
$attrs = (new BoxTreeStyleProjector())->project(array(), $both($anchor), $viewports, $state, $both($text + array('display' => 'flex')), 'core/button');
$css = $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
$assert(1 === preg_match('/>\.wp-block-button__link\{([^}]*)\}/', $css, $link), 'A core/button carrier lays out its link: ' . $css);
$assert(str_contains($link[1], 'display:flex') && str_contains($link[1], 'gap:10px') && str_contains($link[1], 'align-items:center'), 'The link restates the anchor\'s flex row and gap: ' . $link[1]);
$assert(!str_contains($link[1], 'width') && !str_contains($link[1], 'color'), 'Only content layout moves to the link: ' . $link[1]);

// Another block keeps its carrier on its own box.
$state = new LayoutGeometryState();
$attrs = (new BoxTreeStyleProjector())->project(array(), $both($anchor), $viewports, $state, $both($text + array('display' => 'flex')), 'core/group');
$assert(!str_contains($state->cssForSerializedBlocks((string) ($attrs['className'] ?? '')), 'wp-block-button__link'), 'A group carrier targets no button link.');

// A RichText inline the author made a flex box keeps its observed box.
$state = new LayoutGeometryState();
$class = (new BoxTreeStyleProjector())->inlineCarrier($both($badge), $viewports, $state, $both($anchor));
$css = $state->cssForSerializedBlocks($class);
$assert('' !== $class, 'A badge span with its own box gets a carrier even with inherited typography.');
foreach (array('display:flex', 'width:44px', 'height:44px', 'justify-content:center', 'border-radius:9999px', 'padding:2px', 'border-width:1px') as $declaration) {
    $assert(str_contains($css, $declaration), "The badge carrier restates $declaration: " . $css);
}
$assert(!preg_match('/(?<![-\w])color:/', $css), 'Color stays with the link and state styles that own it: ' . $css);

// A flex item's blockified label is no author box: typography only.
$state = new LayoutGeometryState();
$label = array('color' => 'rgb(28, 54, 43)', 'font-size' => '18px', 'line-height' => '28px', 'font-family' => 'Inter', 'display' => 'block', 'width' => '75.4px', 'height' => '28px');
$css = $state->cssForSerializedBlocks((new BoxTreeStyleProjector())->inlineCarrier($both($label), $viewports, $state, $both($anchor)));
$assert(str_contains($css, 'font-size:18px') && !str_contains($css, 'display') && !str_contains($css, 'width'), 'A blockified flex item keeps typography only: ' . $css);

echo "Box-tree inline box carrier passed\n";
