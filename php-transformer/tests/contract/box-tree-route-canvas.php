<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// Two captured pages share one stylesheet that paints the canvas and text
// colour on `html` and `body`; the home page's body is white, the post's is
// the shared grey. The root background is therefore not unanimous and stays
// out of theme.json. Once the box-tree routes retire the author stylesheet,
// only engine rules on the WordPress root and body keep each route's paint.
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
$assert(array() === array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with((string) $asset['target_path'], 'style.css') && '' !== trim((string) $asset['content'])), 'The capture routes retire the author stylesheet.');

$post = $delivered('2020/01/02/a-note.html');
$assert('rgb(242,242,242)' === $color($post['root']['background-color'] ?? null), 'The post route keeps the grey canvas the source html paints.');
$assert('rgb(242,242,242)' === $color($post['body']['background-color'] ?? null), 'The post route keeps the grey body background.');
$assert(str_contains($color($post['body']['color'] ?? null), 'rgb(17,17,17)'), 'The post route keeps the body text colour its blocks inherit.');

$home = $delivered('index.html');
$assert('rgb(242,242,242)' === $color($home['root']['background-color'] ?? null), 'The home route keeps the grey canvas the source html paints.');
$assert(str_contains($color($home['body']['background-color'] ?? null), 'rgb(255,255,255)'), 'The home route keeps its white body background.');
