<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6488: authored loading and fetchpriority on src-only images (no
// srcset or picture) ride in blocksEngineImageSelection metadata and are
// restored at render, so WordPress does not load deferred images eagerly.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><title>Lazy</title><style>img{width:100%;height:auto;display:block}</style></head><body><main>'
    . '<section><img src="hero.jpg" alt="Hero" fetchpriority="high"></section>'
    . '<section><img src="two.jpg" alt="Two" loading="lazy"></section>'
    . '<section><img src="three.jpg" alt="Three" loading="lazy"></section>'
    . '<section><img src="four.jpg" alt="Four" loading="eager"></section>'
    . '<section><img src="five.jpg" alt="Five"></section>'
    . '</main></body></html>';
$markup = (string) ((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'] ?? '');
$assert(5 === preg_match_all('/<!-- wp:image (\{.*?\}) -->(.*?)<!-- \/wp:image -->/s', $markup, $images), 'Five native core/image blocks: ' . $markup);

$expected = array(
    'hero.jpg' => array('fetchpriority' => 'high'),
    'two.jpg' => array('loading' => 'lazy'),
    'three.jpg' => array('loading' => 'lazy'),
    'four.jpg' => array('loading' => 'eager'),
    'five.jpg' => null,
);
$blocks = array();
foreach ($images[1] as $index => $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $saved = $images[2][$index];
    $name = preg_match('/<img\b[^>]*\bsrc="([^"]*)"/', $saved, $src) ? basename($src[1]) : '';
    $assert(array_key_exists($name, $expected), 'Unexpected image: ' . $json);
    $selection = $attrs['metadata']['blocksEngineImageSelection'] ?? null;
    if (null === $expected[$name]) {
        $assert(null === $selection, 'An image without authored loading records no selection: ' . $json);
    } else {
        $assert(is_array($selection) && array('src' => $src[1]) + $expected[$name] == $selection, 'Selection records only the authored loading and fetchpriority: ' . $json);
    }
    $assert(!preg_match('/\b(?:loading|fetchpriority|srcset|sizes)=/', $saved), 'Saved core/image markup is unchanged: ' . $saved);
    $blocks[$name] = array($saved, $attrs);
}

$bootstrap = '';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['target_path'] ?? '')) $bootstrap = (string) ($write['payload']['data'] ?? '');
$renderer = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap::IMAGE_SELECTION_RENDERER;
$assert(str_contains($bootstrap, $renderer), 'The theme bootstrap ships the image selection renderer.');

// Minimal HTML API stand-in: execute the shipped render filter.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $tag = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
    }
    public function next_tag(string $name): bool {
        $this->tag = $this->dom->getElementsByTagName(strtolower($name))->item(0);
        return null !== $this->tag;
    }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->tag->removeAttribute($name); }
    public function get_updated_html(): string {
        $html = '';
        foreach ($this->dom->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->dom->saveHTML($node);
        return $html;
    }
}
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['imageSelectionFilter'] = $callback; }
eval($renderer);

foreach ($blocks as $name => [$saved, $attrs]) {
    $rendered = $GLOBALS['imageSelectionFilter']($saved, array('blockName' => 'core/image', 'attrs' => $attrs));
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $rendered . '</body></html>');
    $img = $dom->getElementsByTagName('img')->item(0);
    $assert($img instanceof DOMElement, 'Rendered image keeps its img: ' . $rendered);
    $want = $expected[$name] ?? array();
    foreach (array('loading', 'fetchpriority') as $attribute) {
        $value = $img->hasAttribute($attribute) ? $img->getAttribute($attribute) : null;
        $assert(($want[$attribute] ?? null) === $value, $name . ' renders ' . $attribute . '=' . var_export($want[$attribute] ?? null, true) . ': ' . $rendered);
    }
    $assert(!$img->hasAttribute('srcset') && !$img->hasAttribute('sizes'), 'A src-only selection does not touch srcset or sizes: ' . $rendered);
    if (null === $expected[$name]) $assert($saved === $rendered, 'An image without authored values renders unchanged.');
}

echo "src-only-image-loading: ok\n";
