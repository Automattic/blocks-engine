<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Neutral reduction: source padding may be explicit, implicit, or partial.
$cases = array(
    '<div style="background:#f4eee9"><p style="background-color:#f4eee9;padding:0;margin:16px 0">Text</p><p style="background-color:#f4eee9;padding:0;margin:16px 0">More</p></div>' => array('0', '0', '0', '0'),
    '<p style="background-color:#f4eee9">Text</p>' => array('0', '0', '0', '0'),
    '<h2 style="background-color:#f4eee9;padding-left:7px">Heading</h2>' => array('0', '0', '0', '7px'),
    '<style>.inset{padding:3px 5px}</style><p class="inset" style="background-color:#f4eee9">Styled</p>' => array('3px', '5px', '3px', '5px'),
    '<p style="background:linear-gradient(red,blue)">Gradient</p>' => array('0', '0', '0', '0'),
    '<ul style="background-color:#f4eee9;padding:2px 4px"><li>Item</li></ul>' => array('2px', '4px', '2px', '4px'),
    '<ul style="background-color:#f4eee9"><li>Item</li></ul>' => array('0', '0', '0', '40px'),
    '<div class="columns" style="background-color:#f4eee9"><div><p>One</p></div><div><p>Two</p></div></div>' => array('0', '0', '0', '0'),
);
foreach ($cases as $html => $expected) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $markup = $result['serialized_blocks'];
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new DOMXPath($document);
    $elements = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " has-background ")]');
    if (0 === $elements->length) {
        throw new RuntimeException('Background block missing: ' . $markup);
    }
    foreach ($elements as $element) {
        if ('div' === $element->tagName && !str_contains($element->getAttribute('class'), 'wp-block-columns')) {
            continue;
        }
        foreach (array('top', 'right', 'bottom', 'left') as $index => $side) {
            // Inline declarations beat core :where(p.has-background) padding.
            if (!str_contains($element->getAttribute('style'), 'padding-' . $side . ':' . $expected[$index])) {
                throw new RuntimeException('Source padding changed on ' . $side . ': ' . $markup);
            }
        }
    }
}
echo "background padding: passed\n";
