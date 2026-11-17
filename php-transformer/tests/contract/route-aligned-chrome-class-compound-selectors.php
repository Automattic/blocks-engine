<?php
declare(strict_types=1);

/**
 * Route alignment renames a chrome class whose rules read differently on
 * another route. A selector can name two such classes, an ancestor and its
 * descendant, and chrome then wears both aliases and neither source name.
 * The aliased copy of that rule renames every aligned class it names, so it
 * still matches the aligned chrome: a footer's two-column definition list
 * keeps its floats and widths on every route.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
};

$columns = '.hours .hours-list dt{float:left;width:40%}.hours .hours-list dd{float:right;width:59%}';
$footer = '<footer><div class="hours"><dl class="hours-list"><div><dt>Monday</dt><dd>9 to 5</dd></div><div><dt>Tuesday</dt><dd>Closed</dd></div></dl></div></footer>';
$files = array(
    // The second route's sheet carries one more rule for the same list, so
    // both class names read differently there and are aligned.
    array('path' => 'assets/home.css', 'kind' => 'css', 'content' => $columns),
    array('path' => 'assets/contact.css', 'kind' => 'css', 'content' => $columns . '.hours .hours-list{margin:0}'),
);
foreach (array('index.html' => 'home', 'contact/index.html' => 'contact') as $path => $sheet) {
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/' . $sheet . '.css"></head><body><main><h1>' . $sheet . '</h1></main>' . $footer . '</body></html>');
}

$projected = (new RouteStateProjector())->project($files)['files'];
$sheets = array();
foreach ($projected as $file) if ('css' === $file['kind']) $sheets[$file['path']] = (string) $file['content'];
foreach ($projected as $file) {
    if ('html' !== $file['kind']) continue;
    $document = new DOMDocument();
    @$document->loadHTML((string) $file['content']);
    $xpath = new DOMXPath($document);
    $box = (string) $xpath->evaluate('string(//footer/div/@class)');
    $list = (string) $xpath->evaluate('string(//footer//dl/@class)');
    $assert(str_starts_with($box, 'route-class-') && str_starts_with($list, 'route-class-'), 'Both footer classes are aligned in ' . $file['path'] . '.', (string) $file['content']);
    $css = $sheets['index.html' === $file['path'] ? 'assets/home.css' : 'assets/contact.css'];
    // The aligned footer wears only the aliases, so a rule reaches its list
    // terms only through a copy that names both.
    $assert(str_contains($css, '.' . $box . ' .' . $list . ' dt'), 'The aligned list terms keep their column rule in ' . $file['path'] . '.', $css);
    $assert(str_contains($css, '.' . $box . ' .' . $list . ' dd'), 'The aligned list details keep their column rule in ' . $file['path'] . '.', $css);
}

echo "route-aligned-chrome-class-compound-selectors contract passed\n";
