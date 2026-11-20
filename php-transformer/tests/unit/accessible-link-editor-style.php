<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AccessibleLinkBlockGenerator;

$script = tempnam(sys_get_temp_dir(), 'accessible-link-editor-');
if ( false === $script ) {
    throw new RuntimeException('Could not create the emitted script fixture.');
}
try {
    $assets = ( new AccessibleLinkBlockGenerator() )->assets('neutral/accessible-link');
    file_put_contents($script, $assets['index.js']);
    passthru('node ' . escapeshellarg(__DIR__ . '/accessible-link-editor-style.cjs') . ' ' . escapeshellarg($script) . ' 2>&1', $status);
} finally {
    unlink($script);
}
exit($status);
