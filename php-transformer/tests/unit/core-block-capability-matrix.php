<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;

$matrix = new CoreBlockCapabilityMatrix();
$matrix->assertCoversSnapshot();
$coverage = $matrix->coverage(array('core/paragraph', 'core/tabs'));
if (115 !== $coverage['snapshot_block_count'] || array() !== $coverage['unclassified_runtime_blocks']) throw new RuntimeException('Capability matrix must classify every block in the bundled metadata snapshot.');
if ('html_inferable' !== ($coverage['blocks']['core/paragraph']['applicability'] ?? null) || 'implemented' !== ($coverage['blocks']['core/paragraph']['implementation'] ?? null) || 'contract_tested' !== ($coverage['blocks']['core/paragraph']['verification'] ?? null)) throw new RuntimeException('Capability matrix must separate applicability, implementation, and verification.');
if ('version_gated' !== ($coverage['blocks']['core/tabs']['applicability'] ?? null) || '7.1' !== ($coverage['blocks']['core/tabs']['minimum_runtime'] ?? null)) throw new RuntimeException('Tabs must retain their explicit WordPress runtime gate.');
if (array('core/paragraph', 'core/tabs') !== $coverage['runtime_available_blocks']) throw new RuntimeException('Runtime availability must be reported independently of capability classification.');

$matrix->assertClassifiesAvailableBlocks(array('core/paragraph', 'core/tabs'));
try {
    $matrix->assertClassifiesAvailableBlocks(array('core/paragraph', 'core/future-block'));
    throw new RuntimeException('An unclassified future core block must fail the CI/contract classification boundary.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'core/future-block')) throw $error;
}

$futureCoverage = $matrix->coverage(array('core/future-block'));
if (array('core/future-block') !== $futureCoverage['unclassified_runtime_blocks']) throw new RuntimeException('Production coverage must retain unclassified runtime evidence without enforcing the CI-only contract.');

fwrite(STDOUT, "core block capability matrix contract passed\n");
