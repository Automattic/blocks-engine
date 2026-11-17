<?php
declare(strict_types=1);

/*
 * A site where one document carries box-tree evidence and another links the
 * author stylesheet still loads the engine's before-author support first on
 * the author route: it declares the engine reset layer ahead of the author's
 * layers, so author utilities (a `space-y-*` sibling margin) outrank the
 * engine's synthetic-paragraph margin reset.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$about = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$about = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>', $about);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_path'] = 'about.html';
$tree['source_hash'] = hash('sha256', $about);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$home = '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="author.css"></head><body><main><div class="space-y-2"><a class="block" href="about.html">About</a><a class="block" href="about.html">Atelier</a></div></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $home,
    'about.html' => $about,
    'author.css' => '@layer utilities{.space-y-2>:not(:last-child){margin-block-end:8px}}',
    'box-tree/about.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$evidence = array_filter($plan['pages'], static fn(array $page): bool => !empty($page['document_metadata']['engine_owned_styles']));
$assert(array('about.html') === array_values(array_column($evidence, 'source_path')), 'Only the document with box-tree evidence owns engine styles.');

$bootstrap = BootstrapStyles::expand(array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data']);
$beforeAuthor = strpos($bootstrap, 'engine-support-before-author-');
$homeAuthor = strpos($bootstrap, "'assets/author.css'");
$assert(false !== $beforeAuthor && false !== $homeAuthor, 'The bootstrap enqueues before-author support and the author stylesheet instance.');
$assert($beforeAuthor < $homeAuthor, 'Before-author engine support is enqueued ahead of route author stylesheets.');
$afterAuthor = strpos($bootstrap, 'engine-support-after-author-');
$assert(false === $afterAuthor || $homeAuthor < $afterAuthor, 'After-author engine support still follows route author stylesheets.');
