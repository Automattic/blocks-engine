<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LinkedResponsiveContentBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $label, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '') . PHP_EOL);
        exit(1);
    }
};

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$names = static function (array $blocks) use (&$names): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $found[] = (string) ($block['blockName'] ?? '');
        $found = array_merge($found, $names(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
    }

    return $found;
};
$find = static function (array $blocks, string $name) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? null) ) {
            return $block;
        }
        $child = $find(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name);
        if ( null !== $child ) {
            return $child;
        }
    }

    return null;
};

$home = '<a class="flex items-center gap-4 home-link" href="/" target="_blank" rel="noopener noreferrer"><img alt="Site Name" loading="lazy" width="48" height="48" decoding="async" class="rounded-full phone-only" srcset="mark.png 1x, mark-2x.png 2x" src="mark-2x.png"><span class="name desktop-only">Site Name</span></a>';
$document = '<!doctype html><html><body><header class="site-bar"><div class="site-bar-inner">' . $home . '<nav class="menu"><a href="/about/">About</a></nav></div></header><main><p>Hello</p></main></body></html>';
$result = $transform($document);
$serialized = (string) ($result['serialized_blocks'] ?? '');
$shell = (string) (($result['source_reports']['shell_artifacts'][0]['template_part_block_markup'] ?? ''));
$block = $find($result['blocks'] ?? array(), 'custom/linked-responsive-content');
$comment = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
$inner = (string) ($block['innerHTML'] ?? '');
$generator = new LinkedResponsiveContentBlockGenerator();
$attrs = $generator->attributesFromMarkup($inner, $comment);

$assert(null !== $block, 'direct-and-page-conversion-emits-linked-content', $serialized);
$assert(! in_array('core/html', $names($result['blocks'] ?? array()), true), 'page-body-has-no-html-island', $serialized);
$assert(! str_contains($serialized, 'wp:button'), 'page-body-reduction-does-not-retry-as-button', $serialized);
$assert(str_contains($shell, '<!-- wp:custom/linked-responsive-content'), 'shared-header-uses-the-same-block', $shell);
$assert(! str_contains($shell, 'wp:html'), 'shared-header-does-not-retain-html', $shell);
$assert(1 === substr_count($inner, '<a '), 'one-navigation-target', $inner);
$assert(1 === substr_count($inner, '<a '), 'no-nested-anchor', $inner);
$assert(! isset($comment['src'], $comment['srcset'], $comment['imageClassName'], $comment['href'], $comment['anchorStyle']) && 'mark-2x.png' === ($attrs['src'] ?? null) && str_contains((string) ($attrs['srcset'] ?? ''), 'mark.png 1x') && str_contains((string) ($attrs['srcset'] ?? ''), 'mark-2x.png 2x'), 'markup-owned-fields-are-extracted-not-comment-owned', json_encode($comment));
$assert('48' === ($attrs['width'] ?? null) && '48' === ($attrs['height'] ?? null) && str_contains((string) ($attrs['imageClassName'] ?? ''), 'phone-only') && ! str_contains((string) ($attrs['imageClassName'] ?? ''), 'desktop-only') && str_contains((string) ($attrs['labelClassName'] ?? ''), 'desktop-only') && ! str_contains((string) ($attrs['labelClassName'] ?? ''), 'phone-only'), 'geometry-and-responsive-visibility-stay-on-their-elements', json_encode($attrs));
$assert('flex items-center gap-4 home-link' === ($attrs['className'] ?? null) && str_contains($inner, '<a class="flex items-center gap-4 home-link" href="/"'), 'anchor-keeps-source-selector-classes', $inner);
$assert('_blank' === ($attrs['linkTarget'] ?? null) && 'noopener noreferrer' === ($attrs['rel'] ?? null) && 'Site Name' === ($attrs['alt'] ?? null) && 'Site Name' === ($attrs['label'] ?? null), 'safe-link-and-accessible-names', json_encode($attrs));
$assert($inner === $generator->markup($attrs), 'stored-markup-matches-save-contract', $inner);
$assert(str_contains($shell, 'srcset="mark.png 1x, mark-2x.png 2x"') && str_contains($shell, 'class="name desktop-only"'), 'shared-part-preserves-density-and-label-ownership', $shell);

