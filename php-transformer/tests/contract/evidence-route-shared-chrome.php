<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

/*
 * A captured route among routes without box-tree evidence (#5859). Its
 * carriers style its content, but not the shared header every route renders,
 * whose utility classes route content also uses. That header keeps its author
 * rules on the captured route, scoped to the template parts, and the custom
 * properties delivered engine CSS reads stay defined there.
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="author.css"></head><body>'
    . '<header class="site-bar flex h-16"><a class="brand" href="index.html">Brand</a><nav class="links"><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
    . '<main class="content flex"><h1 class="text-4xl md:text-6xl">' . $title . '</h1><p>' . $title . ' text.</p></main>'
    . '<footer class="site-foot"><p>Footer text</p></footer></body></html>';
$document = new DOMDocument();
@$document->loadHTML($page('About'));
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$nodes = array();
$observe = static function (DOMElement $element) use (&$observe, &$nodes, $fixture): string {
    $id = 'n' . (count($nodes) + 1);
    $nodes[$id] = array();
    $element->setAttribute('data-blocks-engine-node-id', $id);
    $children = array();
    foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $children[] = $observe($child);
    $nodes[$id] = array('children' => $children, 'viewports' => $fixture['nodes']['n1']['viewports']);
    return $id;
};
$observe($document->documentElement);
$about = '<!doctype html>' . $document->saveHTML($document->documentElement);
$tree = array('source_path' => 'about.html', 'source_hash' => hash('sha256', $about), 'nodes' => $nodes) + $fixture;
$css = ':root{--spacing:4px;--text-4xl:2.25rem;--text-6xl:3.75rem;--unread:1px}'
    . '.flex{display:flex}.h-16{height:calc(var(--spacing)*16)}.site-bar{justify-content:space-between}.content{gap:8px}'
    . '.text-4xl{font-size:var(--text-4xl)}@media (width>=48rem){.md\:text-6xl{font-size:var(--text-6xl)}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'),
    'about.html' => $about,
    'author.css' => $css,
    'box-tree/about.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = static function (string $source) use ($plan, $assets): string {
    $css = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $source) as $row) $css .= (string) ($assets[$row['target_path']]['content'] ?? '') . "\n";
    return $css;
};
$header = (string) array_column($plan['template_parts'], 'canonical_block_markup', 'slug')['header'];
$assert(str_contains($header, 'site-bar flex h-16'), 'the header is shared chrome carrying the utility classes route content shares', $header);
$about = $delivered('about.html');
$home = $delivered('index.html');
$scope = '@scope (.wp-block-template-part) to (.wp-block-post-content){';
$assert(str_contains($about, $scope . '.flex{display:flex}.h-16{height:calc(var(--spacing)*16)}'), 'the captured route keeps the shared header rules, scoped to the template parts', $about);
$assert(str_contains($about, '.site-bar{justify-content:space-between}'), 'header-only rules reach the captured route', $about);
$unscoped = (string) preg_replace('/@scope \(\.wp-block-template-part\) to \(\.wp-block-post-content\)\{(?:[^{}]*\{[^{}]*\})*\}/', '', $about);
$assert(!str_contains($unscoped, '.flex{') && !str_contains($unscoped, '.h-16{') && !str_contains($about, '.content{gap'), 'no author rule restyles the route content its carriers own', $unscoped);
$assert(str_contains($about, ':root .blocks-engine-responsive-typography-') && 1 === preg_match('/:root\{[^}]*--text-4xl:2\.25rem[^}]*--text-6xl:3\.75rem/', $about), 'custom properties delivered engine CSS reads stay defined on the captured route', $about);
$assert(str_contains($about, '--spacing:4px') && !str_contains($about, '--unread'), 'only custom properties delivered CSS reads are kept', $about);
$assert(str_contains($home, '.flex{display:flex}.h-16{height:calc(var(--spacing)*16)}') && !str_contains($home, '@scope (.wp-block-template-part)'), 'routes without evidence still apply the whole author sheet', $home);
