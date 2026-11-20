<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Hidden support labels retain their native saved identity and contents.
// The editor resting-state module owns their focusability, not the lowering.
$result = (new HtmlTransformer())->transform('<p class="sr-only" style="visibility:hidden">Top of page</p><h1>About us</h1><p>Visible body</p>')->toArray();
$markup = (string) $result['serialized_blocks'];
if (!str_contains($markup, 'Top of page') || !str_contains($markup, 'blocks-engine-hidden-richtext-marker')) {
    throw new RuntimeException('Hidden support markup and its selector hooks must survive.');
}
$blocks = (new Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime())->parseBlocks($markup);
$visit = static function (array $blocks) use (&$visit): void {
    foreach ($blocks as $block) {
        if (in_array($block['blockName'], array('core/paragraph', 'core/heading'), true)
            && str_contains((string) ($block['attrs']['className'] ?? ''), 'blocks-engine-hidden-richtext-marker')) {
            if ('core/paragraph' !== $block['blockName'] || !str_contains($block['innerHTML'], 'Top of page')) {
                throw new RuntimeException('Hidden support text must retain its paragraph and saved content.');
            }
        }
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($blocks);
if ('pass' !== (new Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator())->validateBlocks($blocks)['status']) {
    throw new RuntimeException('Hidden support boxes must remain valid Gutenberg blocks.');
}
if (str_contains($markup, '<!-- wp:group') || !str_contains($markup, '<!-- wp:heading') || !str_contains($markup, 'Visible body')) {
    throw new RuntimeException('Visible text must remain editable native text blocks.');
}
echo "Hidden support text editor: passed\n";
