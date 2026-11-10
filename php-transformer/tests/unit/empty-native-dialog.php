<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$transform = static fn (string $dialog): array => (new HtmlTransformer())->transform(
    '<!DOCTYPE html><html><head><title>Viewer</title></head><body><main><h1>Photos</h1><p>Body.</p></main>' . $dialog . '</body></html>'
)->toArray();
$baseline = $transform('');
foreach (array(
    '<dialog class="viewer" aria-label="Photo viewer"></dialog>',
    "<dialog id=\"viewer\"> \n\t </dialog>",
    '<dialog><!-- runtime fills this shell --></dialog>',
    '<dialog style="width:600px;background:black" aria-label="Viewer"></dialog>',
) as $source) {
    $result = $transform($source);
    $assert(array() === $result['fallbacks'], 'Empty dialog must not report unsupported content loss: ' . $source);
    $assert($baseline['serialized_blocks'] === $result['serialized_blocks'], 'Empty dialog must leave heading and paragraph unchanged');
    $assert(! str_contains($result['serialized_blocks'], '<dialog'), 'Empty dialog emits no block');
}

// Open dialogs render even without content. Boolean attribute presence, not
// its value, determines whether the dialog is open.
foreach (array(
    '<dialog open></dialog>',
    '<dialog open style="width:600px;background:black" aria-label="Viewer"></dialog>',
    "<dialog open=\"false\"> \n\t </dialog>",
    '<dialog open><!-- runtime fills this shell --></dialog>',
) as $source) {
    $result = $transform($source);
    $unsupported = array_filter($result['fallbacks'], static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? '') && 'dialog' === ($finding['tag'] ?? ''));
    $assert(array() !== $unsupported, 'Open empty dialog retains its unsupported conversion path: ' . $source);
}

// Text, elements without text, and nested empty elements are all content.
// Closed native dialogs with content use the editable dialog container path.
foreach (array('Text', '<h2>Tell us more</h2><form><label>Your name<input name="name"></label><button>Send</button></form>', '<img src="photo.jpg" alt="Photo">', '<span></span>') as $content) {
    $result = $transform('<dialog>' . $content . '</dialog>');
    $unsupported = array_filter($result['fallbacks'], static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? '') && 'dialog' === ($finding['tag'] ?? ''));
    $assert(array() === $unsupported, 'Closed dialog containing source content must not use unsupported fallback');
    $assert(str_contains($result['serialized_blocks'], 'blocksEngineDialog'), 'Closed dialog content must retain its dialog shell');
}

echo "OK: empty native dialog shells\n";
