<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$sizes = array();
foreach (array(2, 8, 32) as $count) {
    $html = '<style>*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}.hero>*{position:relative}.btn{display:inline-flex;padding:.5rem 1rem;background:#123;color:#fff}</style><main><section class="hero"><h1>T</h1>';
    for ($i = 0; $i < $count; ++$i) {
        $html .= '<a class="btn" href="/p' . $i . '">Go ' . $i . '</a>';
    }
    $result = (new HtmlTransformer())->transform($html . '</section></main>')->toArray();
    $css = implode('', array_map(
        static fn (array $asset): string => 'author-css' === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        $result['assets'] ?? array()
    ));
    $sizes[$count] = strlen($css);
    $document = new DOMDocument();
    $document->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $links = (new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-button__link ")]');
    $assert(false !== $links && $count === $links->length, 'All controls lower to button links.');
    $rules = array();
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use (&$rules, $assert): void {
        // Only .btn owns these declarations; all other source subjects are universal.
        if (!str_contains($body, 'display:inline-flex')) {
            $assert(!str_contains($selector, 'blocks-engine-control-'), 'Universal subjects must not enumerate control markers: ' . $selector);
        } else {
            $assert(str_contains($selector, 'blocks-engine-control-'), 'The non-universal .btn subject keeps its control identity.');
        }
        $rules[] = array($selector, $body);
    });
    foreach ($links as $link) {
        $buttonPaint = false;
        $resetPaint = false;
        $assert(str_contains($css, '.hero>:where(.blocks-engine-role-control)'), 'Universal child arms retain their ancestor and combinator.');
        foreach ($rules as [$prelude, $body]) {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $match = CssSelectorMatcher::matches($link, CssSelectorMatcher::parse($selector));
                if (!$match['supported'] || !$match['matches']) continue;
                $buttonPaint = $buttonPaint || str_contains($body, 'background:#123;color:#fff');
                $resetPaint = $resetPaint || (str_contains($body, 'box-sizing:border-box') && str_contains($selector, ':where(.blocks-engine-role-control)>'));
            }
        }
        $assert($buttonPaint, '.btn declarations still reach each button link.');
        $assert($resetPaint, 'The universal reset reaches each button link through the shared role.');
    }
}
$assert(abs($sizes[32] - $sizes[2]) < 200, 'Projected bytes must not scale with controls: ' . json_encode($sizes));
echo 'universal-subject-control-arm: ok ' . json_encode($sizes) . "\n";
