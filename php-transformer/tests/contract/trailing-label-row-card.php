<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #5249: icon/text/cue rows must not add an icon-only Group or put the
// auto-margin cue inside a boxless RichText carrier. Both change the whole
// import's wrapper budget and the card's narrow-screen geometry.
$html = '<style>*{margin:0}a{color:inherit}.row{display:flex;gap:16px}.icon{display:flex;width:48px;height:48px;align-items:center;justify-content:center}.glyph{width:24px;height:24px}.cue{margin-left:auto}</style>'
    . '<a class="row" href="/location/" target="_blank" rel="noopener">'
    . '<div class="icon"><svg class="glyph" width="24" height="24" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></div>'
    . '<div><h3>Location</h3><p>Where to find us.</p></div><span class="cue">→</span></a>';
$out = (new HtmlTransformer())->transform($html)->toArray();
$card = $out['blocks'][0];
$children = $card['innerBlocks'];
if ('core/group' !== $card['blockName'] || 3 !== count($children)
    || 'core/paragraph' !== $children[0]['blockName']
    || !str_contains($children[0]['attrs']['content'], '<img ')
    || 'core/group' !== $children[1]['blockName']
    || 'core/paragraph' !== $children[2]['blockName']
    || !str_contains($children[2]['attrs']['className'], 'cue')
    || '→' !== $children[2]['attrs']['content']
    || !str_contains($card['attrs']['className'], 'blocks-engine-role-semantic')
    || substr_count($out['serialized_blocks'], 'href="/location/"') !== 1
    || str_contains($out['serialized_blocks'], '/layout-shell')) {
    throw new RuntimeException('Trailing-label cards need native source boxes and one heading destination: ' . $out['serialized_blocks']);
}
// A leading eyebrow and a headingless product are existing preservation
// paths, not trailing-label cards.
foreach (array(
    '<span>Journal</span><h3>Title</h3><p>Excerpt</p>',
    '<img src="kit.jpg" alt="Kit"><div><p>Kit</p><p>Price</p></div><span>→</span>',
) as $content) {
    $out = (new HtmlTransformer())->transform('<a href="/item/">' . $content . '</a>')->toArray();
    if (!str_contains($out['serialized_blocks'], '/layout-shell')) {
        throw new RuntimeException('Unrelated listing and eyebrow cards must retain their source anchor.');
    }
}
echo "Trailing-label row card contract passed.\n";
