<?php
declare(strict_types=1);

/**
 * ButtonLinkDispatcher, exercised without an HtmlTransformer.
 *
 * The rule this pins down is the anchor class-identity split: source classes
 * belong to the saved link, and only generated geometry may ride the paragraph
 * host. While this was `ButtonLinkDispatchTrait` that rule could only be
 * observed by transforming a document and reading the serialized output.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;

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

$makeDispatcher = static function (array $overrides = array()): ButtonLinkDispatcher {
    $defaults = array(
        'isRuntimeTarget'  => static fn (DOMElement $e): bool => false,
        'recordIsland'     => static function (DOMElement $e): void {},
        'preserve'         => static fn (DOMElement $e): array => array('blockName' => 'core/html'),
        'recognize'        => static function (DOMElement $e, array &$f, array $p): ?array {
            return null;
        },
        'linkedLogo'       => static function (DOMElement $e, array &$f): ?array {
            return null;
        },
        'linkedImage'      => static fn (DOMElement $e): ?array => null,
        'linkWrapper'      => static function (DOMElement $e, array &$f): ?array {
            return null;
        },
        'presentation'     => static fn (DOMElement $e, array $p, array $g): array => array(),
        'createBlock'      => static fn (string $n, array $a, array $i, ?DOMElement $s): array => array('blockName' => $n, 'attrs' => $a),
        'attr'             => static fn (DOMElement $e, string $n): string => $e->getAttribute($n),
        'outerHtml'        => static fn (DOMElement $e): string => $e->ownerDocument->saveHTML($e),
        'safeLinkUrl'      => static fn (string $h): string => str_starts_with($h, 'javascript:') ? '' : $h,
        'hasBlockChildren' => static fn (DOMElement $e): bool => false,
        'mergeClassNames'  => static fn (string $a, string $b): string => trim($a . ' ' . $b),
        'structural'       => static fn (DOMElement $e): array => array(),
    );
    $c = array_merge($defaults, $overrides);

    return new ButtonLinkDispatcher(new ButtonLinkDispatchContext(
        $c['isRuntimeTarget'],
        $c['recordIsland'],
        $c['preserve'],
        $c['recognize'],
        $c['linkedLogo'],
        $c['linkedImage'],
        $c['linkWrapper'],
        $c['presentation'],
        $c['createBlock'],
        $c['attr'],
        $c['outerHtml'],
        $c['safeLinkUrl'],
        $c['hasBlockChildren'],
        $c['mergeClassNames'],
        $c['structural']
    ));
};

$fallbacks = array();

// A runtime-targeted anchor is preserved verbatim and never pattern-matched.
$recognizeCalled = false;
$runtimeAnchor   = $makeDispatcher(array(
    'isRuntimeTarget' => static fn (DOMElement $e): bool => true,
    'recognize'       => static function (DOMElement $e, array &$f, array $p) use (&$recognizeCalled): ?array {
        $recognizeCalled = true;
        return null;
    },
));
$assert('core/html' === ($runtimeAnchor->convertAnchor($elementFrom('<a href="/x">go</a>'), $fallbacks)['blockName'] ?? ''), 'runtime-anchor-preserved');
$assert(! $recognizeCalled, 'runtime-anchor-skips-pattern-recognition');

// A runtime-targeted button additionally records a control island.
$islandRecorded = false;
$runtimeButton  = $makeDispatcher(array(
    'isRuntimeTarget' => static fn (DOMElement $e): bool => true,
    'recordIsland'    => static function (DOMElement $e) use (&$islandRecorded): void {
        $islandRecorded = true;
    },
));
$assert('core/html' === ($runtimeButton->convertButton($elementFrom('<button>go</button>'))['blockName'] ?? ''), 'runtime-button-preserved');
$assert($islandRecorded, 'runtime-button-records-control-island');

// A plain button that matches no pattern yields nothing.
$assert(null === $makeDispatcher()->convertButton($elementFrom('<button>go</button>')), 'unmatched-button-yields-nothing');

// An empty anchor with no accessible name drops.
$assert(null === $makeDispatcher()->convertAnchor($elementFrom('<a href="/x"></a>'), $fallbacks), 'empty-anchor-drops');

// An icon-only anchor with an aria-label and a safe href survives as a paragraph host.
$iconOnly = $makeDispatcher()->convertAnchor($elementFrom('<a href="/x" aria-label="Home"></a>'), $fallbacks);
$assert('core/paragraph' === ($iconOnly['blockName'] ?? ''), 'icon-only-anchor-survives');

// The same anchor with an unsafe href does not qualify and drops.
$assert(null === $makeDispatcher()->convertAnchor($elementFrom('<a href="javascript:void(0)" aria-label="Home"></a>'), $fallbacks), 'icon-only-anchor-with-unsafe-href-drops');

// Class identity split: source classes stay on the saved link, generated
// geometry classes ride the paragraph host.
$split = $makeDispatcher(array(
    'presentation' => static fn (DOMElement $e, array $p, array $g): array => array(
        'className' => 'source-class generated-geometry',
        'anchor'    => 'should-be-dropped',
    ),
));
$splitBlock = $split->convertAnchor($elementFrom('<a href="/x" class="source-class">text</a>'), $fallbacks);
$assert('generated-geometry' === ($splitBlock['attrs']['className'] ?? ''), 'source-classes-stripped-from-paragraph-host', (string) ($splitBlock['attrs']['className'] ?? 'unset'));
$assert(! array_key_exists('anchor', $splitBlock['attrs']), 'anchor-attribute-stays-on-the-saved-link');

// When nothing but source classes were mapped, className is dropped entirely
// rather than emitted empty.
$allSource = $makeDispatcher(array(
    'presentation' => static fn (DOMElement $e, array $p, array $g): array => array('className' => 'source-class'),
));
$allSourceBlock = $allSource->convertAnchor($elementFrom('<a href="/x" class="source-class">text</a>'), $fallbacks);
$assert(! array_key_exists('className', $allSourceBlock['attrs']), 'empty-classname-is-dropped');

// An absolutely positioned fragment link gets the carrier class, because its
// positioning cannot ride the saved anchor.
$positioned = $makeDispatcher(array(
    'structural' => static fn (DOMElement $e): array => array('position' => 'absolute'),
));
$positionedBlock = $positioned->convertAnchor($elementFrom('<a href="#section">text</a>'), $fallbacks);
$assert(
    str_contains((string) ($positionedBlock['attrs']['className'] ?? ''), ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS),
    'positioned-fragment-link-gets-carrier-class'
);

// A statically positioned fragment link does not.
$staticFragment = $makeDispatcher(array(
    'structural' => static fn (DOMElement $e): array => array('position' => 'static'),
));
$assert(
    ! str_contains((string) ($staticFragment->convertAnchor($elementFrom('<a href="#section">text</a>'), $fallbacks)['attrs']['className'] ?? ''), ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS),
    'static-fragment-link-has-no-carrier-class'
);

// A positioned anchor with role=button is a control, not a fragment link.
$roleButton = $makeDispatcher(array(
    'structural' => static fn (DOMElement $e): array => array('position' => 'absolute'),
));
$assert(
    ! str_contains((string) ($roleButton->convertAnchor($elementFrom('<a href="#x" role="button">text</a>'), $fallbacks)['attrs']['className'] ?? ''), ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS),
    'role-button-anchor-is-not-a-fragment-link'
);

// Dispatch precedence: a linked logo wins over pattern recognition.
$logoFirst = $makeDispatcher(array(
    'linkedLogo' => static function (DOMElement $e, array &$f): ?array {
        return array('blockName' => 'core/site-logo');
    },
    'recognize'  => static function (DOMElement $e, array &$f, array $p): ?array {
        return array('blockName' => 'core/buttons');
    },
));
$assert('core/site-logo' === ($logoFirst->convertAnchor($elementFrom('<a href="/"><svg></svg></a>'), $fallbacks)['blockName'] ?? ''), 'linked-logo-precedes-pattern-recognition');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Button link dispatcher tests: ' . $assertions . " passed\n";
