<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css {
    // A counting double: the production pruner is final and has no call-count API.
    // This standalone test loads it before the autoloader can load the real class.
    final class UnreachableSelectorPruner
    {
        public int $calls = 0;

        public function __construct(private string $absent) {}

        public function prune(string $css, bool $analysis = false): string
        {
            if (! $analysis) throw new \RuntimeException('Assets must use analysis pruning.');
            ++$this->calls;
            return str_replace($this->absent, '', $css);
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;
    use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

    function expectSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) throw new RuntimeException($message);
    }

    $document = new DOMDocument();
    $body = $document->createElement('body');
    $document->appendChild($body);
    $assets = array(
        array('path' => 'style.css', 'source_path' => 'source.css', 'content' => '.live{color:red}.dead{color:blue}', 'cascade_content' => '.dead{color:blue}.live{color:red}', 'source_hash' => 'authored-hash', 'media' => 'screen'),
        array('path' => 'empty.css', 'source_path' => 'empty.css', 'content' => '', 'source_hash' => 'empty-hash', 'media' => ''),
    );
    $analysis = new AuthorStyleAnalysis('', '', $assets, $body);
    expectSame($assets, $analysis->stylesheetAssets(true), 'Without a pruner, preserve raw assets.');
    $pruner = new UnreachableSelectorPruner('.dead{color:blue}');
    $analysis->siteSelectorPruner = $pruner;
    $expected = $assets;
    $expected[0]['content'] = '.live{color:red}';
    $expected[0]['cascade_content'] = '.live{color:red}';
    expectSame($expected, $analysis->stylesheetAssets(true), 'Prune both content variants, preserving metadata and order.');
    expectSame($expected, $analysis->stylesheetAssets(true), 'Repeated reads must be byte-identical.');
    expectSame(3, $pruner->calls, 'Each present content field must be pruned only once, including empty content.');
    expectSame($assets, $analysis->stylesheetAssets(), 'Raw reads must not return pruned assets.');
    $copy = $analysis->stylesheetAssets(true);
    $copy[0]['content'] = 'caller mutation';
    expectSame($expected, $analysis->stylesheetAssets(true), 'Returned arrays must not expose mutable cache state.');

    // There is no asset setter: replacing readonly assets requires a new analysis.
    $replacementAssets = $assets;
    $replacementAssets[0]['content'] = '.dead{color:blue}.new{color:green}';
    $replacement = new AuthorStyleAnalysis('', '', $replacementAssets, $body);
    $replacement->siteSelectorPruner = $pruner;
    $replacementExpected = $expected;
    $replacementExpected[0]['content'] = '.new{color:green}';
    expectSame($replacementExpected, $replacement->stylesheetAssets(true), 'New asset inputs must be freshly pruned.');
    expectSame(6, $pruner->calls, 'A new asset list requires one fresh pass.');

    $otherPruner = new UnreachableSelectorPruner('.live{color:red}');
    $analysis->siteSelectorPruner = $otherPruner;
    $otherExpected = $assets;
    $otherExpected[0]['content'] = '.dead{color:blue}';
    $otherExpected[0]['cascade_content'] = '.dead{color:blue}';
    expectSame($otherExpected, $analysis->stylesheetAssets(true), 'Replacing the public pruner must invalidate the memo.');
    expectSame($otherExpected, $analysis->stylesheetAssets(true), 'Replacement pruner results must also be memoized.');
    expectSame(3, $otherPruner->calls, 'The replacement pruner needs just one pass.');
    $analysis->siteSelectorPruner = null;
    expectSame($assets, $analysis->stylesheetAssets(true), 'Removing the pruner restores raw output.');
    $analysis->siteSelectorPruner = $otherPruner;
    expectSame($otherExpected, $analysis->stylesheetAssets(true), 'Reinstalling a pruner after removal recomputes output.');
    expectSame(6, $otherPruner->calls, 'Observed removal must clear the memo.');

    echo "Author style analysis pruned assets passed.\n";
}
