<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A captured menu toggle whose whole content is an inline SVG icon (a lucide
// menu glyph, the button's only child) has no RichText label. It lowers to a
// core container around a core image, published as the native source button,
// not to an opaque generated authored-button.
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-menu h-6 w-6"><line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line></svg>';
$toggle = static fn (string $id): string => '<button aria-label="Open menu"' . ('' !== $id ? ' id="' . $id . '"' : '') . ' class="flex h-10 w-10 items-center justify-center rounded-lg text-primary lg:hidden" data-blocks-engine-capture-dialog-trigger="panel" aria-controls="panel" aria-expanded="false" aria-haspopup="menu">' . $svg . '</button>';
$cases = array(
    'bound trigger beside a link' => '<div class="flex items-center gap-3"><a href="/booking/">Book Now</a>' . $toggle('opener') . '</div><div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" hidden>Menu contents</div>',
    'bound trigger alone in a flex wrapper' => '<div class="flex items-center gap-3">' . $toggle('opener') . '</div><div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" hidden>Menu contents</div>',
);
$failures = 0;
foreach ($cases as $name => $html) {
    $result = (new HtmlTransformer())->transform($html, array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]')))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $checks = array(
        'no generated authored button' => !str_contains($markup, '/authored-button'),
        'no HTML block' => !str_contains($markup, '<!-- wp:html'),
        'published as the native button' => str_contains($markup, '"blocksEngineControlTagName":"button"'),
        'keeps the dialog binding and accessible name' => str_contains($markup, '"data-blocks-engine-capture-dialog-trigger":"panel"') && str_contains($markup, '"aria-label":"Open menu"'),
        'icon is a core image rendered back as its source SVG' => str_contains($markup, '<!-- wp:image ') && str_contains($markup, 'blocksEngineInlineSvg') && str_contains($markup, 'lucide-menu'),
        'source wrapper is not folded into the published control' => str_contains($markup, '<!-- wp:group {"className":"flex items-center gap-3"}'),
        'valid core save' => 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''),
    );
    foreach ($checks as $label => $ok) {
        if ($ok) continue;
        ++$failures;
        fwrite(STDERR, "FAIL: {$name}: {$label}\n{$markup}\n");
    }
}
// A trigger a retained runtime still finds by its saved attributes keeps them
// in its saved markup.
$retained = (string) (new HtmlTransformer())->transform(
    '<div class="flex items-center gap-3"><a href="/booking/">Book Now</a>' . $toggle('') . '</div><div id="panel" data-blocks-engine-capture-dialog-panel="panel" hidden><a href="/a">A</a></div>',
    array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]'))
)->toArray()['serialized_blocks'];
if (!str_contains($retained, 'data-blocks-engine-capture-dialog-trigger="panel"')) {
    ++$failures;
    fwrite(STDERR, "FAIL: a runtime-retained trigger keeps its saved binding\n{$retained}\n");
}
// A labelled trigger keeps its label path, unchanged.
$labelled = (string) (new HtmlTransformer())->transform(
    '<button id="opener" data-blocks-engine-capture-dialog-trigger="panel" aria-controls="panel"><span>Menu</span>' . $svg . '</button><div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" hidden>Menu contents</div>',
    array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]'))
)->toArray()['serialized_blocks'];
if (str_contains($labelled, 'blocksEngineInlineSvg')) {
    ++$failures;
    fwrite(STDERR, "FAIL: a labelled trigger does not lower its icon into a control container\n{$labelled}\n");
}
if ($failures) exit(1);
echo "svg-only-dialog-trigger-core: ok\n";
