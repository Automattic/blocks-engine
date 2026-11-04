<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;

// The former userland stream, independent of the native canonicalization path.
$oldStream = static function ($context, mixed $value) use (&$oldStream): void {
    if (!is_array($value)) {
        hash_update($context, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return;
    }
    $keys = array_keys($value);
    if (!array_is_list($value)) usort($keys, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
    $list = true;
    foreach ($keys as $index => $key) if ($index !== $key) $list = false;
    hash_update($context, $list ? '[' : '{');
    foreach ($keys as $index => $key) {
        if ($index > 0) hash_update($context, ',');
        if (!$list) hash_update($context, json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':');
        $oldStream($context, $value[$key]);
    }
    hash_update($context, $list ? ']' : '}');
};

$values = array(
    null,
    true,
    array(1.0, -0.0, 1.25, 1.0e30, 1.0e-20),
    'slash/string',
    "line\u{2028}separator\u{2029}",
    array("key\u{2028}" => "value\u{2029}"),
    array('z' => array(3, 2, 1), 'a' => 'unicode-é', 4 => false),
    array(2 => 'x', 0 => 'a', 1 => 'b'),
    array(0 => 'a', 2 => 'b'),
    array(array('nested' => array('b' => 2, 'a' => array()))),
    str_repeat('x', 65535) . 'é' . str_repeat('y', 65535),
    array('large' => str_repeat('é/"', 3 * 1024 * 1024), 'small' => array('z' => 0, 'a' => 1)),
    array_fill(0, 40000, array('z' => false, 'a' => 'value')),
);

foreach ($values as $value) {
    $context = hash_init('sha256');
    $oldStream($context, $value);
    if (!hash_equals(hash_final($context), RuntimeDeclarations::hash($value))) {
        throw new RuntimeException('Canonical hash changed the existing stream digest contract.');
    }
}

echo "Runtime declarations hash equivalence passed.\n";
