<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\ButtonMenuVisualProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\ButtonMenuVisualProbeComparator;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityComparator;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\TypographyVisualProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\TypographyVisualProbeComparator;

if ( in_array('--legacy-child', $argv ?? array(), true) ) {
    runLegacyChildProcess();
}

$runMigrationComparisons = in_array('--migration-comparison', $argv ?? array(), true) || in_array('--legacy-comparison', $argv ?? array(), true);

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
$migrationSkipped = 0;
$migrationCompared = 0;

foreach ( $fixtures as $fixturePath ) {
    $fixture = loadFixture($fixturePath);
    validateFixture($fixture, $fixturePath);

    $output = runFixture($fixture);
    foreach ( $fixture['expect'] as $expectation ) {
        if (str_starts_with((string) ($expectation['path'] ?? ''), 'source_reports.materialization_plan.') && !is_array($output['source_reports']['wordpress_site_plan'] ?? null)) {
            continue;
        }
        $expectation = migrateMaterializationExpectation($expectation);
        if (null !== $expectation) {
            assertExpectation($output, $expectation, $fixture['name']);
        }
    }
    assertStructuredCoverage($output, $fixture);
    assertArtifactReportConsistency($output, $fixture);

    if ( $runMigrationComparisons ) {
        $migrationResult = runLegacyComparison($fixture, $output);
        if ( 'compared' === $migrationResult['status'] ) {
            ++$migrationCompared;
        }
        if ( 'skipped' === $migrationResult['status'] ) {
            ++$migrationSkipped;
            fwrite(STDOUT, "Skipped migration comparison for {$fixture['name']}: {$migrationResult['reason']}\n");
        }
    }

    ++$ran;
}

/**
 * Migrate persisted parity assertions from the removed v1 projection to the
 * canonical plan without reintroducing that projection in production output.
 *
 * @param array<string,mixed> $expectation
 * @return array<string,mixed>|null
 */
function migrateMaterializationExpectation(array $expectation): ?array
{
    $path = $expectation['path'] ?? null;
    if (!is_string($path) || !str_starts_with($path, 'source_reports.materialization_plan.')) {
        return $expectation;
    }

    $path = substr($path, strlen('source_reports.materialization_plan.'));
    if (str_starts_with($path, 'theme.font_materialization.')) {
        $expectation['path'] = 'source_reports.font_materialization.' . substr($path, strlen('theme.font_materialization.'));
        return $expectation;
    }
    if ('visual_repair_css' === $path) {
        $expectation['path'] = 'source_reports.wordpress_site_plan.visual_repair.css';
        return $expectation;
    }
    foreach (array('pages', 'routes', 'navigation_links', 'menus', 'template_parts', 'assets') as $surface) {
        if (!str_starts_with($path, $surface)) {
            continue;
        }
        $path = preg_replace('/^' . preg_quote($surface, '/') . '\./', $surface . '.', $path) ?? $path;
        $path = str_replace('.block_markup', '.canonical_block_markup', $path);
        $path = str_replace('.path', '.source_path', $path);
        $path = str_replace('.media_type', '.mime_type', $path);
        if ('assets' === $surface && str_ends_with($path, '.target_path') && is_string($expectation['value'] ?? null)) {
            $expectation['value'] = 'assets/' . ltrim($expectation['value'], '/');
        }
        if (str_ends_with($path, '.content_encoding')) {
            return null;
        }
        $expectation['path'] = 'source_reports.wordpress_site_plan.' . $path;
        return $expectation;
    }

    // Theme lists, totals, rewrite candidates, and v1 template write rows are
    // superseded by canonical assets/writes and the consistency assertions.
    return null;
}

if ( $runMigrationComparisons ) {
    fwrite(STDOUT, "PHP transformer parity fixtures passed: {$ran} fixture(s), {$migrationCompared} migration comparison(s), {$migrationSkipped} migration comparison(s) skipped.\n");
} else {
    fwrite(STDOUT, "PHP transformer parity fixtures passed: {$ran} fixture(s).\n");
}

/**
 * @param array<string, mixed> $output
 * @param array<string, mixed> $fixture
 */
