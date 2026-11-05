<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

function apply_filters(string $hook, mixed $value): mixed { return $value; }
function is_singular(): bool { return $GLOBALS['captured_request']; }
function get_queried_object_id(): int { return 1; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['captured_identity']; }
function is_front_page(): bool { return false; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$document = static fn(int $id): string => '<!doctype html><html class="shared-root root-' . $id . '" data-site="same" data-route="' . $id . '"><head><title>Page ' . $id . '</title></head><body class="breakdance page-id-' . $id . '" data-site="same" data-route="' . $id . '"' . (1 === $id ? ' data-only="first"' : '') . '><main><h1>Page ' . $id . '</h1></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document(1), 'about.html' => $document(2))))->toArray()['source_reports']['wordpress_site_plan'];
$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];

// Execute the registry and resolver from the emitted functions.php, without
// unrelated WordPress hooks or a database. No intersection runs in this code.
$resolve = static function (string $bootstrap): Closure {
    unset($GLOBALS['blocks_engine_document_roots'], $GLOBALS['blocks_engine_document_attributes'], $GLOBALS['blocks_engine_document_fallback']);
    $start = strpos($bootstrap, 'global $blocks_engine_document_roots');
    $end = strpos($bootstrap, "add_filter( 'language_attributes'", $start);
    if (false === $start || false === $end) throw new RuntimeException('Missing emitted document resolver.');
    eval(substr($bootstrap, $start, $end - $start) . "\n}");
    return $GLOBALS['blocks_engine_document_attributes'];
};
$attributes = $resolve($bootstrap);
$GLOBALS['captured_request'] = false;
foreach (array('404', 'search', 'archive') as $request) {
    $assert(array('data-site' => 'same', 'class' => 'breakdance') === $attributes('body'), $request . ' retains only shared body attributes.');
    $html = $attributes('html');
    $assert('same' === ($html['data-site'] ?? null) && !isset($html['data-route']), $request . ' retains only identical non-class html attributes.');
    $assert(in_array('shared-root', explode(' ', $html['class'] ?? ''), true) && !preg_match('/root-[12]|be-r-/', $html['class'] ?? ''), $request . ' excludes per-document html classes.');
}
$GLOBALS['captured_request'] = true;
$GLOBALS['captured_identity'] = $plan['pages'][0]['reconciliation_identity'];
$assert(str_contains($attributes('body')['class'], 'page-id-1'), 'Captured routes still resolve their full attributes.');
$GLOBALS['captured_request'] = false;
$pages = $plan['pages'];
$pages[1]['document_metadata']['body_attributes'] = array();
$attributes = $resolve(DocumentRootContext::bootstrap($pages));
$assert(array() === $attributes('body'), 'A root with no attributes prevents inheritance from another root.');
$pages[1]['synthetic'] = true;
$attributes = $resolve(DocumentRootContext::bootstrap($pages));
$assert(str_contains($attributes('body')['class'] ?? '', 'breakdance'), 'Synthetic pages do not participate in captured-root unanimity.');
fwrite(STDOUT, "Document root fallback contracts passed\n");
