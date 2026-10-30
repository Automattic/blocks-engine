<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// #3152: a head style row links its canonical CSS write in place instead of
// inlining the CSS body into the head and functions.php.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$html = '<!doctype html><html><head><title>Home</title>'
    . '<style id="before" media="screen" type="text/css">.before-rule{color:red}</style>'
    . '<link rel="stylesheet" href="site.css" media="print">'
    . '<style class="after" media="(min-width: 600px)" data-scope="wide">.after-rule{color:blue}</style>'
    . '</head><body><main><h1 class="before-rule after-rule">Home</h1></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'site.css' => '.linked-rule{margin:0}')))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The fixture compiles to a site plan.');
$page = array_column($plan['pages'], null, 'source_path')['index.html'];
$head = $page['document_metadata']['head'];
$assert(array('style', 'link', 'style') === array_column($head['elements'], 'tag'), 'The head keeps style, link, style in source order.');

$rendered = DocumentHeadContext::render($head, $plan['assets'], $plan['reference_tokens'], WordPressSitePlan::entryRootFromDocuments($plan['pages']));
$links = array_map(static fn(array $row): array => HtmlTagScanner::attributes($row['tag']), HtmlTagScanner::scan($rendered, 'link'));
$assert(3 === count($links), 'render() emits three link elements: ' . $rendered);
$assert(!str_contains($rendered, '<style'), 'render() emits no style element.');
$expected = array_map(static fn(array $row): string => $row['asset_reference'] ?? '', $head['elements']);
$assert($expected === array_column($links, 'href') && array() === array_filter($expected, static fn(string $href): bool => !str_starts_with($href, WordPressSitePlan::TOKEN_PREFIX)), 'Each link href is its write token, in source order.');
$assert(array('screen', 'print', '(min-width: 600px)') === array_column($links, 'media'), 'Each link keeps its media.');
$assert('stylesheet' === $links[0]['rel'] && 'before' === $links[0]['id'] && !isset($links[0]['type']), 'A style link carries id and drops type.');
$assert('stylesheet' === $links[2]['rel'] && 'after' === $links[2]['class'] && 'wide' === $links[2]['data-scope'], 'A style link carries class and data-* attributes.');

$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
foreach (array('.before-rule', '.after-rule', '.linked-rule') as $rule) {
    $assert(!str_contains($rendered, $rule) && !str_contains($bootstrap, $rule), "No CSS body ({$rule}) appears in render() output or functions.php.");
}
$byToken = array();
foreach ($plan['assets'] as $asset) $byToken[WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}'] = $asset;
$writes = array_column($plan['writes'], null, 'target_path');
$assert(str_contains($writes[$byToken[$expected[0]]['target_path']]['payload']['data'] ?? '', '.before-rule') && str_contains($writes[$byToken[$expected[2]]['target_path']]['payload']['data'] ?? '', '.after-rule'), 'The linked theme files carry the style bodies.');
$owned = DocumentHeadContext::ownedAssets($plan['pages'])['index.html'] ?? array();
$assert(array() === array_diff($expected, array_keys($owned)), 'Every linked stylesheet is head-owned, so its enqueue is dequeued.');

fwrite(STDOUT, "Document head style link contract passed\n");
