<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<div class="comment-a"><div class="wp-block-comment-content"><p>Only paragraph</p></div></div>'
    . '<div class="comment-b"><div class="wp-block-comment-content"><p>First</p><p>Second</p></div></div>';
$css = '.wp-block-comment-content{font-size:.95rem;line-height:1.7}.wp-block-comment-content p{margin:0 0 1em}';

// Also isolate each reason to retain the boundary, and a nonmatching rule
// which must not turn an unstyled wrapper into a group.
foreach (array($css, '.wp-block-comment-content{font-size:.95rem;line-height:1.7}', '.wp-block-comment-content p{margin:0 0 1em}', '.wp-block-comment-content h2{margin:0}') as $index => $styles) {
    $result = (new HtmlTransformer())->transform('<html><head><style>' . $styles . '</style></head><body>' . $html . '</body></html>')->toArray();
    $markup = preg_replace('/<!--.*?-->/s', '', $result['serialized_blocks']);
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new DOMXPath($document);
    $single = $xpath->query('//p[contains(text(), "Only paragraph")]')->item(0);
    $first = $xpath->query('//p[contains(text(), "First")]')->item(0);
    if (!$single instanceof DOMElement || !$first instanceof DOMElement) throw new RuntimeException('Comment paragraphs survive');
    $sourceClasses = static function (DOMElement $node): array {
        return array_values(array_filter(explode(' ', $node->getAttribute('class')), static fn (string $class): bool => str_starts_with($class, 'blocks-engine-source-class-')));
    };
    $multiClasses = $sourceClasses($first->parentNode);
    $singleClasses = $sourceClasses($single->parentNode);
    if ($index < 3) {
        if (!str_contains($single->parentNode->getAttribute('class'), 'wp-block-group') || array() === array_intersect($singleClasses, $multiClasses)) {
            throw new RuntimeException('Single-paragraph comment must keep the same source-class group as the two-paragraph comment');
        }
    } elseif (array() !== array_intersect($singleClasses, $multiClasses)) {
        throw new RuntimeException('Unstyled single-child wrapper still collapses');
    }
}
fwrite(STDOUT, "styled-single-paragraph-comment: passed\n");
