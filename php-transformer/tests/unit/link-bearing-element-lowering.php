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

$hostRuntimeDirectiveSource = '<share-control href="/article" data-wp-on--click="actions.share"><span>Share article</span></share-control>';
$hostRuntimeDirective = (new HtmlTransformer())->transform($hostRuntimeDirectiveSource)->toArray();
$secondHostRuntimeDirective = (new HtmlTransformer())->transform($hostRuntimeDirectiveSource)->toArray();
if ('html_unsupported_element' !== ($hostRuntimeDirective['fallbacks'][0]['diagnostic_code'] ?? null) || str_contains((string) ($hostRuntimeDirective['serialized_blocks'] ?? ''), 'core/button')) throw new RuntimeException('WordPress Interactivity API directives on link-bearing custom-element hosts must retain the explicit unsupported path.');
if (($hostRuntimeDirective['serialized_blocks'] ?? null) !== ($secondHostRuntimeDirective['serialized_blocks'] ?? null) || ($hostRuntimeDirective['fallbacks'] ?? null) !== ($secondHostRuntimeDirective['fallbacks'] ?? null)) throw new RuntimeException('Host WordPress Interactivity API directive fallback must be deterministic.');

$nestedRuntimeDirectives = array(
    'data-wp-interactive',
    'data-wp-on--click',
    'data-wp-bind--hidden',
    'data-wp-init',
    'data-wp-context',
    'data-wp-each',
    'data-wp-watch',
    'data-wp-class--is-shared',
    'data-wp-text',
    'data-wp-style--color',
    'data-wp-router-region',
    'data-wp-body',
    'data-wp-ignore',
);
foreach ($nestedRuntimeDirectives as $directive) {
    $source = sprintf('<share-control href="/article"><span %s="store.callback">Share article</span></share-control>', $directive);
    $first = (new HtmlTransformer())->transform($source)->toArray();
    $second = (new HtmlTransformer())->transform($source)->toArray();
    if ('html_unsupported_element' !== ($first['fallbacks'][0]['diagnostic_code'] ?? null) || str_contains((string) ($first['serialized_blocks'] ?? ''), 'core/button')) throw new RuntimeException('WordPress Interactivity API directives on nested content of link-bearing custom elements must retain the explicit unsupported path.');
    if (($first['serialized_blocks'] ?? null) !== ($second['serialized_blocks'] ?? null) || ($first['fallbacks'] ?? null) !== ($second['fallbacks'] ?? null)) throw new RuntimeException('Nested WordPress Interactivity API directive fallback must be deterministic.');
}

// A file destination does not make an anchor a document link. core/file renders
// its label as a bare inline link and, for a `download` anchor, adds a second
// download link beside it, so an authored pill would materialise as two anchors
// and lose its control presentation.
$pillCss = '.pill{border-radius:9999px;background:#1b2a3a;color:#fff;padding:8px 16px;display:inline-block}';
$blockNames = static function (array $blocks) use (&$blockNames): array {
    $names = array();
    foreach ($blocks as $block) {
        if (!empty($block['blockName'])) $names[] = $block['blockName'];
        if (!empty($block['innerBlocks'])) $names = array_merge($names, $blockNames($block['innerBlocks']));
    }
    return $names;
};

$styledDownload = (new HtmlTransformer())->transform(
    '<html><body><div><a download="cv.pdf" href="/cv.pdf" class="pill">Download full CV</a></div></body></html>',
    array('static_css' => $pillCss)
)->toArray();
$styledMarkup = (string) ($styledDownload['serialized_blocks'] ?? '');
if (!in_array('core/button', $blockNames($styledDownload['blocks'] ?? array()), true) || in_array('core/file', $blockNames($styledDownload['blocks'] ?? array()), true)) throw new RuntimeException('An anchor carrying its own control presentation is a button that points at a file, not a document link.');
if (1 !== substr_count($styledMarkup, '<a ')) throw new RuntimeException('One authored control must materialise as exactly one anchor.');
if (!str_contains($styledMarkup, 'padding')) throw new RuntimeException('A promoted download control must keep the authored padding core/file cannot hold.');
if ('pass' !== ((new BlockValidityValidator())->validateBlocks($styledDownload['blocks'] ?? array())['status'] ?? '')) throw new RuntimeException('A promoted download control must stay editor-valid.');

