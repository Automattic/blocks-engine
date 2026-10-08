<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\NavigationBlockNormalizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$normalizer = new NavigationBlockNormalizer(static fn (string $label): string => trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<body><ul><li id="services"><a>Services</a></li><li id="services"><a>Services</a><div><ul><li><a>Design</a></li></ul></div></li></ul></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$body = $document->getElementsByTagName('body')->item(0);
if ( ! $body instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: unable to create navigation fixture DOM\n");
    exit(1);
}
$normalizer->hydrateDuplicateSubmenus($body);
$items = $body->getElementsByTagName('li');
$assert(2 === $items->item(0)?->getElementsByTagName('a')->length, 'hydrates the shallow duplicate item with the complete submenu tree');

$link = static fn (string $label, string $url): array => array('blockName' => 'core/navigation-link', 'attrs' => array('label' => $label, 'url' => $url), 'innerBlocks' => array());
$navigation = static fn (int $provenanceId, string $label = 'Home'): array => array(
    'blockName' => 'core/navigation',
    '_source_provenance_id' => $provenanceId,
    'innerBlocks' => array($link($label, '/')),
    'innerContent' => array('<nav>', null, '</nav>'),
    'innerHTML' => '<nav></nav>',
);

$sourceProvenance = array(
    1 => array('source_attributes' => array('class' => 'desktop-nav'), 'context' => array('class_names' => array('desktop-nav'))),
    2 => array('source_attributes' => array('class' => 'mobile-nav'), 'context' => array('class_names' => array('mobile-nav'))),
);
$normalized = $normalizer->normalize(array($navigation(2), $navigation(1)), $sourceProvenance, array(2 => true));
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'prefers the visible sibling before duplicate removal');

$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $sourceProvenance, array());
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'removes a mobile duplicate after preserving the first canonical navigation');

$disclosure = static fn (array $children): array => array(
    'blockName' => 'core/details',
    'attrs' => array('className' => 'dla-disclosure'),
    'innerBlocks' => $children,
    'innerContent' => array('<details>', null, '</details>'),
    'innerHTML' => '<details></details>',
);
$normalized = $normalizer->normalize(array($disclosure(array($navigation(1))), $disclosure(array($navigation(2))),), $sourceProvenance, array());
$assert(2 === count($normalized) && 1 === ($normalized[0]['innerBlocks'][0]['_source_provenance_id'] ?? null) && 2 === ($normalized[1]['innerBlocks'][0]['_source_provenance_id'] ?? null), 'keeps responsive navigation variants inside independent native disclosures');

$sourceProvenance[2] = array('source_attributes' => array('class' => 'wsite-menu-default'), 'context' => array('class_names' => array('wsite-menu-default'), 'ancestor_class_names' => array('mobile-nav', 'menu')));
$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $sourceProvenance, array());
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'recognizes a responsive duplicate from its source ancestor identity');

// A visible second menu is page content, not a responsive copy: a breakpoint
// utility on a shared container (`px-container-padding-mobile` on a footer
// grid) must not let the ancestor keyword vocabulary condemn the footer nav
// for repeating the header's destinations.
$footerLikeProvenance = array(
    1 => array('source_attributes' => array('class' => 'hidden md:flex'), 'context' => array('class_names' => array('hidden', 'md:flex'))),
    2 => array(
        'source_attributes' => array('class' => 'flex flex-col gap-3'),
        'context' => array('class_names' => array('flex', 'flex-col', 'gap-3'), 'ancestor_class_names' => array('bg-secondary', 'max-w-7xl', 'px-container-padding-mobile', 'md:grid-cols-3', 'grid')),
    ),
);
$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $footerLikeProvenance, array());
$assert(2 === count($normalized), 'a visible menu stays when a breakpoint utility, not menu identity, carries the keyword');

