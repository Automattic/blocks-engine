<?php
declare(strict_types=1);

// A context rule restating the root font (`.banner{font-family:var(--mono)}`,
// `.card .kicker{...}`, where --mono is the body font) pins its text to the
// imported font: a Global Styles font change never reaches it. When no other
// author font can sit between that text and body, the rule yields the font
// to Global Styles, keeping it only for elements that do not inherit fonts.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compile = static function (string $css): array {
    $html = '<!doctype html><html><head><style>:root{--mono:"Helvetica Neue",Helvetica,Arial,sans-serif}'
        . 'body{font-family:var(--mono);color:#171717;background:#ede8e3}code{font-family:var(--mono)}' . $css . '</style></head>'
        . '<body><main><div class="banner"><p>Concept mockup</p></div><article class="card"><p class="kicker">This week</p><p>Run <code>npm test</code></p></article></main></body></html>';
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
    $theme = null;
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
    }
    return array(implode("\n", array_column($plan['assets'], 'content')), $theme);
};
// Selectors whose body declares font-family for every element they match.
$unconditionalFonts = static function (string $css, string $class): array {
    preg_match_all('/([^{}]*\.' . $class . '\b[^{}]*)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $pinned = array();
    foreach ($rules as [, $selector, $body]) {
        if (str_contains($body, 'font-family') && !str_contains($selector, ':where(h1')) $pinned[] = trim($selector);
    }
    return $pinned;
};

[$css, $theme] = $compile('.banner{color:#e8e2d8;background:#101010;font-family:var(--mono);letter-spacing:.2em}.card .kicker{color:#d9381c;font-family:var(--mono);text-transform:uppercase}');
$assert(is_array($theme) && 'var:preset|font-family|body' === ($theme['styles']['typography']['fontFamily'] ?? null), 'The body font is the root Global Styles default');
$assert(array() === $unconditionalFonts($css, 'banner'), 'A class restating the root font yields it to Global Styles: ' . json_encode($unconditionalFonts($css, 'banner')));
$assert(array() === $unconditionalFonts($css, 'kicker'), 'A context rule restating the root font yields it to Global Styles: ' . json_encode($unconditionalFonts($css, 'kicker')));
// Form controls render their own font and keep the class font; `code`
// already renders the root font through its own rule, which stays.
$assert(1 === preg_match('/\.banner:where\(([^)]*)\)[^{]*\{font-family:/', $css, $keepers) && str_contains($keepers[1], 'textarea') && !str_contains($keepers[1], 'code'), 'Only elements that would not inherit the font keep the class font: ' . ($keepers[1] ?? ''));
$assert(1 === preg_match('/(?:^|[}\s])code[^{]*\{font-family:/', $css), 'The rule giving code the root font stays');
$assert(str_contains($css, 'letter-spacing:.2em') && str_contains($css, 'text-transform:uppercase'), 'The rules keep their other declarations');

// Another author font could reach the text by inheritance: keep the rule.
[$css] = $compile('.banner{color:#e8e2d8;background:#101010;font-family:var(--mono)}.card .kicker{font-family:var(--mono)}main{font-family:Georgia,serif}');
$assert(array() !== $unconditionalFonts($css, 'kicker'), 'A context rule keeps the root font when another author font can be inherited');

echo "Root font restatement yields to Global Styles passed\n";
