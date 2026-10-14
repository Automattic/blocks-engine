<?php
declare(strict_types=1);

/**
 * A bare source <img> lowers into a synthesized core/image <figure> that the
 * source never had. Author rules whose subject is the figure type addressed
 * authored figures only, so they must not reach that wrapper. Otherwise a
 * reset such as `figure{margin:0 0 1rem}` adds space below every image.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$html = '<html><head><style>'
    . 'figure{margin:0 0 1rem;padding:4px}'
    . 'figure,.note{border-top:1px solid red}'
    . '</style></head><body><main>'
    . '<div class="card"><img src="/a.jpg" alt="A" width="400" height="300"></div>'
    . '<figure class="authored"><img src="/b.jpg" alt="B" width="400" height="300"><figcaption>Caption</figcaption></figure>'
    . '<p class="note">Note</p>'
    . '</main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

$synthetic = 'blocks-engine-synthetic-image-figure';
$exclusion = ':not(:where(.' . $synthetic . '))';
$assert(str_contains($blocks, $synthetic), 'the bare image lowers into a synthetic figure', $blocks);

$figureSelectors = array();
$noteSelectors = array();
( new CssStylesheetTransformer() )->visitStyleRules($css, static function (string $prelude, string $body) use (&$figureSelectors, &$noteSelectors): string {
    if ( ! str_contains($body, '1rem') && ! str_contains($body, '4px') && ! str_contains($body, 'solid') ) {
        return '';
    }
    foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
        $selector = trim($selector);
        if ( 1 === preg_match('/(?:^|[\s>+~])figure\b/', $selector) ) {
            $figureSelectors[] = $selector . '{' . $body . '}';
        } elseif ( str_contains($selector, 'note') ) {
            $noteSelectors[] = $selector;
        }
    }
    return '';
});

$assert(array() !== $figureSelectors, 'the author figure rules are still projected for the authored figure', $css);
foreach ( $figureSelectors as $rule ) {
    $assert(str_contains($rule, $exclusion), 'an author figure rule stays off the synthetic image figure', $rule);
}
$assert(array() !== $noteSelectors, 'a non-figure selector in the same list is still projected', $css);
foreach ( $noteSelectors as $selector ) {
    $assert(! str_contains($selector, $synthetic), 'a non-figure selector is left unchanged', $selector);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "synthetic image figure author rules FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "synthetic image figure author rules passed: {$passes} assertions\n";
