<?php
declare(strict_types=1);

/**
 * Carried inline geometry must not be saved as a raw wrapper `style`.
 *
 * A captured Webflow menu arrives open, with the runtime's
 * `style="display:block !important"` on its `nav`, and converts to
 * core/buttons. The generated `be-inline-geometry-*` carrier class already
 * restates that declaration in engine CSS, but the wrapper also kept it as a
 * raw `style`. core/buttons save() only writes a style from block supports, so
 * Gutenberg reported "Expected attributes" and marked the block invalid while
 * the transformer's own validity report said it was valid.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.btn{display:inline-block;padding:10px 20px;background:#000;color:#fff}.nav-menu{display:none}</style>'
    . '<main><h1>Welcome</h1>'
    . '<div id="nav-menu" class="wp-block-buttons has-border-color px-4 py-3 space-y-1 nav-menu" style="border-color:hsl(var(--border));border-style:solid;padding-top:.75rem;padding-right:1rem;padding-bottom:.75rem;padding-left:1rem;transition:all, transform 400ms;display:block !important;">'
    . '<a class="btn" href="/events">Event Space</a><a class="btn" href="/contact">Contact us</a></div></main>',
    array()
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($result['assets'] ?? null) ? $result['assets'] : array()
));

$assert(1 === preg_match('/<!-- wp:buttons (\{.*?\}) -->(<div[^>]*>)/', $markup, $buttons), 'the source menu converts to core/buttons', $markup);
$wrapper = $buttons[2] ?? '';
$attrs = json_decode($buttons[1] ?? '{}', true) ?: array();
$assert(
    str_contains($wrapper, 'style="border-color:hsl(var(--border));border-style:solid;padding-top:.75rem;padding-right:1rem;padding-bottom:.75rem;padding-left:1rem"')
        && ! str_contains($wrapper, 'display:block !important'),
    'the core/buttons wrapper keeps save-supported styles but drops transport-only display',
    $wrapper
);
$assert('nav-menu' === ($attrs['anchor'] ?? null) && str_contains($wrapper, 'id="nav-menu"'), 'the source id is saved through the anchor attribute', $wrapper);
$assert(
    1 === preg_match('/be-inline-geometry-[0-9a-f]+/', (string) ($attrs['className'] ?? ''), $carrier)
        && 1 === preg_match('/\.' . preg_quote($carrier[0], '/') . '\{[^}]*display:block/', $css),
    'the inline display rides the generated carrier class in engine CSS',
    $css
);

$runtime = new Runtime();
$validity = $runtime->validateBlockSerialization($markup);
$assert(array() === ($validity['findings'] ?? array()), 'the serialized page passes block validation', (string) json_encode($validity['findings'] ?? array()));
$reported = $result['source_reports']['wp_block_validity'] ?? array();
$assert('pass' === ($reported['status'] ?? null), 'the transform receipt reports the page valid', (string) json_encode($reported));

// The validator itself flags a raw wrapper style when no support attribute can
// reproduce it, which is the shape emitted before this boundary fix.
$rawStyle = $runtime->validateBlockSerialization(
    '<!-- wp:buttons {} --><div class="wp-block-buttons" style="display:block !important"></div><!-- /wp:buttons -->'
);
$assert(
    in_array('unexpected_wrapper_style', array_map(static fn (array $finding): string => (string) ($finding['details']['reason'] ?? ''), $rawStyle['findings'] ?? array()), true),
    'a raw transport-only display declaration is reported as a save-shape violation',
    (string) json_encode($rawStyle['findings'] ?? array())
);

$factory = new BlockFactory();
$styleCases = array(
    'middle' => array(
        'input' => 'width:1px;display:block;height:2px',
        'expected' => 'width:1px;height:2px',
    ),
    'first' => array(
        'input' => 'display:block;width:1px;height:2px',
        'expected' => 'width:1px;height:2px',
    ),
    'last' => array(
        'input' => 'width:1px;height:2px;display:block',
        'expected' => 'width:1px;height:2px',
    ),
    'duplicates' => array(
        'input' => 'display:block;width:1px;display:flex;height:2px;display:none',
        'expected' => 'width:1px;height:2px',
    ),
    'priority' => array(
        'input' => 'width:1px !important;display:block !important;height:2px !important',
        'expected' => 'width:1px !important;height:2px !important',
    ),
    'functional-value' => array(
        'input' => 'background:var(--x, ";");display:block;width:1px',
        'expected' => 'background:var(--x, &quot;;&quot;);width:1px',
    ),
);
foreach ( $styleCases as $caseName => $case ) {
    $block = $factory->create('core/buttons', array('inlineGeometryStyle' => $case['input']));
    $assert(
        str_contains((string) ($block['innerHTML'] ?? ''), 'style="' . $case['expected'] . '"'),
        'display filter preserves ' . $caseName . ' declarations',
        (string) ($block['innerHTML'] ?? '')
    );
}

if ( 0 < $failures ) {
    fwrite(STDERR, "core block inline geometry save validity FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "core block inline geometry save validity passed: {$passes} assertions\n";
