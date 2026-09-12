<?php
declare(strict_types=1);

/**
 * Unit tests for the shared DOM reading vocabulary (#242).
 *
 * Plain-PHP test script in the style of tests/unit/source-element-classifier.php
 * — no PHPUnit.
 *
 * These operations were previously reachable only through DomHelpersTrait,
 * which meant they could not be asserted without a class that was already the
 * transformer. Every case below calls a static method directly.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

/** Build the first element child of an HTML fragment. */
$element = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $body = $doc->getElementsByTagName('body')->item(0);
    foreach ( $body->childNodes as $child ) {
        if ( $child instanceof DOMElement ) {
            return $child;
        }
    }

    throw new RuntimeException('fragment has no element child');
};

// --- attribute reading ------------------------------------------------------

$assert(SourceDom::attr($element('<a href="/x">y</a>'), 'href') === '/x', 'a present attribute is returned');
$assert(SourceDom::attr($element('<a>y</a>'), 'href') === '', 'a missing attribute is the empty string, not null');
$assert(SourceDom::attr($element('<a href="">y</a>'), 'href') === '', 'an empty attribute stays empty');

// --- class handling ---------------------------------------------------------

$assert(SourceDom::hasClass($element('<div class="a b">x</div>'), 'b'), 'a class in a list is found');
$assert(! SourceDom::hasClass($element('<div class="ab">x</div>'), 'b'), 'a substring is not a class match');
$assert(SourceDom::classNames($element('<div class=" a   b ">x</div>')) === array('a', 'b'), 'class lists collapse whitespace');

$assert(SourceDom::mergeClassNames('a b', 'b c') === 'a b c', 'merging class lists de-duplicates');
$assert(SourceDom::mergeClassNames('  a  ', '', 'a') === 'a', 'merging trims and drops empties');
$assert(SourceDom::mergeClassNames() === '', 'merging nothing yields the empty string');

// --- child inspection -------------------------------------------------------

$assert(SourceDom::childElementCount($element('<div><p>a</p>text<p>b</p></div>')) === 2, 'text nodes are not element children');
$assert(SourceDom::childElementCount($element('<div>text only</div>')) === 0, 'a text-only element has no element children');

$only = SourceDom::onlyChildElement($element('<figure>  <img src="a.png">  </figure>'), 'img');
$assert($only instanceof DOMElement, 'whitespace around a sole child is ignored');
$assert(SourceDom::onlyChildElement($element('<figure><img><img></figure>'), 'img') === null, 'two candidates are not an only child');
$assert(SourceDom::onlyChildElement($element('<figure>text<img></figure>'), 'img') === null, 'meaningful text disqualifies an only child');

// --- ancestry: the <body> boundary ------------------------------------------
// This boundary is the contract. A second implementation that walked past
// <body> to the document root was the one real divergence found in the
// duplicated copies of this vocabulary.

$nested = $element('<header><div><a href="/x" id="leaf">y</a></div></header>');
$leaf   = $nested->getElementsByTagName('a')->item(0);
$assert(SourceDom::hasAncestorTag($leaf, array('header')), 'an ancestor tag is found');
$assert(SourceDom::hasAncestorTag($leaf, array('nav', 'header')), 'any tag in the list matches');
$assert(! SourceDom::hasAncestorTag($leaf, array('nav')), 'an absent ancestor tag is not found');
$assert(! SourceDom::hasAncestorTag($leaf, array('body')), 'the walk stops at <body> rather than matching it');
$assert(! SourceDom::hasAncestorTag($leaf, array('html')), 'the walk does not reach past <body> to <html>');
$assert(! SourceDom::hasAncestorTag($nested, array('header')), 'an element is not its own ancestor');

// --- selectors --------------------------------------------------------------

$sel = $element('<section><p>a</p><p id="second">b</p></section>');
$second = $sel->getElementsByTagName('p')->item(1);
$assert(
    SourceDom::elementSelector($second) === 'section:nth-of-type(1) > p:nth-of-type(2)',
    'selectors are nth-of-type paths rooted below <body>'
);

$assert(SourceDom::safeAnchor('valid-id_1') === 'valid-id_1', 'a conforming anchor is preserved');
$assert(SourceDom::safeAnchor('  spaced  ') === 'spaced', 'an anchor is trimmed');
$assert(SourceDom::safeAnchor('1leading-digit') === '', 'an anchor may not start with a digit');
$assert(SourceDom::safeAnchor('has space') === '', 'an anchor may not contain a space');
$assert(SourceDom::safeAnchor('') === '', 'an empty anchor stays empty');

// --- URL safety -------------------------------------------------------------

