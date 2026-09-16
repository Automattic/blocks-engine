<?php
declare(strict_types=1);

/**
 * WordPress copies a navigation block's classes onto the `nav` and its inner
 * container, so a source rule keyed on one of those classes matches twice.
 * Paint stacks on itself; a frame (padding plus rules) is charged twice and
 * doubles the menu's height. Both must be stated once.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compile = static function (string $html, string $css): string {
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html, 'styles.css' => $css ),
    ))->toArray();
    $out = '';
    foreach ( $result['source_reports']['compiled_site']['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

$containerRule = static function (string $css, string $class): string {
    foreach ( explode('}', $css) as $chunk ) {
        if ( str_contains($chunk, '.wp-block-navigation__container') && str_contains($chunk, '.' . $class . ' ') ) {
            return trim($chunk) . '}';
        }
    }
    return '';
};

$document = static fn (string $navClass): string => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main>'
    . '<nav class="' . $navClass . '" aria-label="Sections"><a href="#one">One</a><a href="#two">Two</a><a href="#three">Three</a></nav>'
    . '<section id="one"><h2>One</h2><p>First section copy.</p></section>'
    . '<section id="two"><h2>Two</h2><p>Second section copy.</p></section>'
    . '<section id="three"><h2>Three</h2><p>Third section copy.</p></section>'
    . '</main></body></html>';

// A tab-style jump nav states its own gutters and hairlines. The container must
// not charge them a second time.
$framed = $compile(
    $document('jumpnav'),
    '.jumpnav{display:flex;flex-wrap:wrap;gap:0;border-top:1px solid #ddd;border-bottom:1px solid #ddd;padding:0.4rem 0}.jumpnav a{padding:0.35rem 0.85rem;text-decoration:none}'
);
$framedRule = $containerRule($framed, 'jumpnav');
$assert('' !== $framedRule, 'a framed source menu records a container reset');
$assert(str_contains($framedRule, 'padding:0!important'), 'the container reset drops the duplicated menu padding');
$assert(str_contains($framedRule, 'border:0!important'), 'the container reset drops the duplicated menu rules');
$assert(! str_contains($framedRule, 'background:none!important'), 'an unpainted menu records no paint reset');

// Paint-only menus keep the original behavior.
$painted = $compile(
    $document('painted-nav'),
    '.painted-nav{display:flex;background:#123456;border-radius:8px}.painted-nav a{padding:0.5rem 1rem;color:#fff;text-decoration:none}'
);
$paintedRule = $containerRule($painted, 'painted-nav');
$assert(str_contains($paintedRule, 'background:none!important') && str_contains($paintedRule, 'box-shadow:none!important'), 'a painted source menu still records the paint reset');
$assert(! str_contains($paintedRule, 'padding:0!important'), 'a menu without its own frame records no frame reset');

// A menu that states both gets both, in one rule.
$both = $compile(
    $document('chrome-nav'),
    '.chrome-nav{display:flex;background:#fff;border-bottom:2px solid #000;padding:1rem 0}.chrome-nav a{padding:0.5rem 1rem;text-decoration:none}'
);
$bothRule = $containerRule($both, 'chrome-nav');
$assert(str_contains($bothRule, 'background:none!important') && str_contains($bothRule, 'padding:0!important') && str_contains($bothRule, 'border:0!important'), 'a painted and framed menu neutralizes both on the container');
$assert(1 === substr_count($both, '.chrome-nav .wp-block-navigation__container'), 'both resets share one container rule');

// A plain menu that states neither must not gain a reset at all.
$plain = $compile(
    $document('plain-nav'),
    '.plain-nav{display:flex;gap:1rem}.plain-nav a{color:#333;text-decoration:none}'
);
$assert('' === $containerRule($plain, 'plain-nav'), 'a menu that states no paint or frame records no container reset');

// Zero-valued declarations are not a frame.
$reset = $compile(
    $document('reset-nav'),
    '.reset-nav{display:flex;padding:0;border:0}.reset-nav a{color:#333;text-decoration:none}'
);
$assert('' === $containerRule($reset, 'reset-nav'), 'zero padding and border are not treated as a duplicated frame');

// The observed academic CV corpus case.
$cvDirectory = dirname(__DIR__, 3) . '/fixtures/websites/31-personal-cv-academic';
$cv = $compile((string) file_get_contents($cvDirectory . '/index.html'), (string) file_get_contents($cvDirectory . '/styles.css'));
$cvRule = $containerRule($cv, 'jumpnav');
$assert(str_contains($cvRule, 'padding:0!important') && str_contains($cvRule, 'border:0!important'), 'academic CV jump nav neutralizes its duplicated frame on the container');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation container frame reset tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Navigation container frame reset tests: {$passes} passed" . PHP_EOL);
