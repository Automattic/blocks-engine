<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

// Subresource Integrity metadata describes exact bytes. The plan rewrites
// local stylesheets and scripts (asset URLs), so a digest copied from the
// source must describe the bytes the theme actually serves, or the browser
// blocks the whole resource.
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$passes): void {
    if (!$condition) throw new RuntimeException($message);
    ++$passes;
};
$sri = static fn(string $algorithm, string $bytes): string => $algorithm . '-' . base64_encode(hash($algorithm, $bytes, true));
// Browser check: the strongest listed algorithm wins; any digest of it may match.
$validates = static function (string $integrity, string $bytes): bool {
    $digests = array();
    foreach (preg_split('/\s+/', trim($integrity)) ?: array() as $token) if (preg_match('/^(sha256|sha384|sha512)-([A-Za-z0-9+\/=_-]+)/', $token, $match)) $digests[$match[1]][] = $match[2];
    foreach (array('sha512', 'sha384', 'sha256') as $algorithm) if (isset($digests[$algorithm])) return in_array(base64_encode(hash($algorithm, $bytes, true)), $digests[$algorithm], true);
    return true;
};

$css = 'main{background:url(/media/bg.svg)}h1{color:#123}';
$js = 'window.appLoaded = true;';
$widget = 'document.body.insertAdjacentHTML("beforeend", \'<img src="/media/bg.svg" alt="">\');';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    // The id on <style> makes this page carry a declared head context.
    'index.html' => '<!doctype html><html><head><title>Home</title><meta name="viewport" content="width=device-width, initial-scale=1"><style id="inline-owned">h1{margin:0}</style>'
        . '<link rel="stylesheet" type="text/css" href="/css/site.css" integrity="' . $sri('sha384', $css) . '" crossorigin="anonymous">'
        . '<link rel="preload" as="style" href="/css/site.css" integrity="' . $sri('sha256', $css) . ' ' . $sri('sha512', $css) . '">'
        . '<link rel="stylesheet" href="https://cdn.example.test/vendor.css" integrity="sha384-externalDigestKept" crossorigin="anonymous">'
        . '<script src="/js/app.js" integrity="' . $sri('sha384', $js) . '" crossorigin="anonymous"></script>'
        . '</head><body><main><h1>Home</h1></main></body></html>',
    'about/index.html' => '<!doctype html><html><head><title>About</title><link rel="stylesheet" href="/css/site.css" integrity="' . $sri('sha384', $css) . '" crossorigin="anonymous"></head><body><main><h1>About</h1></main>'
        . '<script src="/js/app.js" integrity="' . $sri('sha384', $js) . '"></script><script src="/js/widget.js" integrity="' . $sri('sha384', $widget) . '"></script></body></html>',
    'css/site.css' => $css,
    'js/app.js' => $js,
    'js/widget.js' => $widget,
    'media/bg.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>',
));

$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The neutral fixture compiles to a WordPress site plan.');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$served = array();
foreach ($resolved['writes'] as $write) if ('utf8' === ($write['payload']['encoding'] ?? null)) $served[$write['target_path']] = $write['payload']['data'];
$servedByToken = array();
foreach ($resolved['reference_tokens'] as $token) if (isset($served[$token['target_path']])) $servedByToken[WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}'] = $served[$token['target_path']];
$finalCss = $served['assets/css/site.css'] ?? '';
$assert('' !== $finalCss && $finalCss !== $css, 'The plan rewrites the stylesheet asset URL, so served bytes differ from the source bytes.');
$assert($js === ($served['assets/js/app.js'] ?? null), 'An asset without references is served byte for byte.');
$assert(str_contains($served['assets/js/widget.js'] ?? '', 'https://example.test/theme/'), 'A script whose references resolve to the destination theme URI cannot be known before resolution.');

$tags = static function (string $html, string $tag): array {
    $rows = array();
    foreach (HtmlTagScanner::scan($html, $tag) as $row) $rows[] = HtmlTagScanner::attributes($row['tag']);
    return $rows;
};
$head = DocumentHeadContext::fromPlan($resolved, 'index.html');
$links = $tags($head, 'link');
$assert(4 === count($links) + count($tags($head, 'script')), 'The head context emits the three links and the script.');
$assert($sri('sha384', $finalCss) === ($links[0]['integrity'] ?? null), 'A head stylesheet link carries the sha384 digest of the served stylesheet, not the source digest.');
$assert($sri('sha256', $finalCss) . ' ' . $sri('sha512', $finalCss) === ($links[1]['integrity'] ?? null), 'A preload link keeps every source algorithm, recomputed over the served bytes.');
$assert('sha384-externalDigestKept' === ($links[2]['integrity'] ?? null) && 'anonymous' === ($links[2]['crossorigin'] ?? null), 'An external link keeps its source integrity unchanged.');
$headScripts = $tags($head, 'script');
$assert($sri('sha384', $js) === ($headScripts[0]['integrity'] ?? null), 'A head script served byte for byte keeps its valid source digest.');

$about = array_column($resolved['pages'], null, 'source_path')['about/index.html'];
$assert(!isset($about['document_metadata']['head']), 'The about page has no declared head context, so its declarations drive loading.');
$assert($sri('sha384', $finalCss) === ($about['document_metadata']['links'][0]['integrity'] ?? null), 'Link declarations carry the digest of the served stylesheet for every plan consumer.');
$assert($sri('sha384', $js) === ($about['document_metadata']['scripts'][0]['integrity'] ?? null), 'An unchanged script declaration keeps its valid digest.');
$assert(!array_key_exists('integrity', $about['document_metadata']['scripts'][1]), 'A script whose served bytes depend on the destination drops the digest instead of shipping a stale one.');

$bootstrap = $served['functions.php'] ?? '';
$assert(str_contains($bootstrap, "'integrity' => '" . $sri('sha384', $js) . "'") && !str_contains($bootstrap, $sri('sha384', $widget)) && !str_contains($bootstrap, $sri('sha384', $css)), 'The generated theme registers scripts with valid digests only and never emits a source stylesheet digest.');

// Class check: every integrity the plan declares for a local asset validates
// against the bytes the theme serves for that asset.
$checked = 0;
foreach ($resolved['pages'] as $page) {
    $rows = array_merge($page['document_metadata']['links'], $page['document_metadata']['scripts']);
    foreach ($page['document_metadata']['head']['elements'] ?? array() as $element) if (isset($element['attributes']['integrity'])) $rows[] = array('integrity' => $element['attributes']['integrity'], 'asset_reference' => $element['asset_reference'] ?? null);
    foreach ($rows as $row) {
        if (!isset($row['integrity']) || !isset($servedByToken[$row['asset_reference'] ?? ''])) continue;
        $assert($validates($row['integrity'], $servedByToken[$row['asset_reference']]), 'Declared integrity validates against the served bytes: ' . $page['source_path'] . ' ' . $row['integrity']);
        ++$checked;
    }
}
$assert(8 === $checked, 'All eight local integrity declarations were checked.');

fwrite(STDOUT, "Rewritten asset integrity contract passed: {$passes} assertions\n");
