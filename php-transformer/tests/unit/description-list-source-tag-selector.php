<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) {
        throw new RuntimeException($message);
    }
};

// Author type selectors that reach description-list rows are rewritten to the
// source div marker, so the generated rows must carry that marker to match.
$html = '<style>.intro dl div{border-top:1px solid #ccc}dl + div{color:red}</style>'
    . '<main><section class="intro"><dl><div><dt>Color story</dt><dd>Spring · Summer</dd></div><div><dt>Forms</dt><dd>Plates · Bowls</dd></div></dl><div>After</div></section></main>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'Description-list rows with source markers stay editor-valid');

$css = '';
foreach ( $result['assets'] as $asset ) {
    if ( 'author-css' === ($asset['source'] ?? '') ) {
        $css .= (string) $asset['content'];
    }
}
$projected = array();
( new CssStylesheetTransformer() )->transformStyleRules($css, static function (string $selector, string $body) use (&$projected): string {
    $projected[$body] = $selector;
    return $selector . '{' . $body . '}';
});

$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$rows = iterator_to_array($xpath->query('//dl/div'));
$after = $xpath->query('//*[normalize-space(text())="After"]')->item(0);
$assert(2 === count($rows) && $after instanceof DOMElement, 'Rows and the following sibling are rendered');

foreach ( array(
    'descendant row selector' => array( 'body' => 'border-top:1px solid #ccc', 'rows' => true, 'after' => false ),
    'sibling selector stays rewritten' => array( 'body' => 'color:red', 'rows' => false, 'after' => true ),
) as $case => $expected ) {
    $selector = $projected[$expected['body']] ?? '';
    $assert(1 !== preg_match('/(?:^|[\s>+~])div\b/', $selector), $case . ': the div type selector is rewritten to its source marker, got ' . $selector);
    $parsed = CssSelectorMatcher::parse($selector);
    $assert($parsed['supported'], $case . ': projected selector is matchable');
    foreach ( $rows as $row ) {
        $assert($expected['rows'] === CssSelectorMatcher::matches($row, $parsed)['matches'], $case . ': row match is ' . var_export($expected['rows'], true) . ' for ' . $selector . ' on ' . $dom->saveHTML($row));
    }
    $assert($expected['after'] === CssSelectorMatcher::matches($after, $parsed)['matches'], $case . ': following sibling match is ' . var_export($expected['after'], true));
}

echo "Description-list source tag selector passed\n";
