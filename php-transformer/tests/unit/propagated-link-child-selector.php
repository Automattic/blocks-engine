<?php
declare(strict_types=1);

/**
 * A child-combinator rule still reaches its subject after a card link is
 * pushed down into the block that holds it (Automattic/blocks-engine#7373).
 *
 * `<a class="card"><div>…</div><p><span>Label</span><span>↗</span></p></a>`
 * lowers the anchor to a group and rebuilds the href as one `<a>` wrapping
 * the paragraph's children. `.card>p>span:first-child` lost its subject:
 * the span is now `p > a > span`, or a RichText `<mark>` when another rule
 * gives it a marker. Each row asserts that a projected arm of the authored
 * letter-spacing rule matches the emitted label.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$base = '.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}.card>div{aspect-ratio:16/9}'
    . '.card>p{display:flex;justify-content:space-between;margin:16px 0 0;font-size:13px}'
    . '.card>p>span:first-child{letter-spacing:.08em}'
    . '@media(max-width:780px){.card>p>span:first-child{letter-spacing:0}}';
$cases = array(
    'plain span under the pushed-down link' => $base,
    'RichText mark under the pushed-down link' => $base . '.card span{transition:color .2s}',
);
$card = static fn (string $slug, string $label): string => '<a class="card" href="/' . $slug . '/"><div><img src="/' . $slug . '.webp" alt="" width="1600" height="900"></div>'
    . '<p><span>' . $label . '</span><span aria-hidden="true">↗</span></p></a>';

$failures = 0;
foreach ( $cases as $name => $css ) {
    $html = '<!doctype html><html lang="en"><head><title>Rooms</title><style>' . $css . '</style></head><body><main><div class="grid">'
        . $card('lighting', '02 / Lighting') . $card('office', '03 / Office') . '</div></main></body></html>';
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
    $markup = (string) ( $result['serialized_blocks'] ?? '' );
    $stylesheet = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $stylesheet .= "\n" . (string) ( $asset['content'] ?? '' );
        }
    }
    // Rules outside @media: drop the media block before reading selectors.
    $unconditional = (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $stylesheet);
    $labels = array();
    foreach ( HtmlSerialization::parseDocument($markup)->getElementsByTagName('*') as $element ) {
        if ( in_array(strtolower($element->tagName), array( 'span', 'mark' ), true ) && '02 / Lighting' === trim($element->textContent) ) {
            $labels[] = $element;
        }
    }
    $matched = false;
    preg_match_all('/([^{}]+)\{[^{}]*letter-spacing:\.08em[^{}]*\}/', $unconditional, $rules);
    foreach ( $rules[1] as $selectorList ) {
        foreach ( CssStylesheetTransformer::splitSelectorList(trim($selectorList)) ?? array() as $selector ) {
            $parsed = CssSelectorMatcher::parse($selector);
            foreach ( $labels as $label ) {
                $matched = $matched || CssSelectorMatcher::matches($label, $parsed)['matches'];
            }
        }
    }
    if ( 1 !== count($labels) || ! $matched ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $name . ': no projected letter-spacing arm matches the label (' . count($labels) . " label(s)).\nMarkup: " . $markup . "\nCSS: " . implode("\n", $rules[0]) . PHP_EOL);
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo "propagated-link-child-selector: ok\n";
