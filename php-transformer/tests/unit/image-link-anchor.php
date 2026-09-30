<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void {
    if ( $ok ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();

$result = $transform('<style>a#thumbnail:hover{opacity:.8}a#thumbnail:hover>img{opacity:.6}a#thumbnail>img{border-radius:12px}@media(min-width:800px){a#thumbnail{padding:4px}}</style><a id="thumbnail" class="source-link" href="/print" target="_blank" rel="noopener"><img src="print.jpg" alt="Print"></a>');
$block = $result['blocks'][0];
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$assert('core/image' === $block['blockName'], 'an identified image link stays a native editable image');
$assert('thumbnail' === ($block['attrs']['anchor'] ?? null), 'the native figure anchor retains the source fragment target');
$assert(! str_contains($markup, 'linkAnchor') && 1 !== preg_match('/<a\b[^>]*\bid=/', $markup), 'saved image links use only attributes supported by core/image save');
$assert(str_contains($markup, '<figure id="thumbnail"'), 'the source ID is rendered once on the supported figure');
$assert(str_contains($markup, 'href="/print" target="_blank" rel="noopener" class="source-link '), 'destination, target, rel, and source class survive promotion');
$assert(str_contains($markup, '<img src="print.jpg" alt="Print"/>'), 'image source and alt text remain editable native content');
preg_match('/<a[^>]*class="[^"]*(blocks-engine-semantic-[^" ]+)/', $markup, $linkMarker);
preg_match('/<figure[^>]*class="[^"]*(blocks-engine-semantic-[^" ]+)/', $markup, $imageMarker);
$assert(isset($linkMarker[1]) && str_contains($css, ':where(.' . $linkMarker[1] . ')') && str_contains($css, ':hover{opacity:.8}'), 'ID-qualified link hover styling follows the retained anchor through semantic CSS projection');
$assert(str_contains($css, '@media(min-width:800px){:where(.' . ($linkMarker[1] ?? 'missing') . ')'), 'conditional ID-qualified link styling retains its media condition');
$assert(isset($imageMarker[1]) && preg_match('/' . preg_quote(':where(.' . $imageMarker[1] . ')', '/') . '[^{]*\{border-radius:12px\}/', $css), 'descendant image styling follows the generated figure instead of the removed source ancestry');
$assert(isset($imageMarker[1]) && str_contains($css, ':where(.' . $imageMarker[1] . ').wp-block-image > a > img'), 'the native image bridge still reaches the image inside its link');

$collision = $transform('<figure id="frame"><a id="thumbnail" href="/print"><img src="print.jpg" alt="Print"></a></figure>');
$outer = $collision['blocks'][0];
$inner = $outer['innerBlocks'][0] ?? array();
$assert('core/group' === $outer['blockName'] && 'thumbnail' === ($outer['attrs']['anchor'] ?? null), 'an existing figure identity uses a separate native fragment host for the link ID');
$assert('core/image' === ($inner['blockName'] ?? null) && 'frame' === ($inner['attrs']['anchor'] ?? null), 'collision handling preserves the existing image identity and native editing');
$assert(1 === substr_count($collision['serialized_blocks'], 'id="thumbnail"') && 1 === substr_count($collision['serialized_blocks'], 'id="frame"'), 'each fragment target remains unique');
$assert(! str_contains(implode("\n", array_column($collision['assets'], 'content')), 'display:contents'), 'fragment hosts retain a box so native fragment scrolling can reach them');

$assert(str_contains($css, 'a:where(.' . ($linkMarker[1] ?? 'missing') . '):not(#') && str_contains($css, ':hover>img{opacity:.6}'), 'ancestor hover conditions remain on the native link rather than being flattened or lost');
$escaped = $transform('<style>a#thumbnail\\:extra:hover>img{opacity:.4}a[data-ref="#thumbnail"]:hover>img{opacity:.3}</style><a id="thumbnail" href="/print"><img src="print.jpg"></a>');
$escapedCss = implode("\n", array_column($escaped['assets'], 'content'));
$assert(str_contains($escapedCss, 'a#thumbnail\\:extra:hover>img{opacity:.4}'), 'an escaped suffix prevents rewriting the prefix of a different CSS ID');
$assert(str_contains($escapedCss, '[data-ref="#thumbnail"]'), 'quoted attribute values are not rewritten as selector IDs');
$same = $transform('<figure id="thumbnail"><a id="thumbnail" href="/print"><img src="print.jpg"></a></figure>');
$assert('core/image' === $same['blocks'][0]['blockName'] && 1 === substr_count($same['serialized_blocks'], 'id="thumbnail"'), 'matching source IDs share one native anchor without a wrapper or duplicate ID');
$imageId = $transform('<a id="thumbnail" href="/print"><img id="photo" src="print.jpg"></a>');
$assert('thumbnail' === ($imageId['blocks'][0]['attrs']['anchor'] ?? null) && 'photo' === ($imageId['blocks'][0]['innerBlocks'][0]['attrs']['anchor'] ?? null), 'an image-owned ID is also preserved on collision');
$gallery = $transform('<div class="gallery"><figure id="frame"><a id="thumbnail" href="/print"><img src="print.jpg"></a></figure><figure><img src="other.jpg"></figure></div>');
$assert('core/gallery' !== $gallery['blocks'][0]['blockName'] && str_contains($gallery['serialized_blocks'], 'id="thumbnail"') && str_contains($gallery['serialized_blocks'], 'id="frame"'), 'gallery recognition falls back to native flow rather than placing identity groups inside a Gallery block');

$plain = $transform('<a href="/print"><img src="print.jpg" alt="Print"></a>');
$assert('core/image' === $plain['blocks'][0]['blockName'] && ! isset($plain['blocks'][0]['attrs']['anchor']), 'unidentified linked images keep their existing native shape');
$assert(! str_contains($plain['serialized_blocks'], 'blocks-engine-semantic'), 'unidentified links need no new semantic markers');

if ( $failures ) { fwrite(STDERR, "image link anchor FAILED: $passes passed, $failures failed\n"); exit(1); }
fwrite(STDOUT, "image link anchor passed: $passes assertions\n");
