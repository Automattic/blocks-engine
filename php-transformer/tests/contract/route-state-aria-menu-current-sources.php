<?php
declare(strict_types=1);

/**
 * A site builder's header menu is a WAI-ARIA menu list outside any `<nav>`.
 * Its section parent stays current on a child's page, a route its own link
 * does not name, so the parent records every capture that marks it current
 * and the shared menu can mark it on the child's route as well.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 2000)));
};

$page = static function (string $served): string {
    $item = static function (string $href, string $label, bool $current, string $submenu = ''): string {
        $state = $current ? ' aria-current="true"' : '';
        return '<li' . ($current ? ' class="selected"' : '') . ' role="none"><a href="' . $href . '" class="menu-item" role="menuitem"' . $state . '>' . $label . '</a>' . $submenu . '</li>';
    };
    $submenu = '<ul role="menu">' . $item('/models/fiat/', 'Fiat', '/models/fiat/' === $served) . $item('/models/ford/', 'Ford', false) . '</ul>';
    return '<html><body><header><div id="menu"><ul role="menu">' . $item('/', 'Home', '/' === $served)
        . $item('/models/', 'Models', str_starts_with($served, '/models/'), $submenu) . '</ul></div></header><main><p>' . $served . '</p></main></body></html>';
};
$files = array();
foreach (array('index.html' => '/', 'models/index.html' => '/models/', 'models/fiat/index.html' => '/models/fiat/') as $path => $served) {
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => $page($served));
}

foreach ((new RouteStateProjector())->project($files)['files'] as $file) {
    $document = new DOMDocument();
    @$document->loadHTML((string) $file['content']);
    $sources = (string) (new DOMXPath($document))->evaluate('string(//li[a[@href="/models/"]]/@' . RouteStateProjector::CURRENT_SOURCES_ATTRIBUTE . ')');
    $assert(array('models/index.html', 'models/fiat/index.html') === json_decode($sources, true), 'The section parent is current on its own and its child\'s capture in ' . $file['path'] . '.', (string) $file['content']);
}

echo "route-state-aria-menu-current-sources contract passed\n";
