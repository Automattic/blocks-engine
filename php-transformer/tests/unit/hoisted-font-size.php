<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'outer size' => array('<p><span style="font-size:20px"><span style="color:#f79122"><strong>MISSÃO</strong></span></span></p>', '20px'),
    'inner size' => array('<p><span style="color:#f79122"><span style="font-size:20px"><strong>VISÃO</strong></span></span></p>', '20px'),
    'host size' => array('<p style="font-size:16px"><span style="font-size:20px">Text</span></p>', '20px'),
    'stylesheet heading' => array('<div class="cs-text-widget"><div class="text-editor"><h5 style="text-align:center"><span style="color:#333333"><strong>Serviços</strong></span></h5></div></div>', null),
    'unsized heading' => array('<h5><span style="color:#333333"><strong>Heading</strong></span></h5>', null),
    'mixed sizes' => array('<p><span style="font-size:20px">Large</span> normal</p>', null),
);

foreach (array('', 'p,h5,span{font-size:100%}') as $reset) {
    foreach ($cases as $name => [$html, $expected]) {
        $result = (new HtmlTransformer())->transform('<style>' . $reset . '.cs-text-widget .text-editor h5{font-size:24px}</style>' . $html)->toArray();
        $markup = (string) $result['serialized_blocks'];
        $document = new DOMDocument();
        @$document->loadHTML('<html><body>' . $markup . '</body></html>');
        $host = $document->getElementsByTagName(str_contains($html, '<h5') ? 'h5' : 'p')->item(0);
        $style = $host instanceof DOMElement ? $host->getAttribute('style') : '';
        if (null !== $expected && ! str_contains($style, 'font-size:' . $expected)) {
            throw new RuntimeException($name . ': missing size: ' . $markup);
        }
        // A host's own authored reset may remain, but no neutral inline span
        // may invent one when the host has no font-size declaration.
        if ('' === $reset && null === $expected && str_contains($style, 'font-size:')) {
            throw new RuntimeException($name . ': unexpected block size: ' . $markup);
        }
        if ('stylesheet heading' === $name && str_contains($style, 'font-size:100%')) {
            throw new RuntimeException($name . ': inline reset overrides stylesheet: ' . $markup);
        }
        if ('mixed sizes' === $name && ! str_contains($markup, 'font-size:20px')) {
            throw new RuntimeException('Mixed-size content lost its inline size');
        }
    }
}

echo "Hoisted font sizes passed.\n";
