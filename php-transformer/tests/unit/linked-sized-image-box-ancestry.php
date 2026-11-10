<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #5109: `a.act > div.art > img` must keep its source ancestry. Folding
// the link into core/image as `figure.art > a.act > img` inverts it, so
// `.act .art` and `.act img` stop selecting the logo box.
$result = (new ArtifactCompiler())->compileFragment(
    '<style>.grid{display:grid;grid-template-columns:repeat(2,1fr)}.act{display:flex;flex-direction:column}.act .art{height:90px;max-width:200px}.act img{max-height:100%}</style>'
    . '<ol class="grid"><li><a class="act" href="/a"><div class="art"><img src="https://example.com/a.png" alt="A"></div></a></li><li><a class="act" href="/b"><span>Band B</span></a></li></ol>',
    'src'
)->toArray();

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8"?><div>' . $result['serialized_blocks'] . '</div>');
$image = (new DOMXPath($document))->query('//img[@src="https://example.com/a.png"]')->item(0);

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};
$hasClass = static fn (DOMNode $node, string $class): bool => $node instanceof DOMElement
    && in_array($class, preg_split('/\s+/', $node->getAttribute('class')) ?: array(), true);
$ancestor = static function (DOMNode $node, callable $match): ?DOMElement {
    for ( $node = $node->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
        if ( $match($node) ) {
            return $node;
        }
    }
    return null;
};

$assert($image instanceof DOMElement, 'The logo image is emitted.');
$art = $ancestor($image, static fn (DOMElement $node): bool => $hasClass($node, 'art'));
$assert($art instanceof DOMElement, 'The logo image keeps its .art box ancestor.');
$assert(null !== $ancestor($art, static fn (DOMElement $node): bool => $hasClass($node, 'act')), '.act .art still selects the logo box.');
$assert(null !== $ancestor($image, static fn (DOMElement $node): bool => 'a' === $node->tagName && '/a' === $node->getAttribute('href')), 'The logo stays linked to its href.');
$assert(1 === preg_match('/<!-- wp:image /', $result['serialized_blocks']), 'The logo remains a native core/image.');

echo "Linked sized image box ancestry passed\n";
