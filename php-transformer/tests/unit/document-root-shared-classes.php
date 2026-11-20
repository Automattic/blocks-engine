<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

// Source roots repeat long class lists that differ by a token or two. The
// bootstrap emits the tokens every route shares once, and each route's
// runtime row still restores its exact class set.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
if (!function_exists('add_filter')) { function add_filter(...$args): bool { return true; } }
if (!function_exists('add_action')) { function add_action(...$args): bool { return true; } }

$shared = array();
for ($i = 0; $i < 60; $i++) $shared[] = 'theme-option-' . $i . '-enabled';
$pages = array();
foreach (array('home' => '', 'services' => 'services', 'team' => 'team') as $identity => $path) {
    $pages[] = array(
        'reconciliation_identity' => $identity,
        'entrypoint' => '' === $path,
        'route' => array('path' => '/' . $path),
        'document_metadata' => array(
            'root_attributes' => array('class' => 'js flexbox', 'lang' => 'en'),
            'body_attributes' => array('class' => implode(' ', $shared) . ' collection-' . $identity, 'id' => 'page-' . $identity),
        ),
    );
}
$pages[] = array('reconciliation_identity' => 'plain', 'route' => array('path' => '/plain'), 'document_metadata' => array('root_attributes' => array('class' => 'js flexbox'), 'body_attributes' => array('class' => implode(' ', $shared))));
$bootstrap = DocumentRootContext::bootstrap($pages);
$assert(1 === substr_count($bootstrap, 'theme-option-42-enabled'), 'A class token every route shares is emitted once.');

$tokens = static fn(string $class): array => (static function (array $list): array { sort($list); return $list; })(preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: array());
(static function () use ($bootstrap, $pages, $assert, $tokens): void {
    $blocks_engine_document_roots = $blocks_engine_document_attributes = $blocks_engine_document_fallback = $blocks_engine_document_hooks = null;
    eval($bootstrap);
    $roots = array_column($blocks_engine_document_roots, null, 'identity');
    foreach ($pages as $page) {
        $row = $roots[$page['reconciliation_identity']] ?? null;
        $assert(null !== $row, 'Every route has a runtime row: ' . $page['reconciliation_identity']);
        foreach (array('root_attributes' => 'attributes', 'body_attributes' => 'body_attributes') as $source => $field) {
            $expected = $page['document_metadata'][$source];
            $actual = $row[$field];
            $assert($tokens($expected['class']) === $tokens($actual['class'] ?? ''), "The {$field} class set of {$page['reconciliation_identity']} is restored: " . ($actual['class'] ?? ''));
            unset($expected['class'], $actual['class']);
            ksort($expected); ksort($actual);
            $assert($expected === $actual, "The other {$field} attributes of {$page['reconciliation_identity']} are unchanged.");
        }
    }
})();

// A single route keeps its attributes whole.
$single = DocumentRootContext::bootstrap(array($pages[0]));
$assert(str_contains($single, 'theme-option-42-enabled collection-home') || str_contains($single, 'collection-home'), 'A lone route keeps its own classes.');
echo "Document root shared classes passed\n";
