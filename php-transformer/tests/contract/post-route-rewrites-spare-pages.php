<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$plan = (new ArtifactCompiler())->compile(require __DIR__ . '/fixtures/post-route-rewrites-spare-pages.php')->toArray()['source_reports']['wordpress_site_plan'];
$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
}
// Read literal arguments back from generated PHP, including PHP string escaping.
$rules = array();
preg_match_all("/add_rewrite_rule\\( ('(?:\\\\.|[^'\\\\])*'), ('(?:\\\\.|[^'\\\\])*'), 'top' \\);/", $bootstrap, $calls, PREG_SET_ORDER);
foreach ($calls as $call) {
    $pattern = eval('return ' . $call[1] . ';');
    $query = eval('return ' . $call[2] . ';');
    $assert(!str_contains($query, 'name='), 'Prefixed posts must never resolve by a source slug.');
    if ('index.php?blocks_engine_post_route=$matches[1]' === $query) $rules[$pattern] = $query;
}
$assert(3 === count($rules), 'Each post prefix gets one exact-slug rewrite.');
$matchesFor = static function (string $path) use ($rules): array {
    $found = array();
    foreach ($rules as $pattern => $query) {
        if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $path, $matches)) $found[] = $matches[1];
    }
    return $found;
};
foreach ($plan['pages'] as $page) {
    $path = trim($page['route']['path'], '/');
    foreach (array($path, $path . '/') as $request) {
        if ('post' === $page['post_type']) {
            $assert(array($path) === $matchesFor($request), 'Listed post must capture its exact route: ' . $request);
        } else {
            $assert(array() === $matchesFor($request), 'Page must remain outside post rewrites: ' . $request);
        }
    }
}
foreach (array('north/unlisted', 'south/unlisted', 'north/first-note/child', 'edition-v1/releaseXv2', 'edition-v1/release-v2-extra') as $path) {
    $assert(array() === $matchesFor($path), 'Unlisted routes and regex near misses must not match: ' . $path);
}
// Execute the emitted route runtime, not a parallel implementation of it.
$filters = $actions = $storedPosts = array();
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void {
    $GLOBALS['filters'][$hook][] = $callback;
}
function add_action(string $hook, callable $callback): void {
    $GLOBALS['actions'][$hook][] = $callback;
}
function get_posts(array $args): array {
    if ('post' !== $args['post_type'] || 'publish' !== $args['post_status'] || 'ids' !== $args['fields'] || '_blocks_engine_reconciliation_identity' !== $args['meta_key']) {
        throw new RuntimeException('Route lookup must use the published post reconciliation identity.');
    }
    return array_column(array_filter($GLOBALS['storedPosts'], static fn(array $post): bool => $post['identity'] === $args['meta_value']), 'ID');
}
$start = strpos($bootstrap, '$blocks_engine_post_routes =');
$end = strpos($bootstrap, "add_filter( 'post_link',", $start ?: 0);
$assert(false !== $start && false !== $end, 'Generated route runtime is present.');
eval(substr($bootstrap, $start, $end - $start));
$vars = $filters['query_vars'][0](array('p'));
$assert(in_array('blocks_engine_post_route', $vars, true), 'The route query var is public.');
$resolve = $filters['request'][0];
$sharedIds = array();
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    $path = trim($page['route']['path'], '/');
    $id = count($storedPosts) + 51;
    $storedPosts[] = array('ID' => $id, 'identity' => $page['reconciliation_identity'], 'post_name' => isset($sharedIds[basename($path)]) ? basename($path) . '-2' : basename($path));
    $sharedIds[basename($path)][] = $id;
    $assert(array('p' => $id) === $resolve(array('blocks_engine_post_route' => $matchesFor($path)[0], 'name' => 'wrong-post', 'pagename' => 'wrong-page', 'p' => 999)), 'Each captured route resolves exclusively to its identity owner: ' . $path);
}
$assert(2 === count($sharedIds['first-note']) && $sharedIds['first-note'][0] !== $sharedIds['first-note'][1], 'Fixture includes two distinct owners sharing a source slug.');
$assert(array('error' => '404') === $resolve(array('blocks_engine_post_route' => 'north/unknown')), 'Unknown routes return a 404, never a slug fallback.');
$storedPosts = array();
$assert(array('error' => '404') === $resolve(array('blocks_engine_post_route' => 'north/first-note')), 'A shipped route without an imported identity owner returns a 404.');
$assert(array('pagename' => 'north/contact') === $resolve(array('pagename' => 'north/contact')), 'Core page requests are unchanged.');
fwrite(STDOUT, "post-route-rewrites-spare-pages: passed\n");
