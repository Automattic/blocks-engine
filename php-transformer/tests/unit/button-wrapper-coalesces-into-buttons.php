<?php
declare(strict_types=1);

/**
 * A render-neutral `div` whose only content is a single link button folds its
 * class onto the generated core/buttons block instead of staying an extra
 * core/group level, so captured "button-inner" shells do not deepen the block
 * tree. A wrapper with its own box geometry still keeps its Group.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$page = static fn (string $css): string => '<!DOCTYPE html><html><head><style>.card .btn a{display:inline-block;padding:1em 2.5em;background:#27aa9f;color:#fff}' . $css . '</style></head><body><div class="card"><div class="btn"><div class="btn-inner"><a href="/x/">Learn More</a></div></div></div></body></html>';

$result = ( new HtmlTransformer() )->transform($page(''))->toArray();
$markup = $result['serialized_blocks'] ?? '';
$assert(! str_contains($markup, '<!-- wp:group {"className":"btn btn-inner"}') && ! str_contains($markup, '<!-- wp:group {"className":"btn-inner"}'), 'the neutral button-inner wrapper is not kept as its own Group');
$assert(1 === preg_match('/<!-- wp:buttons \{[^}]*"className":"btn-inner(?: |")/', $markup), 'the wrapper class moves onto the core/buttons block');
$assert(str_contains($markup, 'href="/x/"') && str_contains($markup, 'background-color:#27aa9f'), 'the button keeps its link and paint');
$assert(1 === preg_match('/<!-- wp:buttons (\{.*?\}) -->/', $markup, $root)
    && ! str_contains($root[1], 'blocks-engine-layout-neutral-buttons')
    && ! str_contains($root[1], 'blocks-engine-subject-tag-a'), 'the replacement Buttons root owns the parent box, not the flattened anchor projection');
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(str_contains($css, '.wp-block-buttons.blocks-engine-coalesced-buttons-box{display:block}'), 'the replacement root retains normal block flow instead of introducing a flex row');

$padded = ( new HtmlTransformer() )->transform($page('.btn-inner{padding:20px}'))->toArray()['serialized_blocks'] ?? '';
$assert(1 !== preg_match('/<!-- wp:buttons \{[^}]*"className":"btn-inner(?: |")/', $padded), 'a wrapper with its own padding is not folded onto the buttons block');

// Inline anchors rely on the retained parent's line box, even when their
// painted padding makes them look like boxed buttons.
$inline = (new HtmlTransformer())->transform(
    '<style>.cta-host{font:16px/1.5 sans-serif}.cta{padding:12px 24px;background:#111;color:#fff}</style>'
    . '<div class="cta-host"><a class="cta" href="/contact">Contact</a></div>'
)->toArray();
$assert('core/group' === ($inline['blocks'][0]['blockName'] ?? null), 'an inline anchor keeps its block parent and line box');

$custom = (new HtmlTransformer())->transform(
    '<div class="desktop-share"><share-control href="/article" aria-label="Share article"><span></span></share-control></div>'
)->toArray();
$assert('core/group' === ($custom['blocks'][0]['blockName'] ?? null)
    && 'core/buttons' === ($custom['blocks'][0]['innerBlocks'][0]['blockName'] ?? null), 'custom controls keep their independent wrapper ancestry');

fwrite(STDOUT, sprintf('button-wrapper-coalesces-into-buttons: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
