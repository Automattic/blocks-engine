<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$source = dirname(__DIR__, 2) . '/resources';
$fixture = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'blocks-engine-core-block-snapshot-' . bin2hex(random_bytes(6));
mkdir($fixture . '/resources', 0777, true);
foreach ( glob($source . '/wordpress-latest-core-block-*.json') as $path ) {
    copy($path, $fixture . '/resources/' . basename($path));
}

try {
    $runtime = new Runtime($fixture . '/resources/');
    (new CoreBlockCapabilityMatrix($runtime))->assertCoversSnapshot();

    unlink($fixture . '/resources/wordpress-latest-core-block-supports.json');
    assertFails(static fn (): array => (new Runtime($fixture . '/resources/'))->bundledCoreBlockNames(), 'wordpress-latest-core-block-supports.json', 'A missing packaged snapshot must fail closed.');

    file_put_contents($fixture . '/resources/wordpress-latest-core-block-attributes.json', '{invalid');
    assertFails(static fn () => (new CoreBlockCapabilityMatrix(new Runtime($fixture . '/resources/')))->assertCoversSnapshot(), 'wordpress-latest-core-block-attributes.json', 'A malformed packaged snapshot must fail closed through the capability matrix.');
} finally {
    foreach ( glob($fixture . '/resources/*') as $path ) unlink($path);
    rmdir($fixture . '/resources');
    rmdir($fixture);
}

fwrite(STDOUT, "core block snapshot validity contract passed\n");

function assertFails(callable $operation, string $snapshot, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        if ( str_contains($error->getMessage(), $snapshot) ) return;
        throw $error;
    }

    throw new RuntimeException($message);
}
