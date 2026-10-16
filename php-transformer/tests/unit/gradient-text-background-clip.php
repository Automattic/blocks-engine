<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } };
$gradient = 'linear-gradient(100deg,#ffffff 0%,#34d6c4 100%)';
$g = "background-image:{$gradient};-webkit-background-clip:text;background-clip:text;color:transparent";
$result = (new HtmlTransformer())->transform("<h1>A first impression, <span style=\"$g\">rebuilt.</span></h1><h3 style=\"$g\">Bespoke joinery</h3>")->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));

// Inline shape: the rich-text mark keeps the gradient paint with its clip.
$assert(1 === preg_match('/<mark style="[^"]*background-image:' . preg_quote($gradient, '/') . '[^"]*background-clip:text[^"]*color:transparent/', $markup), 'inline mark keeps gradient, clip and transparent text');

// Block shape: no background support (its inline `background` shorthand resets the clip);
// the gradient and both clips ride the heading's generated carrier instead.
$assert(! str_contains($markup, '"gradient"') && ! str_contains($markup, 'has-background'), 'text-clipped gradient is not mapped to the background support');
$assert(1 === preg_match('/"className":"(be-inline-geometry-[0-9a-f]+)","level":3/', $markup, $carrier), 'heading has a generated carrier');
$assert(1 === preg_match('/\.' . $carrier[1] . '\{[^}]*background-image:' . preg_quote($gradient, '/') . '[^}]*-webkit-background-clip:text[^}]*background-clip:text/', $css), 'heading carrier keeps gradient and both clip declarations');
$assert(1 === preg_match('/\.' . $carrier[1] . '\{[^}]*color:transparent/', $css), 'heading carrier keeps transparent text color');
echo "gradient text background clip ok\n";
