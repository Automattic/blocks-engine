<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\BoundedMemo;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$computed = array();
$memo = new BoundedMemo(2);
$length = static function (string $key) use ($memo, &$computed): int {
    return $memo->remember($key, static function () use ($key, &$computed): int {
        $computed[] = $key;
        return strlen($key);
    });
};

// Each key is computed once, and a third key makes room by dropping the oldest.
foreach ( array( 'a', 'bb', 'a', 'ccc', 'bb', 'a' ) as $key ) {
    $assert(strlen($key) === $length($key), 'the remembered value for ' . $key);
}
$assert(array( 'a', 'bb', 'ccc', 'a' ) === $computed, 'computed: ' . implode(',', $computed));

echo "bounded-memo: {$count} assertions passed\n";
