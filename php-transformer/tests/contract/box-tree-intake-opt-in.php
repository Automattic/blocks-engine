<?php
declare(strict_types=1);

// #7352: box-tree evidence changes nothing unless intake is opted in, so a
// capture with sidecars converts exactly like the same capture without them.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$html = (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json');
$compile = static function (bool $sidecar, bool $intake) use ($html, $tree): array {
    putenv(BoxTree::INTAKE_ENV . ($intake ? '=1' : ''));
    $files = array('index.html' => $html) + ($sidecar ? array('box-tree/index.json' => $tree) : array());
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
};
$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };

$without = $compile(false, false);
$withheld = $compile(true, false);
$applied = $compile(true, true);
$assert($withheld['serialized_blocks'] === $without['serialized_blocks'], 'Default import with box-tree evidence equals the import without it.');
$codes = array_column($withheld['diagnostics'] ?? array(), 'code');
$assert(in_array('box_tree_withheld', $codes, true), 'Withheld evidence is reported.');
$assert($applied['serialized_blocks'] !== $without['serialized_blocks'], 'Opting in still applies box-tree decisions.');
$assert(!in_array('box_tree_withheld', array_column($applied['diagnostics'] ?? array(), 'code'), true), 'Opted-in import does not report withheld evidence.');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "box-tree intake opt-in: ok\n";