$assert(SourceDom::safeFallbackUrl('https://example.test/a', 'href'), 'https is allowed');
$assert(SourceDom::safeFallbackUrl('/relative/path', 'href'), 'a schemeless URL is allowed');
$assert(SourceDom::safeFallbackUrl('mailto:a@example.test', 'href'), 'mailto is allowed');
$assert(! SourceDom::safeFallbackUrl('javascript:alert(1)', 'href'), 'javascript: is rejected');
$assert(
    ! SourceDom::safeFallbackUrl('java&#115;cript:alert(1)', 'href'),
    'an entity-encoded javascript scheme is rejected'
);
$assert(
    ! SourceDom::safeFallbackUrl('java%73cript:alert(1)', 'href'),
    'a percent-encoded javascript scheme is rejected'
);
$assert(
    ! SourceDom::safeFallbackUrl("java\tscript:alert(1)", 'href'),
    'control characters do not smuggle a scheme past the check'
);
$assert(
    SourceDom::safeFallbackUrl('data:image/png;base64,iVBORw0KGgo=', 'src'),
    'a base64 image data URL is allowed on src'
);
$assert(
    ! SourceDom::safeFallbackUrl('data:image/png;base64,iVBORw0KGgo=', 'href'),
    'the same data URL is not allowed on href'
);
$assert(
    ! SourceDom::safeFallbackUrl('data:text/html;base64,PHNjcmlwdD4=', 'src'),
    'a non-image data URL is rejected even on src'
);

// --- strict inline SVG payloads ---------------------------------------------

$assert(SourceDom::isSafeInlineSvgMarkup('<svg viewBox="0 0 20 20"><path d="M1 1h18v18H1z"/></svg>'), 'a passive globe SVG is accepted as inline markup');
$assert(SourceDom::isSafeInlineSvgMarkup('<svg width="20" height="20" viewBox="0 0 20 20"><path d="M4 7l6 6 6-6"/></svg>'), 'a 20px intrinsic chevron SVG is accepted without conflating CSS presentation size');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><a href="java&#x73;cript:alert(1)"><text>Click</text></a></svg>'), 'an entity-encoded javascript SVG link is rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><path d="M0 0"/></svg><div>extra root</div>'), 'SVG markup with an extra HTML root is rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><animate attributeName="href" to="javascript:alert(1)"/></svg>'), 'scriptable SVG animation is rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><set attributeName="href" to="javascript:alert(1)"/></svg>'), 'SVG mutation elements are rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><image href="https://example.test/image.svg"/></svg>'), 'external SVG resources are rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<svg><path fill="url(&#x68;ttps://example.test/paint.svg)" d="M0 0"/></svg>'), 'decoded external paint URLs are rejected');
$assert(! SourceDom::isSafeInlineSvgMarkup('<!DOCTYPE svg [<!ENTITY payload SYSTEM "file:///etc/passwd">]><svg><path d="M0 0"/></svg>'), 'DOCTYPE and entity declarations are rejected');

// --- statelessness ----------------------------------------------------------

$host = $element('<wow-image data-image-info="{&quot;imageData&quot;:{&quot;url&quot;:&quot;https://cdn.example.test/logo.png&quot;,&quot;alt&quot;:&quot;Logo&quot;}}"><picture><img alt="Logo"></picture></wow-image>');
$img = $host->getElementsByTagName('img')->item(0);
$assert($img instanceof DOMElement && SourceDom::imageUrlFromHostMetadata($img) === 'https://cdn.example.test/logo.png', 'host JSON image metadata exposes the image URL');
SourceDom::materializeMissingImageSources($host);
$assert($img instanceof DOMElement && $img->getAttribute('src') === 'https://cdn.example.test/logo.png', 'missing img src is materialized from host metadata');

$bounded = $element('<media-frame data-image-info="bounded"><picture><img alt="Mark"></picture></media-frame>');
$boundedImg = $bounded->getElementsByTagName('img')->item(0);
$assert($boundedImg instanceof DOMElement && '' === SourceDom::imageUrlFromHostMetadata($boundedImg), 'non-JSON host metadata is not treated as an image URL');
SourceDom::materializeMissingImageSources($bounded);
$assert(0 === $bounded->getElementsByTagName('picture')->length, 'a sourceless picture without recoverable metadata is dropped');

$unsafe = $element('<wow-image data-image-info="{&quot;url&quot;:&quot;javascript:alert(1)&quot;}"><img alt="x"></wow-image>');
$assert('' === SourceDom::imageUrlFromHostMetadata($unsafe->getElementsByTagName('img')->item(0)), 'javascript image metadata URLs are rejected');

$probe = $element('<div class="a">x</div>');
$first = SourceDom::attr($probe, 'class');
SourceDom::mergeClassNames('x', 'y');
SourceDom::safeAnchor('other');
$assert($first === SourceDom::attr($probe, 'class'), 'static calls carry no state between invocations');

echo 'Source DOM vocabulary tests: ' . $passes . ' passed' . PHP_EOL;

if ( $failures > 0 ) {
    fwrite(STDERR, 'Source DOM vocabulary tests: ' . $failures . ' FAILED' . PHP_EOL);
    exit(1);
}
