<?php
declare(strict_types=1);

/**
 * ButtonLinkDispatcher, exercised without an HtmlTransformer.
 *
 * The rule this pins down is the anchor class-identity split: source classes
 * belong to the saved link, and only generated geometry may ride the paragraph
 * host. Button-shaped controls go through the pattern registry first.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ButtonLinkLeftoversFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

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

$makeRuntimeIslands = static function (array $domSelectors): RuntimeIslandAnalyzer {
    $behavioral = array_fill_keys($domSelectors, true);
    $session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
    $session->installRuntimeSelectorState(new RuntimeSelectorState(
        array_fill_keys($domSelectors, true),
        $behavioral,
        array()
    ));
    $metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $e): string => strtolower($e->tagName));
    $descendants = static function (DOMElement $element): array {
        $out = array();
        foreach ($element->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }
        return $out;
    };

    return new RuntimeIslandAnalyzer(new RuntimeIslandContext(
        $session,
        new SourceElementClassifier(),
        $descendants,
        static fn (DOMElement $e): array => array(),
        static fn (string $h): ?DOMElement => null,
        static fn (DOMElement $e): bool => false
    ), new PseudoFormAnalyzer($metadataBuilder, static fn (DOMElement $e): string => strtolower($e->tagName)));
};

$makeDispatcher = static function (array $overrides = array()) use ($makeRuntimeIslands): ButtonLinkDispatcher {
    $createBlock = $overrides['createBlock'] ?? new SourceBlockCreatorFixture(static fn (string $n, array $a, array $i, ?DOMElement $s): array => array('blockName' => $n, 'attrs' => $a));
    $presentation = $overrides['presentation'] ?? static fn (DOMElement $e, array $p, array $g): array => array();
    $structural = $overrides['structural'] ?? static fn (DOMElement $e): array => array();

    return new ButtonLinkDispatcher(new ButtonLinkDispatchContext(
        new SourceElementClassifier(),
        new ElementPresentationResolverFixture($presentation, structuralPresentationDeclarations: $structural),
        $createBlock,
        $overrides['patternRecognizers'] ?? PatternRecognizerRegistry::createDefault(),
        $overrides['patternContext'] ?? new PatternContext(
            static fn (DOMElement $e, array $g = array()): array => array(),
            $createBlock
        ),
        $overrides['runtimeIslands'] ?? null,
        $overrides['formRuntimeIslands'] ?? null,
        $overrides['leftovers'] ?? null
    ));
};

$fallbacks = array();

// A wrapped native button is preserved by ButtonsPattern via the registry,
// not by a duplicate dispatcher predicate.
$wrapped = $makeDispatcher()->convertAnchor($elementFrom('<a href="/x"><button type="submit" class="cta">Go</button></a>'), $fallbacks);
$assert('core/html' === ($wrapped['blockName'] ?? ''), 'wrapped-button-preserved-by-buttons-pattern');

// A runtime-targeted anchor is a leftover after pattern recognition declines.
$runtimeAnchor = $makeDispatcher(array(
    'runtimeIslands' => $makeRuntimeIslands(array('#mount')),
));
$assert('core/html' === ($runtimeAnchor->convertAnchor($elementFrom('<a href="/x" id="mount">go</a>'), $fallbacks)['blockName'] ?? ''), 'runtime-anchor-preserved-as-leftover');

// A runtime-targeted button additionally records a control island.
$islandRecorded = false;
$runtimeButton  = $makeDispatcher(array(
    'runtimeIslands' => $makeRuntimeIslands(array('#mount')),
    'formRuntimeIslands' => new FormRuntimeIslandRecorder(
        new FormControlMetadataBuilder(static fn (DOMElement $e): string => strtolower($e->tagName)),
        static function (DOMElement $e, string $kind) use (&$islandRecorded): void {
            $islandRecorded = 'control' === $kind;
        },
        static fn (DOMElement $e): array => array(),
        static fn (DOMElement $e): array => array()
    ),
));
$assert('core/html' === ($runtimeButton->convertButton($elementFrom('<button id="mount">go</button>'))['blockName'] ?? ''), 'runtime-button-preserved-as-leftover');
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

// Pattern recognition precedes linked-logo leftovers: a wrapped button is
// preserved even when leftover conversion would emit a logo.
$logoIgnored = $makeDispatcher(array(
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/site-logo');
        }
    ),
));
$assert('core/html' === ($logoIgnored->convertAnchor($elementFrom('<a href="/" class="logo"><button type="submit" class="cta">Go</button></a>'), $fallbacks)['blockName'] ?? ''), 'pattern-recognition-precedes-linked-logo-leftover');

// A brand lockup whose spans stack (column flex link) converts as a link
// wrapper group instead of a paragraph host that merges its two lines.
$declarationsFor = static fn (array $map): Closure => static function (DOMElement $e) use ($map): array {
    return $map[$e->getAttribute('class')] ?? array();
};
$stacked = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'flex flex-col' => array( 'display' => 'flex', 'flex-direction' => 'column' ),
    )),
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/group');
        }
    ),
));
$assert(
    'core/group' === ($stacked->convertAnchor($elementFrom('<a class="flex flex-col" href="#home"><span>Name</span><span>Role</span></a>'), $fallbacks)['blockName'] ?? ''),
    'column-flex-brand-lockup-converts-as-link-wrapper'
);

// A row flex link keeps its items on one line, so it stays a paragraph host.
$rowFlex = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'flex' => array( 'display' => 'flex' ),
    )),
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/group');
        }
    ),
));
$assert(
    'core/paragraph' === ($rowFlex->convertAnchor($elementFrom('<a class="flex" href="/"><span>Icon</span><span>Label</span></a>'), $fallbacks)['blockName'] ?? ''),
    'row-flex-link-stays-paragraph-host'
);

// Without a navigable href there is no link to propagate, so a stacked lockup
// keeps today's paragraph host rather than a wrapper group it cannot fill.
$unlinked = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'flex flex-col' => array( 'display' => 'flex', 'flex-direction' => 'column' ),
    )),
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/group');
        }
    ),
));
$assert(
    'core/paragraph' === ($unlinked->convertAnchor($elementFrom('<a class="flex flex-col"><span>Name</span><span>Role</span></a>'), $fallbacks)['blockName'] ?? ''),
    'stacked-anchor-without-href-stays-paragraph-host'
);

// A stacked lockup whose wrapper conversion yields nothing still falls back to
// the paragraph host instead of dropping.
$unavailable = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'flex flex-col' => array( 'display' => 'flex', 'flex-direction' => 'column' ),
    )),
));
$assert(
    'core/paragraph' === ($unavailable->convertAnchor($elementFrom('<a class="flex flex-col" href="/"><span>Name</span><span>Role</span></a>'), $fallbacks)['blockName'] ?? ''),
    'stacked-anchor-without-wrapper-block-falls-back-to-paragraph-host'
);

// Block-level spans stack in plain flow: the lockup converts as a wrapper.
$blockSpans = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'lockup-name' => array( 'display' => 'block' ),
        'lockup-role' => array( 'display' => 'block' ),
    )),
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/group');
        }
    ),
));
$assert(
    'core/group' === ($blockSpans->convertAnchor($elementFrom('<a href="/"><span class="lockup-name">Name</span><span class="lockup-role">Role</span></a>'), $fallbacks)['blockName'] ?? ''),
    'block-level-span-lockup-converts-as-link-wrapper'
);

// A single span child never stacks, whatever its container displays.
$singleSpan = $makeDispatcher(array(
    'structural'  => $declarationsFor(array(
        'flex flex-col' => array( 'display' => 'flex', 'flex-direction' => 'column' ),
    )),
    'leftovers' => new ButtonLinkLeftoversFixture(
        convertLinkWrapperGroup: static function (DOMElement $e, array &$f): ?array {
            return array('blockName' => 'core/group');
        }
    ),
));
$assert(
    'core/paragraph' === ($singleSpan->convertAnchor($elementFrom('<a class="flex flex-col" href="/"><span>Name</span></a>'), $fallbacks)['blockName'] ?? ''),
    'single-span-anchor-stays-paragraph-host'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Button link dispatcher tests: ' . $assertions . " passed\n";
