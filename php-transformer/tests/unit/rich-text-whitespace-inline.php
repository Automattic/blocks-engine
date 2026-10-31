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
echo "rich-text-whitespace-inline: passed\n";
