<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$blocks = static fn(string $html): string => (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
$count = static fn(string $markup, string $block): int => (int) preg_match_all('/<!-- wp:' . preg_quote($block, '/') . '[ \/]/', $markup);
$page = static fn(string $items, string $css = ''): string => '<!doctype html><html><head><title>t</title><style>' . $css . '</style></head><body><nav role="navigation"><ul>'
    . '<li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/contact/">Contact</a></li>'
    . $items . '</ul></nav><p>after</p></body></html>';
$copies = '<ul role="menu"><li role="menuitem"><a href="/">Home</a></li><li role="menuitem"><a href="/about/">About</a></li></ul>';

// The reduced page: a hidden "More" item repeating the visible destinations.
$fixture = $blocks(file_get_contents(dirname(__DIR__) . '/fixtures/navigation-hidden-overflow-copy.html'));
$assert(1 === $count($fixture, 'navigation'), 'A menu with a hidden overflow copy must stay a core/navigation block.');
$assert(3 === $count($fixture, 'navigation-link'), 'Each visible destination is one link, without the hidden copies.');
$assert(0 === $count($fixture, 'list'), 'The menu must not become a List block.');
$assert(!str_contains($fixture, 'wp:html'), 'No Custom HTML.');

// Without the empty opener, the copies must not be flattened into the menu either.
$noOpener = $blocks($page('<li style="display:none">' . $copies . '</li>'));
$assert(3 === $count($noOpener, 'navigation-link'), 'Hidden copies must not become extra visible links.');

// Hidden through author CSS rather than an inline style, or the hidden attribute.
$authored = $blocks($page('<li class="overflow"><div><span></span></div>' . $copies . '</li>', '.overflow{display:none}'));
$assert(1 === $count($authored, 'navigation') && 3 === $count($authored, 'navigation-link'), 'Author CSS can hide the overflow copy.');
$attribute = $blocks($page('<li hidden><div><span></span></div>' . $copies . '</li>'));
$assert(1 === $count($attribute, 'navigation') && 3 === $count($attribute, 'navigation-link'), 'The hidden attribute can hide the overflow copy.');

// Never lose a destination: a hidden item with a page of its own is not a copy.
$unique = $blocks($page('<li style="display:none"><ul><li><a href="/">Home</a></li><li><a href="/only-here/">Only here</a></li></ul></li>'));
$assert(str_contains($unique, '/only-here/'), 'A destination found only in the hidden item must survive.');

// A real submenu stays a submenu, whether its opener is shown or starts hidden.
$visible = $blocks($page('<li><a href="/more/">More</a><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></li>'));
$assert(1 === $count($visible, 'navigation-submenu'), 'A visible labelled submenu is unchanged.');
$labelled = $blocks($page('<li style="display:none"><a href="#more">More</a><ul><li><a href="/">Home</a></li></ul></li>'));
$assert(1 === $count($labelled, 'navigation-submenu'), 'A hidden item with its own label is unchanged.');

// A visible item with an empty slot is not hidden, so it is not treated as a copy.
$shown = $blocks($page('<li><div><span></span></div>' . $copies . '</li>'));
$assert(str_contains($shown, '/about/'), 'Visible content is never dropped.');

echo "Navigation hidden overflow copy passed.\n";
