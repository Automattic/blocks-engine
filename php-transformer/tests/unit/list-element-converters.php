<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DescriptionListElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DescriptionListElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListElementConverter;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$elementFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};
$createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs, array $innerBlocks, ?DOMElement $source): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
    'sourceTag' => $source?->tagName,
));

$state = (object) array( 'mode' => 'native' );
$listContext = new ListElementContext(
    static fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => 'pattern' === $state->mode ? array( 'blockName' => 'core/navigation' ) : null,
    static fn (array $block, DOMElement $element): array => array_merge($block, array( 'remembered' => true )),
    static fn (DOMElement $element): bool => 'structured' === $state->mode,
    static fn (DOMElement $element, array &$fallbacks): ?array => array( 'blockName' => 'core/columns' ),
    static fn (DOMElement $element): bool => 'structural' === $state->mode,
    static fn (DOMElement $element, array &$fallbacks): ?array => array( 'blockName' => 'core/group', 'attrs' => array( 'structural' => true ) ),
    static fn (DOMElement $element, array &$fallbacks): array => 'empty' === $state->mode ? array() : array( array( 'blockName' => 'core/list-item' ) ),
    static fn (DOMElement $element): bool => 'grid' === $state->mode,
    static fn (DOMElement $element): array => array( 'className' => 'grid-list' ),
    new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'presented-list' )),
    $createBlock
);
$listConverter = new ListElementConverter($listContext);
$fallbacks = array();
$assert($listConverter->handles('ul') && $listConverter->handles('ol') && ! $listConverter->handles('dl'), 'list-handles-tags');
$assert(! $listConverter->convert($elementFrom('<div></div>'), 'div', $fallbacks)->handled, 'list-unhandled-outcome');

$state->mode = 'pattern';
$result = $listConverter->convert($elementFrom('<ul><li>One</li></ul>'), 'ul', $fallbacks);
$assert('core/navigation' === ($result->block['blockName'] ?? '') && true === ($result->block['remembered'] ?? false), 'pattern-list-wins');
$state->mode = 'structured';
$assert('core/columns' === ($listConverter->convert($elementFrom('<ul></ul>'), 'ul', $fallbacks)->block['blockName'] ?? ''), 'structured-card-list');
$state->mode = 'structural';
$assert(true === ($listConverter->convert($elementFrom('<ul></ul>'), 'ul', $fallbacks)->block['attrs']['structural'] ?? false), 'structural-list');
$state->mode = 'empty';
$empty = $listConverter->convert($elementFrom('<ul></ul>'), 'ul', $fallbacks);
$assert($empty->handled && null === $empty->block, 'empty-list-handled-without-block');
$state->mode = 'native';
$native = $listConverter->convert($elementFrom('<ul><li>One</li></ul>'), 'ul', $fallbacks)->block;
$assert('core/list' === ($native['blockName'] ?? '') && 'presented-list' === ($native['attrs']['className'] ?? ''), 'native-unordered-list');
$ordered = $listConverter->convert($elementFrom('<ol><li>One</li></ol>'), 'ol', $fallbacks)->block;
$assert(true === ($ordered['attrs']['ordered'] ?? false), 'native-ordered-list');
$state->mode = 'grid';
$grid = $listConverter->convert($elementFrom('<ul><li>One</li></ul>'), 'ul', $fallbacks)->block;
$assert('grid-list' === ($grid['attrs']['className'] ?? ''), 'css-grid-list-attributes');

$state->mode = 'native';
$descriptionContext = new DescriptionListElementContext(
    new SourceElementClassifier(),
    static fn (DOMElement $element): ?array => 'description' === $state->mode ? array( 'blockName' => 'blocks-engine/description-list' ) : null,
    static fn (DOMElement $element): ?array => 'metadata' === $state->mode ? array( 'blockName' => 'core/group', 'attrs' => array( 'metadata' => true ) ) : null,
    static fn (DOMElement $element): array => 'items' === $state->mode ? array( array( 'blockName' => 'core/list-item' ) ) : array(),
    static fn (DOMElement $element): bool => 'grid-items' === $state->mode,
    static fn (DOMElement $element): array => array( 'className' => 'grid-description' ),
    new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'presented-' . strtolower($element->tagName) )),
    static fn (DOMElement $element, array &$fallbacks, bool $capture): array => 'empty' === $state->mode ? array() : array( array( 'blockName' => 'core/paragraph' ) ),
    $createBlock,
    static fn (DOMElement $element): string => $element->textContent ?? '',
    static fn (string $text): bool => '' !== trim(strip_tags($text))
);
$descriptionConverter = new DescriptionListElementConverter($descriptionContext);
$assert($descriptionConverter->handles('dl') && $descriptionConverter->handles('dt') && $descriptionConverter->handles('dd') && ! $descriptionConverter->handles('ul'), 'description-handles-tags');
$assert(! $descriptionConverter->convert($elementFrom('<div></div>'), 'div', $fallbacks)->handled, 'description-unhandled-outcome');
$state->mode = 'description';
$assert('blocks-engine/description-list' === ($descriptionConverter->convert($elementFrom('<dl></dl>'), 'dl', $fallbacks)->block['blockName'] ?? ''), 'description-list-companion');
$state->mode = 'metadata';
$assert(true === ($descriptionConverter->convert($elementFrom('<dl></dl>'), 'dl', $fallbacks)->block['attrs']['metadata'] ?? false), 'metadata-grid');
$state->mode = 'items';
$items = $descriptionConverter->convert($elementFrom('<dl></dl>'), 'dl', $fallbacks)->block;
$assert('core/list' === ($items['blockName'] ?? '') && 1 === count($items['innerBlocks'] ?? array()), 'definition-items-list');
$state->mode = 'native';
$group = $descriptionConverter->convert($elementFrom('<dl><div>Child</div></dl>'), 'dl', $fallbacks)->block;
$assert('core/group' === ($group['blockName'] ?? ''), 'description-children-group');
$state->mode = 'empty';
$emptyDescription = $descriptionConverter->convert($elementFrom('<dl></dl>'), 'dl', $fallbacks);
$assert($emptyDescription->handled && null === $emptyDescription->block, 'empty-description-list');
$state->mode = 'native';
$term = $descriptionConverter->convert($elementFrom('<dt>Term</dt>'), 'dt', $fallbacks)->block;
$assert('core/paragraph' === ($term['blockName'] ?? '') && 'Term' === ($term['attrs']['content'] ?? ''), 'definition-term-paragraph');
$state->mode = 'block-detail';
$detail = $descriptionConverter->convert($elementFrom('<dd><p>Detail</p></dd>'), 'dd', $fallbacks)->block;
$assert('core/group' === ($detail['blockName'] ?? ''), 'block-detail-group');
$state->mode = 'native';
$emptyTerm = $descriptionConverter->convert($elementFrom('<dt></dt>'), 'dt', $fallbacks);
$assert($emptyTerm->handled && null === $emptyTerm->block, 'empty-term-handled-without-block');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'List element converter tests: ' . $assertions . " passed\n";
