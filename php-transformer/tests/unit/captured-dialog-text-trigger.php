<?php
declare(strict_types=1);

/**
 * A text-labelled capture trigger keeps its native dialog.
 *
 * Once the recognized disclosure runtime is retired, its dropdown is a
 * projected native dialog. A trigger with a text label and that panel also
 * have the shape of a source aria disclosure, which lowers to core/details.
 * core/details cannot close on Escape, keep the trigger's aria-expanded or
 * replay ancestor bindings, all of which the retired runtime did, so the
 * projected dialog stays a native dialog block. A source disclosure that no
 * capture runtime drives still becomes core/details.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$compile = static function (array $ancestors) use ($runtime): array {
    $key = 'site-menu-panel';
    $binding = array() === $ancestors ? '' : ' data-blocks-engine-capture-dialog-ancestor-state="' . htmlspecialchars(json_encode($ancestors, JSON_THROW_ON_ERROR), ENT_QUOTES) . '"';
    $html = '<!doctype html><html><head><script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body>'
        . '<div id="scope" class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">'
        . '<header id="site-header" class="dark header"><div class="menu-host">'
        . '<button type="button" data-blocks-engine-capture-disclosure-label="Menu" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="menu"' . $binding . '>Menu</button>'
        . '<div id="' . $key . '" class="menu-panel data-blocks-engine-capture-dropdown" hidden data-blocks-engine-capture-dialog-panel="' . $key . '"><div><p>Visit</p><a href="#one">One</a></div></div>'
        . '</div></header><main><h2 id="one">One</h2></main></div></body></html>';
    $state = array(
        'status' => 'captured',
        'trigger' => array('selector' => 'missing', 'label' => 'Menu', 'tag' => 'button', 'ariaHaspopup' => 'menu'),
        'dialog' => array('html' => '<div>Report snapshot</div>', 'htmlBytes' => strlen('<div>Report snapshot</div>'), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
    );
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA, 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array($state)))))),
    )))->toArray();
};
$retired = static fn (array $result): bool => CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === (array_column($result['source_reports']['native_runtime_replacements'] ?? array(), 'body_hash', 'attribute')[CaptureEvidence::DISCLOSURE_RUNTIME] ?? null);

// With an ancestor binding: the binding must reach the native dialog.
$rows = array(array('selector' => '#site-header', 'tag' => 'header', 'depth' => 2, 'closed' => array('class' => 'dark header'), 'opened' => array('class' => 'light header')));
$bound = $compile($rows);
$boundMarkup = (string) ($bound['serialized_blocks'] ?? '');
$assert($retired($bound), 'the published runtime is retired', json_encode($bound['source_reports']['native_runtime_replacements'] ?? null));
$assert(!str_contains($boundMarkup, '<!-- wp:details') && 1 === substr_count($boundMarkup, '<!-- wp:custom/captured-dialog '), 'a text trigger with ancestor bindings keeps its native dialog instead of core/details', $boundMarkup);
$assert(str_contains($boundMarkup, '"ancestorState":[{"tag":"header","closed":{"class":"dark header"},"opened":{"class":"light header"}}]'), 'the ancestor binding travels to the native dialog', $boundMarkup);

// Without one: the retired runtime still closed the dropdown on Escape and
// kept aria-expanded, which core/details does not.
$plain = $compile(array());
$plainMarkup = (string) ($plain['serialized_blocks'] ?? '');
$assert($retired($plain) && !str_contains($plainMarkup, '<!-- wp:details') && 1 === substr_count($plainMarkup, '<!-- wp:custom/captured-dialog '), 'a text trigger without ancestor bindings keeps its native dialog too', $plainMarkup);
$assert(0 === ($bound['metrics']['fallback_count'] ?? -1) && 0 === ($plain['metrics']['fallback_count'] ?? -1), 'both pages lower without fallback blocks');

// A source disclosure that no capture runtime drives keeps core/details.
$source = (new HtmlTransformer())->transform('<div><button aria-expanded="false" aria-controls="answer-1">What is your refund policy?</button><div id="answer-1" hidden><p>Full refund within 30 days.</p></div></div>')->toArray();
$assert('core/details' === ($source['blocks'][0]['blockName'] ?? null), 'an uncaptured aria disclosure still becomes core/details');

if (0 !== $failures) exit(1);
echo "captured-dialog-text-trigger: ok\n";
