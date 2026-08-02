<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$first = array('id' => 1, 'meta' => array('labels' => array('featured', 'new')));
$second = array('id' => 2, 'meta' => array('labels' => array('archived')));
$assert(
    array($first, $second) === DeterministicRowDeduplicator::dedupe(array($first, $second, $first)),
    'duplicate associative and nested rows retain their first occurrence'
);

$third = array('id' => 3);
$assert(
    array($second, $first, $third) === DeterministicRowDeduplicator::dedupe(array($second, $first, $second, $third, $first)),
    'unique rows retain input order'
);

$integerKey = array('id' => 1);
$stringKey = array('id' => '1');
$differentKey = array('identifier' => 1);
$reorderedKeys = array('label' => 'same', 'id' => 1);
$orderedKeys = array('id' => 1, 'label' => 'same');
$assert(
    array($integerKey, $stringKey, $differentKey, $reorderedKeys, $orderedKeys) === DeterministicRowDeduplicator::dedupe(array($integerKey, $stringKey, $differentKey, $reorderedKeys, $orderedKeys)),
    'scalar values, associative keys, and key order remain part of JSON row identity'
);

$valid = array('id' => 'valid');
$assert(
    array($valid) === DeterministicRowDeduplicator::dedupe(array(array('id' => INF), $valid)),
    'rows that fail JSON encoding are excluded'
);

if ( 0 < $failures ) {
    exit(1);
}

echo "deterministic-row-deduplicator ok ({$passes} assertions)\n";
