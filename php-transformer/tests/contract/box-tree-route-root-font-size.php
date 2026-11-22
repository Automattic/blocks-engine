<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// The about page sets `html{font-size:10px}` and sizes its body from the
// viewport (`calc(1.6em + 0.5vw)`, `calc(1.3em + 0.9vw)` from 48em, `2.7em`
// from 90em); its paragraphs are 90% of the body. Once the box-tree routes
// retire the author stylesheets, carriers keep the relative 90% and prune
// sizes equal to their parent's, so without the root and body sizes the
// paragraphs rendered 90% of WordPress's 16px (14.4px instead of 24.3px at
// 1440px). The home page keeps the initial 16px root.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$fixture = __DIR__ . '/../fixtures/box-tree-route-root-font-size';
$files = array();
foreach (array('index.html', 'about/index.html', 'home.css', 'about.css', 'box-tree/index.json', 'box-tree/about/index.json') as $path) $files['website/' . $path] = file_get_contents($fixture . '/' . $path);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];

// The declarations a route's stylesheets deliver on the document root and
// body, unconditionally ('') and under each media condition.
$delivered = static function (string $route) use ($plan): array {
    $declarations = array();
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        $scoped = false;
        foreach ($asset['scopes'] ?? array() as $scope) $scoped = $scoped || 'global' === $scope['kind'] || $route === ($scope['source_path'] ?? null);
        if (!$scoped) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$declarations): void {
            $prelude = trim($prelude);
            $condition = implode(' ', $ancestors);
            if (1 === preg_match('/^(?::root|html)$/', $prelude)) $declarations['root'][$condition] = array_replace($declarations['root'][$condition] ?? array(), SourceDeclarations::parse($body));
            if (1 === preg_match('/^(?::root\s+)?body(?::not\([^()]*\))*$/', $prelude)) $declarations['body'][$condition] = array_replace($declarations['body'][$condition] ?? array(), SourceDeclarations::parse($body));
        });
    }
    return $declarations;
};

foreach (array('website/index.html', 'website/about/index.html') as $route) {
    $assert(1.0 === (float) $result['source_reports']['box_tree'][$route]['coverage'], "The $route capture route has complete box-tree evidence.");
}
$assert(array() === array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with((string) $asset['target_path'], 'about.css') && '' !== trim((string) $asset['content'])), 'The capture routes retire the author stylesheet.');

$about = $delivered('website/about/index.html');
$assert('10px' === ($about['root']['']['font-size'] ?? null), 'The about route keeps the 10px root its rem lengths resolve against.');
$assert('2.7em' === ($about['body']['']['font-size'] ?? null), 'The about route keeps the body size its content inherits at the widest capture.');
$bands = array();
foreach ($about['body'] ?? array() as $condition => $body) if ('' !== $condition && isset($body['font-size'])) $bands[$condition] = $body['font-size'];
$assert(in_array('calc(1.3em + 0.9vw)', $bands, true) && in_array('calc(1.6em + 0.5vw)', $bands, true), 'The about route keeps the author body sizes below each breakpoint (' . json_encode($bands) . ').');

$home = $delivered('website/index.html');
$assert(!isset($home['root']['']['font-size']) && !isset($home['body']['']['font-size']), 'The home route, at the initial root and body size, restates neither.');
