<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = rtrim($argv[1] ?? dirname(__DIR__, 3) . '/fixtures', '/');
$files = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
        $files[] = $file->getPathname();
    }
}
sort($files, SORT_STRING);
if (array() === $files) {
    throw new InvalidArgumentException('No HTML fixtures found.');
}
$stripDuration = static function (array &$value) use (&$stripDuration): void {
    unset($value['transform_duration_ms']);
    foreach ($value as &$item) {
        if (is_array($item)) {
            $stripDuration($item);
        }
    }
};
$warningDocuments = 0;
foreach ($files as $file) {
    $html = file_get_contents($file);
    $full = (new HtmlTransformer())->transform($html, array('validation_evidence' => 'full'));
    $compact = (new HtmlTransformer())->transform($html, array('validation_evidence' => 'compact'));
    if (serialize($full->blockCompilationOutput) !== serialize($compact->blockCompilationOutput)) {
        throw new RuntimeException('Operational output differs: ' . $file);
    }
    $left = $full->toArray();
    $right = $compact->toArray();
    $warningDocuments += (int) (count(array_filter($left['diagnostics'], static fn(array $row): bool => ($row['severity'] ?? '') === 'warning')) > 0);
    foreach (array(array('source_reports', 'semantic_parity'), array('source_reports', 'conversion_report', 'semantic_parity')) as $path) {
        $a =& $left;
        $b =& $right;
        foreach ($path as $key) {
            if (!isset($a[$key])) {
                continue 2;
            }
            if (!isset($b[$key])) {
                throw new RuntimeException('Compact report missing: ' . $file);
            }
            $a =& $a[$key];
            $b =& $b[$key];
        }
        if (($b['evidence'] ?? null) !== array('detail' => 'compact', 'omitted' => array('landmarks', 'navigation_menus'))) {
            throw new RuntimeException('Incorrect omission declaration: ' . $file);
        }
        if (array_key_exists('landmarks', $b) || array_key_exists('navigation_menus', $b)) {
            throw new RuntimeException('Compact inventory retained: ' . $file);
        }
        unset($a['landmarks'], $a['navigation_menus'], $b['evidence']);
    }
    unset($a, $b);
    $stripDuration($left);
    $stripDuration($right);
    if ($left !== $right) {
        throw new RuntimeException('Unexpected full/compact difference: ' . $file);
    }
}
echo json_encode(array('fixtures' => count($files), 'warning_documents' => $warningDocuments, 'operational_outputs_equal' => true, 'equal_except_declared_detail_and_duration' => true), JSON_PRETTY_PRINT), "\n";
