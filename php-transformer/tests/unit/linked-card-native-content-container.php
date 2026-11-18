<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A flow-content link card keeps its unadorned content box (icon, text box,
// arrow) without making that box a generated block. The source div adds no
// spacing, so its children keep only their author margins.
$result = (new HtmlTransformer())->transform(
    '<style>.card{display:flex;align-items:center;gap:16px}h3,p{margin:0}.sub{margin-top:2px}.arrow{margin-left:auto}</style>'
    . '<a class="card" href="/contact/contact-information/">'
    . '<div class="icon"><svg width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg></div>'
    . '<div><h3>Contact Information</h3><p class="sub">Phone, email, address, and hours at a glance.</p></div>'
    . '<span class="arrow">→</span></a>'
)->toArray();

$content = null;
$find = static function (array $blocks) use (&$find, &$content): void {
    foreach ($blocks as $block) {
        if (str_ends_with((string) $block['blockName'], '/layout-shell') && 'div' === ($block['attrs']['wrappers'][0]['tagName'] ?? '')) {
            throw new RuntimeException('A plain linked content div must not become a generated layout-shell block.');
        }
        $names = array_column($block['innerBlocks'], 'blockName');
        if ('core/group' === $block['blockName'] && array('core/heading', 'core/paragraph') === $names) {
            $content = $block;
        }
        $find($block['innerBlocks']);
    }
};
$find($result['blocks']);
if (null === $content) {
    throw new RuntimeException('Linked card content must retain a native editable Group.');
}
if (isset($content['attrs']['layout'])
    || !str_contains($content['attrs']['className'] ?? '', 'blocks-engine-css-owned-layout')
    || !str_contains($content['attrs']['className'], 'blocks-engine-css-owned-flow')
    || !str_contains(json_encode($result['assets']), '.blocks-engine-css-owned-flow>*')) {
    throw new RuntimeException('The linked content box must cancel Core flow spacing and keep author margins.');
}

$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($result['serialized_blocks']));
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?>' . $saved);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//a[@href="/contact/contact-information/"]/div[contains(@class,"wp-block-group")]/h3[text()="Contact Information"]')->length
    || 1 !== $xpath->query('//a/div[contains(@class,"wp-block-group")]/p[contains(@class,"sub")]')->length) {
    throw new RuntimeException('Editor save must preserve the linked content box and its text ancestry.');
}
echo "Linked card native content container passed\n";
