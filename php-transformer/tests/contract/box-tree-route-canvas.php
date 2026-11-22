<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// Two captured pages share one stylesheet that paints the canvas and text
// colour on `html` and `body`; the home page's body is white, the post's is
// the shared grey. The root background is therefore not unanimous and stays
// out of theme.json. Once the box-tree routes retire the author stylesheet,
// only engine rules on the WordPress root and body keep each route's paint.
// The post's link inherits the text colour (`color: inherit`) and turns red
// on hover; the home page's link keeps the browser's own link colour.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$fixture = __DIR__ . '/../fixtures/box-tree-route-canvas';
$files = array();
foreach (array('index.html', '2020/01/02/a-note.html', 'style.css', 'box-tree/index.json', 'box-tree/2020/01/02/a-note.json') as $path) $files[$path] = file_get_contents($fixture . '/' . $path);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];

// The unconditional declarations a route's stylesheets deliver on the
// document root and body.
$delivered = static function (string $route) use ($plan): array {
    $declarations = array('root' => array(), 'body' => array());
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        $scoped = false;
        foreach ($asset['scopes'] ?? array() as $scope) $scoped = $scoped || 'global' === $scope['kind'] || $route === ($scope['source_path'] ?? null);
        if (!$scoped) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$declarations): void {
            if (array() !== $ancestors) return;
            $prelude = trim($prelude);
            if (1 === preg_match('/^(?::root|html)$/', $prelude)) $declarations['root'] = array_replace($declarations['root'], SourceDeclarations::parse($body));
            if (1 === preg_match('/^(?::root\s+)?body(?::not\([^()]*\))*$/', $prelude)) $declarations['body'] = array_replace($declarations['body'], SourceDeclarations::parse($body));
        });
    }
    return $declarations;
};
$color = static fn(?string $value): string => (string) preg_replace('/\s+/', '', (string) $value);

foreach (array('index.html', '2020/01/02/a-note.html') as $route) {
    $assert(1.0 === (float) $result['source_reports']['box_tree'][$route]['coverage'], "The $route capture route has complete box-tree evidence.");
}
$assert(array() === array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with((string) $asset['target_path'], 'style.css') && !in_array(trim((string) $asset['content']), array('', 'a:hover{color:red}'), true)), 'The capture routes retire the author stylesheet except its hover state.');

$post = $delivered('2020/01/02/a-note.html');
$assert('rgb(242,242,242)' === $color($post['root']['background-color'] ?? null), 'The post route keeps the grey canvas the source html paints.');
$assert('rgb(242,242,242)' === $color($post['body']['background-color'] ?? null), 'The post route keeps the grey body background.');
$assert(str_contains($color($post['body']['color'] ?? null), 'rgb(17,17,17)'), 'The post route keeps the body text colour its blocks inherit.');

$home = $delivered('index.html');
$assert('rgb(242,242,242)' === $color($home['root']['background-color'] ?? null), 'The home route keeps the grey canvas the source html paints.');
$assert(str_contains($color($home['body']['background-color'] ?? null), 'rgb(255,255,255)'), 'The home route keeps its white body background.');

// The colour a route's link computes at rest or on hover: the most specific
// unconditional rule (later on a tie) whose selector matches the link.
$linkColor = static function (string $route, bool $hover) use ($plan, $color): ?string {
    $page = current(array_filter($plan['pages'], static fn(array $page): bool => $route === $page['source_path']));
    $dom = new DOMDocument();
    @$dom->loadHTML('<!DOCTYPE html><html><body>' . $page['canonical_block_markup'] . '</body></html>');
    $link = $dom->getElementsByTagName('a')->item(0);
    $winner = null;
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        $scoped = false;
        foreach ($asset['scopes'] ?? array() as $scope) $scoped = $scoped || 'global' === $scope['kind'] || $route === ($scope['source_path'] ?? null);
        if (!$scoped) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$winner, $link, $hover, $color): void {
            $value = SourceDeclarations::parse($body)['color'] ?? null;
            if (array() !== $ancestors || null === $value) return;
            foreach (CssValueSplitter::splitTopLevel($prelude, array(',')) as $selector) {
                $parsed = CssSelectorMatcher::parse(trim($selector));
                $state = null !== ($parsed['pseudo_state_suffix_span'] ?? null);
                if (($state && !$hover) || !CssSelectorMatcher::matches($link, $parsed, $state)['matches']) continue;
                $specificity = CssSelectorMatcher::specificity($parsed);
                if (null === $winner || $specificity >= $winner[0]) $winner = array($specificity, $color($value));
            }
        });
    }
    return $winner[1] ?? null;
};
$assert(str_contains((string) $linkColor('2020/01/02/a-note.html', false), 'rgb(17,17,17)'), 'The post link keeps the text colour it inherits instead of the browser default blue.');
$assert(str_starts_with((string) $linkColor('2020/01/02/a-note.html', false), 'var(--wp--preset--color--contrast,'), "The post link's colour follows the theme's contrast colour, so a Global Styles change reaches it.");
$assert('red' === $linkColor('2020/01/02/a-note.html', true), "The source's retained hover colour still outranks the link's resting colour.");
$assert(null === $linkColor('index.html', false), "A link at the browser's own colour declares none, keeping the browser's visited colour.");
