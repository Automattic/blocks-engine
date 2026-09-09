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
$serializedHash = hash_init('sha256');
foreach ($files as $file) {
    $result = (new HtmlTransformer())->transform((string) file_get_contents($file), array('validation_evidence' => $evidence))->toArray();
    $path = str_replace($root . '/', '', $file);
    $serialized = serialize($stripDuration($result));
    $serializedBytes += strlen($serialized);
    hash_update($serializedHash, pack('N', strlen($path)) . $path . pack('N', strlen($serialized)) . $serialized);
}
fwrite(STDOUT, json_encode(array(
    'file_count' => count($files),
    'evidence' => $evidence,
    'serialized_bytes' => $serializedBytes,
    'serialized_sha256' => hash_final($serializedHash),
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'duration_ms' => (hrtime(true) - $started) / 1000000,
), JSON_UNESCAPED_SLASHES) . PHP_EOL);