$visible = $transform('<main><a class="row-link" href="/studio"><img alt="Studio" width="32" height="32" src="studio.png"><span class="label">Studio</span></a></main>');
$visibleBlock = $find($visible['blocks'] ?? array(), 'custom/linked-responsive-content');
$visibleParsed = $generator->attributesFromMarkup((string) ($visibleBlock['innerHTML'] ?? ''), $visibleBlock['attrs'] ?? array());
$assert(null !== $visibleBlock && 'Studio' === ($visibleParsed['label'] ?? null) && 'label' === ($visibleParsed['labelClassName'] ?? null) && ! str_contains((string) ($visible['serialized_blocks'] ?? ''), 'wp:html'), 'simultaneously-visible-media-and-label-stay-one-link', json_encode($visibleParsed));

$flex = $transform('<style>.row{display:flex;align-items:center;gap:1rem}</style><a class="row" href="/"><img src="mark.png" alt="Site"><span>Site</span></a>');
$assert('custom/linked-responsive-content' === ($find($flex['blocks'] ?? array(), 'custom/linked-responsive-content')['blockName'] ?? null), 'resolved-row-flex-without-dimensions-stays-one-link');

$inline = $transform('<p><a href="/x">Read more <img src="icon.png" alt=""></a></p>');
$assert(null === $find($inline['blocks'] ?? array(), 'custom/linked-responsive-content'), 'ordinary-inline-icon-text-is-not-relinked', (string) ($inline['serialized_blocks'] ?? ''));

$imageOnly = $transform('<a href="/photo"><img src="photo.jpg" alt="Photo" width="48" height="48"></a>');
$assert('core/image' === ($imageOnly['blocks'][0]['blockName'] ?? null) && null === $find($imageOnly['blocks'] ?? array(), 'custom/linked-responsive-content'), 'image-only-link-stays-core-image', (string) ($imageOnly['serialized_blocks'] ?? ''));

$runtime = $transform('<a href="/" onclick="return false"><img src="mark.png" alt="Site" width="48" height="48"><span>Site</span></a>');
$assert(null === $find($runtime['blocks'] ?? array(), 'custom/linked-responsive-content'), 'event-handler-anchor-is-not-relinked', (string) ($runtime['serialized_blocks'] ?? ''));

$wrapped = $transform('<a href="/x"><button type="submit" class="cta">Go</button></a>');
$assert(null === $find($wrapped['blocks'] ?? array(), 'custom/linked-responsive-content'), 'wrapped-control-is-not-relinked', (string) ($wrapped['serialized_blocks'] ?? ''));

$labelFirst = $transform('<a class="row-link" href="/studio"><span id="studio-name" class="label">Studio</span><img id="studio-mark" alt="Studio" title="Studio mark" width="32" height="32" src="studio.png"></a>');
$labelFirstBlock = $find($labelFirst['blocks'] ?? array(), 'custom/linked-responsive-content');
$labelFirstInner = (string) ($labelFirstBlock['innerHTML'] ?? '');
$assert('label-first' === ($labelFirstBlock['attrs']['contentOrder'] ?? null) && str_contains($labelFirstInner, '</span><img') && str_contains($labelFirstInner, 'id="studio-name"') && str_contains($labelFirstInner, 'id="studio-mark"') && str_contains($labelFirstInner, 'title="Studio mark"'), 'label-before-image-order-and-ids-are-preserved', $labelFirstInner);
$labelFirstParsed = $generator->attributesFromMarkup($labelFirstInner, $labelFirstBlock['attrs'] ?? array());
$assert($labelFirstInner === $generator->markup($labelFirstParsed), 'label-first-markup-matches-parsed-save');

$styled = $transform('<a class="row-link" href="/" aria-labelledby="home-name" title="Home" style="display:flex;gap:16px"><img alt="Site" width="48" height="48" data-nimg="1" style="color:transparent" src="mark.png"><span id="home-name" style="font-weight:500">Site</span></a>');
$styledBlock = $find($styled['blocks'] ?? array(), 'custom/linked-responsive-content');
$styledInner = (string) ($styledBlock['innerHTML'] ?? '');
$styledParsed = $generator->attributesFromMarkup($styledInner, $styledBlock['attrs'] ?? array());
$assert('display:flex;gap:16px' === ($styledParsed['anchorStyle'] ?? null) && 'color:transparent' === ($styledParsed['imageStyle'] ?? null) && 'font-weight:500' === ($styledParsed['labelStyle'] ?? null), 'authored-inline-presentation-is-preserved', json_encode($styledParsed));
$assert('home-name' === ($styledParsed['ariaLabelledBy'] ?? null) && 'Home' === ($styledParsed['anchorTitle'] ?? null) && array( 'data-nimg' => '1' ) === ($styledBlock['attrs']['imageData'] ?? null) && str_contains($styledInner, 'data-nimg="1"') && str_contains($styledInner, 'style="color:transparent"'), 'identity-aria-and-safe-data-attributes-stay-on-their-elements', $styledInner);
$assert($styledInner === $generator->markup($styledParsed), 'styled-markup-matches-parsed-save');

