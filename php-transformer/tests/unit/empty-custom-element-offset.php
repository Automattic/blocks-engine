<?php
declare(strict_types=1);

/** Empty custom hosts with positioning-only CSS disappear even in a grid body (#6211). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$html = (string) file_get_contents(__DIR__ . '/../fixtures/empty-custom-element-offset.html');
foreach ( array(
    'bottom: 7.6rem !important;',
    'top: 1rem; right: 2rem; bottom: 3rem; left: 4rem;',
    'inset: 1rem; z-index: 20;',
    'inset-block: 1rem; inset-inline-start: 2rem;',
    'position: fixed;',
    'position: absolute; inset: 0; z-index: 10;',
) as $declarations ) {
    $fixture = str_replace('bottom: 7.6rem !important;', $declarations, $html);
    $result = ( new HtmlTransformer() )->transform($fixture)->toArray();
    $blocks = (string) ($result['serialized_blocks'] ?? '');
    $assert(array() === ($result['fallbacks'] ?? array()), $declarations . ' records no fallbacks');
    $assert(! str_contains($blocks, 'x-slot'), $declarations . ' emits no host block');
    $assert(str_contains($blocks, '<!-- wp:heading') && str_contains($blocks, 'Product'), $declarations . ' keeps the native heading');
    $assert(str_contains($blocks, '<!-- wp:paragraph') && str_contains($blocks, 'Some description.'), $declarations . ' keeps the native paragraph');
}

// Positioning does not excuse authored size, paint, or generated content.
foreach ( array(
    'x-slot { position: fixed; width: 2rem; }',
    'x-slot { position: absolute; height: 2rem; }',
    'x-slot { bottom: 1rem; background: red; }',
    'x-slot { inset: 0; border: 1px solid black; }',
    'x-slot::before { bottom: 1rem; content: "Note"; }',
) as $rule ) {
    $fixture = str_replace('</style>', $rule . '</style>', $html);
    $result = ( new HtmlTransformer() )->transform($fixture)->toArray();
    $assert(
        in_array('x-slot', array_column($result['fallbacks'] ?? array(), 'tag'), true)
            || str_contains((string) ($result['serialized_blocks'] ?? ''), 'x-slot'),
        $rule . ' keeps the non-neutral host'
    );
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}

echo "Empty custom element offset tests passed\n";
