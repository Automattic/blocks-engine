<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style {
    // Intercept only the owning primitive's markup-wide operations, without
    // adding production counters or relying on elapsed time.
    function md5(string $string, bool $binary = false): string
    {
        if ( NavigationContainerHostScope::class === (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? '') ) {
            ++$GLOBALS['navigationHostMarkupPasses'];
        }
        return \md5($string, $binary);
    }

    function str_contains(string $haystack, string $needle): bool
    {
        if ( NavigationContainerHostScope::class === (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? '') && '<!-- wp:navigation ' === $needle ) {
            ++$GLOBALS['navigationHostMarkupPasses'];
        }
        return \str_contains($haystack, $needle);
    }

    function preg_match_all(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        if ( 'navigationRootClassLists' === (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '') ) {
            ++$GLOBALS['navigationHostMarkupPasses'];
        }
        return \preg_match_all($pattern, $subject, $matches, $flags, $offset);
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

    $assert = static function (bool $condition, string $message): void {
        if ( ! $condition ) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $css = '.menu{width:50%;padding-left:12px}';
    for ( $i = 0; $i < 2000; ++$i ) {
        $css .= '.menu{--rule-' . $i . ':1}';
    }
    $compiler = new ArtifactCompiler();
    $compile = static function (string $content) use ($compiler, $css): array {
        $GLOBALS['navigationHostMarkupPasses'] = 0;
        $result = $compiler->compile(array(
            'entrypoint' => 'index.html',
            'files' => array('index.html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $content . '</body></html>'),
        ))->toArray();
        $stylesheet = '';
        foreach ( $result['source_reports']['compiled_site']['assets'] ?? array() as $asset ) {
            if ( 'css' === ($asset['kind'] ?? '') ) {
                $stylesheet .= $asset['content'];
            }
        }
        return array($stylesheet, $GLOBALS['navigationHostMarkupPasses']);
    };

    [$stylesheet, $passes] = $compile('<nav class="menu"><a href="/one">One</a><a href="/two">Two</a><a href="/three">Three</a></nav>');
    echo 'Stylesheet SHA-256: ' . hash('sha256', $stylesheet) . "; markup passes: {$passes}\n";
    $assert(str_contains($stylesheet, '.wp-block-navigation.menu .wp-block-navigation__container.menu{width:auto;padding-left:0}'), 'The wrapper sizing reset is still emitted.');
    // Recorded from the unoptimized projection of this reduced page.
    $assert('ade765900977014b7441ee7d58833a36b50417691e78269e86bdefc4d9c6f457' === hash('sha256', $stylesheet), 'The complete emitted stylesheet is byte-identical to the baseline.');
    $assert(1 === $passes, 'One projection derives navigation roots once, not once per class rule (got ' . $passes . ').');

    [$withoutNavigation, $passes] = $compile('<p class="menu">Text</p>');
    $assert(1 === $passes, 'A subsequent projection without navigation scans markup only once.');
    $assert(! str_contains($withoutNavigation, '.wp-block-navigation__container.menu'), 'Navigation hosts do not leak between projections.');

    [$again, $passes] = $compile('<nav class="menu"><a href="/one">One</a><a href="/two">Two</a><a href="/three">Three</a></nav>');
    $assert(1 === $passes && $again === $stylesheet, 'A later projection derives its own roots and preserves the stylesheet.');
    echo "navigation-host-scope-projection: passed\n";
}
