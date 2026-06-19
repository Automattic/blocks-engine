<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

if ( ! function_exists('serialize_blocks') ) {
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    function serialize_blocks(array $blocks): string
    {
        $serialized = '';
        foreach ( $blocks as $block ) {
            $name = (string) ($block['blockName'] ?? '');
            $attrs = empty($block['attrs']) ? '' : ' ' . json_encode($block['attrs'], JSON_UNESCAPED_SLASHES);
            $innerContent = $block['innerContent'] ?? array();
            $innerBlocks = $block['innerBlocks'] ?? array();
            $inner = '';

            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $child = array_shift($innerBlocks);
                    $inner .= is_array($child) ? serialize_blocks(array($child)) : '';
                    continue;
                }
                $inner .= $part;
            }

            $slug = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            $serialized .= '<!-- wp:' . $slug . $attrs . ' -->' . $inner . '<!-- /wp:' . $slug . ' -->';
        }

        return $serialized;
    }
}

$fixtureDir = dirname(__DIR__) . '/fixtures/parity';
$fixtures = glob($fixtureDir . '/*.json');
if ( false === $fixtures || array() === $fixtures ) {
    fwrite(STDERR, "No parity fixtures found.\n");
    exit(1);
}

$ran = 0;
$legacySkipped = 0;
$legacyCompared = 0;

foreach ( $fixtures as $fixturePath ) {
    $fixture = loadFixture($fixturePath);
    validateFixture($fixture, $fixturePath);

    $output = runFixture($fixture);
    foreach ( $fixture['expect'] as $expectation ) {
        assertExpectation($output, $expectation, $fixture['name']);
    }

    $legacyResult = runLegacyComparison($fixture, $output);
    if ( 'compared' === $legacyResult['status'] ) {
        ++$legacyCompared;
    }
    if ( 'skipped' === $legacyResult['status'] ) {
        ++$legacySkipped;
        fwrite(STDOUT, "Skipped legacy comparison for {$fixture['name']}: {$legacyResult['reason']}\n");
    }

    ++$ran;
}

fwrite(STDOUT, "PHP transformer parity fixtures passed: {$ran} fixture(s), {$legacyCompared} legacy comparison(s), {$legacySkipped} legacy comparison(s) skipped.\n");

/**
 * @return array<string, mixed>
 */
function loadFixture(string $path): array
{
    $json = file_get_contents($path);
    if ( false === $json ) {
        fail("Unable to read fixture: {$path}");
    }

    $fixture = json_decode($json, true);
    if ( ! is_array($fixture) ) {
        fail("Invalid JSON fixture: {$path}");
    }

    return $fixture;
}

/**
 * @param array<string, mixed> $fixture
 */
