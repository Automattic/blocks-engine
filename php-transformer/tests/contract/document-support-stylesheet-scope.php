<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Non-landmark shared chrome, colliding terminal slugs, and content-box
// support CSS. The two about documents share declarations, not ownership
// with the home document: wherever their rule loads, it matches only them.
$files = array('site.css' => '.masthead{width:900px;padding:17px}.colophon{width:800px;padding:19px}.home-copy{width:600px;padding:31px}.about-copy{width:610px;padding:32px}');
foreach (array('index.html', 'en/about.html', 'fr/about.html') as $index => $path) {
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="' . ($index ? '../' : '') . 'site.css"></head><body><div class="app frame-' . $index . '"><div class="masthead"><div><a href="/">Home</a><a href="/en/about">About</a></div></div><main><div class="' . ($index ? 'about-copy' : 'home-copy') . '"><p>Document ' . $index . '</p></div></main><div class="colophon"><p>Shared footer</p></div></div></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
$assert($pages['en/about.html']['route_template'] !== $pages['fr/about.html']['route_template'], 'Colliding slugs receive distinct route templates.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
foreach (array('home-copy' => array('index.html'), 'about-copy' => array('en/about.html', 'fr/about.html')) as $class => $owners) {
    $support = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '') && str_contains((string) ($asset['content'] ?? ''), '.' . $class . '{box-sizing:content-box}')));
    $assert(1 === count($support), 'Identical support payloads share one resource.');
    $asset = $support[0];
    $markup = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
    foreach (array_keys($files) as $other) {
        if (!str_ends_with($other, '.html')) continue;
        $targets = array_column(WordPressSitePlan::documentStylesheets($plan, $other), 'target_path');
        // Support folded past its consumers matches nothing on other routes.
        $assert(in_array($other, $owners, true) ? in_array($asset['target_path'], $targets, true) : !in_array($asset['target_path'], $targets, true) || !str_contains($markup[$other], $class), 'Support CSS loads on its consumers and is inert elsewhere.');
    }
}
foreach (array('masthead', 'colophon') as $class) {
    $sheets = array_filter($plan['assets'], static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), '.' . $class . '{box-sizing:content-box}'));
    $assert(1 === count($sheets), 'Shared chrome support rules are emitted once.');
    $assert(array(array('kind' => 'global')) === current($sheets)['scopes'], 'Shared chrome stays global.');
}
$site = current(array_filter($plan['assets'], static fn(array $asset): bool => 'site.css' === $asset['source_path']));
$assert(array(array('kind' => 'global')) === $site['scopes'], 'Site-wide author CSS remains global.');
echo "Document support stylesheet scope passed\n";
