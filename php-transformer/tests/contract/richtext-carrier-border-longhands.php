<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Tailwind badge (`<span class="inline-block border px-2">`) lowers to a
// RichText <mark> carrier whose inline style replaces the author's classes.
// Preflight resets every box with `border:0 solid`; the `.border` utility
// paints the box through the `border-style`/`border-width` longhands. The
// carrier must keep those longhands, or it keeps only the reset and the badge
// loses its 1px frame (and the 2px of height that frame adds to its line).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$css = '@layer theme,base,components,utilities;'
    . '@layer base{*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}}'
    . '@layer utilities{.inline-block{display:inline-block}.border{border-style:var(--tw-border-style);border-width:1px}.border-t-2{border-top-width:2px}.px-2{padding-inline:8px}}'
    . '@property --tw-border-style{syntax:"*";inherits:false;initial-value:solid}';
$html = '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><a href="/x"><div>'
    . '<span class="inline-block border border-t-2 px-2">Fits your vehicle</span><p>Hockenheim</p>'
    . '</div></a></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))->toArray();
$assert(1 === preg_match('/<mark\b[^>]*style="([^"]*)"[^>]*>Fits your vehicle/', (string) $result['serialized_blocks'], $match), 'The badge lowers to a styled RichText carrier.');
$style = $match[1];
$assert(str_contains($style, 'border-width:1px'), 'The carrier keeps the utility border width over the preflight reset.');
$assert(str_contains($style, 'border-style:var(--tw-border-style)'), 'The carrier keeps the utility border style.');
$assert(str_contains($style, 'border-top-width:2px'), 'The carrier keeps a per-side border width.');
$assert(strpos($style, 'border:0 solid') < strpos($style, 'border-width:1px'), 'The longhands follow the reset shorthand they override.');
