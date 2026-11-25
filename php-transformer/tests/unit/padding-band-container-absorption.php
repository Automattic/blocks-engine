<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A paint-free vertical-padding band around a centered container is one box:
// the band's padding moves onto the container group instead of a click-through
// group wrapping it.
$css = '<style>.stripe{padding-top:5rem;padding-bottom:5rem}@media (min-width:640px){.stripe-sm{padding-top:7rem;padding-bottom:7rem}}.container{width:100%;max-width:48rem;margin-left:auto;margin-right:auto;padding-left:2rem;padding-right:2rem}.card{padding:1rem;background:#eee}</style>';
$body = '<div class="card"><h2>Question</h2><p>Answer</p></div><div class="card"><h2>Another</h2><p>Reply</p></div>';
$result = $transform($css . '<section class="stripe stripe-sm"><div class="container">' . $body . '</div></section>');
$block = $result['blocks'][0];
$assert('core/group' === $block['blockName'] && 'section' === ($block['attrs']['tagName'] ?? ''), 'The band keeps its section tag on one native group.');
$assert(str_contains($block['attrs']['className'], 'stripe stripe-sm') && str_contains($block['attrs']['className'], 'container'), 'Band and container selectors share the single group.');
$assert(2 === count($block['innerBlocks']) && str_contains($block['innerBlocks'][0]['attrs']['className'] ?? '', 'card'), 'The container children sit directly in the merged group.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The merged band remains editor-valid.');

// Anything the added padding would change keeps the two boxes apart.
foreach (array(
    '<style>.stripe{background:#123}</style><section class="stripe"><div class="container">' . $body . '</div></section>',
    '<section class="stripe"><div class="container" style="background:#fff">' . $body . '</div></section>',
    '<section class="stripe"><div class="container" style="min-height:20rem">' . $body . '</div></section>',
    '<section class="stripe"><div class="container" style="margin-top:2rem">' . $body . '</div></section>',
    '<section class="stripe"><div class="container" style="padding:1rem">' . $body . '</div></section>',
    '<section class="stripe" id="faq"><div class="container">' . $body . '</div></section>',
    '<style>.stripe:hover{outline:1px solid red}</style><section class="stripe"><div class="container">' . $body . '</div></section>',
    '<style>.stripe > .container{color:red}</style><section class="stripe"><div class="container">' . $body . '</div></section>',
    '<style>.stripe{max-width:60rem}</style><section class="stripe"><div class="container">' . $body . '</div></section>',
) as $html) {
    $kept = $transform($css . $html)['blocks'][0];
    $assert(!str_contains($kept['attrs']['className'] ?? '', 'container'), 'An unsafe band keeps its own boundary: ' . $html);
}
