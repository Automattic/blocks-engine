<?php
declare(strict_types=1);

/** Generated component content must retain styled custom-element hosts. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$subtree = '<div class="masthead-row"><a href="/"><img src="brand.svg" alt="Brand"><img class="alternate-mark" src="brand-light.svg" alt=""></a>'
    . '</div><div class="menu-row"><details><summary>Explore</summary><nav><a href="/about">About</a><a href="/journal">Journal</a><a href="/contact">Contact</a></nav></details></div><div class="links-row">'
    . '<nav><a href="/collections"><img src="collections.jpg" alt="Collections"></a><a href="/stories"><img src="stories.jpg" alt="Stories"></a><a href="/visit"><img src="visit.jpg" alt="Visit"></a></nav></div>';
$compile = static function (string $attributes) use ($subtree): array {
    $html = '<style>.masthead .alternate-mark{position:absolute;opacity:0}</style>'
        . '<exhibit-masthead' . $attributes . '>' . $subtree . '</exhibit-masthead>';
    return (new HtmlTransformer())->transform($html)->toArray();
};
$reference = static function (array $result): array {
    foreach ($result['blocks'] ?? array() as $block) {
        if (preg_match('~^custom/[a-z]+-[a-f0-9]{64}$~', $block['blockName'] ?? '')) return $block;
    }
    return array();
};

$styled = $compile(' class="masthead variant-wide" id="exhibit-top"');
$block = $reference($styled);
$content = $block['attrs']['content'] ?? '';
$assert(array() !== $block, 'rich custom-element host emits a generated component');
$assert(str_contains($content, 'masthead variant-wide'), 'generated content retains host classes');
$assert(str_contains($content, 'id="exhibit-top"'), 'generated content retains host id');

// The author descendant selector must still match inside the rendered component.
$dom = new DOMDocument();
@$dom->loadHTML('<html><body>' . $content . '</body></html>');
$xpath = new DOMXPath($dom);
$matches = $xpath->query('//*[@id="exhibit-top" and contains(concat(" ", normalize-space(@class), " "), " masthead ")]//*[contains(concat(" ", normalize-space(@class), " "), " alternate-mark ")]');
$assert(false !== $matches && 1 === $matches->length, 'host-scoped descendant rule still matches rendered content');

$plain = $reference($compile(''));
$assert(array() !== $plain, 'attribute-less host also emits a generated component');
$assert(str_starts_with($plain['attrs']['content'] ?? '', '<div class="masthead-row">'), 'attribute-less host keeps inner-only content');
$assert(!str_contains($plain['attrs']['content'] ?? '', '<exhibit-masthead'), 'attribute-less host is not retained');

foreach (array(' class="masthead"', ' id="exhibit-top"', ' style="color:navy"') as $attributes) {
    $single = $reference($compile($attributes));
    $assert(str_contains($single['attrs']['content'] ?? '', '<exhibit-masthead'), 'each non-empty presentation attribute independently preserves the host: ' . $attributes);
}
$empty = $reference($compile(' class="  " id="" style=" "'));
$assert(!str_contains($empty['attrs']['content'] ?? '', '<exhibit-masthead'), 'empty presentation attributes do not preserve the host');

// Preserved content is also the static render and the input to stable naming.
$definitions = array_column($styled['source_reports']['generated_blocks'] ?? array(), null, 'name');
$localName = substr($block['blockName'] ?? '', strlen('custom/'));
$assert(($definitions[$localName]['render'] ?? null) === $content, 'reference and generated static render retain the same host');
$assert(($reference($compile(' class="masthead variant-wide" id="exhibit-top"'))['blockName'] ?? null) === ($block['blockName'] ?? ''), 'preserved host identity is deterministic');
$assert(($plain['blockName'] ?? '') !== ($block['blockName'] ?? ''), 'different rendered roots have distinct generated identities');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Generated component host root tests passed\n";
