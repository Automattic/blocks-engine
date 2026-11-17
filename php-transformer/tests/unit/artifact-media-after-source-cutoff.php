<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$media = static fn(string $name): array => array(
    'path' => 'media/' . $name . '.jpg',
    'mime_type' => 'image/jpeg',
    'payload_reference' => array(
        'schema' => 'blocks-engine/payload-reference/v1',
        'id' => $name,
        'bytes' => 100,
        'sha256' => hash('sha256', $name),
    ),
);
$artifact = array(
    'entrypoint' => 'index.html',
    'compiler_limits' => array('max_files' => 2, 'max_media_files' => 1),
    'files' => array(
        array('path' => 'index.html', 'content' => '<main><h1>Gallery</h1><img src="media/hero.jpg" alt="Hero"></main>'),
        array('path' => 'site.css', 'content' => 'h1{color:red}'),
        array('path' => 'excess.css', 'content' => 'p{color:blue}'),
        $media('hero'),
        array('path' => 'excess.html', 'content' => '<main>Excess page</main>'),
        $media('overflow'),
    ),
);

$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$serialized = json_encode($result, JSON_THROW_ON_ERROR);
$assets = array_column($result['assets'], null, 'path');
$assert('hero' === ($assets['media/hero.jpg']['payload_reference']['id'] ?? null), 'Media after the non-media cutoff is compiled as an asset with its original payload.');
$assert(str_contains($result['serialized_blocks'], 'hero.jpg'), 'The compiled page references the retained image.');
$assert(!str_contains($serialized, 'asset-667e3e6f8f8bfd37'), 'The referenced image is not replaced by the missing-media placeholder.');

$normalized = (new ArtifactNormalizer())->normalize($artifact);
$assert(array('index.html', 'site.css', 'media/hero.jpg') === array_column($normalized['files'], 'path'), 'Both budgets admit only their own files, even after the source cutoff.');
$assert(3 === $normalized['rejected_count'], 'Only the two excess source files and excess media file are rejected.');
$warnings = array_values(array_filter($normalized['diagnostics'], static fn(array $row): bool => 'file_limit_exceeded' === $row['code']));
$assert(1 === count($warnings), 'The source-limit warning is emitted once.');
$impact = $warnings[0]['context']['truncation_impact'];
$assert($impact === $normalized['truncation_impact'] && 2 === $impact['omitted_count'], 'Truncation evidence counts only source-limit omissions.');
$assert(array('excess.css', 'excess.html') === array_column($impact['omitted_path_samples'], 'path'), 'Truncation evidence does not misclassify media as source-limit omissions.');
$summary = current(array_filter($normalized['diagnostics'], static fn(array $row): bool => 'artifact_inputs_rejected' === $row['code']));
$assert(array('file_limit_exceeded' => 2, 'media_file_limit_exceeded' => 1) === $summary['context']['rejected_by_code'], 'Each rejection is attributed to its own budget.');
$assert(array('excess.css', 'excess.html', 'media/overflow.jpg') === array_column($summary['context']['samples'], 'path'), 'Rejection samples include only actually rejected files.');

echo "artifact media after source cutoff: ok\n";
