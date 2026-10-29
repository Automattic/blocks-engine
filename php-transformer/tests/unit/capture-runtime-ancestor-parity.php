<?php
declare(strict_types=1);

/**
 * Recognized disclosure runtime -> native dialog: ancestor class bindings.
 *
 * Blocks Engine retires the capture's disclosure runtime only when its body is
 * the published one (CaptureEvidence::DISCLOSURE_RUNTIME_SHA256). The native
 * dialog must then replay what that runtime replays. The current producer body
 * replays ancestor `class` bindings as token sets (a re-ordered theme class, an
 * empty closed class on `body`, a long class list recorded as changed tokens
 * plus identifying ones). This test compiles a scoped dropdown whose trigger
 * carries those bindings and checks that the published body is replaced and an
 * altered body is kept. It writes the fixture for
 * capture-runtime-ancestor-parity-browser.mjs, which compares the source
 * runtime and the native dialog in Chromium.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$assert(CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === hash('sha256', $runtime), 'the fixture is the published disclosure runtime');

// Rows as the producer records them: a re-ordered theme class on the header,
// an empty closed class on body, and a class list recorded as tokens only.
$ancestors = array(
    array('selector' => '#site-header', 'tag' => 'header', 'depth' => 2, 'closed' => array('class' => 'dark-bold header theme-col--primary'), 'opened' => array('class' => 'header theme-col--primary light')),
    array('selector' => '#scope', 'tag' => 'div', 'depth' => 3, 'closed' => array('class' => 'config-0 config-1 config-2 menu-closed'), 'opened' => array('class' => 'config-0 config-1 config-2 menu-open'), 'classTokensOnly' => true),
    array('selector' => 'body', 'tag' => 'body', 'depth' => 4, 'closed' => array('class' => ''), 'opened' => array('class' => 'header--menu-open')),
);
$key = 'site-menu-panel';
$trigger = '<button type="button" aria-label="Open menu" data-blocks-engine-capture-disclosure-label="Open menu" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="menu" data-blocks-engine-capture-dialog-ancestor-state="' . htmlspecialchars(json_encode($ancestors, JSON_THROW_ON_ERROR), ENT_QUOTES) . '"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M2 4h16M2 10h16M2 16h16" stroke="currentColor"></path></svg></button>';
$panel = '<div id="' . $key . '" class="menu-panel data-blocks-engine-capture-dropdown" hidden data-blocks-engine-capture-dialog-panel="' . $key . '"><div><p>Visit</p><a href="#one">One</a><hr><button type="button">Ordinary action</button></div></div>';
$css = 'body{margin:0;font-family:Arial}header{display:block;padding:8px}.dark-bold{background:rgb(20,20,20);color:white}.light{background:rgb(250,250,250);color:black}.menu-panel{padding:8px}';
$scope = '<div id="scope" class="data-blocks-engine-capture-desktop-document config-0 config-1 config-2 config-3 menu-closed" data-blocks-engine-capture-document-scope="">'
    . '<header id="site-header" class="dark-bold header theme-col--primary"><div class="menu-host">' . $trigger . $panel . '</div></header>'
    . '<main><h2 id="one">One</h2><h2 id="two">Two</h2></main></div>';
$page = static fn (string $body): string => '<!doctype html><html><head><style>' . $css . '</style><script data-blocks-engine-capture-disclosure-runtime="true">' . $body . '</script></head><body>' . $scope . '</body></html>';
$files = static function (string $html): array {
    $state = array(
        'status' => 'captured',
        'trigger' => array('selector' => 'missing', 'label' => 'Open menu', 'tag' => 'button', 'ariaHaspopup' => 'menu'),
        'dialog' => array('html' => '<div>Report snapshot</div>', 'htmlBytes' => strlen('<div>Report snapshot</div>'), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
    );
    return array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA, 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))))),
    );
};
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files($html)))->toArray();

$source = $page($runtime);
$native = $compile($source);
$markup = (string) ($native['serialized_blocks'] ?? '');
$proofs = array_values(array_filter($native['source_reports']['native_runtime_replacements'] ?? array(), static fn (array $proof): bool => CaptureEvidence::DISCLOSURE_RUNTIME === ($proof['attribute'] ?? null)));
$assert(1 === count($proofs) && CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === $proofs[0]['body_hash'], 'the published disclosure runtime is retired with a native replacement proof', json_encode($native['source_reports']['native_runtime_replacements'] ?? null));
$assert(!str_contains(json_encode($native['assets']), 'function triggers()'), 'the retired runtime is not shipped as an asset');
$assert(1 === substr_count($markup, '<!-- wp:custom/captured-dialog '), 'the scoped dropdown becomes one native dialog block', $markup);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<body>' . $markup . '</body>');
$dialog = (new DOMXPath($dom))->query('//*[@data-blocks-engine-triggers]')->item(0);
$expected = array_map(static fn (array $row): array => array('tag' => $row['tag'], 'closed' => $row['closed'], 'opened' => $row['opened']), $ancestors);
$assert($dialog instanceof DOMElement && $expected === json_decode($dialog->getAttribute('data-blocks-engine-ancestor-state'), true), 'every recorded ancestor class binding travels to the native dialog', $markup);
$assert(0 === ($native['metrics']['fallback_count'] ?? -1), 'the page lowers without fallback blocks');

// One changed byte makes the body unknown: the capture keeps its runtime and markup.
$unknown = $compile($page(str_replace('function triggers(){', 'function triggers( ){', $runtime)));
$assert(array() === array_filter($unknown['source_reports']['native_runtime_replacements'] ?? array(), static fn (array $proof): bool => CaptureEvidence::DISCLOSURE_RUNTIME === ($proof['attribute'] ?? null)), 'an unknown disclosure body is not retired');
$assert(str_contains(json_encode($unknown['assets']) . (string) ($unknown['serialized_blocks'] ?? ''), 'function triggers( )'), 'an unknown disclosure body keeps working as the captured runtime');
$assert(!str_contains((string) ($unknown['serialized_blocks'] ?? ''), '"ancestorState"'), 'an unknown disclosure body gets no native dialog replacement', (string) ($unknown['serialized_blocks'] ?? ''));

$definition = (new CapturedDialogBlockGenerator())->definition('custom/captured-dialog');
file_put_contents(sys_get_temp_dir() . '/capture-runtime-ancestor-parity.json', json_encode(array(
    'source' => $source,
    'native' => $markup,
    'css' => $css . (string) ($definition['assets']['style.css'] ?? ''),
    'script' => (string) ($definition['view_js'] ?? ''),
    'ancestors' => $ancestors,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if (0 !== $failures) exit(1);
echo "capture-runtime-ancestor-parity: ok\n";
