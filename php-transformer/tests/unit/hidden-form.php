<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/hidden-form.html');
$hidden = (new HtmlTransformer())->transform($html)->toArray();
$visible = (new HtmlTransformer())->transform(str_replace('form hidden', 'form', $html))->toArray();
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
foreach (array($hidden, $compiled) as $result) {
    $fallback = $result['fallbacks'][0] ?? array();
    $root = $fallback['readable_blocks'][0] ?? array();
    $assert('core/group' === ($root['blockName'] ?? null) && 'form' === ($root['attrs']['tagName'] ?? null), 'Hidden form keeps the provider form-group root.');
    $assert('' === ($root['attrs']['metadata']['blocksEngineRuntimeAttributes']['hidden'] ?? null), 'Native hidden default survives in runtime metadata.');
    $indexes = array();
    $walk = static function (array $block) use (&$walk, &$indexes): void {
        if (isset($block['form_control_index'])) $indexes[] = $block['form_control_index'];
        foreach ($block['innerBlocks'] ?? array() as $child) $walk($child);
    };
    $walk($root);
    sort($indexes);
    $assert(array(0, 1) === $indexes && 2 === count($fallback['controls'] ?? array()), 'Every control remains indexed exactly once.');
}
$visibleRoot = $visible['fallbacks'][0]['readable_blocks'][0];
$assert('core/group' === $visibleRoot['blockName'] && 'form' === $visibleRoot['attrs']['tagName'], 'Visible form root stays unchanged.');
$assert(!isset($visibleRoot['attrs']['metadata']['blocksEngineRuntimeAttributes']['hidden']), 'Visible forms gain no hidden state.');
$assert($hidden['fallbacks'][0]['readable_blocks'][0]['innerBlocks'] === $visibleRoot['innerBlocks'], 'Hidden state does not change the control tree.');

// Execute the shipped render filter with a minimal WordPress HTML API stand-in.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $root = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
    }
    public function next_tag(): bool {
        $this->root = $this->dom->getElementsByTagName('body')->item(0)->firstElementChild;
        return null !== $this->root;
    }
    public function get_attribute(string $name): ?string { return $this->root->hasAttribute($name) ? $this->root->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->root->setAttribute($name, true === $value ? '' : $value); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->root); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['hiddenFormRenderFilter'] = $callback; }
eval(RuntimeAttributesRuntime::source());
$root = $hidden['fallbacks'][0]['readable_blocks'][0];
$rendered = $GLOBALS['hiddenFormRenderFilter']($root['innerHTML'], $root);
$dom = new DOMDocument();
@$dom->loadHTML($rendered);
$form = $dom->getElementsByTagName('form')->item(0);
$assert($form instanceof DOMElement && $form->hasAttribute('hidden'), 'Materialized form starts hidden.');
$form->removeAttribute('hidden');
$assert(!$form->hasAttribute('hidden') && !$form->hasAttribute('style'), 'Removing hidden reveals the form without a permanent hiding style.');
echo "Hidden form: provider root, control coverage, and rendered hidden default preserved.\n";
