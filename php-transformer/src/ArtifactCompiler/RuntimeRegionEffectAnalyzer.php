<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use RuntimeException;

/** Runs the built, versioned TypeScript ownership analyzer over local scripts. */
final class RuntimeRegionEffectAnalyzer
{
    public const BUNDLE = 'blocks-engine/runtime-region-effect-analyzer/v1';
    public const SCHEMA = 'blocks-engine/runtime-region-effects/v1';

    /** @var string|null */
    private ?string $bundlePath;

    public function __construct(?string $bundlePath = null)
    {
        $this->bundlePath = $bundlePath;
    }

    /** @param array<int,array<string,mixed>> $files @return array<string,mixed> */
    public function analyzeFiles(array $files): array
    {
        $sources = array();
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['content'] ?? null) || (!in_array($file['kind'] ?? null, array('js', 'javascript'), true) && 'inline-script' !== ($file['source'] ?? null))) continue;
            $source = $file['content'];
            $sources[hash('sha256', $source)] = array('source_path' => (string) ($file['path'] ?? ''), 'source_hash' => hash('sha256', $source), 'source' => $source);
        }
        if (array() === $sources) return array('schema' => self::SCHEMA, 'analyzer' => self::BUNDLE, 'manifests' => array());

        ksort($sources, SORT_STRING);
        $this->assertBundle();
        $manifests = array();
        foreach ($sources as $source) {
            $response = $this->run(array('source' => $source['source']));
            if (self::BUNDLE !== ($response['bundle'] ?? null)) throw new RuntimeException('Region effect analyzer response has an unsupported bundle version.');
            $manifest = $response['manifest'] ?? null;
            if (!is_array($manifest)) throw new RuntimeException('Region effect analyzer returned no manifest.');
            $this->assertManifest($manifest, $source['source']);
            $manifests[] = array('source_path' => $source['source_path'], 'source_hash' => $source['source_hash'], 'manifest' => $manifest);
        }
        return array('schema' => self::SCHEMA, 'analyzer' => self::BUNDLE, 'manifests' => $manifests);
    }

    private function assertBundle(): void
    {
        $version = $this->run(null, '--version');
        if (self::BUNDLE !== ($version['bundle'] ?? null) || self::SCHEMA !== ($version['schema'] ?? null)) throw new RuntimeException('Region effect analyzer bundle or schema version is unsupported.');
    }

    /** @return array<string,mixed> */
    private function run(?array $request, string $argument = ''): array
    {
        $bundle = $this->bundlePath ?? dirname(__DIR__, 3) . '/packages/blocks-engine/dist/runtime/region-effect-analyzer.js';
        if (!is_file($bundle) || !is_readable($bundle)) throw new RuntimeException('Built region effect analyzer bundle is unavailable.');
        $command = array('node', $bundle);
        if ('' !== $argument) $command[] = $argument;
        $pipes = array();
        $process = @proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Node is unavailable for region effect analysis.');
        if (null !== $request) fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (0 !== proc_close($process)) throw new RuntimeException('Region effect analyzer failed: ' . trim($stderr));
        try { $value = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException $exception) { throw new RuntimeException('Region effect analyzer returned invalid JSON.', 0, $exception); }
        if (!is_array($value)) throw new RuntimeException('Region effect analyzer returned an invalid response.');
        return $value;
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest, string $source): void
    {
        if (self::SCHEMA !== ($manifest['schema'] ?? null) || hash('sha256', $source) !== ($manifest['sourceHash'] ?? null) || !is_array($manifest['units'] ?? null)) throw new RuntimeException('Region effect analyzer returned an invalid manifest header.');
        $ids = array();
        foreach ($manifest['units'] as $unit) {
            $region = is_array($unit) ? ($unit['source'] ?? null) : null;
            if (!is_array($unit) || !is_string($unit['id'] ?? null) || isset($ids[$unit['id']]) || !is_array($region) || !is_int($region['start'] ?? null) || !is_int($region['end'] ?? null) || $region['start'] < 0 || $region['end'] <= $region['start'] || strlen($source) < $region['end'] || !is_string($region['hash'] ?? null) || hash('sha256', substr($source, $region['start'], $region['end'] - $region['start'])) !== $region['hash'] || !in_array($unit['status'] ?? null, array('independently_suppressible', 'shared_or_unsplittable'), true)) throw new RuntimeException('Region effect analyzer returned an invalid ownership unit.');
            $ids[$unit['id']] = true;
        }
    }
}
