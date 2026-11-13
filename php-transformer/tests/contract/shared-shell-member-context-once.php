<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array(
    'reset.css' => '*{box-sizing:border-box}body{margin:0}',
    'group.css' => 'body.route-group .brand{color:rgb(22,44,66)}',
);
foreach (array('index.html', 'about.html', 'team.html') as $path) {
    $member = 'index.html' !== $path;
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="reset.css"><link rel="stylesheet" href="group.css"></head><body' . ($member ? ' class="route-group"' : '') . '><header><p class="brand">' . ($member ? 'Shared brand' : 'Entry brand') . '</p></header><main><h1>' . $path . '</h1></main></body></html>';
}
$compiles = 0;
$compiler = new ArtifactCompiler(beforeDocumentTransform: static function (string $path, string $scope) use (&$compiles): void {
    if ('artifact-shared-shell' === $scope) ++$compiles;
});
$start = microtime(true);
$shared = $compiler->prepareShared(array('entrypoint' => 'index.html', 'files' => $files));
$elapsed = microtime(true) - $start;
$inline = $shared['shared_reduction']['inline_shell_compilation'];
$assert(1 === count($inline['artifacts']), 'Fixture must prepare one shared header group.');
$projections = array_column($inline['author_stylesheet_projections'], null, 'path');
$owners = $projections['group.css']['member_owners'] ?? array();
sort($owners);
$assert(array('about.html', 'team.html') === $owners, 'The ancestor-scoped stylesheet must belong only to group members.');
$assert(isset($projections['reset.css']) && !isset($projections['reset.css']['member_owners']), 'The unrelated reset must remain site-wide.');
$assert(str_contains($projections['group.css']['content'], '22,44,66'), 'The ancestor-scoped projection must retain its authored declaration.');
fprintf(STDOUT, "Shared preparation: %.3f s; shared document transforms: %d\n", $elapsed, $compiles);
$assert(1 === $compiles, 'Each shared shell group must transform its document exactly once.');
fwrite(STDOUT, "shared-shell-member-context-once contract passed\n");
