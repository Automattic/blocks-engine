<?php
declare(strict_types=1);

/**
 * Google Sites marks the served page's menu item on a box between the list
 * item and its link: `aria-current` plus a state class no peer wears. That
 * class is route state, so its paint must follow Core's current link rather
 * than the item position this snapshot served; otherwise a shared menu bolds
 * the same item on every route. Home's own presentation keeps item subjects
 * positional, as on the source site.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$page = static function (string $current): string {
    $item = static fn(string $label, string $href): string => '<li><div class="item-box' . ('Home' === $label ? ' item-home' : '') . ($label === $current ? ' item-on" aria-current="true' : '') . '"><div class="item-inner"><a class="item-link" href="' . $href . '">' . $label . '</a></div></div></li>';
    return '<style>.item-box{color:#f9f9f9;padding-left:36px}.item-home{padding-left:0}.item-on{font-weight:bold}.item-link{display:block}</style>'
        . '<header><nav><ul>' . $item('Home', '/home') . $item('About', '/about') . $item('Kings', '/kings') . '</ul></nav></header><main><p>Body</p></main>';
};
$compile = static fn(string $current): string => stripslashes(json_encode((new HtmlTransformer())->transform($page($current))->toArray()));

foreach (array('About', 'Kings') as $current) {
    $output = $compile($current);
    $assert(0 === preg_match('/nth-child\(\d+\)[^{}]*\{[^}]*font-weight: *bold/', $output), "$current: the current-item paint does not pin an item position");
    $assert(1 === preg_match('/\[aria-current="page"\][^{}]*\{[^}]*font-weight: *bold/', $output), "$current: the current-item paint follows Core's current link");
}
echo "Navigation current item box state passed: 4 assertions\n";
