<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Topology;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use DOMDocument;
use DOMElement;

/**
 * Measures source-vs-rendered element-tree divergence over website fixtures.
 *
 * Each fixture directory is compiled the way an import compiles it (every
 * HTML, CSS, JS and SVG file through ArtifactCompiler). The source tree is the
 * entry page's `<body>`; the rendered tree is the saved markup of the compiled
 * blocks. Dynamic blocks (saved as a self-closing block comment, rendered by
 * WordPress) have no saved element tree; they are counted so the report states
 * that gap instead of reporting their source elements as silently lost.
 */
final class TopologyCorpusRunner
{
    public const SCHEMA = 'blocks-engine/php-transformer/topology-diff-report/v1';

    public const BASELINE_SCHEMA = 'blocks-engine/php-transformer/topology-diff-baseline/v1';

    private const SOURCE_EXTENSIONS = array( 'html', 'css', 'js', 'svg' );

    /**
     * @param array<int, string> $fixtureDirs
     * @return array<string, mixed>
     */
    public function run(array $fixtureDirs, ?callable $progress = null): array
    {
        $fixtures = array();
        foreach ( $fixtureDirs as $dir ) {
            $name = basename(rtrim($dir, '/'));
            $fixtures[$name] = $this->measure($dir);
            if ( null !== $progress ) {
                $progress($name, $fixtures[$name]);
            }
        }

        return array(
            'schema' => self::SCHEMA,
            'fixtures' => $fixtures,
            'kinds' => $this->rank($fixtures),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function measure(string $fixtureDir): array
    {
        $files = $this->sourceFiles($fixtureDir);
        $entry = $files['index.html'] ?? null;
        if ( null === $entry ) {
            return array( 'error' => 'no index.html' );
        }
        $result = ( new ArtifactCompiler() )->compile(array( 'files' => $files ))->toArray();
        $serialized = (string) ( $result['serialized_blocks'] ?? '' );

        return $this->compare($entry, $serialized) + array(
            'fallbacks' => count((array) ( $result['fallbacks'] ?? array() )),
        );
    }

    /**
     * @return array{matched: int, dynamic_blocks: int, kinds: array<string, int>}
     */
    public function compare(string $sourceHtml, string $serializedBlocks): array
    {
        $diff = ElementTreeDiff::compare(
            self::body($sourceHtml),
            self::body('<!doctype html><html><body>' . $serializedBlocks . '</body></html>')
        );
        $kinds = array();
        foreach ( $diff['events'] as $event ) {
            $kind = self::kindKey($event);
            $kinds[$kind] = ( $kinds[$kind] ?? 0 ) + 1;
        }
        arsort($kinds);

        return array(
            'matched' => $diff['matched'],
            'dynamic_blocks' => (int) preg_match_all('/<!-- wp:[a-z0-9-]+(?:\/[a-z0-9-]+)? (?:\{(?:(?!-->).)*\} )?\/-->/', $serializedBlocks),
            'kinds' => $kinds,
        );
    }

    /**
     * Baseline form of a report: per-fixture divergence counts only.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public static function baseline(array $report): array
    {
        $fixtures = array();
        foreach ( $report['fixtures'] as $name => $fixture ) {
            $kinds = (array) ( $fixture['kinds'] ?? array() );
            ksort($kinds);
            $fixtures[$name] = $kinds;
        }
        ksort($fixtures);

        return array( 'schema' => self::BASELINE_SCHEMA, 'fixtures' => $fixtures );
    }

    /**
     * Divergence weights. A retag keeps the box, its position and its classes;
     * only tag selectors and user-agent defaults see it. Every other kind adds
     * or drops a box, which also changes parent, child and sibling relations
     * for author selectors, inheritance and layout.
     */
    public const RETAG_WEIGHT = 1;

    public const STRUCTURAL_WEIGHT = 2;

    public static function weight(string $kindKey): int
    {
        return str_starts_with($kindKey, 'retagged ') ? self::RETAG_WEIGHT : self::STRUCTURAL_WEIGHT;
    }

    /**
     * @param array<string, int> $kinds
     */
    public static function score(array $kinds): int
    {
        $score = 0;
        foreach ( $kinds as $kind => $count ) {
            $score += self::weight((string) $kind) * (int) $count;
        }

        return $score;
    }

    /**
     * Compares measured fixtures with a baseline by weighted score. A rising
     * score is a regression. A falling score is an improvement, and any other
     * count change is a change; both must be written into the baseline so it
     * stays exact.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $baseline
     * @return array{regressions: array<int, string>, improvements: array<int, string>, changes: array<int, string>}
     */
    public static function check(array $report, array $baseline): array
    {
        $result = array( 'regressions' => array(), 'improvements' => array(), 'changes' => array() );
        foreach ( self::baseline($report)['fixtures'] as $name => $kinds ) {
            if ( ! isset($baseline['fixtures'][$name]) ) {
                $result['regressions'][] = "{$name}: not in the baseline";
                continue;
            }
            $expected = (array) $baseline['fixtures'][$name];
            $deltas = array();
            foreach ( array_unique(array_merge(array_keys($kinds), array_keys($expected))) as $kind ) {
                $delta = (int) ( $kinds[$kind] ?? 0 ) - (int) ( $expected[$kind] ?? 0 );
                if ( 0 !== $delta ) {
                    $deltas[$kind] = sprintf('%s %+d', $kind, $delta);
                }
            }
            if ( array() === $deltas ) {
                continue;
            }
            ksort($deltas);
            $was = self::score($expected);
            $now = self::score($kinds);
            $bucket = $now > $was ? 'regressions' : ( $now < $was ? 'improvements' : 'changes' );
            $result[$bucket][] = "{$name}: score {$was} -> {$now} (" . implode(', ', $deltas) . ')';
        }
        foreach ( $result as &$lines ) {
            sort($lines);
        }

        return $result;
    }

    /**
     * @param array{kind: string, tag: string, marker: string} $event
     */
    public static function kindKey(array $event): string
    {
        return $event['kind'] . ' ' . $event['tag'] . ( '' !== $event['marker'] ? ' [' . $event['marker'] . ']' : '' );
    }

    public function renderSummary(array $report, int $limit): string
    {
        $fixtures = $report['fixtures'];
        $matched = 0;
        $events = 0;
        $score = 0;
        $dynamic = 0;
        foreach ( $fixtures as $fixture ) {
            $matched += (int) ( $fixture['matched'] ?? 0 );
            $dynamic += (int) ( $fixture['dynamic_blocks'] ?? 0 );
            $events += array_sum((array) ( $fixture['kinds'] ?? array() ));
            $score += self::score((array) ( $fixture['kinds'] ?? array() ));
        }
        $lines = array(
            sprintf('Fixtures: %d   matched source elements: %d   divergences: %d   weighted score: %d   dynamic blocks (not compared): %d', count($fixtures), $matched, $events, $score, $dynamic),
            '',
            sprintf('%7s %7s  %s', 'sites', 'count', 'divergence'),
        );
        foreach ( array_slice($report['kinds'], 0, $limit, true) as $kind => $row ) {
            $lines[] = sprintf('%7d %7d  %s', $row['sites'], $row['count'], $kind);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, array<string, mixed>> $fixtures
     * @return array<string, array{sites: int, count: int}>
     */
    private function rank(array $fixtures): array
    {
        $kinds = array();
        foreach ( $fixtures as $fixture ) {
            foreach ( (array) ( $fixture['kinds'] ?? array() ) as $kind => $count ) {
                $kinds[$kind] ??= array( 'sites' => 0, 'count' => 0 );
                ++$kinds[$kind]['sites'];
                $kinds[$kind]['count'] += (int) $count;
            }
        }
        uksort($kinds, static fn (string $a, string $b): int => array( $kinds[$b]['sites'], $kinds[$b]['count'], $a ) <=> array( $kinds[$a]['sites'], $kinds[$a]['count'], $b ));

        return $kinds;
    }

    /**
     * @return array<string, string>
     */
    private function sourceFiles(string $fixtureDir): array
    {
        $root = rtrim($fixtureDir, '/');
        $files = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ( $iterator as $file ) {
            if ( ! $file instanceof \SplFileInfo || ! in_array(strtolower($file->getExtension()), self::SOURCE_EXTENSIONS, true) ) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
            if ( ! str_contains($relative, 'node_modules/') ) {
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private static function body(string $html): DOMElement
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : $document->documentElement;
    }
}
