<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeRegionEffectAnalyzer;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$files = array(array('path' => 'assets/app.js', 'kind' => 'js', 'content' => 'document.querySelector(".app").classList.add("ready");'));

try { (new RuntimeRegionEffectAnalyzer('/missing/analyzer.js'))->analyzeFiles($files); $assert(false, 'Missing bundles must fail closed.'); } catch (RuntimeException) { $assert(true, 'Missing bundles fail closed.'); }

$path = tempnam(sys_get_temp_dir(), 'blocks-engine-invalid-analyzer-');
file_put_contents($path, <<<'JS'
const invalid = { bundle: 'blocks-engine/runtime-region-effect-analyzer/v1', manifest: { schema: 'blocks-engine/runtime-region-effects/v1', sourceHash: '0'.repeat(64), units: [] } };
process.stdout.write(JSON.stringify(process.argv.includes('--version') ? { bundle: invalid.bundle, schema: invalid.manifest.schema } : invalid));
JS
);
try { (new RuntimeRegionEffectAnalyzer($path))->analyzeFiles($files); $assert(false, 'Invalid analyzer hashes must fail closed.'); } catch (RuntimeException) { $assert(true, 'Invalid analyzer hashes fail closed.'); } finally { unlink($path); }

fwrite(STDOUT, "Runtime region effect analyzer unit tests passed\n");
