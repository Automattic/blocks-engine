<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use InvalidArgumentException;

final class VisualParityReportContract
{
    public const REPORT_SCHEMA = 'blocks-engine/php-transformer/visual-parity-report/v1';
    public const FIXTURE_SCHEMA = 'blocks-engine/php-transformer/visual-parity-fixture/v1';

    /**
     * @param array<string, mixed> $report
     */
    public static function assertReport(array $report): void
    {
        self::requireKeys($report, array('schema', 'status', 'severity', 'source_render', 'target_render', 'viewports', 'matches', 'findings', 'recommendations'), 'visual parity report');

        if ( self::REPORT_SCHEMA !== $report['schema'] ) {
            throw new InvalidArgumentException('Visual parity report has an unsupported schema.');
        }

        if ( ! in_array($report['status'], array('pass', 'warning', 'fail', 'unknown'), true) ) {
            throw new InvalidArgumentException('Visual parity report has an unsupported status.');
        }

        self::assertSeverity($report['severity'], 'visual parity report severity');
        self::assertObject($report['source_render'], 'visual parity report source_render');
        self::assertObject($report['target_render'], 'visual parity report target_render');
        self::assertList($report['viewports'], 'visual parity report viewports');
        self::assertList($report['matches'], 'visual parity report matches');
        self::assertList($report['findings'], 'visual parity report findings');
        self::assertList($report['recommendations'], 'visual parity report recommendations');

        foreach ( $report['viewports'] as $index => $viewport ) {
            self::assertObject($viewport, "visual parity report viewports.{$index}");
            self::requireKeys($viewport, array('id', 'width', 'height'), "visual parity report viewports.{$index}");
        }

        foreach ( $report['matches'] as $index => $match ) {
            self::assertObject($match, "visual parity report matches.{$index}");
            self::requireKeys($match, array('kind', 'source_selector', 'target_selector', 'confidence'), "visual parity report matches.{$index}");
            self::assertComponentKind($match['kind'], "visual parity report matches.{$index}.kind");
            if ( ! is_int($match['confidence']) && ! is_float($match['confidence']) ) {
                throw new InvalidArgumentException("Visual parity report matches.{$index}.confidence must be numeric.");
            }
        }

        foreach ( $report['findings'] as $index => $finding ) {
            self::assertObject($finding, "visual parity report findings.{$index}");
            self::requireKeys($finding, array('id', 'severity', 'category', 'summary'), "visual parity report findings.{$index}");
            self::assertSeverity($finding['severity'], "visual parity report findings.{$index}.severity");
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    public static function assertFixture(array $fixture): void
    {
        self::requireKeys($fixture, array('schema', 'name', 'source', 'target', 'viewports', 'thresholds'), 'visual parity fixture');

        if ( self::FIXTURE_SCHEMA !== $fixture['schema'] ) {
            throw new InvalidArgumentException('Visual parity fixture has an unsupported schema.');
        }

        self::assertObject($fixture['source'], 'visual parity fixture source');
        self::assertObject($fixture['target'], 'visual parity fixture target');
        self::assertList($fixture['viewports'], 'visual parity fixture viewports');
        self::assertObject($fixture['thresholds'], 'visual parity fixture thresholds');

        foreach ( $fixture['viewports'] as $index => $viewport ) {
            self::assertObject($viewport, "visual parity fixture viewports.{$index}");
            self::requireKeys($viewport, array('id', 'width', 'height'), "visual parity fixture viewports.{$index}");
        }

        foreach ( array('capture', 'matchers', 'expectations') as $optionalList ) {
            if ( array_key_exists($optionalList, $fixture) ) {
                self::assertList($fixture[$optionalList], "visual parity fixture {$optionalList}");
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, string> $keys
     */
    private static function requireKeys(array $value, array $keys, string $label): void
    {
        foreach ( $keys as $key ) {
            if ( ! array_key_exists($key, $value) ) {
                throw new InvalidArgumentException(sprintf('%s is missing "%s".', ucfirst($label), $key));
            }
        }
    }

    private static function assertObject(mixed $value, string $label): void
    {
        if ( ! is_array($value) ) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', ucfirst($label)));
        }
    }

    private static function assertList(mixed $value, string $label): void
    {
        if ( ! is_array($value) || array_values($value) !== $value ) {
            throw new InvalidArgumentException(sprintf('%s must be an array.', ucfirst($label)));
        }
    }

    private static function assertSeverity(mixed $value, string $label): void
    {
        if ( ! in_array($value, array('none', 'info', 'warning', 'error', 'critical'), true) ) {
            throw new InvalidArgumentException(sprintf('%s has an unsupported severity.', ucfirst($label)));
        }
    }

    private static function assertComponentKind(mixed $value, string $label): void
    {
        if ( ! in_array($value, array('generic', 'button', 'menu', 'card', 'form'), true) ) {
            throw new InvalidArgumentException(sprintf('%s has an unsupported component kind.', ucfirst($label)));
        }
    }
}
