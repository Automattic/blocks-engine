<?php
declare(strict_types=1);

// A whitespace-only inline still separates the words around it. Dropping its
// wrapper must keep the space, or "advancing<span> </span>civic" reads
// "advancingcivic" (#2366).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<!doctype html><html><head><meta charset="utf-8"><title>T</title></head><body><main><h4>My work focuses on advancing<span class="color_18"> </span><span style="font-family:serif"><span style="font-weight:400;"><span class="color_18">civic participation</span></span></span><span style="font-family:serif"><span style="font-weight:400;"> </span></span>through research.</h4><p>One<span>&#10;</span>two<span></span>three</p></main></body></html>';
$markup = (string) (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
$text = static fn(string $tag): string => preg_match('#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#s', $markup, $m) ? preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]))) : '';

foreach (array(
    'h4' => 'My work focuses on advancing civic participation through research.',
    'p'  => 'One twothree',
) as $tag => $expected) {
    if ($expected !== $text($tag)) {
        fwrite(STDERR, "FAIL: {$tag} text is \"{$expected}\", got \"{$text($tag)}\"\n{$markup}\n");
        exit(1);
    }
}
// Reduced centred shadow layer from #5199. Its trailing space is layout,
// not collapsible formatting whitespace, and needs a styled RichText carrier.
foreach (array(
    'author pre' => array('.pre{white-space:pre}', 'class="pre"', 'white-space:pre'),
    'inline pre-wrap' => array('', 'style="white-space:pre-wrap"', 'white-space:pre-wrap'),
    'inline break-spaces' => array('', 'style="white-space:break-spaces"', 'white-space:break-spaces'),
    'author preserve' => array('.pre{white-space-collapse:preserve}', 'class="pre"', 'white-space-collapse:preserve'),
    'author important' => array('.pre{white-space:pre!important}', 'class="pre" style="white-space:normal"', 'white-space:pre'),
) as $label => [$css, $attributes, $declaration]) {
    $html = '<style>' . $css . '</style><p style="text-align:center;white-space:nowrap;width:200px"><span style="color:transparent;text-shadow:#fcba1f 1.5px 1.5px 0">Cheesy</span><span ' . $attributes . '> </span></p>';
    $markup = (string) (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    if ('author pre' === $label && isset($argv[1])) {
        file_put_contents($argv[1], '<!doctype html><body style="font:32px monospace"><section id="source">' . $html . '</section><section id="compiled">' . $markup . '</section></body>');
    }
    if (!preg_match('#<mark\b[^>]*style="[^"]*' . preg_quote($declaration, '#') . '(?:;|"|\s)[^>]*> </mark>#', $markup)) {
        fwrite(STDERR, "FAIL: {$label}: preserved trailing space must retain its whitespace style in a RichText mark\n{$markup}\n");
        exit(1);
    }
}
foreach (array('normal', 'nowrap', 'pre-line') as $whiteSpace) {
    $markup = (string) (new HtmlTransformer())->transform('<p>Cheesy<span style="white-space:' . $whiteSpace . '"> </span></p>')->toArray()['serialized_blocks'];
    if (str_contains($markup, '<mark')) {
        fwrite(STDERR, "FAIL: collapsible {$whiteSpace} space unexpectedly retained its wrapper\n{$markup}\n");
        exit(1);
    }
}
echo "rich-text-whitespace-inline: passed\n";
