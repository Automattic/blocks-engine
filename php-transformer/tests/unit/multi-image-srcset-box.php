<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
// Minimal DOM-backed WordPress HTML API stand-in: execute the shipped image
// render filter so srcset assertions inspect actual rendered <img> markup.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $image = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML($html);
    }
    public function next_tag(string $tag): bool {
        $this->image = $this->dom->getElementsByTagName(strtolower($tag))->item(0);
        return null !== $this->image;
    }
    public function get_attribute(string $name): ?string { return $this->image->hasAttribute($name) ? $this->image->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->image->setAttribute($name, $value); }
    public function remove_attribute(string $name): void { $this->image->removeAttribute($name); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->dom->getElementsByTagName('body')->item(0)); }
}
function add_filter(string $name, callable $callback, int $priority, int $arguments): void { $GLOBALS['imageSelectionRenderer'] = $callback; }
eval(Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap::IMAGE_SELECTION_RENDERER);

$image = static function (string $name, bool $responsive, bool $hidden = false): string {
    return '<img class="image-' . $name . '" src="https://example.com/' . $name . '.jpg"'
        . ($responsive ? ' srcset="https://example.com/' . $name . '-300.jpg 300w, https://example.com/' . $name . '.jpg 1000w" sizes="25vw"' : '')
        . ' alt="' . ('a' === $name ? 'Front' : 'Back') . '" width="1000" height="1000"'
        . ($hidden ? ' style="display:none"' : '') . '>';
};
// The five shapes from #4378, including both visibility forms of its last row.
$cases = array(
    'box: srcset and plain' => array(true, array(true, false), false),
    'box: two srcsets' => array(true, array(true, true), false),
    'no box: two srcsets' => array(false, array(true, true), false),
    'box: one srcset' => array(true, array(true), false),
    'box: two plain' => array(true, array(false, false), false),
    'box: two plain, hidden sibling' => array(true, array(false, false), true),
    'box: srcset and hidden sibling' => array(true, array(true, false), true),
    'picture source selection' => array(true, array(true), false, true),
    'capture variant duplicates' => array(true, array(true, true), false, false, true),
);
foreach ($cases as $label => $case) {
    [$wrapped, $responsive, $hidden] = $case;
    $picture = $case[3] ?? false;
    $capture = $case[4] ?? false;
    $carrier = $picture || $capture;
    $images = '';
    foreach ($responsive as $i => $hasSrcset) {
        $images .= $image(0 === $i ? 'a' : 'b', $hasSrcset, $hidden && 1 === $i);
    }
    if ($capture) {
        $images = str_replace(array('class="image-a"', 'class="image-b"'), array('id="photo"', 'id="photo--data-blocks-engine-capture-mobile"'), $images);
    }
    if ($picture) {
        $images = '<picture><source media="(max-width:600px)" srcset="https://example.com/mobile.jpg">' . $images . '</picture>';
    }
    $html = '<!doctype html><html><head></head><body><main><div class="grid"><a href="/p/x">'
        . ($wrapped ? '<div class="w">' : '') . $images . ($wrapped ? '</div>' : '')
        . '<h2>Sticker</h2></a></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $serialized = $result['serialized_blocks'] ?? '';
    $assert(($carrier ? 1 : 0) === preg_match_all('~<!-- wp:[^\s/]+/responsive-media(?:\s|/|>)~', $serialized), "$label: responsive-media count");
    $assert(($carrier ? 0 : count($responsive)) === substr_count($serialized, '<!-- wp:image '), "$label: core/image count");
    if ($carrier) {
        $assert(str_contains($serialized, $picture ? 'mobile.jpg' : 'https://example.com/b.jpg'), "$label: source survives");
        continue;
    }
    if ($wrapped && count($responsive) > 1 && in_array(true, $responsive, true)) {
        $assert(1 === preg_match('~<!-- wp:group [^\n]*"w"~', $serialized), "$label: native image box");
    }
    $rendered = preg_replace_callback('~<!-- wp:image (\{.*?\}) -->(.*?)<!-- /wp:image -->~s', static function (array $match): string {
        return ($GLOBALS['imageSelectionRenderer'])($match[2], array('attrs' => json_decode($match[1], true)));
    }, $serialized);
    $dom = new DOMDocument();
    @$dom->loadHTML($rendered);
    $nodes = $dom->getElementsByTagName('img');
    $assert(count($responsive) === $nodes->length, "$label: image markup count");
    foreach ($responsive as $i => $hasSrcset) {
        $node = $nodes->item($i);
        $name = 0 === $i ? 'a' : 'b';
        foreach (array('src' => "https://example.com/$name.jpg", 'alt' => 0 === $i ? 'Front' : 'Back') as $attribute => $expected) {
            $assert($expected === $node->getAttribute($attribute), "$label: $name $attribute survives");
        }
        $assert(str_contains($node->getAttribute('style'), 'width:1000px') && str_contains($node->getAttribute('style'), 'height:1000px'), "$label: $name dimensions survive");
        if ($hasSrcset) {
            // Gutenberg saves the source selection in metadata and restores it
            // on rendered image markup, rather than adding invalid save attrs.
            $assert("https://example.com/$name-300.jpg 300w, https://example.com/$name.jpg 1000w" === $node->getAttribute('srcset'), "$label: $name srcset on rendered image");
            $assert('25vw' === $node->getAttribute('sizes'), "$label: $name sizes on rendered image");
        }
    }
    if ($hidden) {
        $assert(1 === preg_match('/display\s*:\s*none/', $serialized . json_encode($result['assets'] ?? array())), "$label: hidden sibling styling survives");
    }
}
echo "PASS: multi-image srcset boxes\n";
