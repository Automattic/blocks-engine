<?php
/**
 * A custom element deep inside shared chrome converts to a generated block on
 * every route. The shared header part keeps that block, as each page copy does.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/shared-shell-generated-component.html');
$files = array('index.html' => str_replace('TITLE', 'Home', $fixture), 'about/index.html' => str_replace('TITLE', 'About', $fixture));
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['compiled_site'];
$headers = array_values(array_filter($compiled['inline_shell_artifacts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
if (1 !== count($headers)) { fwrite(STDERR, "Expected one shared header artifact.\n"); exit(1); }
if (!preg_match('/<!-- wp:[a-z0-9-]+\/block-[a-f0-9]+ [^>]*overflow-items/', (string) $headers[0]['block_markup'])) { fwrite(STDERR, "Shared header dropped its generated custom-element block.\n"); exit(1); }
echo "shared shell generated component contract passed\n";