// A synthetic paragraph carrier saves its anchor verbatim, so a margin the
// author wrote on that anchor arrives with it. Restating the same margin as a
// block attribute on the carrier applies it twice — once on the host box and
// once on the link inside it — which stretched a case-study column by exactly
// the authored margin at the viewport where the column stacks.
$carriedMargin = (new HtmlTransformer())->transform(
    '<html><body><div><a class="cta" href="/project">Ask me about this project</a></div></body></html>',
    array('static_css' => '.cta{display:inline-block;margin-top:28px;color:#333}')
)->toArray();
$carrierMarkup = (string) ($carriedMargin['serialized_blocks'] ?? '');
if (!str_contains($carrierMarkup, 'class="cta"')) throw new RuntimeException('The synthetic carrier must save the authored anchor verbatim.');
if (preg_match('/<p[^>]*style="[^"]*margin-top:28px/', $carrierMarkup)) throw new RuntimeException('The carrier must not restate a margin the saved anchor already carries.');

// Spacing the anchor never authored has no source to arrive from, so a carrier
// that owns its own geometry keeps it.
$ownedGeometry = (new HtmlTransformer())->transform(
    '<html><body><div><a href="/project">Ask me about this project</a></div></body></html>',
    array('static_css' => 'div>a{display:inline-block;margin-top:28px}')
)->toArray();
if (!str_contains((string) ($ownedGeometry['serialized_blocks'] ?? ''), '28px')) throw new RuntimeException('A margin the anchor carries no class for must still reach the output.');

// A run of sibling anchors that all address files is a document listing. A
// cluster mixing a file link with ordinary destinations is a set of links, and
// core/file cannot represent one of them: it empties the author's anchor of its
// classes, moves `download` onto a generated button the author never wrote, and
// renders both at the button's smaller type.
$linkCluster = (new HtmlTransformer())->transform(
    '<html><body><div class="row">'
    . '<a href="mailto:hello@example.com">Email</a>'
    . '<a href="/cv.pdf" download="CV.pdf" class="lnk">Download CV</a>'
    . '<a href="#work">Work</a>'
    . '</div></body></html>',
    array('static_css' => '.row{display:flex;gap:24px}.lnk{color:#333}')
)->toArray();
$clusterMarkup = (string) ($linkCluster['serialized_blocks'] ?? '');
if (in_array('core/file', $blockNames($linkCluster['blocks'] ?? array()), true)) throw new RuntimeException('A file link beside ordinary destinations is one link among links, not a document listing.');
if (3 !== substr_count($clusterMarkup, '<a ')) throw new RuntimeException('A link cluster must materialise exactly the anchors the author wrote.');
if (!str_contains($clusterMarkup, 'download="CV.pdf"')) throw new RuntimeException('The authored download attribute must stay on the authored anchor.');
if (!str_contains($clusterMarkup, 'lnk')) throw new RuntimeException('The authored anchor must keep its own classes.');

// Siblings that all address files remain a document listing.
$listing = (new HtmlTransformer())->transform(
    '<html><body><div><a href="/docs/a.pdf">Plain PDF</a><a href="/docs/b.pdf" download>Download PDF</a></div></body></html>'
)->toArray();
if (2 !== count(array_filter($blockNames($listing['blocks'] ?? array()), static fn (string $name): bool => 'core/file' === $name))) throw new RuntimeException('Sibling anchors that all address files stay on core/file.');

// The unstyled case is what core/file exists for, and stays there.
$plainDownload = (new HtmlTransformer())->transform(
    '<html><body><div><a download="cv.pdf" href="/cv.pdf">Ethan-Chalmers-CV.pdf</a></div></body></html>'
)->toArray();
if (!in_array('core/file', $blockNames($plainDownload['blocks'] ?? array()), true)) throw new RuntimeException('A plain document link must still lower to core/file.');

fwrite(STDOUT, "link-bearing element lowering contract passed\n");