// One class naming BOTH the breakpoint and the menu (`mobile-menu`) still
// proves the copy — the corroboration lives inside a single token.
$mobileMenuProvenance = array(
    1 => array('source_attributes' => array('class' => 'primary'), 'context' => array('class_names' => array('primary'))),
    2 => array('source_attributes' => array('class' => 'flex flex-col'), 'context' => array('class_names' => array('flex', 'flex-col'), 'ancestor_class_names' => array('mobile-menu')),
    ),
);
$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $mobileMenuProvenance, array());
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'a class naming breakpoint and menu together is still a responsive duplicate');

$group = array(
    'blockName' => 'core/group',
    'innerBlocks' => array($link('One', '/one'), $link('Two', '/two')),
    'innerContent' => array('<div>', null, '</div>'),
    'innerHTML' => '<div></div>',
);
$normalized = $normalizer->normalize(array($group), array(), array());
$assert(array('<div>', null, null, '</div>') === $normalized[0]['innerContent'], 'repairs serialized child placeholders after recursive normalization');

// Independently displayed document variants each need a complete menu. They
// are not alternate controls for a single navigation rendered outside them.
foreach ( array(
    array('data-liberation-desktop-document', 'data-liberation-mobile-document'),
    array('site-document-variant-default', 'site-document-variant-mobile'),
) as $variantClasses ) {
    $variants = array();
    foreach ( $variantClasses as $index => $className ) {
        $variants[] = array(
            'blockName' => 'core/group',
            'attrs' => array('className' => $className),
            'innerBlocks' => array($navigation($index + 1)),
            'innerContent' => array('<div>', null, '</div>'),
            'innerHTML' => '<div></div>',
        );
    }
    foreach ( array($variants, array_reverse($variants)) as $orderedVariants ) {
        $normalized = $normalizer->normalize($orderedVariants, $sourceProvenance, array());
        foreach ( $normalized as $variant ) {
            $assert(1 === count($variant['innerBlocks']), 'retains navigation in independent document ' . $variant['attrs']['className']);
        }
    }
    $variants[1]['innerBlocks'] = array($navigation(1), $navigation(2));
    $normalized = $normalizer->normalize($variants, $sourceProvenance, array());
    $assert(1 === count($normalized[1]['innerBlocks']), 'still reconciles duplicate controls within one document variant');
}

$menu = '<header><nav class="rail" id="site-menu"><ul class="menu">'
    . '<li><a href="/">Home</a></li><li><a href="/contact">Contact</a></li></ul></nav></header>';
$converted = (new HtmlTransformer())->transform(
    '<style>.rail{position:fixed;width:250px}.menu{display:block}'
    . '.data-liberation-mobile-document{display:none}'
    . '@media(max-width:768px){.data-liberation-desktop-document{display:none}.data-liberation-mobile-document{display:block}}</style>'
    . '<div class="data-liberation-desktop-document">' . $menu . '</div>'
    . '<div class="data-liberation-mobile-document">' . $menu . '</div>'
)->toArray();
$markup = (string) ($converted['serialized_blocks'] ?? '');
$assert(2 === substr_count($markup, '<!-- wp:navigation '), 'full conversion serializes an editable navigation for each responsive document');
$assert(2 === substr_count($markup, '"url":"/contact"'), 'both document menus retain the Contact destination through serialization');

// A structurally hidden duplicate that carries none of isMobileDuplicate()'s
// keyword vocabulary (mobile|drawer|offcanvas|overlay|collapsed|hamburger|
// menu-panel|nav-panel) is still recognized and dropped via the
// `sourceBaseHiddenStates` signal, independent of the keyword check.
$sourceProvenanceNoKeywords = array(
    1 => array('source_attributes' => array('class' => 'primary'), 'context' => array('class_names' => array('primary'))),
    2 => array('source_attributes' => array('class' => 'flex flex-col'), 'context' => array('class_names' => array('flex', 'flex-col'), 'ancestor_class_names' => array('fixed', 'inset-0', 'z-40', 'bg-white', 'lg:hidden', 'translate-x-full'))),
);
$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $sourceProvenanceNoKeywords, array(2 => true));
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'a structurally hidden duplicate is dropped even when its class/ancestor identity carries no drawer-style keyword');
$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $sourceProvenanceNoKeywords, array());
$assert(2 === count($normalized), 'the same keyword-free duplicate survives when neither copy is reported as starting hidden');

