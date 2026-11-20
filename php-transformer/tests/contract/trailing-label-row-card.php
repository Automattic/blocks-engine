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
// Source analysis initially sees the cue as RichText. After the cue
// becomes its own paragraph, no dead per-element RichText arm should remain
// in the shared author CSS (nor multiply once for each card on a route).
$out = (new HtmlTransformer())->transform(
    '<style>.cue{margin-left:auto;color:green;transition:transform .15s}.row{display:flex}</style>'
    . '<a class="row" href="/one/"><div><h3>One</h3><p>Summary</p></div><span class="cue">→</span></a>'
    . '<a class="row" href="/two/"><div><h3>Two</h3><p>Summary</p></div><span class="cue">→</span></a>'
)->toArray();
$css = implode("\n", array_column($out['assets'], 'content'));
if (str_contains($css, 'data-blocks-engine-richtext-marker')
    || !str_contains($css, '.cue')
    || substr_count($out['serialized_blocks'], 'blocks-engine-source-box-paragraph') !== 4) {
    throw new RuntimeException('Native card cues must not leave speculative RichText CSS: ' . $css);
}
// Extending eligibility must not remove semantic roles from existing cards.
// Whole-site styles are shared with those cards: even a universal reset uses
// the role's subject arm after the source anchor becomes a Group.
$out = (new HtmlTransformer())->transform(
    '<style>*,:before,:after{margin:0;padding:0}a{color:inherit}</style>'
    . '<main><a href="/existing/"><h3>Existing card</h3><p>Summary</p></a>'
    . '<a href="/new/"><div><h3>New card</h3><p>Summary</p></div><span>→</span></a></main>'
)->toArray();
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $out['serialized_blocks']);
$xpath = new DOMXPath($dom);
$cards = $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]');
if (2 !== $cards->length) {
    throw new RuntimeException('Mixed existing and trailing-label cards must both remain native.');
}
foreach ($cards as $card) {
    if (!str_contains($card->getAttribute('class'), 'blocks-engine-role-semantic')) {
        throw new RuntimeException('Existing stretched cards must keep their semantic selector projection.');
    }
}
$css = implode('', array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($out['assets'], static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? ''))
));
if (!str_contains($css, ':where(.blocks-engine-role-semantic.blocks-engine-subject)')
    || preg_match('/blocks-engine-semantic-[0-9a-f]+-\d+/', $css)) {
    throw new RuntimeException('Mixed cards must retain the shared semantic reset arm: ' . $css);
}
// Public-interface regression: introducing a native card must not rebind
// shared utility classes to semantic subjects local to this route. Site-plan
// consumers (including extracted chrome) still use the authored hooks.
$out = (new HtmlTransformer())->transform(
    '<style>.rounded{border-radius:16px}.cue{margin-left:auto}a{color:green}</style>'
    . '<aside class="rounded">Shared chrome</aside>'
    . '<a class="rounded" href="/place/"><div><h3>Place</h3><p>Summary</p></div><span class="cue">→</span></a>'
)->toArray();
$css = implode('', array_column(array_filter(
    $out['assets'], static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')
), 'content'));
if (!preg_match('/\.rounded[^{}]*\{border-radius:16px\}/', $css)
    || !str_contains($css, '.cue:not(.be-c-0){margin-left:auto}')
    || !str_contains($css, 'blocks-engine-subject-tag-a')
    || str_contains($out['serialized_blocks'], '/layout-shell')) {
    throw new RuntimeException('Native cards must preserve shared class rules and project anchor types: ' . $css);
}
// Shared utilities must retain a context-free semantic subject when old and
// newly eligible cards coexist. A native-card bypass used to remove the new
// card from the match set and bind the existing card to route-local markers.
$out = (new HtmlTransformer())->transform(
    '<style>.rounded{border-radius:16px}.row{display:flex}.cue{margin-left:auto}</style>'
    . '<a class="rounded" href="/existing/"><h3>Existing</h3><p>Summary</p></a>'
    . '<a class="rounded row" href="/new/"><div><h3>New</h3><p>Summary</p></div><span class="cue">→</span></a>'
)->toArray();
$css = implode('', array_column(array_filter(
    $out['assets'], static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')
), 'content'));
if (preg_match('/blocks-engine-semantic-[0-9a-f]+-\d+/', $css)
    || !str_contains($css, 'blocks-engine-role-semantic.blocks-engine-subject.blocks-engine-subject-class-')
    || str_contains($out['serialized_blocks'], '/layout-shell')) {
    throw new RuntimeException('Mixed cards must not rebind shared utility subjects to route-local markers: ' . $css);
}
echo "Trailing-label row card contract passed.\n";
