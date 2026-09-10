<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$root = $argv[1] ?? dirname(__DIR__, 3) . '/fixtures/websites';
$evidence = $argv[2] ?? 'full';
if (!in_array($evidence, array('full', 'compact'), true)) {
    throw new InvalidArgumentException('Evidence must be full or compact.');
}

$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && 'html' === strtolower($file->getExtension())) {
        $files[] = $file->getPathname();
    }
}
sort($files, SORT_STRING);
if (array() === $files) {
    throw new InvalidArgumentException('No HTML fixtures found.');
}

$stripDuration = static function (mixed $value) use (&$stripDuration): mixed {
    if (!is_array($value)) {
        return $value;
    }
    unset($value['transform_duration_ms']);
    foreach ($value as $key => $child) {
        $value[$key] = $stripDuration($child);
    }
    return $value;
};

$started = hrtime(true);
$serializedBytes = 0;
$serializedReportBytes = 0;
$jsonBytes = 0;
$jsonReportBytes = 0;
$jsonEncodableResults = 0;
$jsonEncodableReports = 0;
$serializedHash = hash_init('sha256');
foreach ($files as $file) {
    $result = (new HtmlTransformer())->transform((string) file_get_contents($file), array('validation_evidence' => $evidence))->toArray();
    $path = str_replace($root . '/', '', $file);
    $result = $stripDuration($result);
    $serialized = serialize($result);
    $serializedBytes += strlen($serialized);
    $serializedReportBytes += strlen(serialize($result['source_reports']));
    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
    $reportJson = json_encode($result['source_reports'], JSON_UNESCAPED_SLASHES);
    if (is_string($json)) { $jsonBytes += strlen($json); ++$jsonEncodableResults; }
    if (is_string($reportJson)) { $jsonReportBytes += strlen($reportJson); ++$jsonEncodableReports; }
    hash_update($serializedHash, pack('N', strlen($path)) . $path . pack('N', strlen($serialized)) . $serialized);
}
fwrite(STDOUT, json_encode(array(
    'file_count' => count($files),
    'evidence' => $evidence,
    'serialized_bytes' => $serializedBytes,
    'serialized_report_bytes' => $serializedReportBytes,
    'json_bytes' => $jsonBytes,
    'json_report_bytes' => $jsonReportBytes,
    'json_encodable_results' => $jsonEncodableResults,
    'json_encodable_reports' => $jsonEncodableReports,
    'serialized_sha256' => hash_final($serializedHash),
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'duration_ms' => (hrtime(true) - $started) / 1000000,
), JSON_UNESCAPED_SLASHES) . PHP_EOL);
