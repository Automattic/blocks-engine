<?php
declare(strict_types=1);

/**
 * Every declaration in an element's inline `style` renders after conversion
 * (#2974). A declaration with a block support maps to that support; every
 * other declaration (`backdrop-filter`, `transform`, `filter`, ...) rides the
 * element's engine-owned carrier rule, the same generated stylesheet that
 * restates inline geometry. The inline `style` attribute is never passed
 * through raw.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @return array{markup: string, css: string} */
$compile = static function (string $body): array {
    $plan = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'website/index.html',
        'files'      => array(
            'website/index.html' => array(
                'path'    => 'website/index.html',
                'content' => '<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>',
            ),
        ),
    ))->toWordPressSitePlanView()['wordpress_site_plan'];

    $css = '';
    foreach ( $plan['writes'] as $write ) {
        if ( str_ends_with((string) $write['target_path'], '.css') ) {
            $data = (string) $write['payload']['data'];
            $css .= ('base64' === $write['payload']['encoding'] ? (string) base64_decode($data, true) : $data) . "\n";
        }
    }

    $markup = '';
    foreach ( array_merge($plan['pages'], $plan['template_parts']) as $entity ) {
        $markup .= (string) ($entity['canonical_block_markup'] ?? $entity['content'] ?? '') . "\n";
    }

    return array( 'markup' => $markup, 'css' => $css );
};

/** Declarations of every theme CSS rule whose selector targets one of the element's classes. */
$rulesFor = static function (string $css, string $classes): string {
    $bodies = array();
    foreach ( preg_split('/\s+/', trim($classes)) ?: array() as $class ) {
        if ( '' === $class || ! preg_match_all('/[^{}]*\.' . preg_quote($class, '/') . '(?![\w-])[^{}]*\{([^}]*)\}/', $css, $matches) ) {
            continue;
        }
        array_push($bodies, ...$matches[1]);
    }

    return implode(';', $bodies);
};

/** The class attribute of the first core/group wrapper whose class list matches `$pattern`. */
$groupClasses = static function (string $markup, string $pattern = ''): string {
    preg_match_all('/<(?:div|header|section|main)\b[^>]*\bclass="(wp-block-group\b[^"]*)"/', $markup, $matches);
    foreach ( $matches[1] as $classes ) {
        if ( '' === $pattern || preg_match($pattern, $classes) ) {
            return $classes;
        }
    }

    return '';
};

// 1. Supported and unsupported declarations on one inline style.
$mixed = $compile('<main><div style="background: red; backdrop-filter: blur(14px); transform: rotate(2deg)"><p>a</p></div></main>');
$classes = $groupClasses($mixed['markup'], '/\bhas-background\b/');
$assert('' !== $classes, 'the styled div converts to a group with its background support', $mixed['markup']);
$rules = $rulesFor($mixed['css'], $classes);
$assert(1 === preg_match('/(?:^|;)\s*backdrop-filter:\s*blur\(14px\)/', $rules), 'backdrop-filter: blur(14px) applies to the converted group', $rules);
$assert(1 === preg_match('/(?:^|;)\s*transform:\s*rotate\(2deg\)/', $rules), 'transform: rotate(2deg) applies to the converted group', $rules);
$assert(1 === preg_match('/"(?:backgroundColor|style)"\s*:/', $mixed['markup']) && str_contains($mixed['markup'], 'has-background'), 'background still maps to the block background support', $mixed['markup']);
$assert(! preg_match('/(?:^|;)\s*background(?:-color)?\s*:/', $rules), 'the supported background is not duplicated in the carrier rule', $rules);
$assert(! preg_match('/<[^>]+\bstyle="[^"]*(?:backdrop|rotate)/', $mixed['markup']), 'no raw inline style passthrough carries the unsupported declarations', $mixed['markup']);

// 2. An inline backdrop-filter on a nested header with no other styling.
$header = $compile('<main><div><header style="backdrop-filter: blur(14px)"><p>a</p></header><main><p>b</p></main></div></main>');
$rules = '';
preg_match_all('/class="([^"]*\bbe-inline-geometry-[a-f0-9]+[^"]*)"/', $header['markup'], $carriers);
foreach ( $carriers[1] as $carrierClasses ) {
    $rules .= $rulesFor($header['css'], $carrierClasses) . ';';
}
$assert(1 === preg_match('/(?:^|;)\s*backdrop-filter:\s*blur\(14px\)/', $rules), 'a nested header keeps its inline backdrop-filter', $header['markup'] . "\n" . $header['css']);

// 3. The class-rule variant still renders through the author stylesheet.
$classRule = $compile('<style>.bf{backdrop-filter:blur(14px)}</style><main><div class="bf"><p>a</p></div></main>');
$classes = $groupClasses($classRule['markup'], '/\bbf\b/');
$assert(1 === preg_match('/(?:^|;)\s*backdrop-filter:\s*blur\(14px\)/', $rulesFor($classRule['css'], $classes)), 'the class-rule variant keeps backdrop-filter', $classRule['css']);

// 4. A supported border colour maps to the border support only.
$border = $compile('<main><div style="border: 2px solid #123456; filter: blur(2px)"><p>a</p></div></main>');
$classes = $groupClasses($border['markup'], '/\bhas-border-color\b|\bbe-inline-geometry-/');
$rules = $rulesFor($border['css'], $classes);
$assert(1 === preg_match('/(?:^|;)\s*filter:\s*blur\(2px\)/', $rules), 'filter: blur(2px) applies to the converted group', $rules);
$assert(1 === preg_match('/"border"\s*:/', $border['markup']), 'the inline border maps to the border support', $border['markup']);
$assert(! preg_match('/(?:^|;)\s*border(?:-[a-z]+)*\s*:/', $rules), 'the supported border is not duplicated in the carrier rule', $rules);

fwrite(STDOUT, sprintf("Inline unsupported declaration carrier: %d passed, %d failed\n", $passes, $failures));
exit($failures > 0 ? 1 : 0);
