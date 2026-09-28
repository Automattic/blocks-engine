<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// One header holds the same menu twice: a desktop bar and a phone panel. Their
// labels carry rich-text wrappers, so each compiles with its own document
// markers. A visitor reads the same five items, so they are one navigation.
$items = static fn (string $wrap): string => implode('', array_map(static fn (string $label): string => '<li><a href="index.html#' . strtolower($label) . '"><span class="' . $wrap . '"><span class="label">' . $label . '</span></span></a></li>', array('About', 'Services', 'Team')));
$header = '<header><nav class="bar"><ul>' . $items('wrap-a') . '</ul></nav>'
    . '<details class="dla-disclosure"><summary aria-label="Menu"></summary><div class="dla-dialog" role="dialog" aria-label="Site"><nav class="panel"><ul>' . $items('wrap-b') . '</ul></nav></div></details></header>';
$page = static fn (string $title): string => '<!doctype html><html><head><style>.label{letter-spacing:1px}.wrap-a{font-weight:700}.wrap-b{font-weight:600}</style></head><body>' . $header
    . '<main><section id="about"><h1>' . $title . '</h1></section><section id="services"><p>S</p></section><section id="team"><p>T</p></section></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home'), 'about.html' => $page('About'))))->toWordPressSitePlanView()['wordpress_site_plan'];
$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => 3 === ($menu['items'] ?? 0)));
$assert(1 === count($menus), 'The same three-item menu rendered twice is one navigation entity: ' . json_encode(array_column($plan['menus'], 'target_slug')));

// A menu whose items read differently is its own entity.
$other = str_replace('>Team<', '>Our Team<', $page('Home'));
$split = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => str_replace('<nav class="panel"><ul>' . $items('wrap-b'), '<nav class="panel"><ul>' . str_replace('>Team<', '>Our Team<', $items('wrap-b')), $page('Home')))))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(2 === count(array_filter($split['menus'], static fn (array $menu): bool => 3 === ($menu['items'] ?? 0))), 'Menus whose visible labels differ stay separate.');
unset($other);

echo "Navigation entity label identity contract passed.\n";
