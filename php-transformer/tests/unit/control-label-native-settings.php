<?php
declare(strict_types=1);

// A captured form action is published as its native <button>, collapsed from
// the editor's group + Core button label. The color, font size and padding an
// author sets on that Core button must style the published control, so the
// source class rule (`.action{color:…}`) no longer decides them.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Execute the shipped render filter with a minimal WordPress HTML API stand-in.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $root = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
    }
    public function next_tag(): bool {
        $this->root = $this->dom->getElementsByTagName('body')->item(0)->firstElementChild;
        return null !== $this->root;
    }
    public function get_attribute(string $name): ?string { return $this->root->hasAttribute($name) ? $this->root->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->root->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->root->removeAttribute($name); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->root); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['controlLabelRenderFilter'] = $callback; }
eval(RuntimeAttributesRuntime::source());

$result = (new HtmlTransformer())->transform(
    '<style>.action{padding:8px;background:#123;color:white;font-size:14px}</style>'
    . '<form><input name="email" data-blocks-engine-capture-native-control-state=\'{"version":1,"kind":"input","value":"","defaultValue":""}\'>'
    . '<button type="submit" class="action">Send</button></form>'
)->toArray();
$box = null;
$find = static function (array $blocks) use (&$find, &$box): void {
    foreach ($blocks as $block) {
        if (!empty($block['attrs']['metadata']['blocksEngineControlLabel'])) { $box = $block; return; }
        $find($block['innerBlocks'] ?? array());
    }
};
$find($result['blocks'] ?? array());
$assert(is_array($box) && 'core/group' === $box['blockName'], 'The form action is a labelled control box: ' . $result['serialized_blocks']);

$render = static fn (string $label): string => $GLOBALS['controlLabelRenderFilter']('<div class="wp-block-group"><div class="wp-block-button">' . $label . '</div></div>', $box);

// Untouched, the published control is exactly the source box.
$plain = $render('<button type="submit" class="wp-block-button__link wp-element-button">Send</button>');
$assert('<button type="submit" class="action">Send</button>' === $plain, 'An unstyled label publishes the bare source box: ' . $plain);

// Edited in the inspector, the label's settings ride onto the native control.
$edited = $render('<button type="submit" class="wp-block-button__link has-text-color has-custom-font-size wp-element-button has-base-color" style="color:#0b1c10;font-size:37px;padding-top:23px">Send</button>');
$dom = new DOMDocument();
@$dom->loadHTML('<html><body>' . $edited . '</body></html>');
$buttons = $dom->getElementsByTagName('button');
$button = $buttons->item(0);
$classes = $button instanceof DOMElement ? preg_split('/\s+/', $button->getAttribute('class')) : array();
$assert(1 === $buttons->length && 'Send' === $button->textContent, 'The control publishes as one native button: ' . $edited);
$assert(in_array('action', $classes, true) && in_array('has-text-color', $classes, true) && in_array('has-base-color', $classes, true) && in_array('has-custom-font-size', $classes, true), 'The label\'s block-support classes join the source box: ' . $edited);
$assert(!in_array('wp-element-button', $classes, true) && !in_array('wp-block-button__link', $classes, true), 'Core button chrome stays editor-only: ' . $edited);
$assert('color:#0b1c10;font-size:37px;padding-top:23px' === $button->getAttribute('style'), 'The label\'s inline settings style the published control: ' . $edited);

echo "Control label native settings: passed\n";
