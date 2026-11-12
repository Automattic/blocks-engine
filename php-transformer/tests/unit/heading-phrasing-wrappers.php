<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'linked text' => array('<h2><div><a href="/x">Check</a> out</div></h2>', false, false),
    'centered text' => array('<h3 style="text-align:center"><div>Check out</div></h3>', true, false),
    'nested inline control' => array('<h2 id="a_l" style="text-align:center"><div class="w"><div class="c"><div role="presentation" aria-label="Copy heading link"><a href="#a" aria-label="Copy heading link" role="button">#<svg width="22" height="22" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></a></div></div><span>Check out</span></div></h2>', true, false),
    'real block content' => array('<h2><div><img src="red.png" alt="Red square" width="40" height="40"></div><div><p>Check out</p></div></h2>', false, true),
);
$failures = array();
foreach ( $cases as $label => $case ) {
    [$source, $centered, $container] = $case;
    $result = (new HtmlTransformer())->transform('<!doctype html><html><head><style>.c{position:absolute;opacity:0}</style></head><body><main>' . $source . '<p>Body.</p></main></body></html>')->toArray();
    $markup = (string) $result['serialized_blocks'];
    $blocks = $result['blocks'][0]['innerBlocks'] ?? array();
    // The heading must be at the source level, not synthesized inside a group.
    $block = $blocks[0] ?? array();
    if ( $container ) {
        $valid = 'core/group' === ($block['blockName'] ?? '')
            && str_contains($markup, '<!-- wp:image')
            && str_contains($markup, '<p')
            && ! str_contains($markup, '<!-- wp:heading');
    } else {
        $content = (string) ($block['attrs']['content'] ?? '');
        $valid = 'core/heading' === ($block['blockName'] ?? '')
            && 1 === substr_count($markup, '<!-- wp:heading')
            && (str_contains($source, '<h3') ? 3 : 2) === ($block['attrs']['level'] ?? null)
            && 'Check out' === trim(preg_replace('/\s+/', ' ', str_replace('#', '', strip_tags($content))) ?? '')
            && ! str_contains($content, '<div')
            && (! $centered || str_contains($markup, 'has-text-align-center'))
            && (! str_contains($source, 'href="/x"') || str_contains($content, '<a href="/x">Check</a>'))
            && (! str_contains($source, 'role="button"') || str_contains($content, 'href="#a"'));
    }
    if ( ! $valid || str_contains($markup, '<!-- wp:html') || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = $label . ': ' . $markup;
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Heading phrasing wrappers passed.\n";
