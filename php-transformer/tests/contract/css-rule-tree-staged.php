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
// All syntax entry points must return exactly the standalone shape and bytes.
$styles = (new ReflectionClass(\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver::class))->newInstanceWithoutConstructor();
$parses = static function () use ($styles): array {
    $results = array();
    foreach (array('', '0', 'a, :is(b, c)', 'a[', 'a > :not(b, :has(> c))', 'a/**/ + b', 'a\\,b') as $source) {
        $results[] = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList($source);
        $results[] = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer::tokenize($source);
        $results[] = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::functionalTypeSpans($source);
    }
    foreach (array('', '0', 'color:red; color: blue !important; color: green; --X: var(--Y, a); font: italic 16px/2 serif', 'content:"a;b,c"; background:url("a;b")') as $source) {
        foreach (array(array(';'), array(','), array(':', ';'), array()) as $delimiters) {
            $results[] = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevel($source, $delimiters);
        }
        $results[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations::verbatim($source);
        $results[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations::verbatim($source, true);
    }
    foreach (array('screen', 'print', '(min-width: 40em)', '(width > 600px)', 'not screen, (max-width: 10px)') as $source) {
        foreach (array(320.0, 640.0, 640.00001, 1200.0) as $width) {
            foreach (array(16.0, 20.0) as $font) {
                $results[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade::mediaConditionApplies($source, $width, $font);
            }
        }
    }
    return $results;
};
$expected = serialize($parses());
foreach (array(1, 2) as $page) {
    $memo->runPage(static function () use ($parses, $expected): void {
        if ($expected !== serialize($parses())) throw new RuntimeException('Memoized CSS syntax changed output.');
    });
}

// Count misses directly, including falsy results, exact source keys and scope restoration.
$builds = 0;
$read = static function (string $source = '0') use (&$builds): array|int {
    return CssRuleTreeCache::memo('probe', $source, static function () use (&$builds): int {
        ++$builds;
        return 0;
    });
};
$read();
$read();
if (2 !== $builds) throw new RuntimeException('Standalone callers retained a global memo.');
$memo->runPage(static function () use ($read): void { $read(); $read(); $read('00'); });
$memo->runPage(static function () use ($read): void { $read(); $read('00'); });
if (4 !== $builds) throw new RuntimeException('Exact-key syntax memos were not shared across pages.');
(new CssRuleTreeCache())->runPage(static function () use ($read): void { $read(); });
if (5 !== $builds) throw new RuntimeException('Syntax memo leaked between compilers.');
$memo->runPage(static function () use ($read): void {
    try {
        (new CssRuleTreeCache())->runPage(static function () use ($read): void {
            $read();
            throw new RuntimeException('nested');
        });
    } catch (RuntimeException $error) {
        if ('nested' !== $error->getMessage()) throw $error;
    }
    $read();
});
if (6 !== $builds) throw new RuntimeException('Exceptional nested scope did not restore the compiler memo.');
$read();
if (7 !== $builds) throw new RuntimeException('Compiler memo remained active outside its operation.');
$budget = new CssRuleTreeCache();
$oversizedBuilds = 0;
$budget->runPage(static function () use (&$oversizedBuilds): void {
    for ($i = 0; $i < 2; ++$i) {
        CssRuleTreeCache::memo('budget', 'large', static function () use (&$oversizedBuilds): array {
            ++$oversizedBuilds;
            return array(str_repeat('x', 4194305));
        });
    }
});
if (2 !== $oversizedBuilds) throw new RuntimeException('Oversized syntax results escaped the retention budget.');
$properties = (new ReflectionObject($memo))->getProperty('strings')->getValue($memo);
foreach ($properties as $entries) {
    foreach ($entries as $value) {
        foreach (is_array($value) ? $value : array($value) as $scalar) {
            if (!is_int($scalar) && !is_string($scalar)) throw new RuntimeException('Syntax memo retained non-flat data.');
        }
    }
}
echo "Staged CSS tokenization contract passed\n";
