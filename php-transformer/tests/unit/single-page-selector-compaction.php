<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;

// Repeated projected subjects can already be inside logical selector lists.
// Deduplication must reach those lists without changing specificity or strings.
$input = ':is(:where(.a):not(.pad),:where(.a):not(.pad),:where(.b):not(.pad))>a';
$output = CssSelectorListCompactor::compact($input);
if (substr_count($output, ':where(.a)') !== 1 || !str_contains($output, ':where(.b)')) {
    throw new RuntimeException('Nested projected alternatives were not deduplicated: ' . $output);
}
$quoted = '[data-label=":is(.a,.a)"]';
if ($quoted !== CssSelectorListCompactor::compact($quoted)) {
    throw new RuntimeException('Attribute string changed');
}

// A single-page artifact must take the same serialization path as a site.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><main><div class="card">One</div><div class="tile">Two</div></main>',
    'site.css' => 'main .card,main .tile,main .third{color:red}',
)))->toArray();
$css = implode('', array_column(array_filter($result['assets'], static fn(array $asset): bool => 'site.css' === ($asset['path'] ?? '')), 'content'));
if (!str_contains($css, 'main :is(.card,.tile,.third)')) {
    throw new RuntimeException('Single-page stylesheet was not compacted: ' . $css);
}
// Deduplicating identical alternatives leaves a singleton :is() containing
// :where(). The public matcher must consume the balanced argument, not stop
// at the inner close, and preserve the zero-specificity marker.
$document = new DOMDocument();
$document->loadHTML('<figure class="target wp-block-image"><img></figure>');
$selector = ':is(:where(.target).wp-block-image:where(.inline)) > img:not(be-t-0)';
$figure = $document->getElementsByTagName('figure')->item(0);
$figure->setAttribute('class', 'target wp-block-image inline');
$parsed = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse($selector);
if (!$parsed['supported'] || !\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches($document->getElementsByTagName('img')->item(0), $parsed)['matches'] || array('ids' => 0, 'classes' => 1, 'types' => 2) !== \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::specificityCounts($parsed)) {
    throw new RuntimeException('Compacted singleton logical selector lost its match or specificity');
}
echo "Single-page selector compaction passed\n";