function assertArtifactReportConsistency(array $output, array $fixture): void
{
    if ( 'artifact_compiler.compile' !== ($fixture['operation'] ?? '') ) {
        return;
    }

    $sourceReports = $output['source_reports'] ?? array();
    if ( ! is_array($sourceReports) ) {
        fail("Fixture {$fixture['name']} output source_reports must be an object.");
    }

    $materializationPlan = $sourceReports['wordpress_site_plan'] ?? array();
    $conversionReport = $sourceReports['conversion_report'] ?? array();
    if ( ! is_array($materializationPlan) || ! is_array($conversionReport) ) {
        fail("Fixture {$fixture['name']} artifact output must include canonical plan and conversion reports.");
    }

    $summary = $conversionReport['source_summary'] ?? array();
    if ( ! is_array($summary) ) {
        fail("Fixture {$fixture['name']} canonical plan and conversion source summary must be objects.");
    }

    // Failed artifact compilation intentionally has no materializable plan.
    if (array() === $materializationPlan) {
        return;
    }

    $summaryKeys = array(
        'pages' => 'page_count',
        'assets' => 'asset_count',
        'routes' => 'route_count',
        'navigation_links' => 'navigation_link_count',
        'menus' => 'menu_count',
    );
    foreach ( $summaryKeys as $totalKey => $summaryKey ) {
        if (count($materializationPlan[$totalKey] ?? array()) !== ($summary[$summaryKey] ?? null)) {
            failExpectation((string) $fixture['name'], "source_reports.conversion_report.source_summary.{$summaryKey}", count($materializationPlan[$totalKey] ?? array()), $summary[$summaryKey] ?? null);
        }
    }

    foreach ( array('pages', 'routes', 'navigation_links', 'menus', 'template_parts', 'assets') as $listKey ) {
        if ( ! array_key_exists($listKey, $materializationPlan) || ! is_array($materializationPlan[$listKey]) ) {
            fail("Fixture {$fixture['name']} wordpress_site_plan.{$listKey} must be an array.");
        }
    }

}

/**
 * @param array<string, mixed> $output
 * @param array<string, mixed> $fixture
 */
