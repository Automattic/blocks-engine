<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\CapturedDialogRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(__DIR__ . '/../fixtures/closed-native-dialog.html');
$result = (new HtmlTransformer())->transform($html)->toArray();
$assert(array() === $result['fallbacks'], 'Closed native dialog must not report a fallback');
$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if (isset($block['attrs']['metadata']['blocksEngineDialog'])) return $block;
        $found = $find($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$panel = $find($result['blocks']);
$assert(null !== $panel && 'core/group' === $panel['blockName'], 'Dialog must use the existing editable Group shell');
$assert(array('core/paragraph') === array_column($panel['innerBlocks'], 'blockName'), 'Dialog children must remain native editable blocks');
$assert(str_contains($panel['innerBlocks'][0]['innerHTML'], 'Hi'), 'Dialog must retain its paragraph');
$assert(!str_contains($result['serialized_blocks'], 'wp:html'), 'Dialog must not use raw HTML fallback');

// Exercise the same public artifact entry point used by imports, without any
// capture receipt or interaction evidence.
$artifact = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(array('path' => 'index.html', 'content' => $html)),
))->toArray();
$json = json_encode($artifact, JSON_THROW_ON_ERROR);
$assert(!str_contains($json, 'html_unsupported_element') && !str_contains($json, 'unsupported_html_fallback'), 'Reduced page must compile without an unsupported import defect');
$markup = implode('', array_column($artifact['source_reports']['wordpress_site_plan']['pages'] ?? array(), 'canonical_block_markup'));
$assert(str_contains($markup, 'blocksEngineDialog') && str_contains($markup, 'Hi'), 'Artifact compilation must retain the dialog shell and content');

// Execute the generated frontend Group filter: the editor Group becomes a
// native closed dialog, not a visible div. The browser hides it without open.
$filters = array();
function add_filter(string $name, callable $callback, int $priority, int $args): void
{
    global $filters;
    $filters[$name] = $callback;
}
function add_action(string $name, callable $callback): void {}
function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
eval(CapturedDialogRuntime::bootstrap());
$rendered = $filters['render_block_core/group']('<div class="wp-block-group"><p>Hi</p></div>', $panel);
$assert('<dialog><p>Hi</p></dialog>' === $rendered, 'Frontend must preserve the native closed dialog and its hidden default');

echo "OK: closed native dialog\n";
