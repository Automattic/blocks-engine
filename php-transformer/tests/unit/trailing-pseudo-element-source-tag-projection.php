<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #6378: a form lowered to a group still owned its placeholder styling
// through `form#contactForm ::-webkit-input-placeholder`. The trailing
// pseudo-element hid the source `form` from projection, so the rule kept
// naming an element the page no longer has.
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$result = (new HtmlTransformer())->transform(
    '<style>section#contact form#contactForm ::-webkit-input-placeholder{font-weight:700;color:#ced4da}'
    . 'section#contact form#contactForm .form-group{margin-bottom:1.5rem}</style>'
    . '<section id="contact"><form id="contactForm"><div class="form-group"><input class="form-control" type="text" placeholder="Your Name *"></div>'
    . '<button type="submit">Send</button></form></section>', array()
)->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$markup = $result['serialized_blocks'];
$assert(1 === preg_match('/blocks-engine-source-form-[a-f0-9]+-\d+/', $markup, $marker), 'the lowered form keeps its source tag identity');
$marker = $marker[0] ?? 'missing-marker';
$assert(!str_contains($css, 'form#contactForm ::-webkit-input-placeholder'), 'no placeholder rule remains stranded on the source form tag');
$assert(str_contains($css, ':where(.' . $marker . '):not(be-t-0)#contactForm ::-webkit-input-placeholder{'), 'the trailing pseudo-element rule addresses the lowered form');
$assert(str_contains($css, ':where(.' . $marker . '):not(be-t-0)#contactForm .form-group'), 'ordinary descendants of the form project the same way');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Trailing pseudo-element source tag projection passed: 4 assertions\n";