$unsafeStyle = $transform('<a href="/" style="width:expression(1)"><img alt="Site" width="48" height="48" src="mark.png"><span>Site</span></a>');
$assert(null === $find($unsafeStyle['blocks'] ?? array(), 'custom/linked-responsive-content'), 'unpreservable-inline-style-is-declined', (string) ($unsafeStyle['serialized_blocks'] ?? ''));

$nestedControl = $transform('<a href="/"><img alt="Site" width="48" height="48" src="mark.png"><span>Go <button type="button">Now</button></span></a>');
$assert(null === $find($nestedControl['blocks'] ?? array(), 'custom/linked-responsive-content') && str_contains((string) ($nestedControl['serialized_blocks'] ?? ''), '<button'), 'label-control-is-not-flattened', (string) ($nestedControl['serialized_blocks'] ?? ''));

$nestedClass = $transform('<a href="/"><img alt="Site" width="48" height="48" src="mark.png"><span>Go <em class="accent">now</em></span></a>');
$assert(null === $find($nestedClass['blocks'] ?? array(), 'custom/linked-responsive-content'), 'selector-owning-label-leaf-is-not-dropped', (string) ($nestedClass['serialized_blocks'] ?? ''));

$safeEmphasis = $transform('<a class="row-link" href="/"><img alt="Site" width="48" height="48" src="mark.png"><span>Go <em>now</em></span></a>');
$emphasisBlock = $find($safeEmphasis['blocks'] ?? array(), 'custom/linked-responsive-content');
$emphasisParsed = $generator->attributesFromMarkup((string) ($emphasisBlock['innerHTML'] ?? ''), $emphasisBlock['attrs'] ?? array());
$assert(str_contains((string) ($emphasisParsed['label'] ?? ''), '<em>now</em>'), 'attribute-free-rich-text-stays-editable', json_encode($emphasisParsed));

$drifted = str_replace(
    array( 'class="rounded-full phone-only"', 'src="mark-2x.png"', 'srcset="mark.png 1x, mark-2x.png 2x"' ),
    array( 'class="rounded-full phone-only wp-image-141"', 'src="/wp-content/uploads/2026/10/mark-2x.png"', 'srcset="/wp-content/uploads/2026/10/mark.png 1x, /wp-content/uploads/2026/10/mark-2x.png 2x"' ),
    $inner
);
$driftedParsed = $generator->attributesFromMarkup($drifted, $comment);
$assert('rounded-full phone-only wp-image-141' === ($driftedParsed['imageClassName'] ?? null) && str_starts_with((string) ($driftedParsed['src'] ?? ''), '/wp-content/uploads/') && str_contains((string) ($driftedParsed['srcset'] ?? ''), '/wp-content/uploads/2026/10/mark.png 1x'), 'materialized-markup-extraction-sees-library-class-and-urls', json_encode($driftedParsed));
$assert($drifted === $generator->markup($driftedParsed), 'parsed-materialized-markup-saves-without-comment-drift', $generator->markup($driftedParsed));

$editor = '';
foreach ( $result['source_reports']['generated_blocks'] ?? array() as $definition ) {
    if ( 'linked-responsive-content' === ($definition['name'] ?? null) ) {
        $editor = (string) ($definition['assets']['index.js'] ?? '');
    }
}
$assert(str_contains($editor, 'MediaUpload') && str_contains($editor, 'RichText') && str_contains($editor, 'Replace image') && str_contains($editor, 'allowedFormats: [ \'core/bold\', \'core/italic\' ]') && ! str_contains($editor, 'core/link'), 'editor-uses-media-and-rich-text-controls', $editor);

