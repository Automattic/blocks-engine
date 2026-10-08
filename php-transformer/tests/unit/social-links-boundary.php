<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<div><section><video src="movie.mp4"></video></section><div><a href="https://x.com">X</a><a href="https://facebook.com">Facebook</a></div></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

if (! str_contains($markup, '<!-- wp:video')) {
    fwrite(STDERR, "FAIL: a social-links descendant must not consume sibling video content\n");
    exit(1);
}

$wrapped = (new HtmlTransformer())->transform(
    '<style>.social-region{max-width:40rem}.icon-row{display:inline-flex;gap:4px;margin:10px 0}@media(min-width:900px){.icon-row{gap:12px}}</style>'
    . '<div class="social-region"><div class="icon-row"><a href="https://facebook.com/example" aria-label="Facebook"><svg width="24" height="24"></svg></a>'
    . '<a href="https://instagram.com/example" aria-label="Instagram"><svg width="24" height="24"></svg></a></div></div>'
)->toArray();
$wrapper = $wrapped['blocks'][0] ?? array();
$social = $wrapper['innerBlocks'][0] ?? array();
if ('core/group' !== ($wrapper['blockName'] ?? '') || !str_contains((string) ($wrapper['attrs']['className'] ?? ''), 'social-region')) {
    throw new RuntimeException('An outer social region must retain its own presentation boundary instead of consuming the inner icon row.');
}
if ('core/social-links' !== ($social['blockName'] ?? '') || !str_contains((string) ($social['attrs']['className'] ?? ''), 'icon-row')) {
    throw new RuntimeException('The native Social Links block must carry the actual row classes that own responsive gap and margins.');
}
if ('normal' !== ($social['attrs']['size'] ?? '') || 2 !== count($social['innerBlocks'] ?? array())) {
    throw new RuntimeException('Preserving the row must retain native icon sizing and every social link.');
}
if ('pass' !== ($wrapped['source_reports']['wp_block_validity']['status'] ?? '')) {
    throw new RuntimeException('Wrapped native social links must retain valid Gutenberg save markup.');
}

$nestedPlaceholders = (new HtmlTransformer())->transform('<div class="social-region"><div class="icon-row"><a href="#" aria-label="Facebook"><span></span></a><a href="#" aria-label="Instagram"><span></span></a></div></div>')->toArray();
$placeholderRow = $nestedPlaceholders['blocks'][0]['innerBlocks'][0] ?? array();
if ('core/social-links' !== ($placeholderRow['blockName'] ?? '') || array('facebook', 'instagram') !== array_column(array_column($placeholderRow['innerBlocks'] ?? array(), 'attrs'), 'service')) {
    throw new RuntimeException('An explicit outer social region must still identify its nested labeled placeholders as social links.');
}

$mixed = (new HtmlTransformer())->transform('<style>.frame{max-width:60rem}.social-region{display:block}.icon-row{display:inline-flex;gap:8px}@media(max-width:700px){.social-region{padding:12px}.rule{display:none}}</style>'
    . '<section class="social-region" role="region" aria-label="Social channels"><div class="frame"><hr class="rule"><div class="responsive-region"><h2>Connect with the community</h2><span class="icon-row"><a href="https://facebook.com/example" aria-label="Facebook"><span></span></a><a href="https://instagram.com/example" aria-label="Instagram"><svg width="24" height="24"></svg></a></span></div><hr class="rule"></div></section>')->toArray();
$flat = array();
$walk = static function (array $blocks) use (&$walk, &$flat): void {
    foreach ($blocks as $block) { $flat[] = $block; $walk($block['innerBlocks'] ?? array()); }
};
$walk($mixed['blocks']);
$names = array_column($flat, 'blockName');
if (0 !== ($mixed['metrics']['fallback_count'] ?? -1) || !in_array('core/heading', $names, true) || 2 !== count(array_filter($names, static fn(string $name): bool => 'core/separator' === $name))) {
    throw new RuntimeException('Zero fallback counts must not conceal the loss of a social region heading or its two separators.');
}
$rows = array_values(array_filter($flat, static fn(array $block): bool => 'core/social-links' === $block['blockName']));
if (1 !== count($rows) || !str_contains($rows[0]['attrs']['className'] ?? '', 'blocks-engine-social-source-row') || !str_contains($mixed['serialized_blocks'], 'icon-row')) {
    throw new RuntimeException('Only the tight link row owns native social links within mixed enclosing content.');
}
$markup = $mixed['serialized_blocks'];
if (!(strpos($markup, 'wp:separator') < strpos($markup, 'wp:heading') && strpos($markup, 'wp:heading') < strpos($markup, 'wp:social-links') && strpos($markup, 'wp:social-links') < strrpos($markup, 'wp:separator'))) {
    throw new RuntimeException('Native separator, heading, social row, separator order must match the source.');
}
foreach (array('frame', 'responsive-region', 'social-region') as $class) {
    if (!str_contains($markup, $class)) throw new RuntimeException('Mixed ancestor CSS ownership must survive: ' . $class);
}

