<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;

/**
 * Issue #5649: a Squarespace menu marks the served item `active` beside the
 * classes that item has on every route (`collection homepage`, `folder`).
 * Only the current token is route state. Intersecting the item's classes
 * with its siblings' also dropped `homepage` and `folder` on the routes where
 * that item was current, so one header split into per-route shells.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$header = static function (string $current): string {
    $active = static fn (string $item): string => $item === $current ? ' active' : '';
    return '<header id="header"><div id="headerNav"><nav id="mainNavigation">'
        . '<div class="user-account-link"><a href="#"><span class="unauth">Sign In</span></a></div>'
        . '<div class="collection' . $active('home') . ' homepage"><a href="/">Home</a></div>'
        . '<div class="folder' . $active('about') . '"><div class="folder-toggle" data-href="/about/">About</div><div class="subnav">'
        . '<div class="collection"><a href="/what-we-do/">What We Do</a></div>'
        . '<div class="collection' . $active('about') . '"><a href="/contact/">Contact</a></div></div></div>'
        . '<div class="collection' . $active('news') . '"><a href="/news/">News</a></div>'
        . '</nav></div></header>';
};

$news = SourceShells::identity($header('news'));
$assert(SourceShells::identity($header('home')) === $news, 'The current home item keeps its own `homepage` class.');
$assert(SourceShells::identity($header('about')) === $news, 'The current folder and its current sub-item keep their own classes.');
$assert(str_contains($news, 'class="collection homepage"') && str_contains($news, 'class="folder"') && str_contains($news, 'class="subnav"'), 'Item classes stay in the shell identity.');
$assert(!str_contains(SourceShells::identity($header('home')), 'active'), 'The current token is route state.');

// Without a known current token, sibling intersection still infers the
// utility classes a router swaps onto the current item.
$utility = static fn (string $current): string => '<header><nav>'
    . '<div class="item' . ('home' === $current ? ' text-blue' : '') . '" aria-current="' . ('home' === $current ? 'page' : 'false') . '"><a href="/">Home</a></div>'
    . '<div class="item' . ('news' === $current ? ' text-blue' : '') . '"' . ('news' === $current ? ' aria-current="page"' : '') . '><a href="/news/">News</a></div></nav></header>';
$assert(SourceShells::identity($utility('home')) === SourceShells::identity($utility('news')), 'Swapped utility classes remain route state.');

echo "shell-identity-current-item-own-classes passed\n";
