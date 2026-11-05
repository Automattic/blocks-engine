<?php
declare(strict_types=1);

// Reduced public-compiler fixture from issue #4457.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

foreach (array('h1' => true, 'p' => true, 'bare-h1' => false) as $case => $preserved) {
    $tag = 'p' === $case ? 'p' : 'h1';
    $style = $preserved ? '<style>h1{font-size:56px;line-height:70px;margin:32px 0;white-space:pre-wrap}h1:first-child{margin-top:0}h1[data-rte-preserve-empty]:empty::before,p[data-rte-preserve-empty]:empty::before{content:"";display:inline-block}</style>' : '';
    $html = $style . '<div class="t"><' . $tag . ' style="text-align:center" data-rte-preserve-empty="true"></' . $tag . '><h1 style="text-align:center">Title</h1></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = $result['serialized_blocks'];
    $css = implode('\n', array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $empty = $dom->getElementsByTagName($tag)->item(0);
    $check = static function (bool $ok, string $message) use ($case): void {
        if (!$ok) { fwrite(STDERR, "$case: $message\n"); exit(1); }
    };
    if (!$preserved) {
        $check(1 === $dom->getElementsByTagName('h1')->length && 'Title' === $empty->textContent, 'boxless heading stays dropped');
        continue;
    }
    $check('' === $empty->textContent && str_contains($markup, 'wp:' . ('p' === $tag ? 'paragraph' : 'heading')), 'empty native block precedes Title');
    if ('h1' === $tag) {
        $check(str_contains($markup, '"level":1') && str_contains($empty->getAttribute('class'), 'has-text-align-center'), 'heading level and alignment survive');
    }
    $matching = false;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (!str_contains($rule[2], 'content:""') || !str_contains($rule[2], 'display:inline-block')) continue;
        foreach (explode(',', $rule[1]) as $selector) {
            $host = CssSelectorMatcher::pseudoElementHost(trim($selector));
            if (null !== $host && CssSelectorMatcher::matches($empty, $host['parsed'])['matches']) $matching = true;
        }
    }
    $check($matching, 'projected ::before line-box rule matches saved empty element');
    $check(str_contains($css, '.editor-styles-wrapper ' . $tag . '.blocks-engine-empty-source-paragraph[data-empty="true"]{line-height:0}'), 'editor padding line collapses');
    $check(str_contains($css, '[data-empty="true"]::before'), 'editor gets authored empty-rule variant');
}
echo "empty rich-text source box passed\n";
