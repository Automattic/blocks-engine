<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$source = '<main><h2>Menu</h2><div data-subfilters hidden><div class="chips"><button>All</button><button>Pots</button></div></div><p>After</p></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$marker = SourceBlockAttributeProjector::HIDDEN_RICH_TEXT_MARKER_CLASS;
$runtime = new Runtime();
$hidden = array();
$walk = static function (array $blocks) use (&$walk, &$hidden, $marker, $assert): void {
    foreach ($blocks as $block) {
        $assert('core/html' !== ($block['blockName'] ?? null), 'No HTML fallback');
        $assert(null !== ($block['blockName'] ?? null) || '' === trim($block['innerHTML'] ?? ''), 'No freeform residue');
        if (str_contains($block['attrs']['className'] ?? '', $marker)) $hidden[] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$parsed = $runtime->parseBlocks($markup);
$walk($parsed);
$assert('pass' === (new BlockValidityValidator())->validateBlocks($parsed)['status'], 'Serialized blocks remain Gutenberg-valid');
$assert(1 === count($hidden), 'The uncontrolled hidden wrapper survives as one hidden block');
$assert('core/group' === $hidden[0]['blockName'], 'Hidden state belongs to an editable Group');
$encoded = json_encode($hidden[0]);
$assert(str_contains($encoded, 'All') && str_contains($encoded, 'Pots'), 'Both chips stay inside the hidden wrapper');
$assert(!str_contains($encoded, 'Menu') && !str_contains($encoded, 'After'), 'Visible siblings remain outside the hidden wrapper');
$assert(str_contains($markup, '>Menu</h2>') && str_contains($markup, '>After</p>'), 'Visible heading and paragraph survive');
$assert(str_contains($hidden[0]['innerHTML'], $marker), 'Saved Group markup carries the class as well as its block attributes');
$assets = json_encode($result, JSON_UNESCAPED_SLASHES);
$assert(str_contains($assets, ':root :where(.' . $marker . '){display:none!important}'), 'Theme-owned support CSS hides the saved wrapper');
foreach (array(
    'label' => array('<label class="tz-food-search" hidden><span>Search</span><input type="search"></label>', '<label class="tz-food-search" hidden="">', false),
    'display override' => array('<style>.shown{display:flex}</style><div class="shown" hidden><p>Visible</p></div>', '<div class="shown" hidden="">', false),
    'semantic group' => array('<section hidden><div class="chips"><button>All</button><button>Pots</button></div></section>', '<section', true),
) as $name => [$html, $tag, $projected]) {
    $variation = (new HtmlTransformer())->transform($html)->toArray();
    $saved = $variation['serialized_blocks'];
    $assert(str_contains($saved, $tag), $name . ': source tag survives');
    $assert($projected === str_contains($saved, $marker), $name . ': only lost hidden state needs the marker');
    $assert('pass' === (new BlockValidityValidator())->validateBlocks($runtime->parseBlocks($saved))['status'], $name . ': valid blocks');
    $assert(!str_contains($saved, 'wp:html'), $name . ': no fallback');
    if ('display override' === $name) {
        $assert(str_contains(json_encode($variation), 'display:flex'), 'Author display override remains visible');
    }
}
echo "Uncontrolled hidden wrapper passed.\n";