$node = tempnam(sys_get_temp_dir(), 'linked-content-');
$nodeScript = <<<'JS'
var settings;
var MediaUpload = function MediaUpload() {};
function createElement(type, props) {
  var children = Array.prototype.slice.call(arguments, 2);
  return { type: type, props: props || {}, children: children, child: children[0] };
}
var element = { createElement: createElement, Fragment: function Fragment() {}, RawHTML: function RawHTML() {} };
var window = { wp: { blocks: { registerBlockType: function(name, value) { settings = value; } }, blockEditor: { MediaUpload: MediaUpload, useBlockProps: function(props) { return props || {}; }, RichText: function RichText() {}, InspectorControls: function InspectorControls() {} }, components: { PanelBody: function PanelBody() {}, TextControl: function TextControl() {}, TextareaControl: function TextareaControl() {}, ToggleControl: function ToggleControl() {}, Button: function Button() {} }, element: element } };
JS;
$nodeScript .= $editor . "\n";
$nodeScript .= 'var fixtures = ' . json_encode(array(
    'home' => $attrs,
    'labelFirst' => $labelFirstParsed,
    'styled' => $styledParsed,
    'drifted' => $driftedParsed,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
$nodeScript .= <<<'JS'
function walk(node, found) {
  if (!node || typeof node !== 'object') return;
  if (node.type === MediaUpload && node.props && node.props.onSelect) found.push(node.props.onSelect);
  (node.children || []).forEach(function(child) { walk(child, found); });
}
var saved = {
  home: settings.save({ attributes: fixtures.home }).child,
  labelFirst: settings.save({ attributes: fixtures.labelFirst }).child,
  styled: settings.save({ attributes: fixtures.styled }).child,
  drifted: settings.save({ attributes: fixtures.drifted }).child
};
var kept = null;
var seeded = null;
var authored = { width: '48', height: '48', srcset: 'mark.png 1x, mark-2x.png 2x', src: 'mark.png', alt: 'Site', href: '/' };
settings.edit({ attributes: authored, setAttributes: function(next) { kept = next; } });
var authoredHandlers = [];
walk(settings.edit({ attributes: authored, setAttributes: function(next) { kept = next; } }), authoredHandlers);
authoredHandlers[0]({ url: 'library.png', width: 1024, height: 768, alt: 'Library', id: 9 });
settings.edit({ attributes: { href: '/', src: 'mark.png', alt: 'Site', srcset: 'mark.png 1x' }, setAttributes: function(next) { seeded = next; } });
var emptyHandlers = [];
walk(settings.edit({ attributes: { href: '/', src: 'mark.png', alt: 'Site', srcset: 'mark.png 1x' }, setAttributes: function(next) { seeded = next; } }), emptyHandlers);
emptyHandlers[0]({ url: 'library.png', width: 1024, height: 768, alt: '', id: 4 });
process.stdout.write(JSON.stringify({ saved: saved, kept: kept, seeded: seeded }));
JS;
file_put_contents($node . '.js', $nodeScript);
$savedJson = shell_exec('node ' . escapeshellarg($node . '.js') . ' 2>&1');
$saved = json_decode((string) $savedJson, true);
$assert(is_array($saved) && $inner === ($saved['saved']['home'] ?? null), 'editor-save-matches-stored-markup', (string) $savedJson);
$assert($labelFirstInner === ($saved['saved']['labelFirst'] ?? null), 'editor-save-preserves-label-first-order', (string) ($saved['saved']['labelFirst'] ?? $savedJson));
$assert($styledInner === ($saved['saved']['styled'] ?? null), 'editor-save-preserves-inline-style', (string) ($saved['saved']['styled'] ?? $savedJson));
$assert($drifted === ($saved['saved']['drifted'] ?? null), 'editor-save-matches-materialized-library-markup', (string) ($saved['saved']['drifted'] ?? $savedJson));
$assert('library.png' === ($saved['kept']['src'] ?? null) && '' === ($saved['kept']['srcset'] ?? null) && ! array_key_exists('width', $saved['kept'] ?? array()) && ! array_key_exists('height', $saved['kept'] ?? array()) && 9 === ($saved['kept']['mediaId'] ?? null), 'media-replacement-keeps-authored-box-and-clears-srcset', json_encode($saved['kept'] ?? null));
$assert('1024' === ($saved['seeded']['width'] ?? null) && '768' === ($saved['seeded']['height'] ?? null) && ! array_key_exists('alt', $saved['seeded'] ?? array()), 'media-replacement-initializes-missing-dimensions-only', json_encode($saved['seeded'] ?? null));

fwrite(STDOUT, "OK: linked responsive content passed ({$assertions} assertions)\n");
