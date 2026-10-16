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
        $dynamic = 0;
        foreach ( $fixtures as $fixture ) {
            $matched += (int) ( $fixture['matched'] ?? 0 );
            $dynamic += (int) ( $fixture['dynamic_blocks'] ?? 0 );
            $events += array_sum((array) ( $fixture['kinds'] ?? array() ));
        }
        $lines = array(
            sprintf('Fixtures: %d   matched source elements: %d   divergences: %d   dynamic blocks (not compared): %d', count($fixtures), $matched, $events, $dynamic),
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
