<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support/CaptureEvidenceEquivalence.php';
$mode = $argv[1] ?? 'diff';
$id = $argv[2] ?? '';
$root = dirname(__DIR__, 2);
foreach (CaptureEvidenceEquivalence::corpus() as $case) {
    if ($case['id'] !== $id) continue;
    $case['args'] = CaptureEvidenceEquivalence::renameValue($case['args']);
    $normalized = CaptureEvidenceEquivalence::normalized(CaptureEvidenceEquivalence::replay($case), $root);
    $flatten = static function (mixed $value, string $path = '$', array &$leaves = array()) use (&$flatten): array {
        if (is_array($value)) { foreach ($value as $key => $item) $flatten($item, $path . '[' . $key . ']', $leaves); }
        else $leaves[$path] = hash('sha256', serialize($value));
        return $leaves;
    };
    $current = $flatten($normalized['result']['source_reports']['html'] ?? array(), 'html');
    $baselinePath = __DIR__ . '/debug-capture-digest-baseline.json';
    if ('snapshot' === $mode) {
        file_put_contents($baselinePath, json_encode($current, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo 'Saved ', count($current), " baseline leaves\n";
        exit;
    }
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_unique(array_merge(array_keys($baseline), array_keys($current))) as $path) {
        if (($baseline[$path] ?? null) !== ($current[$path] ?? null)) echo $path, ' ', ($baseline[$path] ?? '<missing>'), ' -> ', ($current[$path] ?? '<missing>'), "\n";
    }
    exit;
}
