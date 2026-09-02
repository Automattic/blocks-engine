<?php
declare(strict_types=1);

/**
 * TextLeafElementConverter is exercised without constructing an HtmlTransformer.
 *
 * That is the point of the extraction: these branches used to live inside
 * `HtmlTransformer::convertElement()`, so covering them required building a
 * transformer and driving a full document through it. Here the collaborator
 * surface is supplied directly.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementConverter;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

/** Build a converter over a minimal, fully explicit collaborator surface. */
$makeConverter = static function (array $overrides = array()): TextLeafElementConverter {
    $defaults = array(
        'presentationAttributes'        => static fn (DOMElement $e, array $p, array $g): array => array(),
        'innerHtml'                     => static fn (DOMElement $e): string => (string) $e->textContent,
        'innerHtmlPreservingWhitespace' => static fn (DOMElement $e): string => (string) $e->textContent,
        'createBlock'                   => static fn (string $n, array $a, array $i, ?DOMElement $s): array => array(
            'blockName'   => $n,
            'attrs'       => $a,
            'innerBlocks' => $i,
        ),
        'richTextContent'               => static fn (DOMElement $e, array $x): string => (string) $e->textContent,
        'firstChildElement'             => static function (DOMElement $e, string $tag): ?DOMElement {
            foreach ($e->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === $tag) {
                    return $child;
                }
            }
            return null;
        },
        'codePresentationAttributes'    => static fn (DOMElement $pre, DOMElement $code): array => array('code' => true),
        'codeContent'                   => static fn (DOMElement $c): string => (string) $c->textContent,
        'convertChildren'               => static function (DOMElement $e, array &$f, bool $c): array {
            return array();
        },
    );

    $c = array_merge($defaults, $overrides);

    return new TextLeafElementConverter(new TextLeafElementContext(
        new SourceElementClassifier(),
        new ElementPresentationResolverFixture($c['presentationAttributes']),
        $c['createBlock'],
        $c['richTextContent'],
        new Runtime(),
        $c['codePresentationAttributes'],
        $c['codeContent'],
        $c['convertChildren']
    ));
};

$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $body = $doc->getElementsByTagName('body')->item(0);
    foreach ($body->childNodes as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed from: ' . $html);
};

// Ownership is explicit and closed.
$converter = $makeConverter();
foreach (array('address', 'noscript', 'marquee', 'blink', 'pre', 'plaintext', 'hr', 'br') as $tag) {
    $assert($converter->handles($tag), 'handles-' . $tag);
}
foreach (array('p', 'div', 'h1', 'table', 'a', 'form') as $tag) {
    $assert(! $converter->handles($tag), 'does-not-handle-' . $tag);
}

$fallbacks = array();

// `br` converts to nothing, and that is distinguishable from "not handled".
$outcome = $converter->convert($elementFrom('<br>'), 'br', $fallbacks);
$assert($outcome->handled, 'br-is-handled');
$assert(null === $outcome->block, 'br-produces-no-block');

// An unowned tag reports unhandled so the transformer keeps dispatching.
$assert(! $converter->convert($elementFrom('<div>x</div>'), 'div', $fallbacks)->handled, 'unowned-tag-unhandled');

// Empty text leaves drop out instead of emitting empty blocks.
$assert(null === $converter->convert($elementFrom('<address>   </address>'), 'address', $fallbacks)->block, 'empty-address-drops');
$assert(null === $converter->convert($elementFrom('<plaintext>  </plaintext>'), 'plaintext', $fallbacks)->block, 'empty-plaintext-drops');

// Address with content becomes a paragraph.
$address = $converter->convert($elementFrom('<address>1 Main St</address>'), 'address', $fallbacks)->block;
$assert('core/paragraph' === ($address['blockName'] ?? ''), 'address-is-paragraph');
$assert('1 Main St' === ($address['attrs']['content'] ?? ''), 'address-carries-content');

// `pre > code` becomes core/code and uses the code-specific attribute source.
$pre = $converter->convert($elementFrom('<pre><code>echo 1;</code></pre>'), 'pre', $fallbacks)->block;
$assert('core/code' === ($pre['blockName'] ?? ''), 'pre-code-is-core-code');
$assert('echo 1;' === ($pre['attrs']['content'] ?? ''), 'pre-code-content');
$assert(true === ($pre['attrs']['code'] ?? false), 'pre-code-uses-code-presentation-attributes');

// `pre` without a code child stays preformatted.
$bare = $converter->convert($elementFrom('<pre>raw  text</pre>'), 'pre', $fallbacks)->block;
$assert('core/preformatted' === ($bare['blockName'] ?? ''), 'bare-pre-is-preformatted');

// `hr` maps to a separator and excludes horizontal margins.
$seenGeometry = array();
$hrConverter  = $makeConverter(array(
    'presentationAttributes' => static function (DOMElement $e, array $p, array $g) use (&$seenGeometry): array {
        $seenGeometry = $g;
        return array();
    },
));
$hr = $hrConverter->convert($elementFrom('<hr>'), 'hr', $fallbacks)->block;
$assert('core/separator' === ($hr['blockName'] ?? ''), 'hr-is-separator');
$assert(array('margin-left', 'margin-right') === $seenGeometry, 'hr-excludes-horizontal-margins');

// A marquee with block children groups them; a single child with no
// presentation attributes is hoisted rather than wrapped.
$marqueeConverter = $makeConverter(array(
    'convertChildren'         => static function (DOMElement $e, array &$f, bool $c): array {
        return array(array('blockName' => 'core/paragraph'), array('blockName' => 'core/image'));
    },
));
$grouped = $marqueeConverter->convert($elementFrom('<marquee><p>a</p><img src="x"></marquee>'), 'marquee', $fallbacks)->block;
$assert('core/group' === ($grouped['blockName'] ?? ''), 'marquee-with-children-groups');
$assert(2 === count($grouped['innerBlocks'] ?? array()), 'marquee-group-keeps-children');

$singleChild = $makeConverter(array(
    'convertChildren'         => static function (DOMElement $e, array &$f, bool $c): array {
        return array(array('blockName' => 'core/image'));
    },
));
$hoisted = $singleChild->convert($elementFrom('<marquee><img src="x"></marquee>'), 'marquee', $fallbacks)->block;
$assert('core/image' === ($hoisted['blockName'] ?? ''), 'single-child-marquee-is-hoisted');

// noscript with no convertible children falls back to its own markup.
$noscript = $converter->convert($elementFrom('<noscript>enable js</noscript>'), 'noscript', $fallbacks)->block;
$assert('core/paragraph' === ($noscript['blockName'] ?? ''), 'noscript-without-children-is-paragraph');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Text leaf element converter tests: ' . $assertions . " passed\n";
