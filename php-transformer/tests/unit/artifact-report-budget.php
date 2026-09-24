<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callable, string $message, string $expected) use ($assert): void {
    try {
        $callable();
    } catch (\InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $expected), $message . ' (' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException($message);
};

// www.ghestilistes.com (run r65) declares fourteen capture reports beside its
// website tree. One of them, asset-evidence.json, is 10.17 MiB: evidence about
// the capture that no projector decodes, refused by the budget sized for the
// page source the compiler converts to blocks.
$html = '<!doctype html><html><body><main><h1>Ghestilistes</h1></main></body></html>';
$evidence = str_pad('{"schema":"data-liberation/asset-evidence/v1","assets":[', 10666142 - 2, 'x') . ']}';
$receipt = '{"schema":"data-liberation/capture-receipt/v1","websiteRoot":"website"}';
$payloads = array('index' => $html, 'asset-evidence' => $evidence, 'capture-receipt' => $receipt);
$reference = static fn(string $id): array => array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id'     => $id,
    'bytes'  => strlen($payloads[$id]),
    'sha256' => hash('sha256', $payloads[$id]),
);
$reader = new class($payloads) implements PayloadReader {
    /** @param array<string,string> $payloads */
    public function __construct(private array $payloads) {}
    public function read(array $reference): string
    {
        return $this->payloads[(string) $reference['id']] ?? '';
    }
};

$artifact = array(
    'entrypoint' => 'website/index.html',
    // The declaration the source manifest already carries for these files.
    'reports'    => array('capture-receipt.json', 'asset-evidence.json'),
    'compiler_limits' => array(
        'max_file_bytes'         => 10485760,
        'max_total_bytes'        => 335544320,
        'max_media_file_bytes'   => 104857600,
        'max_media_total_bytes'  => 1073741824,
        'max_report_file_bytes'  => 33554432,
        'max_report_total_bytes' => 67108864,
    ),
    'files' => array(
        array('path' => 'website/index.html', 'payload_reference' => $reference('index')),
        array('path' => 'capture-receipt.json', 'payload_reference' => $reference('capture-receipt')),
        array('path' => 'asset-evidence.json', 'payload_reference' => $reference('asset-evidence')),
    ),
);

$normalized = (new ArtifactNormalizer())->normalize($artifact);
$paths = array_column($normalized['files'], 'path');
$assert(in_array('asset-evidence.json', $paths, true), 'A declared capture report above the parse budget is admitted on the report budget.');
$assert(0 === $normalized['rejected_count'], 'A declared capture report is not rejected by the budget that bounds parsed source bytes.');

$shared = (new ArtifactCompiler())->prepareShared($artifact, $reader);
$page = (new ArtifactCompiler())->preparePage($artifact, $shared, 'website/index.html', $reader);
$composedReceipt = (new ArtifactCompiler())->compilePreparedPage($shared, $page, $reader);
$composed = (new ArtifactCompiler())->compose($shared, array($composedReceipt), $reader)->toArray();
$assert(in_array(($composed['status'] ?? ''), array('success', 'success_with_warnings'), true), 'A capture carrying an oversized declared report compiles end to end.');

// Page source keeps the budget it always had: the wider ceiling belongs to the
// report declaration, not to every file that happens to sit beside one.
$oversizedSource = $artifact;
$oversizedSource['files'][] = array('path' => 'website/huge.html', 'payload_reference' => array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id'     => 'huge',
    'bytes'  => ArtifactNormalizer::MAX_FILE_BYTES + 1,
    'sha256' => hash('sha256', 'huge'),
));
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($oversizedSource, $reader),
    'An oversized page source file is still refused by the per-file read budget.',
    'per-file byte limit'
);

// Growth past the report budget is still refused, under its own message.
$overReportFile = $artifact;
$overReportFile['compiler_limits']['max_report_file_bytes'] = 8 * 1024 * 1024;
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($overReportFile, $reader),
    'A declared report beyond the per-file report budget is refused.',
    'per-file report byte limit'
);

$overReportTotal = $artifact;
$overReportTotal['compiler_limits']['max_report_total_bytes'] = 8 * 1024 * 1024;
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($overReportTotal, $reader),
    'Declared reports beyond the aggregate report budget are refused.',
    'aggregate report byte limit'
);

$normalizedOverReport = (new ArtifactNormalizer())->normalize($overReportFile);
$codes = array_column($normalizedOverReport['diagnostics'], 'code');
$assert(in_array('artifact_report_file_too_large', $codes, true), 'Normalization reports an oversized declared report under its own diagnostic code.');
$assert(!in_array('artifact_file_too_large', $codes, true), 'An oversized declared report is not misreported as an oversized source file.');

// An undeclared file of the same shape stays on the parse budget: the budget
// follows the declaration, never the extension or the directory.
$undeclared = $artifact;
$undeclared['reports'] = array('capture-receipt.json');
$normalizedUndeclared = (new ArtifactNormalizer())->normalize($undeclared);
$undeclaredCodes = array_column($normalizedUndeclared['diagnostics'], 'code');
$assert(in_array('artifact_file_too_large', $undeclaredCodes, true), 'An undeclared oversized JSON file is still bounded by the parse budget.');

$assert(array('reports/a.json' => true) === ArtifactNormalizer::declaredReports(array('reports' => array('./reports/a.json', '../escape.json', 42))), 'The report declaration is read as safe relative paths only.');

echo "artifact report budget: ok\n";
