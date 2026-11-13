<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } };
// Core saves a gradient support as the `background` shorthand, which would
// reset an authored size and turn a 1px underline stripe into a solid fill.
$html = '<style>.btn{display:inline-flex;padding:15px 30px;border-radius:999px}.btn-link{padding-inline:6px;color:#1c2e23;background-image:linear-gradient(currentColor,currentColor);background-size:100% 1px;background-position:6px calc(100% - 12px);background-repeat:no-repeat}.btn-fill{background-image:linear-gradient(90deg,#123,#456);color:#fff}</style>'
    . '<main><div class="actions"><a class="btn btn-link" href="#q">Our story</a><a class="btn btn-fill" href="#s">Shop now</a></div></main>';
$markup = (string) ((new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<a [^>]*style="([^"]*)"[^>]*>Our story/', $markup, $stripe), 'the stripe link is a button');
$assert(! str_contains($stripe[1], 'background:') && ! str_contains($stripe[1], 'gradient'), 'a sized gradient stays in author CSS, not the background shorthand');
$assert(1 === preg_match('/<a [^>]*style="[^"]*background:linear-gradient\(90deg,#123,#456\)[^"]*"[^>]*>Shop now/', $markup), 'an unsized gradient still maps to the native gradient support');
echo "positioned gradient background ok\n";
