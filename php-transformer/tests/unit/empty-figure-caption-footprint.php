<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Squarespace image blocks end with an empty caption wrapper whose padding
// spaces the image from the next block. A caption box that holds space keeps
// that box; one that holds none still disappears.
foreach ( array( 'padded caption' => array( '.cap{padding-top:18px}', true ), 'plain caption' => array( '', false ) ) as $case => [$css, $kept] ) {
    $html = '<html><head><style>' . $css . '</style></head><body><main><figure class="fig"><div class="w"><div class="shape"><img src="/a.jpg" width="512" height="310" alt="Phone"></div></div>'
        . '<figcaption class="cap"><div class="caption"></div></figcaption></figure><h1>After</h1></main></body></html>';
    $markup = (string) ((new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'] ?? '');
    $assert(str_contains($markup, '<!-- wp:image '), "{$case}: the image still lowers natively.");
    $assert($kept === (1 === preg_match('/<!-- wp:group \{"className":"cap[ "]/', $markup)), "{$case}: the empty caption box is " . ($kept ? 'kept' : 'dropped') . '.');
}

echo "empty figure caption footprint passed\n";
