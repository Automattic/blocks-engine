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
    if ('index.php?name=$matches[1]' === $query) $rules[$pattern] = $query;
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
            $assert(array(basename($path)) === $matchesFor($request), 'Listed post must resolve with its exact slug: ' . $request);
        } else {
            $assert(array() === $matchesFor($request), 'Page must remain outside post rewrites: ' . $request);
        }
    }
}
foreach (array('north/unlisted', 'south/first-note', 'north/first-note/child', 'edition-v1/releaseXv2', 'edition-v1/release-v2-extra') as $path) {
    $assert(array() === $matchesFor($path), 'Unlisted routes and regex near misses must not match: ' . $path);
}
fwrite(STDOUT, "post-route-rewrites-spare-pages: passed\n");
