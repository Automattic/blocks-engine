<?php
declare(strict_types=1);

// A `font` shorthand that substitutes a custom property cannot be expanded
// when the stylesheet is parsed, yet it resets the family its element and
// every descendant would otherwise inherit. Design usage must not report
// those headings in the body family, or theme.json gives every heading the
// body font and the element default overrides the inherited display face.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$headingFamily = static function (string $css): ?string {
    $html = '<!doctype html><html><head><style>@font-face{font-family:display;src:url(display.woff2)}' . $css . '</style></head><body><main>'
        . '<div class="title"><h1>A prominent page title</h1></div>'
        . '<p>Body copy long enough to be the dominant text on the page, read in the body family.</p>'
        . '<div class="subtitle"><h2>A section heading</h2></div>'
        . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    foreach ($result['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        return $theme['styles']['elements']['heading']['typography']['fontFamily'] ?? null;
    }
    return null;
};

$base = 'body{font-family:Arial,sans-serif}';

// Builder shape: the type token's size is a runtime calculation, so the
// family it substitutes cannot be read statically, and a breakpoint
// redefines the token. The headings' family is unknown, not the body's.
$runtime = $base . ':root{--heading-font:normal normal 900 max(0.5px, 0.06 * (var(--scale) - var(--gutter)))/1em display}'
    . '@media (max-width:1023px){:root{--heading-font:normal normal 900 max(0.5px, 0.08 * (var(--scale) - var(--gutter)))/1em display}}'
    . '.title,.subtitle{font:var(--heading-font)}';
$assert('var:preset|font-family|body' !== $headingFamily($runtime), 'unresolvable font shorthand: headings are not given the body family');

// Same token without the breakpoint: still unreadable, still not the body.
$static = $base . ':root{--heading-font:normal normal 900 max(0.5px, 0.06 * (var(--scale) - var(--gutter)))/1em display}.title,.subtitle{font:var(--heading-font)}';
$assert('var:preset|font-family|body' !== $headingFamily($static), 'unresolvable static font shorthand: headings are not given the body family');

// A token that substitutes to a plain shorthand states its family.
$resolvable = $base . ':root{--heading-font:900 40px/1em display}.title,.subtitle{font:var(--heading-font)}';
$family = $headingFamily($resolvable);
$assert(null !== $family && 'var:preset|font-family|body' !== $family, 'resolvable font shorthand: headings read the substituted family: ' . var_export($family, true));

// Within one rule the later declaration decides the family.
$assert('var:preset|font-family|body' === $headingFamily($base . ':root{--heading-font:900 40px/1em display}.title,.subtitle{font:var(--heading-font);font-family:Arial,sans-serif}'), 'a later font-family longhand overrides the pending shorthand');
$assert($family === $headingFamily($base . ':root{--heading-font:900 40px/1em display}.title,.subtitle{font-family:Arial,sans-serif;font:var(--heading-font)}'), 'a later pending shorthand overrides the font-family longhand');

// A more specific longhand outranks the shorthand across rules.
$assert('var:preset|font-family|body' === $headingFamily($base . ':root{--heading-font:900 40px/1em display}.title,.subtitle{font:var(--heading-font)}main .title,main .subtitle{font-family:Arial,sans-serif}'), 'a more specific font-family longhand outranks the pending shorthand');

if ($failures > 0) exit(1);
echo "Design usage font shorthand family passed\n";
