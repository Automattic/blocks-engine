<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/unlabeled-viewport-shell.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']), 'Unlabeled device documents extract shared header and footer parts.');
$header = $parts['header']['canonical_block_markup'];
$assert(str_contains($header, 'header-element'), 'The shared header contains the header navigation.');
$pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
foreach (array('index.html' => 'Home', 'services/index.html' => 'Services') as $path => $title) {
    $content = $pages[$path];
    $assert(!str_contains($content, 'header-element') && !str_contains($content, '<nav'), 'Page content no longer contains header navigation: ' . $path);
    $assert(1 === preg_match('/<h1\b[^>]*>' . $title . '<\/h1>/', $content), 'Page content retains its route heading: ' . $path);
}
echo "unlabeled-viewport-shell: ok\n";
