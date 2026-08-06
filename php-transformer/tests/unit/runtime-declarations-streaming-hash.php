<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;

$values = array(
    null,
    true,
    1.0,
    'slash/string',
    "line\u{2028}separator\u{2029}",
    array("key\u{2028}" => "value\u{2029}"),
    array('z' => array(3, 2, 1), 'a' => 'unicode-é', 4 => false),
    array(2 => 'x', 0 => 'a', 1 => 'b'),
    array(array('nested' => array('b' => 2, 'a' => 1))),
);

foreach ($values as $value) {
    $expected = hash('sha256', RuntimeDeclarations::canonicalJson($value));
    if (!hash_equals($expected, RuntimeDeclarations::hash($value))) {
        throw new RuntimeException('Streaming canonical hash changed the existing digest contract.');
    }
}

$payload = str_repeat('x', 64 * 1024 * 1024);
$expectedContext = hash_init('sha256');
hash_update($expectedContext, '{"payload":"');
hash_update($expectedContext, $payload);
hash_update($expectedContext, '"}');
$large = array('payload' => $payload);
$memoryBefore = memory_get_usage(true);
$actual = RuntimeDeclarations::hash($large);
if (!hash_equals(hash_final($expectedContext), $actual)) {
    throw new RuntimeException('Streaming canonical hash changed the large payload digest contract.');
}
if (memory_get_peak_usage(true) - $memoryBefore > 16 * 1024 * 1024) {
    throw new RuntimeException('Streaming canonical hash allocated an unbounded payload-sized buffer.');
}

echo "Runtime declarations streaming hash passed.\n";
