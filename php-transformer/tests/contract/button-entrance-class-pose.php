<?php
declare(strict_types=1);

// A <button> whose entrance replays a class token (`preFade` -> `preFade
// fadeIn`) is lowered to core/button: the source classes are saved on the
// wrapper and the paint rules land on the link. The replay must toggle the
// token where it lives, and the rules it drives must keep reading it live.

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$entrance = htmlspecialchars('{"attributes":{"class":{"before":"arrow preFade","after":"arrow preFade fadeIn"}},"threshold":[0],"repeat":false}', ENT_QUOTES);
$css = '.preFade{opacity:0;transition-property:opacity}.fadeIn:not([data-override]){opacity:1!important}.arrow{width:40px;height:40px;border:0;background:#333;transition:opacity .3s}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><h1>Top</h1>'
    . '<button class="arrow preFade fadeIn" aria-label="Next" data-blocks-engine-capture-viewport-entrance="' . $entrance . '"><svg viewBox="0 0 10 10" width="10" height="10"><path d="M0 0L10 5L0 10z"/></svg></button></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$styles = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'] ?? array(), static fn (array $asset): bool => !str_contains((string) ($asset['path'] ?? ''), 'editor'))));
$assert(1 === preg_match('/:where\(\.blocks-engine-control-[0-9a-f]{12}-0\.fadeIn\)[^{]*>\s*:where\(\.wp-block-button__link\)[^{]*\{opacity:1!important/', $styles), 'The replayed token stays a live predicate of the projected control rule.');

class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $tag = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
    }
    public function next_tag(array $query = array()): bool {
        foreach ($this->dom->getElementsByTagName('body')->item(0)->getElementsByTagName('*') as $element) {
            if (!isset($query['class_name']) || in_array($query['class_name'], preg_split('/\s+/', $element->getAttribute('class')), true)) return null !== ($this->tag = $element);
        }
        return false;
    }
    public function has_class(string $name): bool { return in_array($name, preg_split('/\s+/', $this->tag->getAttribute('class')), true); }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->dom->getElementsByTagName('body')->item(0)->firstElementChild); }
}
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['buttonEntranceFilter'] = $callback; }
eval(RuntimeAttributesRuntime::source());

$rendered = '';
preg_match_all('/<!-- wp:(buttons?) (\{.*?\}) -->/s', (string) $result['serialized_blocks'], $blocks, PREG_OFFSET_CAPTURE);
foreach ($blocks[1] as $index => [$name]) {
    $start = $blocks[0][$index][1] + strlen($blocks[0][$index][0]);
    $markup = 'button' === $name
        ? substr($result['serialized_blocks'], $start, strpos($result['serialized_blocks'], '<!-- /wp:button -->', $start) - $start)
        : '<div class="wp-block-buttons"></div>';
    $rendered .= $GLOBALS['buttonEntranceFilter']($markup, array('blockName' => 'core/' . $name, 'attrs' => json_decode($blocks[2][$index][0], true)));
}
$dom = new DOMDocument();
@$dom->loadHTML('<html><body>' . $rendered . '</body></html>');
$witnesses = (new DOMXPath($dom))->query('//*[@data-blocks-engine-capture-viewport-entrance]');
$assert(1 === $witnesses->length, 'One element replays the entrance.');
$owner = $witnesses->item(0);
$assert(str_contains(' ' . $owner->getAttribute('class') . ' ', ' wp-block-button '), 'The wrapper that saves the source classes replays them.');
$pose = json_decode($owner->getAttribute('data-blocks-engine-capture-viewport-entrance'), true)['attributes']['class'];
$assert(in_array(EngineMarker::subjectClassClass('fadeIn'), explode(' ', $pose['after']), true)
    && !in_array(EngineMarker::subjectClassClass('fadeIn'), explode(' ', $pose['before']), true), 'Subject classes follow their replayed tokens.');

echo "Button entrance class pose passed.\n";
