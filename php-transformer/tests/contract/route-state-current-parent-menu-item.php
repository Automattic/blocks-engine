<?php
declare(strict_types=1);

/**
 * A menu item holding a submenu is current on its own route and on each
 * child's route. Its `li` wraps one link of its own, its label, so its
 * current-state classes are route state like a leaf item's, and every route
 * compiles the same resting menu.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 2000)));
    }
};

$menu = static function (string $served): string {
    $item = static function (string $href, string $label, string $served, string $submenu = ''): string {
        $current = $href === $served;
        $parent = '' !== $submenu && str_starts_with($served, $href);
        $class = $current ? ' class="selected current-item"' : ($parent ? ' class="selected"' : '');
        $state = $current || $parent ? ' aria-current="true"' : '';
        return '<li' . $class . '><a href="' . $href . '" class="menu-item"' . $state . '>' . $label . '</a>' . $submenu . '</li>';
    };
    $submenu = '<ul>' . $item('/models/fiat/', 'Fiat', $served) . $item('/models/ford/', 'Ford', $served) . '</ul>';
    return '<header><nav><ul id="menu">' . $item('/', 'Home', $served) . $item('/about/', 'About', $served) . $item('/models/', 'Models', $served, $submenu) . '</ul></nav></header>';
};
$resting = static fn(string $html): string => str_replace(' aria-current="true"', '', $html);

$home = $resting(RouteStateProjector::normalize($menu('/')));
foreach (array('/about/', '/models/', '/models/fiat/') as $served) {
    $normalized = RouteStateProjector::normalize($menu($served));
    $assert($home === $resting($normalized), 'A current menu item rests on route ' . $served . '.', $normalized);
    $assert(str_contains($normalized, 'aria-current'), 'Menu links keep aria-current for navigation to restore on ' . $served . '.', $normalized);
}

echo "route-state-current-parent-menu-item contract passed\n";