$roleContext = (new HtmlTransformer())->transform('<section role="region" aria-label="Social channels"><h2>Our channels</h2><div><a href="#" aria-label="Facebook"><span></span></a><a href="#" aria-label="Instagram"><span></span></a></div><p>Stay connected</p></section>')->toArray();
if (!str_contains($roleContext['serialized_blocks'], 'wp:heading') || !str_contains($roleContext['serialized_blocks'], 'Stay connected') || 2 !== substr_count($roleContext['serialized_blocks'], '<!-- wp:social-link ')) {
    throw new RuntimeException('A social region role/label supplies row intent without claiming surrounding content.');
}
$headless = (new HtmlTransformer())->transform('<div><a href="https://facebook.com/example"><span></span></a><a href="https://instagram.com/example"><span></span></a></div>')->toArray();
if ('core/social-links' !== ($headless['blocks'][0]['blockName'] ?? '') || 2 !== count($headless['blocks'][0]['innerBlocks'])) {
    throw new RuntimeException('A legitimate direct headless social row still lowers directly to native Social Links.');
}
foreach (array('role="navigation"', 'role="menu"', 'class="manual-menu"') as $owner) {
    $menu = (new HtmlTransformer())->transform('<div ' . $owner . '><a href="https://facebook.com/example">Facebook</a><a href="https://instagram.com/example">Instagram</a></div>')->toArray();
    if (str_contains($menu['serialized_blocks'], '<!-- wp:social-links')) throw new RuntimeException('Manual menu semantics retain their existing owner: ' . $owner);
}
$form = (new HtmlTransformer())->transform('<div class="social-region"><h2>Keep in touch</h2><form action="/search" method="get"><label for="query">Search</label><input id="query" name="q"><button type="submit">Search</button></form><div><a href="https://facebook.com/example"><span></span></a><a href="https://instagram.com/example"><span></span></a></div></div>')->toArray();
if (!str_contains($form['serialized_blocks'], 'Keep in touch') || !str_contains($form['serialized_blocks'], '<!-- wp:search ') || 2 !== substr_count($form['serialized_blocks'], '<!-- wp:social-link ')) {
    throw new RuntimeException('Social recognition preserves sibling form topology and its native semantic owner.');
}
$unusable = (new HtmlTransformer())->transform('<div class="social-links"><a href="https://facebook.com/example">Facebook</a><a href="#" aria-label="Community">Community</a></div>')->toArray();
if (str_contains($unusable['serialized_blocks'], '<!-- wp:social-links') || !str_contains($unusable['serialized_blocks'], 'Community')) {
    throw new RuntimeException('A cluster must not consume an unusable sibling URL or invent its service.');
}

$ctaRow = (new HtmlTransformer())->transform('<style>.closing-links{display:flex;flex-wrap:wrap;gap:1.4rem;align-items:center}.button{display:inline-flex;padding:.88rem 1.2rem;border-radius:5px}.closing-links>a:not(.button){font-weight:750}</style>'
    . '<section class="closing"><h2>Generate freely.</h2><p>Every import is a measurement.</p><div class="closing-links"><a class="button inverted" href="https://github.com/example/engine">Explore the engine</a><a href="https://github.com/example/importer">Importer on GitHub <span aria-hidden="true">↗</span></a></div></section>')->toArray();
if (str_contains($ctaRow['serialized_blocks'], '<!-- wp:social-links') || !str_contains($ctaRow['serialized_blocks'], 'Explore the engine') || !str_contains($ctaRow['serialized_blocks'], 'Importer on GitHub')) {
    throw new RuntimeException('A call-to-action row without declared social intent keeps its layout lowering even when its links point at a social host.');
}

echo "Social-links boundary tests passed\n";