function assertStructuredCoverage(array $output, array $fixture): void
{
    foreach ( array( 'expected_blocks', 'expected_fallbacks' ) as $key ) {
        if ( isset($fixture[$key]) && ! is_array($fixture[$key]) ) {
            fail("Fixture {$fixture['name']} {$key} must be an array.");
        }
    }

    foreach ( $fixture['expected_blocks'] ?? array() as $expectedBlock ) {
        if ( ! is_array($expectedBlock) ) {
            fail("Fixture {$fixture['name']} expected_blocks entries must be objects.");
        }

        $path = (string) ($expectedBlock['path'] ?? '');
        $block = valueAtPath($output, $path);
        if ( ! is_array($block) ) {
            failExpectation((string) $fixture['name'], $path, 'block object', $block);
        }

        if ( isset($expectedBlock['name']) && $expectedBlock['name'] !== ($block['blockName'] ?? null) ) {
            failExpectation((string) $fixture['name'], $path . '.blockName', $expectedBlock['name'], $block['blockName'] ?? null);
        }

        $attrs = $expectedBlock['attrs'] ?? array();
        if ( ! is_array($attrs) ) {
            fail("Fixture {$fixture['name']} expected block attrs must be an object.");
        }
        foreach ( $attrs as $attrName => $expectedValue ) {
            $actualValue = is_array($block['attrs'] ?? null) ? ($block['attrs'][$attrName] ?? null) : null;
            if ( $expectedValue !== $actualValue ) {
                failExpectation((string) $fixture['name'], $path . '.attrs.' . (string) $attrName, $expectedValue, $actualValue);
            }
        }
    }

    $expectedFallbacks = $fixture['expected_fallbacks'] ?? null;
    if ( null === $expectedFallbacks ) {
        return;
    }

    $fallbacks = $output['fallbacks'] ?? array();
    if ( ! is_array($fallbacks) ) {
        failExpectation((string) $fixture['name'], 'fallbacks', 'fallback array', $fallbacks);
    }

    if ( count($expectedFallbacks) !== count($fallbacks) ) {
        failExpectation((string) $fixture['name'], 'fallbacks', count($expectedFallbacks), count($fallbacks));
    }

    foreach ( $expectedFallbacks as $index => $expectedFallback ) {
        if ( ! is_array($expectedFallback) ) {
            fail("Fixture {$fixture['name']} expected_fallbacks entries must be objects.");
        }
        foreach ( $expectedFallback as $key => $expectedValue ) {
            $actualValue = $fallbacks[$index][$key] ?? null;
            if ( $expectedValue !== $actualValue ) {
                failExpectation((string) $fixture['name'], 'fallbacks.' . $index . '.' . (string) $key, $expectedValue, $actualValue);
            }
        }
    }
}

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
        $options = $input['options'] ?? array();
        if ( ! is_array($options) ) {
            fail("Fixture {$fixture['name']} HTML transform options must be an object.");
        }

        return ( new HtmlTransformer() )->transform((string) ($input['content'] ?? ''), $options)->toArray();
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
            'result' => $bridge->convertResult((string) ($input['content'] ?? ''), (string) ($input['from'] ?? ''), (string) ($input['to'] ?? ''))->toArray(),
            'supported_formats' => $bridge->supportedFormats(),
        );
    }

    if ( 'visual_parity_probe.extract' === $fixture['operation'] ) {
        return ( new ButtonMenuVisualProbe() )->extract((string) ($input['content'] ?? ''));
    }

    if ( 'visual_parity_probe.compare' === $fixture['operation'] ) {
        $probe = new ButtonMenuVisualProbe();
        return ( new ButtonMenuVisualProbeComparator() )->compare(
            $probe->extract((string) ($input['source_content'] ?? '')),
            $probe->extract((string) ($input['target_content'] ?? ''))
        );
    }

    if ( 'typography_parity_probe.extract' === $fixture['operation'] ) {
        return ( new TypographyVisualProbe() )->extract((string) ($input['content'] ?? ''));
    }

    if ( 'typography_parity_probe.compare' === $fixture['operation'] ) {
        $probe = new TypographyVisualProbe();
        $report = ( new TypographyVisualProbeComparator() )->compare(
            $probe->extract((string) ($input['source_content'] ?? '')),
            $probe->extract((string) ($input['target_content'] ?? ''))
        );
        \Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract::assertReport($report);
        return $report;
    }

    if ( 'static_style_parity.extract' === $fixture['operation'] ) {
        return ( new StaticStyleParityProbe() )->extract(
            (string) ($input['content'] ?? ''),
            (string) ($input['css'] ?? '')
        );
    }

    if ( 'static_style_parity.compare' === $fixture['operation'] ) {
        $probe = new StaticStyleParityProbe();
        $report = ( new StaticStyleParityComparator() )->compare(
            $probe->extract((string) ($input['source_content'] ?? ''), (string) ($input['source_css'] ?? '')),
            $probe->extract((string) ($input['target_content'] ?? ''), (string) ($input['target_css'] ?? ''))
        );
        \Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract::assertReport($report);
        return $report;
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
        return array('status' => 'skipped', 'reason' => 'fixture does not declare migration comparison metadata');
    }

    if ( true === ($comparison['skip'] ?? false) ) {
        return array('status' => 'skipped', 'reason' => (string) ($comparison['reason'] ?? 'fixture metadata skips migration comparison'));
    }

    if ( true !== ($comparison['safe'] ?? false) ) {
        return array('status' => 'skipped', 'reason' => 'fixture is not marked safe for migration comparison');
    }

    if ( ! legacyComparisonsEnabled($comparison) ) {
        return array('status' => 'skipped', 'reason' => 'set BLOCKS_ENGINE_PARITY_LEGACY=1 or legacy_comparison.enabled=true to opt in to local migration comparison');
    }

    $repo = (string) ($comparison['repo'] ?? legacyRepoForOperation((string) $fixture['operation']));
    if ( '' === $repo ) {
        return array('status' => 'skipped', 'reason' => 'fixture operation has no migration repo mapping');
    }

    $repoPath = legacyRepoPath($repo, $comparison);
    if ( null === $repoPath ) {
        return array('status' => 'skipped', 'reason' => "set {$repo} path in fixture metadata or " . legacyRepoEnvName($repo));
    }

    if ( ! is_dir($repoPath) ) {
        return array('status' => 'skipped', 'reason' => "migration repo path is not a directory: {$repoPath}");
    }

    $bootstrap = (string) ($comparison['bootstrap'] ?? legacyBootstrapForRepo($repo));
    if ( '' !== $bootstrap && ! is_file($repoPath . '/' . ltrim($bootstrap, '/')) ) {
        return array('status' => 'skipped', 'reason' => "migration bootstrap not found: {$repoPath}/{$bootstrap}");
    }

    $callable = (string) ($comparison['callable'] ?? legacyCallableForOperation((string) $fixture['operation']));
    if ( '' === $callable ) {
        return array('status' => 'skipped', 'reason' => 'fixture operation has no migration callable mapping');
    }

    $legacyOutput = normalizeComparisonSnapshot(runLegacyInSubprocess($fixture, $repoPath, $bootstrap, $callable));
    $currentSnapshot = normalizeComparisonSnapshot($currentOutput);
    $paths = $comparison['paths'] ?? array();

    if ( ! is_array($paths) || array() === $paths ) {
        return array('status' => 'skipped', 'reason' => 'migration callable ran, but fixture does not declare comparison paths');
    }

    foreach ( $paths as $path ) {
        $legacyPath = is_array($path) ? (string) ($path['legacy_path'] ?? $path['path'] ?? '') : (string) $path;
        $currentPath = is_array($path) ? (string) ($path['current_path'] ?? $path['path'] ?? '') : (string) $path;
        $label = is_array($path) ? (string) ($path['label'] ?? $currentPath) : $currentPath;
        $legacyValue = valueAtComparisonPath($legacyOutput, $legacyPath);
        $currentValue = valueAtComparisonPath($currentSnapshot, $currentPath);
        if ( $legacyValue !== $currentValue ) {
            failExpectation((string) $fixture['name'], "migration_comparison.{$label}", $legacyValue, $currentValue);
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
        default => '',
    };
}

