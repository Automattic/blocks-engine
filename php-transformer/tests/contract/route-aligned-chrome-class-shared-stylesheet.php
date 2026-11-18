<?php
declare(strict_types=1);

/**
 * Route alignment reads a stylesheet file that only one route links as that
 * route's own sheet. A file several routes link is shared vocabulary: its
 * classes mean the same on every route, even when one route also links a
 * widget sheet that adds a rule for one of them. Chrome keeps those source
 * names, so the shared file's nested, attribute-gated rules (a vertical
 * menu's item separators) still reach the menu they were written for.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
};

$runtime = '.menu[data-structure=vertical]{display:block;&[data-layout=lined] .menu-top>.menu-item:after{border-top:1px solid #d1d1d1;content:""}}.menu .menu-top>.menu-item{display:flex;flex-direction:column}';
$files = array(
    array('path' => 'assets/runtime.css', 'kind' => 'css', 'content' => $runtime),
    // Only the contact route links this widget sheet.
    array('path' => 'assets/contact-widget.css', 'kind' => 'css', 'content' => '.menu-top{margin:0}.contact-form{padding:8px}'),
);
$menu = '<footer><nav class="menu" data-structure="vertical" data-layout="lined"><ul class="menu-top"><li class="menu-item"><a href="/">Home</a></li><li class="menu-item"><a href="/contact/">Contact</a></li></ul></nav></footer>';
foreach (array('index.html' => '', 'contact/index.html' => '<link rel="stylesheet" href="/assets/contact-widget.css">') as $path => $widget) {
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/runtime.css">' . $widget . '</head><body><main><form class="contact-form"></form></main>' . $menu . '</body></html>');
}

$projected = (new RouteStateProjector())->project($files)['files'];
foreach ($projected as $file) {
    if ('css' === $file['kind']) {
        $assert($file['content'] === $files['assets/runtime.css' === $file['path'] ? 0 : 1]['content'], 'Route alignment leaves ' . $file['path'] . ' as written.', (string) $file['content']);
        continue;
    }
    $document = new DOMDocument();
    @$document->loadHTML((string) $file['content']);
    $list = (string) (new DOMXPath($document))->evaluate('string(//nav//ul/@class)');
    $assert('menu-top' === $list, 'The menu list keeps the class its shared rules name in ' . $file['path'] . ', got "' . $list . '".', (string) $file['content']);
}

echo "route-aligned-chrome-class-shared-stylesheet contract passed\n";
