<?php
declare(strict_types=1);

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

$item = static fn (string $id, string $label, string $links): string =>
    '<div class="item"><button id="' . $id . '" type="button" aria-haspopup="menu" aria-controls="p-' . $id . '" aria-expanded="false" data-dla-dialog-trigger="p-' . $id . '">' . $label . '</button>'
    . '<div class="panel dla-dialog dla-dropdown" hidden id="p-' . $id . '" data-dla-dialog-panel="p-' . $id . '">' . $links . '</div></div>';
$switcher = '<div class="lang" aria-label="Switch language"><button type="button" aria-pressed="true">EN</button><span>|</span><button type="button" aria-pressed="false">ES</button></div>';
$cta = '<a class="cta" href="#book">Book now</a>';
$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );

$html = '<style>.cta{display:inline-block;padding:8px 24px;border-radius:999px;background:#036}</style>'
    . '<nav class="bar"><a href="#top" class="logo"><img src="logo.png" alt="Acme"></a><div class="row">'
    . $item('shop', 'Shop', '<a href="#new">New in</a><a href="#sale">Sale</a>')
    . $item('help', 'Help', '<a href="#faq">FAQ</a><a href="#contact">Contact</a>')
    . '<a href="#about">About</a>' . $switcher . $cta . '</div></nav>';
$markup = $transform($html);

$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'the menu items beside a language switcher are one core/navigation', $markup);
$assert(2 === preg_match_all('/<!-- wp:navigation-submenu \{[^}]*"label":"(?:Shop|Help)","kind":"custom"\}/', $markup), 'both dropdown buttons become submenus', $markup);
$assert(5 === preg_match_all('/<!-- wp:navigation-link \{[^}]*"label":"(?:New in|Sale|FAQ|Contact|About)"/', $markup), 'the panel links and the plain link stay navigation links', $markup);
$assert(! str_contains($markup, 'wp:details') && ! str_contains($markup, 'data-dla-dialog-panel'), 'no leftover disclosure or wired panel', $markup);
$assert(str_contains($markup, '>EN<') && str_contains($markup, '>ES<'), 'the language switcher is kept', $markup);
$assert(1 === preg_match('/<!-- \/wp:navigation -->.*>EN<.*Book now/s', $markup), 'the switcher and the call to action follow the menu in authored order', $markup);
$assert(! str_contains($markup, '"label":"Book now"') && ! str_contains($markup, '"label":"EN"'), 'controls are not turned into menu items', $markup);

// Without a dropdown button the row is not vouched as a menu: nothing claims it.
$plain = $transform('<div class="row"><a href="#one">One</a>' . $switcher . '</div>');
$assert(! str_contains($plain, 'wp:navigation '), 'a link beside a switcher with no dropdown is not claimed as a menu', $plain);

if ( 0 < $failures ) {
    fwrite(STDERR, "Menu row beside controls contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "Menu row beside controls contract passed: {$passes} assertions\n";