// End-to-end: a Tailwind off-canvas drawer — `fixed inset-0 … lg:hidden
// translate-x-full` on an ANCESTOR wrapper, nothing hiding the `<nav>` itself
// — duplicates the desktop menu. The desktop nav is hidden BELOW the
// reference viewport (`hidden`) and shown only AT it (`lg:flex`); a
// static-only hidden check would misread that as "hidden" and could drop the
// wrong copy, so this also guards the real desktop nav survives unique.
$offCanvasDrawerHtml = '<style>.hidden{display:none}.flex{display:flex}'
    . '@media(min-width:1024px){.lg\:flex{display:flex}.lg\:hidden{display:none}}</style>'
    . '<header><nav class="hidden lg:flex"><a href="/">Home</a><a href="/about">About</a></nav></header>'
    . '<div class="fixed inset-0 lg:hidden translate-x-full"><nav class="flex"><a href="/">Home</a><a href="/about">About</a></nav></div>';
$offCanvasDrawerResult = (new HtmlTransformer())->transform($offCanvasDrawerHtml)->toArray();
$offCanvasDrawerMarkup = (string) ($offCanvasDrawerResult['serialized_blocks'] ?? '');
$assert(1 === substr_count($offCanvasDrawerMarkup, '<!-- wp:navigation '), 'a Tailwind off-canvas drawer duplicate hidden only via an ancestor and a media query collapses to one navigation block');
$assert(str_contains($offCanvasDrawerMarkup, 'hidden lg:flex'), 'the surviving navigation is the real desktop menu, not the off-canvas duplicate');
$assert(! str_contains($offCanvasDrawerMarkup, 'is-responsive') && false === strpos($offCanvasDrawerMarkup, '"overlayMenu":"mobile"'), 'the surviving desktop navigation is not reclassified as a responsive overlay');

// End-to-end, full-document path: a header desktop nav plus the disclosure's
// mobile dialog copy, and a footer grid whose middle column repeats the same
// destinations under a container carrying the `px-container-padding-mobile`
// utility. All three menus are real surfaces — the footer column must keep
// its own navigation block between the brand and contact columns.
$footerRepeatHtml = '<header>'
    . '<nav class="hidden md:flex items-center gap-8"><a href="/index.html">Home</a><a href="/about/index.html">About</a><a href="/contact/index.html">Contact</a></nav>'
    . '<details class="dla-disclosure"><summary aria-label="Toggle menu">Menu</summary>'
    . '<div class="dla-dialog" role="dialog"><nav class="flex flex-col gap-3"><a href="/index.html">Home</a><a href="/about/index.html">About</a><a href="/contact/index.html">Contact</a></nav></div>'
    . '</details>'
    . '</header>'
    . '<footer><div class="grid grid-cols-3 gap-10 max-w-7xl mx-auto px-container-padding-mobile md:px-container-padding">'
    . '<div>Brand</div>'
    . '<nav class="flex flex-col gap-3"><a href="/index.html">Home</a><a href="/about/index.html">About</a><a href="/contact/index.html">Contact</a></nav>'
    . '<div class="flex flex-col gap-3"><a href="mailto:hi@example.com">hi@example.com</a></div>'
    . '</div></footer>';
$footerRepeatResult = (new HtmlTransformer())->transform($footerRepeatHtml)->toArray();
$footerRepeatMarkup = (string) ($footerRepeatResult['serialized_blocks'] ?? '');
$assert(3 === substr_count($footerRepeatMarkup, '<!-- wp:navigation '), 'a footer nav repeating the header destinations keeps its own navigation block in the full document');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation block normalizer contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation block normalizer contract passed: {$passes} assertions\n";
