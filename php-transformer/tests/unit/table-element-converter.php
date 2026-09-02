<?php
declare(strict_types=1);

/**
 * TableElementConverter routing, exercised without an HtmlTransformer.
 *
 * The ordering here is the contract: layout-table shapes must be claimed before
 * the element reaches data-table classification, otherwise a table used purely
 * for layout would become a semantic core/table.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed');
};

$makeConverter = static function (array $overrides = array()): TableElementConverter {
    $defaults = array(
        'nested'      => static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/columns', 'via' => 'nested');
        },
        'media'       => static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/columns', 'via' => 'media');
        },
        'preserve'    => static fn (DOMElement $e): array => array('blockName' => 'core/html'),
        'presentation' => static fn (DOMElement $e, array $p, array $g): array => array('className' => 'pres'),
        'tableAttrs'  => static fn (DOMElement $e): array => array('hasFixedLayout' => true),
        'createBlock' => static fn (string $n, array $a, array $i, ?DOMElement $s): array => array('blockName' => $n, 'attrs' => $a),
    );
    $c = array_merge($defaults, $overrides);

    return new TableElementConverter(new TableElementContext(
        new TableClassificationPolicy(),
        $c['nested'],
        $c['media'],
        $c['preserve'],
        new ElementPresentationResolverFixture($c['presentation']),
        $c['tableAttrs'],
        $c['createBlock']
    ));
};

$fallbacks = array();
$converter = $makeConverter();

$assert($converter->handles('table'), 'handles-table');
foreach (array('tr', 'td', 'div', 'p') as $tag) {
    $assert(! $converter->handles($tag), 'does-not-handle-' . $tag);
}
$assert(! $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks)->handled, 'unowned-tag-unhandled');

// A plain data table becomes a native core/table carrying both presentation and
// table-specific attributes.
$dataTable = $converter->convert(
    $elementFrom('<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'),
    'table',
    $fallbacks
)->block;
$assert('core/table' === ($dataTable['blockName'] ?? ''), 'data-table-is-core-table');
$assert('pres' === ($dataTable['attrs']['className'] ?? ''), 'data-table-carries-presentation-attributes');
$assert(true === ($dataTable['attrs']['hasFixedLayout'] ?? false), 'data-table-carries-table-attributes');

// A layout table nested inside another table is projected to columns and never
// reaches data-table classification.
$nested = $converter->convert(
    $elementFrom('<table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table>')
        ->getElementsByTagName('table')->item(0),
    'table',
    $fallbacks
)->block;
$assert('core/columns' === ($nested['blockName'] ?? ''), 'nested-layout-table-is-columns');
$assert('nested' === ($nested['via'] ?? ''), 'nested-layout-table-uses-nested-projection');

// A spanning table is not representable as core/table and is preserved rather
// than lossily flattened.
$spanning = $converter->convert(
    $elementFrom('<table><tr><td colspan="2">x</td></tr><tr><td>y</td><td>z</td></tr></table>'),
    'table',
    $fallbacks
)->block;
$assert('core/html' === ($spanning['blockName'] ?? ''), 'spanning-table-is-preserved', (string) ($spanning['blockName'] ?? 'null'));

// Layout projection wins over preservation: a nested table is unrepresentable by
// classification, but the layout branch claims it first and yields columns.
$nestedUnrepresentable = $converter->convert(
    $elementFrom('<table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table>'),
    'table',
    $fallbacks
)->block;
$assert('core/columns' === ($nestedUnrepresentable['blockName'] ?? ''), 'layout-projection-precedes-preservation');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Table element converter tests: ' . $assertions . " passed\n";
