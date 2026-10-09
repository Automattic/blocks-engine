<?php
declare(strict_types=1);

/**
 * A header that draws its menu twice, a row of dropdown items for wide screens
 * and a hamburger that opens a captured panel with the same items for narrow
 * screens, must come out as one core/navigation. That navigation carries the
 * responsive overlay, so there is no separate hamburger button and no dialog
 * block for the panel.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @param array<int, array<string, mixed>> $blocks */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

$css = '.hidden{display:none}.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}'
    . '.gap-8{gap:2rem}.gap-3{gap:.75rem}.cta{display:inline-block;padding:8px 24px;border-radius:999px;background:#036;color:#fff}'
    . '@media (min-width:768px){.md\\:flex{display:flex}.md\\:hidden{display:none}}';
$item = static fn (string $id, string $label, string $links): string =>
    '<div class="relative"><button id="' . $id . '" type="button" aria-haspopup="menu" aria-controls="p-' . $id . '" aria-expanded="false" data-dla-dialog-trigger="p-' . $id . '">' . $label . '</button>'
    . '<div class="panel dla-dialog dla-dropdown" hidden id="p-' . $id . '" data-dla-dialog-panel="p-' . $id . '">' . $links . '</div></div>';
$switcher = '<div class="lang" aria-label="Switch language"><button type="button" aria-pressed="true">EN</button><span>|</span><button type="button" aria-pressed="false">ES</button></div>';
$cta = '<a class="cta" href="/book">Book now</a>';
$accordion = static fn (string $label): string => '<div><button class="acc">' . $label . '<svg viewBox="0 0 4 4"><path d="M0 0l4 4"/></svg></button></div>';

$shop = '<a href="/new">New in</a><a href="/sale">Sale</a>';
$help = '<a href="/faq">FAQ</a><a href="/contact">Contact us</a>';
$page = static fn (string $dialog, string $mobileGroupExtra = ''): string => '<style>' . $css . '</style>'
    . '<nav class="bar"><div class="wrap flex items-center justify-between"><a href="/" class="logo"><img src="logo.png" alt="Acme"></a>'
    . '<div class="hidden md:flex items-center gap-8">'
    . $item('shop', 'Shop', $shop) . $item('help', 'Help', $help)
    . '<a href="/about">About</a>' . $switcher . $cta . '</div>'
    . '<div class="md:hidden flex items-center gap-3">' . $switcher
    . '<button type="button" id="burger" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" aria-controls="dlg" data-dla-dialog-trigger="dla-dialog-4"><svg viewBox="0 0 4 4"><path d="M0 0h4"/></svg></button>' . $mobileGroupExtra . '</div></div>'
    . $dialog . '</nav>';
$dialog = '<dialog id="dlg" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="burger" class="md:hidden dla-dialog dla-dropdown" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-captured-menu="true">'
    . $accordion('Shop') . $accordion('Help') . '<a href="/about">About</a>' . '<a class="cta" href="/book">Book now</a></dialog>';

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$result = $transform($page($dialog));
$markup = (string) ($result['serialized_blocks'] ?? '');
$blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
$navigations = $findBlocks($blocks, 'core/navigation');

$assert(1 === count($navigations), 'the row and the dialog are one core/navigation', $markup);
$assert('mobile' === ($navigations[0]['attrs']['overlayMenu'] ?? ''), 'that navigation owns the responsive overlay', $markup);
$assert(2 === count($findBlocks($blocks, 'core/navigation-submenu')), 'both dropdown items stay submenus', $markup);
$labels = array_map(static fn (array $block): string => (string) ($block['attrs']['label'] ?? ''), $findBlocks($blocks, 'core/navigation-link'));
foreach ( array( 'New in', 'Sale', 'FAQ', 'Contact us', 'About' ) as $label ) {
    $assert(1 === count(array_keys($labels, $label, true)), 'the link "' . $label . '" is in the navigation once', json_encode($labels));
}
$assert(! str_contains($markup, 'captured-dialog') && ! str_contains($markup, '<dialog'), 'no custom dialog block is left for the panel', $markup);
$hamburgers = array_filter($findBlocks($blocks, 'core/button'), static fn (array $block): bool => 'burger' === ($block['attrs']['anchor'] ?? ''));
$assert(array() === $hamburgers && ! str_contains($markup, 'id="burger"'), 'no separate hamburger button is left', $markup);
$assert(1 === substr_count($markup, '>EN<') && 1 === substr_count($markup, '>ES<'), 'the language switcher is kept once', $markup);
$assert(2 === substr_count($markup, 'Book now'), 'the call to action is kept in the row and in the overlay', $markup);
$assert(1 === count($findBlocks((array) ($navigations[0]['innerBlocks'] ?? array()), 'core/buttons')), 'the overlay carries the call to action', $markup);
$assert(str_contains($markup, 'blocks-engine-menu-fold-hide') && str_contains($markup, 'blocks-engine-menu-fold-overlay-only'), 'each copy of the call to action is marked for its own width', $markup);

// A dialog that lists something the row does not have is not a copy of the row.
$other = '<dialog id="dlg" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="burger" class="md:hidden dla-dialog dla-dropdown" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-captured-menu="true">'
    . $accordion('Shop') . $accordion('Help') . '<a href="/about">About</a><a href="/careers">Careers</a></dialog>';
$unmatched = $transform($page($other));
$unmatchedMarkup = (string) ($unmatched['serialized_blocks'] ?? '');
$assert(str_contains($unmatchedMarkup, 'captured-dialog'), 'a dialog with an entry the row lacks keeps its dialog block', $unmatchedMarkup);
$assert(! str_contains($unmatchedMarkup, 'blocks-engine-menu-fold'), 'and the row is not folded', $unmatchedMarkup);

// The hamburger's wrapper keeps whatever the row does not have; only the hamburger goes.
$extra = $transform($page($dialog, '<a href="/phone">Call us</a>'));
$extraMarkup = (string) ($extra['serialized_blocks'] ?? '');
$assert(! str_contains($extraMarkup, 'captured-dialog') && 1 === count($findBlocks(is_array($extra['blocks'] ?? null) ? $extra['blocks'] : array(), 'core/navigation')), 'a wrapper with its own content still folds the dialog into the navigation', $extraMarkup);
$assert(str_contains($extraMarkup, 'Call us') && 2 === substr_count($extraMarkup, '>EN<'), 'the wrapper and what it holds beyond the hamburger are kept', $extraMarkup);

// A row that cannot hand its whole menu to one navigation keeps the dialog as captured.
$split = str_replace('<a href="/about">About</a>' . $switcher, $switcher . '<a href="/about">About</a>', $page($dialog));
$splitResult = $transform($split);
$splitMarkup = (string) ($splitResult['serialized_blocks'] ?? '');
$assert(str_contains($splitMarkup, 'captured-dialog') && ! str_contains($splitMarkup, 'blocks-engine-menu-fold'), 'a row whose items are split by a control keeps the captured dialog', $splitMarkup);

if ( 0 < $failures ) {
    fwrite(STDERR, "Menu dialog fold contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "Menu dialog fold contract passed: {$passes} assertions\n";