function legacyCallableForOperation(string $operation): string
{
    return match ( $operation ) {
        'html_transformer.transform' => 'html_to_blocks_convert',
        default => '',
    };
}

function legacyBootstrapForRepo(string $repo): string
{
    return match ( $repo ) {
        'html-to-blocks-converter' => 'library.php',
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

/**
 * @param array<string, mixed> $fixture
 */
function runLegacyInSubprocess(array $fixture, string $repoPath, string $bootstrap, string $callable): mixed
{
    $payload = base64_encode((string) json_encode(array(
        'fixture' => $fixture,
        'repo_path' => $repoPath,
        'bootstrap' => $bootstrap,
        'callable' => $callable,
    ), JSON_UNESCAPED_SLASHES));
    $descriptorSpec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $env = array_merge($_ENV, array('BLOCKS_ENGINE_PARITY_LEGACY_CHILD_INPUT' => $payload));
    $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --legacy-child', $descriptorSpec, $pipes, null, $env);
    if ( ! is_resource($process) ) {
        fail("Unable to start migration comparison subprocess for {$fixture['name']}.");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = json_decode((string) $stdout, true);
    if ( 0 !== $exitCode || ! is_array($decoded) ) {
        fail("Migration comparison subprocess failed for {$fixture['name']} with exit code {$exitCode}.\n" . trim((string) $stderr));
    }
    if ( ($decoded['status'] ?? '') !== 'ok' ) {
        fail("Migration comparison failed for {$fixture['name']}: " . (string) ($decoded['message'] ?? 'unknown error'));
    }

    return $decoded['output'] ?? null;
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

    fail("Fixture {$fixture['name']} declares unsupported migration operation: {$fixture['operation']}");
}

function runLegacyChildProcess(): never
{
    $payload = getenv('BLOCKS_ENGINE_PARITY_LEGACY_CHILD_INPUT');
    $decodedPayload = false === $payload ? false : base64_decode($payload, true);
    $input = false === $decodedPayload ? null : json_decode($decodedPayload, true);
    if ( ! is_array($input) || ! is_array($input['fixture'] ?? null) ) {
        legacyChildResponse('error', null, 'invalid migration child input');
    }

    installLegacyWordPressStubs();

    $repoPath = (string) ($input['repo_path'] ?? '');
    $bootstrap = (string) ($input['bootstrap'] ?? '');
    $callable = (string) ($input['callable'] ?? '');
    $bootstrapPath = $repoPath . '/' . ltrim($bootstrap, '/');
    if ( ! is_file($bootstrapPath) ) {
        legacyChildResponse('error', null, "migration bootstrap not found: {$bootstrapPath}");
    }

    require_once $bootstrapPath;

    if ( ! function_exists($callable) ) {
        legacyChildResponse('error', null, "migration callable is not available after bootstrap: {$callable}");
    }

    try {
        legacyChildResponse('ok', normalizeComparisonSnapshot(runLegacyCallable($callable, $input['fixture'])));
    } catch ( Throwable $throwable ) {
        legacyChildResponse('error', null, $throwable->getMessage());
    }
}

function legacyChildResponse(string $status, mixed $output = null, string $message = ''): never
{
    fwrite(STDOUT, (string) json_encode(array(
        'status' => $status,
        'output' => $output,
        'message' => $message,
    ), JSON_UNESCAPED_SLASHES));
    exit('ok' === $status ? 0 : 1);
}

function installLegacyWordPressStubs(): void
{
    if ( ! defined('ABSPATH') ) {
        define('ABSPATH', sys_get_temp_dir() . '/blocks-engine-parity-wordpress/');
    }
    if ( ! class_exists('WP_Error', false) ) {
        class WP_Error
        {
            /** @param array<string, mixed> $data */
            public function __construct(public string $code = '', public string $message = '', public array $data = array()) {}
        }
    }
    if ( ! function_exists('did_action') ) {
        function did_action(string $hook): int { return 'plugins_loaded' === $hook ? 1 : 0; }
    }
    if ( ! function_exists('doing_action') ) {
        function doing_action(string $hook): bool { return false; }
    }
    if ( ! function_exists('add_action') ) {
        function add_action(string $hook, callable $callback, int $priority = 10): bool { return true; }
    }
    if ( ! function_exists('add_filter') ) {
        function add_filter(string $hook, callable $callback, int $priority = 10): bool { return true; }
    }
    if ( ! function_exists('do_action') ) {
        function do_action(string $hook, mixed ...$args): void {}
    }
    if ( ! function_exists('apply_filters') ) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
    }
    if ( ! function_exists('has_action') ) {
        function has_action(string $hook, callable|string|array|null $callback = null): bool|int { return false; }
    }
    if ( ! function_exists('trailingslashit') ) {
        function trailingslashit(string $value): string { return rtrim($value, '/') . '/'; }
    }
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
    if ( '' === $path ) {
        return $output;
    }

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

    if ( 'contains_style_declarations' === $assertion ) {
        $expected = parseStyleDeclarations((string) ($expectation['value'] ?? ''));
        if ( array() === $expected ) {
            fail("Fixture {$fixtureName} contains_style_declarations expectation at {$path} must provide at least one CSS declaration.");
        }
        if ( ! is_string($actual) ) {
            failExpectation($fixtureName, $path, 'string containing inline style declarations', $actual);
        }
        if ( ! containsStyleDeclarations($actual, $expected) ) {
            failExpectation($fixtureName, $path, array('style_declarations' => $expected), inlineStyleDeclarationMaps($actual));
        }
        return;
    }

    if ( 'not_contains' === $assertion ) {
        $expected = (string) ($expectation['value'] ?? '');
        if ( is_string($actual) && str_contains($actual, $expected) ) {
            failExpectation($fixtureName, $path, 'not containing ' . $expected, $actual);
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
 * @param array<string, string> $expected
 */
function containsStyleDeclarations(string $actual, array $expected): bool
{
    foreach ( inlineStyleDeclarationMaps($actual) as $styleDeclarations ) {
        $matched = true;
        foreach ( $expected as $property => $value ) {
            if ( ($styleDeclarations[$property] ?? null) !== $value ) {
                $matched = false;
                break;
            }
        }
        if ( $matched ) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, array<string, string>>
 */
function inlineStyleDeclarationMaps(string $html): array
{
    $matchCount = preg_match_all('/\sstyle=("|\')(.*?)\1/s', $html, $matches);
    if ( false === $matchCount || 0 === $matchCount ) {
        return array();
    }

    $styles = array();
    foreach ( $matches[2] as $style ) {
        $styles[] = parseStyleDeclarations(html_entity_decode($style, ENT_QUOTES | ENT_HTML5));
    }

    return $styles;
}

/**
 * @return array<string, string>
 */
function parseStyleDeclarations(string $style): array
{
    $declarations = array();
    foreach ( splitCssTopLevel($style, ';') as $declaration ) {
        $parts = splitCssTopLevel($declaration, ':', 2);
        if ( 2 !== count($parts) ) {
            continue;
        }

        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        if ( '' === $property || '' === $value ) {
            continue;
        }

        $declarations[$property] = $value;
    }

    return $declarations;
}

/**
 * @return array<int, string>
 */
function splitCssTopLevel(string $value, string $delimiter, ?int $limit = null): array
{
    $parts = array();
    $part = '';
    $depth = 0;
    $quote = '';
    $escaped = false;

    foreach ( str_split($value) as $char ) {
        if ( $escaped ) {
            $part .= $char;
            $escaped = false;
            continue;
        }

        if ( '\\' === $char ) {
            $part .= $char;
            $escaped = true;
            continue;
        }

        if ( '' !== $quote ) {
            $part .= $char;
            if ( $char === $quote ) {
                $quote = '';
            }
            continue;
        }

        if ( '"' === $char || "'" === $char ) {
            $part .= $char;
            $quote = $char;
            continue;
        }

        if ( '(' === $char ) {
            ++$depth;
            $part .= $char;
            continue;
        }

        if ( ')' === $char && $depth > 0 ) {
            --$depth;
            $part .= $char;
            continue;
        }

        if ( $char === $delimiter && 0 === $depth && ( null === $limit || count($parts) < $limit - 1 ) ) {
            $parts[] = $part;
            $part = '';
            continue;
        }

        $part .= $char;
    }

    $parts[] = $part;

    return $parts;
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
