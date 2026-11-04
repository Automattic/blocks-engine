<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTreeCache;

$css = '@media screen{/* untouched */.note{color:red}}';
$files = array(array('path' => 'shared.css', 'content' => $css));
foreach (array('one', 'two', 'three') as $page) {
    $files[] = array('path' => "$page.html", 'metadata' => array('compilation' => array('scope' => 'page', 'id' => "$page.html")), 'content' => '<html><head><link rel="stylesheet" href="shared.css"><style>.page-' . $page . '{color:blue}</style></head><body><main><p class="note">' . $page . '</p></main></body></html>');
}
$artifact = array('entrypoint' => 'one.html', 'files' => $files);
$pageTrees = array();
$compiler = new ArtifactCompiler(beforeDocumentTransform: static function () use (&$pageTrees): void {
    foreach ($pageTrees as $tree) {
        if (null !== $tree->get()) throw new RuntimeException('A per-page memo entry survived into the next page.');
    }
    $pageTrees[] = WeakReference::create(CssRuleTreeCache::parse('.page-probe{color:red}'));
});
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
foreach ($pageTrees as $tree) {
    if (null !== $tree->get()) throw new RuntimeException('A per-page memo entry survived its page.');
}
$compiler->compose($shared, array_values($receipts));
$counts = $compiler->cssTokenizationCounts();
if (1 !== ($counts[$css] ?? 0) || array(1) !== array_values(array_unique($counts))) {
    throw new RuntimeException('Shared author stylesheets must be tokenized exactly once across staged pages.');
}
foreach (array_keys($counts) as $source) {
    if ($source !== $css) throw new RuntimeException('Only shared-plan author stylesheet entries may survive compilation.');
}
$other = new ArtifactCompiler();
if (array() !== $other->cssTokenizationCounts()) throw new RuntimeException('CSS parses leaked across compiler instances.');

$memo = new CssRuleTreeCache();
$probe = null;
try {
    $memo->runPage(static function () use (&$probe): void {
        $probe = WeakReference::create(CssRuleTreeCache::parse('.exception{color:red}'));
        throw new RuntimeException('expected');
    });
} catch (RuntimeException $error) {
    if ('expected' !== $error->getMessage()) throw $error;
}
if (null !== $probe->get()) throw new RuntimeException('An exceptional page retained its memo.');
echo "Staged CSS tokenization contract passed\n";