function validateFixture(array $fixture, string $path): void
{
    $required = array('schema', 'name', 'description', 'operation', 'input', 'expect');
    foreach ( $required as $key ) {
        if ( ! array_key_exists($key, $fixture) ) {
            fail("Fixture {$path} is missing required key: {$key}");
        }
    }

    if ( 'blocks-engine/php-transformer/parity-fixture/v1' !== $fixture['schema'] ) {
        fail("Fixture {$path} declares unsupported schema.");
    }

    if ( ! is_array($fixture['input']) || ! is_array($fixture['expect']) || array() === $fixture['expect'] ) {
        fail("Fixture {$path} must provide input and at least one expectation.");
    }
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function runFixture(array $fixture): array
{
    $input = $fixture['input'];

    if ( 'html_transformer.transform' === $fixture['operation'] ) {
        return ( new HtmlTransformer() )->transform((string) ($input['content'] ?? ''))->toArray();
    }

    if ( 'artifact_compiler.compile' === $fixture['operation'] ) {
        $artifact = $input['artifact'] ?? array();
        if ( ! is_array($artifact) ) {
            fail("Fixture {$fixture['name']} artifact input must be an object.");
        }

        return ( new ArtifactCompiler() )->compile($artifact)->toArray();
    }

    if ( 'format_bridge.normalize' === $fixture['operation'] ) {
        $bridge = new FormatBridge();
        return array(
            'normalized' => $bridge->normalize((string) ($input['content'] ?? ''), (string) ($input['format'] ?? '')),
            'supported_formats' => $bridge->supportedFormats(),
        );
    }

    if ( 'format_bridge.convert' === $fixture['operation'] ) {
        $bridge = new FormatBridge();
        return array(
            'converted' => $bridge->convert((string) ($input['content'] ?? ''), (string) ($input['from'] ?? ''), (string) ($input['to'] ?? '')),
            'blocks' => $bridge->toBlocks((string) ($input['content'] ?? ''), (string) ($input['from'] ?? '')),
            'supported_formats' => $bridge->supportedFormats(),
        );
    }

    fail("Fixture {$fixture['name']} declares unsupported operation: {$fixture['operation']}");
}

/**
 * @param array<string, mixed> $fixture
 * @param array<string, mixed> $currentOutput
 * @return array{status: 'compared'|'skipped', reason?: string}
 */
function runLegacyComparison(array $fixture, array $currentOutput): array
{
    $comparison = $fixture['legacy_comparison'] ?? array();
    if ( ! is_array($comparison) ) {
        return array('status' => 'skipped', 'reason' => 'fixture does not declare legacy_comparison metadata');
    }

    if ( true === ($comparison['skip'] ?? false) ) {
        return array('status' => 'skipped', 'reason' => (string) ($comparison['reason'] ?? 'fixture metadata skips legacy comparison'));
    }

    if ( ! legacyComparisonsEnabled($comparison) ) {
        return array('status' => 'skipped', 'reason' => 'set BLOCKS_ENGINE_PARITY_LEGACY=1 or legacy_comparison.enabled=true to opt in');
    }

    $repo = (string) ($comparison['repo'] ?? legacyRepoForOperation((string) $fixture['operation']));
    if ( '' === $repo ) {
        return array('status' => 'skipped', 'reason' => 'fixture operation has no legacy repo mapping');
    }

    $repoPath = legacyRepoPath($repo, $comparison);
    if ( null === $repoPath ) {
        return array('status' => 'skipped', 'reason' => "set {$repo} path in fixture metadata or " . legacyRepoEnvName($repo));
    }

    $bootstrap = (string) ($comparison['bootstrap'] ?? legacyBootstrapForRepo($repo));
    if ( '' !== $bootstrap && ! loadLegacyBootstrap($repoPath, $bootstrap) ) {
        return array('status' => 'skipped', 'reason' => "legacy bootstrap not found: {$repoPath}/{$bootstrap}");
    }

    $callable = (string) ($comparison['callable'] ?? legacyCallableForOperation((string) $fixture['operation']));
    if ( '' === $callable || ! function_exists($callable) ) {
        return array('status' => 'skipped', 'reason' => "legacy callable is not available after bootstrap: {$callable}");
    }

    $legacyOutput = normalizeComparisonSnapshot(runLegacyCallable($callable, $fixture));
    $currentSnapshot = normalizeComparisonSnapshot($currentOutput);
    $paths = $comparison['paths'] ?? array();

    if ( ! is_array($paths) || array() === $paths ) {
        return array('status' => 'skipped', 'reason' => 'legacy callable loaded, but fixture does not declare legacy_comparison.paths');
    }

    foreach ( $paths as $path ) {
        $path = (string) $path;
        $legacyValue = valueAtComparisonPath($legacyOutput, $path);
        $currentValue = valueAtComparisonPath($currentSnapshot, $path);
        if ( $legacyValue !== $currentValue ) {
            failExpectation((string) $fixture['name'], "legacy_comparison.{$path}", $legacyValue, $currentValue);
        }
    }

    return array('status' => 'compared');
}

/**
 * @param array<string, mixed> $comparison
 */
function legacyComparisonsEnabled(array $comparison): bool
{
    if ( true === ($comparison['enabled'] ?? false) ) {
        return true;
    }

    return in_array(strtolower((string) getenv('BLOCKS_ENGINE_PARITY_LEGACY')), array('1', 'true', 'yes'), true);
}

function legacyRepoForOperation(string $operation): string
{
    return match ( $operation ) {
        'html_transformer.transform' => 'html-to-blocks-converter',
        'format_bridge.normalize' => 'block-format-bridge',
        'artifact_compiler.compile' => 'block-artifact-compiler',
        default => '',
    };
}

function legacyCallableForOperation(string $operation): string
{
    return match ( $operation ) {
        'html_transformer.transform' => 'html_to_blocks_convert',
        'format_bridge.normalize' => 'bfb_normalize',
        'artifact_compiler.compile' => 'bac_compile_website_artifact',
        default => '',
    };
}

function legacyBootstrapForRepo(string $repo): string
{
    return match ( $repo ) {
        'html-to-blocks-converter', 'block-format-bridge', 'block-artifact-compiler' => 'library.php',
        default => '',
    };
}

/**
 * @param array<string, mixed> $comparison
 */
function legacyRepoPath(string $repo, array $comparison): ?string
{
    $metadataPath = $comparison['path'] ?? null;
    if ( is_string($metadataPath) && '' !== $metadataPath ) {
        return rtrim($metadataPath, '/');
    }

    $envPath = getenv(legacyRepoEnvName($repo));
    if ( false !== $envPath && '' !== $envPath ) {
        return rtrim($envPath, '/');
    }

    return null;
}

function legacyRepoEnvName(string $repo): string
{
    return 'BLOCKS_ENGINE_PARITY_LEGACY_' . strtoupper(str_replace('-', '_', $repo)) . '_PATH';
}

function loadLegacyBootstrap(string $repoPath, string $bootstrap): bool
{
    $path = $repoPath . '/' . ltrim($bootstrap, '/');
    if ( ! is_file($path) ) {
        return false;
    }

    require_once $path;
    return true;
}

/**
 * @param array<string, mixed> $fixture
 */
function runLegacyCallable(string $callable, array $fixture): mixed
{
    $input = $fixture['input'];

    if ( 'html_transformer.transform' === $fixture['operation'] ) {
        return $callable((string) ($input['content'] ?? ''));
    }

    if ( 'format_bridge.normalize' === $fixture['operation'] ) {
        return $callable((string) ($input['content'] ?? ''), (string) ($input['format'] ?? ''));
    }

    if ( 'artifact_compiler.compile' === $fixture['operation'] ) {
        $artifact = $input['artifact'] ?? array();
        return $callable(is_array($artifact) ? $artifact : array());
    }

    fail("Fixture {$fixture['name']} declares unsupported legacy operation: {$fixture['operation']}");
}

function normalizeComparisonSnapshot(mixed $value): mixed
{
    if ( is_object($value) ) {
        $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
    }

    if ( is_array($value) ) {
        $normalized = array();
        foreach ( $value as $key => $child ) {
            $normalized[$key] = normalizeComparisonSnapshot($child);
        }
        if ( array_is_list($normalized) ) {
            return $normalized;
        }
        ksort($normalized);
        return $normalized;
    }

    if ( is_string($value) ) {
        return str_replace("\r\n", "\n", $value);
    }

    return $value;
}

function valueAtComparisonPath(mixed $output, string $path): mixed
{
    if ( ! is_array($output) ) {
        return null;
    }

    return valueAtPath($output, $path);
}

/**
 * @param array<string, mixed> $output
 * @param array<string, mixed> $expectation
 */
function assertExpectation(array $output, array $expectation, string $fixtureName): void
{
    $path = (string) ($expectation['path'] ?? '');
    $assertion = (string) ($expectation['assert'] ?? '');
    $actual = valueAtPath($output, $path);

    if ( 'equals' === $assertion ) {
        $expected = $expectation['value'] ?? null;
        if ( $expected !== $actual ) {
            failExpectation($fixtureName, $path, $expected, $actual);
        }
        return;
    }

    if ( 'contains' === $assertion ) {
        $expected = (string) ($expectation['value'] ?? '');
        if ( ! is_string($actual) || ! str_contains($actual, $expected) ) {
            failExpectation($fixtureName, $path, $expected, $actual);
        }
        return;
    }

    if ( 'count' === $assertion ) {
        $expected = (int) ($expectation['count'] ?? -1);
        $actualCount = is_countable($actual) ? count($actual) : null;
        if ( $expected !== $actualCount ) {
            failExpectation($fixtureName, $path, $expected, $actualCount);
        }
        return;
    }

    fail("Fixture {$fixtureName} declares unsupported assertion: {$assertion}");
}

/**
 * @param array<string, mixed> $output
 */
function valueAtPath(array $output, string $path): mixed
{
    $value = $output;
    foreach ( pathSegments($path) as $part ) {
        if ( ! is_array($value) || ! array_key_exists($part, $value) ) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
}

/**
 * @return array<int, string>
 */
function pathSegments(string $path): array
{
    $segments = array();
    $segment = '';
    $escaped = false;

    foreach ( str_split($path) as $char ) {
        if ( $escaped ) {
            $segment .= $char;
            $escaped = false;
            continue;
        }

        if ( '\\' === $char ) {
            $escaped = true;
            continue;
        }

        if ( '.' === $char ) {
            $segments[] = $segment;
            $segment = '';
            continue;
        }

        $segment .= $char;
    }

    if ( $escaped ) {
        $segment .= '\\';
    }

    $segments[] = $segment;

    return $segments;
}

function failExpectation(string $fixtureName, string $path, mixed $expected, mixed $actual): never
{
    fail(
        "Fixture {$fixtureName} failed expectation at {$path}.\n" .
        'Expected: ' . var_export($expected, true) . "\n" .
        'Actual: ' . var_export($actual, true)
    );
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
