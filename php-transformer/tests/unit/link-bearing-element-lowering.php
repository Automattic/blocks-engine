<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$source = '<div class="share-row"><div class="desktop-share"><share-control class="share-control" href="/article" aria-label="Share article" target="_blank" rel="noopener" data-runtime-state="removed"><span aria-hidden="true"></span></share-control></div><div class="mobile-share"><share-control class="mobile-share-control" href="/article" title="Share article on mobile"><span></span></share-control></div></div>';
$first = (new HtmlTransformer())->transform($source)->toArray();
$second = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($first['serialized_blocks'] ?? '');

if ('core/group' !== ($first['blocks'][0]['blockName'] ?? null) || 2 !== count($first['blocks'][0]['innerBlocks'] ?? array())) throw new RuntimeException('Safe link-bearing elements must retain their ordered parent structure.');
if ('core/buttons' !== ($first['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? null) || 'core/button' !== ($first['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? null) || '/article' !== ($first['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['url'] ?? null)) throw new RuntimeException('Safe link-bearing elements must lower to native editable button links.');
if (!str_contains($markup, 'desktop-share') || !str_contains($markup, 'mobile-share') || !str_contains($markup, 'share-control') || !str_contains($markup, 'mobile-share-control') || !str_contains($markup, 'Share article</a>') || !str_contains($markup, 'target="_blank"') || !str_contains($markup, 'rel="noopener"') || strpos($markup, 'desktop-share') > strpos($markup, 'mobile-share')) throw new RuntimeException('Link-bearing lowering must retain classes, accessibility labels, link metadata, and responsive variant ordering.');
if (str_contains($markup, 'data-runtime-state') || str_contains($markup, '<share-control') || array() !== ($first['fallbacks'] ?? array()) || 'pass' !== ((new BlockValidityValidator())->validateBlocks($first['blocks'] ?? array())['status'] ?? '')) throw new RuntimeException('Link-bearing lowering must omit runtime bookkeeping and remain native Gutenberg-valid without fallbacks.');
if (($first['serialized_blocks'] ?? null) !== ($second['serialized_blocks'] ?? null) || ($first['fallbacks'] ?? null) !== ($second['fallbacks'] ?? null)) throw new RuntimeException('Link-bearing custom-element lowering must be deterministic.');

$missingLabel = (new HtmlTransformer())->transform('<share-control class="share-control" href="/article"><span></span></share-control>')->toArray();
if (!str_contains((string) ($missingLabel['serialized_blocks'] ?? ''), '>Open link</a>') || array() !== ($missingLabel['fallbacks'] ?? array())) throw new RuntimeException('Destination-bearing elements without source labels must receive a deterministic accessible button label.');

$unsafe = (new HtmlTransformer())->transform('<share-control href="javascript:alert(1)"><span></span></share-control>')->toArray();
$destinationless = (new HtmlTransformer())->transform('<share-control class="share-control"><span></span></share-control>')->toArray();
if ('html_unsupported_element' !== ($unsafe['fallbacks'][0]['diagnostic_code'] ?? null) || 'html_unsupported_element' !== ($destinationless['fallbacks'][0]['diagnostic_code'] ?? null)) throw new RuntimeException('Unsafe or destination-less custom elements must retain explicit unsupported diagnostics.');

$runtimeDirectives = array('data-wp-interactive', 'data-wp-on--click', 'data-wp-bind--hidden', 'data-wp-init', 'data-wp-context');
foreach ($runtimeDirectives as $directive) {
    $source = sprintf('<share-control href="/article" %s="store.callback"><span>Share article</span></share-control>', $directive);
    $first = (new HtmlTransformer())->transform($source)->toArray();
    $second = (new HtmlTransformer())->transform($source)->toArray();
    if ('html_unsupported_element' !== ($first['fallbacks'][0]['diagnostic_code'] ?? null) || str_contains((string) ($first['serialized_blocks'] ?? ''), 'core/button')) throw new RuntimeException('WordPress Interactivity API directives on link-bearing custom elements must retain the explicit unsupported path.');
    if (($first['serialized_blocks'] ?? null) !== ($second['serialized_blocks'] ?? null) || ($first['fallbacks'] ?? null) !== ($second['fallbacks'] ?? null)) throw new RuntimeException('WordPress Interactivity API directive fallback must be deterministic.');
}

fwrite(STDOUT, "link-bearing element lowering contract passed\n");
