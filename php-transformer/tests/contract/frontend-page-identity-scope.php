<?php
declare(strict_types=1);

/**
 * #2623: generated frontend asset activation must resolve page scopes by the
 * persisted reconciliation identity before falling back to the canonical
 * route comparison, exactly like the editor presentation matcher (#1878/#1881)
 * and the document head and document root runtimes. WordPress reserves numeric
 * hierarchical slugs, so a planned route like /guides/2048 materializes under
 * a different stored slug (2048-2) and get_page_uri() no longer returns the
 * planned route path; the persisted _blocks_engine_reconciliation_identity
 * post meta is the only durable link between the plan and the materialized
 * page. Route-owned stylesheets and scripts must still activate for that
 * page, an identity present in post meta stays authoritative over a
 * coincidentally matching route, and plans materialized before identity meta
 * existed must keep matching by route.
 *
 * Boundary: the generated wp_enqueue_scripts closure and script scope
 * conditions extracted from functions.php are evaluated here against stubbed
 * WordPress query functions reproducing the real query shape -- a materialized
 * page whose get_page_uri() differs from the planned route. Exercising the
 * same generated theme under a live WordPress installation is the WordPress
 * integration suite's job; this contract proves the emitted activation
 * conditions themselves.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$document = static fn(string $head, string $body): string => '<!doctype html><html><head>' . $head . '</head><body><main>' . $body . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('<link rel="stylesheet" href="assets/global.css">', 'Home'),
        'guides/2048.html' => $document('<link rel="stylesheet" href="../assets/route.css"><script src="../assets/route.js"></script>', 'Guide'),
        'assets/global.css' => '.global{color:#111}',
        'assets/route.css' => '.route{display:grid}',
        'assets/route.js' => 'window.route = true;',
    ),
))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);

$routePage = current(array_filter($plan['pages'], static fn(array $page): bool => 'guides/2048.html' === ($page['source_path'] ?? null)));
$routeIdentity = (string) ($routePage['reconciliation_identity'] ?? '');
$frontIdentity = (string) (current(array_filter($plan['pages'], static fn(array $page): bool => 'index.html' === ($page['source_path'] ?? null)))['reconciliation_identity'] ?? '');
$assert('' !== $routeIdentity && '' !== $frontIdentity && 'guides/2048' === trim((string) ($routePage['route']['path'] ?? ''), '/'), 'The plan declares the hierarchical numeric route and durable reconciliation identities.');

$assetByTarget = array();
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? null)) foreach ($asset['scopes'] ?? array() as $scope) $assetByTarget[(string) $asset['target_path']] = $scope;
$routeStylePath = (string) current(array_filter(array_keys($assetByTarget), static fn(string $target): bool => str_ends_with($target, 'route.css')));
$frontStylePath = (string) current(array_filter(array_keys($assetByTarget), static fn(string $target): bool => str_ends_with($target, 'global.css')));
$globalStylePath = (string) current(array_filter(array_keys($assetByTarget), static fn(string $target): bool => 'global' === ($assetByTarget[$target]['kind'] ?? null) && !in_array($target, array($routeStylePath, $frontStylePath), true)));
$routeScope = $assetByTarget[$routeStylePath] ?? null;
$assert(is_array($routeScope) && array('kind' => 'page', 'source_path' => 'guides/2048.html', 'route_path' => 'guides/2048', 'reconciliation_identity' => $routeIdentity, 'front_page' => false) === $routeScope, 'The route stylesheet carries the page scope contract: route_path and reconciliation_identity together.', json_encode($routeScope));

$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) {
        $bootstrap = (string) ($write['payload']['data'] ?? '');
        break;
    }
}
$assert('' !== $bootstrap, 'The generated theme scaffold carries a functions.php bootstrap.');

$frontendScopeQuery = array('front_page' => false, 'page' => false, 'queried_id' => 0);
$frontendScopeMetaByPostId = array();
$frontendScopeUriByPostId = array();
$frontendScopeEnqueuedStyles = array();
$frontendScopeEnqueuedScripts = array();
$frontendScopeActions = array();

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        global $frontendScopeActions;
        $frontendScopeActions[$hook][(int) $priority][] = $callback;
    }
}
if (!function_exists('is_front_page')) {
    function is_front_page(): bool
    {
        global $frontendScopeQuery;
        return (bool) $frontendScopeQuery['front_page'];
    }
}
if (!function_exists('is_page')) {
    function is_page(): bool
    {
        global $frontendScopeQuery;
        return (bool) $frontendScopeQuery['page'];
    }
}
if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id(): int
    {
        global $frontendScopeQuery;
        return (int) $frontendScopeQuery['queried_id'];
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false): string
    {
        global $frontendScopeMetaByPostId;
        return (string) ($frontendScopeMetaByPostId[$postId][$key] ?? '');
    }
}
if (!function_exists('get_page_uri')) {
    function get_page_uri(int|string $page = 0): string
    {
        global $frontendScopeUriByPostId;
        return (string) ($frontendScopeUriByPostId[(int) $page] ?? '');
    }
}
if (!function_exists('get_theme_file_uri')) {
    function get_theme_file_uri(string $path = ''): string
    {
        return 'https://theme.test/' . $path;
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, array|string|null $media = 'all'): void
    {
        global $frontendScopeEnqueuedStyles;
        $frontendScopeEnqueuedStyles[] = array('handle' => $handle, 'src' => $src);
    }
}
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, $src = '', array $deps = array(), $ver = false, array $args = array()): void
    {
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, $src = '', array $deps = array(), $ver = false, array $args = array()): void
    {
        global $frontendScopeEnqueuedScripts;
        $frontendScopeEnqueuedScripts[] = $handle;
    }
}

$closureStart = strpos($bootstrap, "add_action( 'wp_enqueue_scripts', static function (): void {");
$closureEnd = false !== $closureStart ? strpos($bootstrap, "\n}, 1 );", $closureStart) : false;
$assert(false !== $closureStart && false !== $closureEnd, 'The generated wp_enqueue_scripts closure is discoverable.');
if (false === $closureStart || false === $closureEnd) {
    exit(1);
}
$enqueueClosure = substr($bootstrap, $closureStart, $closureEnd + strlen("\n}, 1 );") - $closureStart);
$assert(str_contains($enqueueClosure, '_blocks_engine_reconciliation_identity') && !str_contains($enqueueClosure, "is_page() && 'guides/2048' === trim("), 'The generated stylesheet conditions resolve page scopes by persisted reconciliation identity first, with the route comparison only as fallback.');

preg_match_all("/^add_action\( 'wp_enqueue_scripts', static function \(\): void \{ if \( (.+) \) wp_enqueue_script\( '([^']+)' \); \}, (\d+) \);$/m", $bootstrap, $scriptConditions, PREG_SET_ORDER);
$assert(array() !== $scriptConditions, 'The generated script scope conditions are discoverable.');
foreach ($scriptConditions as $scriptCondition) eval($scriptCondition[0]);
eval($enqueueClosure);

$routeScriptHandle = '';
if (preg_match("/wp_register_script\( '([^']+)', get_theme_file_uri\( '([^']*route\.js)' \)/", $bootstrap, $routeScriptMatch)) $routeScriptHandle = (string) $routeScriptMatch[1];
$routeScriptCondition = current(array_filter($scriptConditions, static fn(array $match): bool => $match[2] === $routeScriptHandle)) ?: array();
$assert('' !== $globalStylePath && '' !== $routeStylePath && '' !== $frontStylePath, 'The plan carries the global, route-owned, and entry-page stylesheet targets.');
$assert('' !== $routeScriptHandle && isset($routeScriptCondition[1]) && str_contains($routeScriptCondition[1], '_blocks_engine_reconciliation_identity') && str_contains($routeScriptCondition[1], "'guides/2048' === trim( get_page_uri( get_queried_object_id() ), '/' )"), 'The route-owned script shares the identity-first page scope primitive with the stylesheets.', $routeScriptCondition[1] ?? '');

$serveFrontendRequest = static function (array $query, array $meta, array $uris): array {
    global $frontendScopeQuery, $frontendScopeMetaByPostId, $frontendScopeUriByPostId, $frontendScopeEnqueuedStyles, $frontendScopeEnqueuedScripts, $frontendScopeActions;
    $frontendScopeQuery = $query;
    $frontendScopeMetaByPostId = $meta;
    $frontendScopeUriByPostId = $uris;
    $frontendScopeEnqueuedStyles = array();
    $frontendScopeEnqueuedScripts = array();
    foreach ($frontendScopeActions['wp_enqueue_scripts'] as $callbacks) foreach ($callbacks as $callback) $callback();
    $styleTargets = array_map(static fn(array $style): string => substr((string) $style['src'], strlen('https://theme.test/')), $frontendScopeEnqueuedStyles);
    return array('styles' => $styleTargets, 'scripts' => $frontendScopeEnqueuedScripts);
};
$routeOnly = static fn(array $served): bool => array($globalStylePath, $routeStylePath) === $served['styles'] && array($routeScriptHandle) === $served['scripts'];

// Proven source case: WordPress reserved the numeric hierarchical slug, so the
// materialized page is stored as 2048-2 and get_page_uri() no longer returns
// the planned route. The persisted reconciliation identity is the durable link.
$numericPageId = 20481;
$served = $serveFrontendRequest(array('front_page' => false, 'page' => true, 'queried_id' => $numericPageId), array($numericPageId => array('_blocks_engine_reconciliation_identity' => $routeIdentity)), array($numericPageId => 'guides/2048-2'));
$assert($routeOnly($served), 'A materialized page whose stored URI was normalized to 2048-2 still activates its route-owned stylesheet and script through the persisted reconciliation identity.', json_encode($served));

// The same durability covers any rename or reparenting once identity meta exists.
$served = $serveFrontendRequest(array('front_page' => false, 'page' => true, 'queried_id' => $numericPageId), array($numericPageId => array('_blocks_engine_reconciliation_identity' => $routeIdentity)), array($numericPageId => 'renamed/elsewhere'));
$assert($routeOnly($served), 'A renamed or reparented page with identity meta still activates its route-owned assets.', json_encode($served));

// Identity is authoritative: a coincidentally matching route must not activate
// a different page's assets.
$served = $serveFrontendRequest(array('front_page' => false, 'page' => true, 'queried_id' => $numericPageId), array($numericPageId => array('_blocks_engine_reconciliation_identity' => str_repeat('9', 64))), array($numericPageId => 'guides/2048'));
$assert(array($globalStylePath) === $served['styles'] && array() === $served['scripts'], 'Once identity meta exists it is authoritative over a coincidentally matching route path.', json_encode($served));

// Legacy plans materialized before identity meta keep matching by route.
$served = $serveFrontendRequest(array('front_page' => false, 'page' => true, 'queried_id' => $numericPageId), array(), array($numericPageId => 'guides/2048'));
$assert($routeOnly($served), 'When identity meta is absent the generated conditions fall back to the canonical route comparison.', json_encode($served));
$served = $serveFrontendRequest(array('front_page' => false, 'page' => true, 'queried_id' => $numericPageId), array(), array($numericPageId => 'guides/nova'));
$assert(array($globalStylePath) === $served['styles'] && array() === $served['scripts'], 'The route fallback stays exact: an unrelated URI matches nothing.', json_encode($served));

// Serving the front page keeps activating the entry-page assets and nothing else.
$frontPageId = 7;
$served = $serveFrontendRequest(array('front_page' => true, 'page' => true, 'queried_id' => $frontPageId), array($frontPageId => array('_blocks_engine_reconciliation_identity' => $frontIdentity)), array($frontPageId => ''));
$assert(array($globalStylePath, $frontStylePath) === $served['styles'] && array() === $served['scripts'], 'The front-page scope keeps activating entry-page assets without pulling route-owned ones.', json_encode($served));

if ($failures > 0) {
    fwrite(STDERR, "Frontend page identity scope: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Frontend page identity scope passed: {$passes} assertions\n");
