<?php
declare(strict_types=1);

/**
 * Presentation recovered for one menu stays on that menu.
 *
 * A page can carry two copies of one menu that share their list classes but
 * not their cascade: an inline bar whose item boxes declare the type, and a
 * panel copy (a captured drawer or dialog) that inherits a different type from
 * its own ancestor. The inline bar's retained item boxes own its type, so it
 * must not also receive a nav-wide rule; the panel's recovered rule must name
 * the panel's own instance, not the list class the inline bar also carries.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$items = '<ul class="menu-list">'
    . '<li><div class="wrap"><div class="item"><a href="/about">About</a></div></div></li>'
    . '<li><div class="wrap"><div class="item"><a href="/work">Work</a></div></div></li>'
    . '<li><div class="wrap"><div class="item"><a href="/contact">Contact</a></div></div></li>'
    . '</ul>';
$html = '<style>'
    . '.menu .item{font:700 17px Georgia,serif;color:rgb(10,20,30)}'
    . '.panel nav{font:400 14px Arial,sans-serif;color:rgb(200,0,0)}'
    . '</style>'
    . '<header>'
    . '<div class="menu"><nav aria-label="Site">' . $items . '</nav></div>'
    . '<div class="panel"><nav aria-label="Site">' . $items . '</nav></div>'
    . '</header>'
    . '<main><p>Body</p></main>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($result['assets'] ?? null) ? $result['assets'] : array()
));

$classNames = array();
preg_match_all('/<!-- wp:navigation (\{[^\n]*?\}) \/?-->/', (string) ($result['serialized_blocks'] ?? ''), $matches);
foreach ( $matches[1] as $attributes ) {
    $decoded = json_decode($attributes, true);
    $classNames[] = preg_split('/\s+/', trim((string) ($decoded['className'] ?? ''))) ?: array();
}
$assert(2 === count($classNames), 'both menu copies become navigation blocks', (string) count($classNames));

// Nav-wide item rules: `.wp-block-navigation<.host...> .wp-block-navigation-item__content{...}`.
$navWide = array();
foreach ( explode('}', $css) as $chunk ) {
    if ( 1 === preg_match('/(?:^|[\s,}])\.wp-block-navigation((?:\.[A-Za-z0-9_-]+)+) \.wp-block-navigation-item__content\{([^}]*)$/', trim($chunk), $rule)
        && str_contains($rule[2], 'font')
    ) {
        $navWide[] = array('hosts' => array_values(array_filter(explode('.', $rule[1]))), 'declarations' => $rule[2], 'rule' => trim($chunk) . '}');
    }
}
$reaches = static function (array $rule, array $classes): bool {
    return array() === array_diff($rule['hosts'], $classes);
};

foreach ( $navWide as $rule ) {
    $assert(
        ! (isset($classNames[0]) && $reaches($rule, $classNames[0])),
        'the inline menu, whose item boxes own its type, receives no nav-wide recovered type',
        $rule['rule']
    );
}
$panelRules = array_values(array_filter($navWide, static fn (array $rule): bool => isset($classNames[1]) && $reaches($rule, $classNames[1])));
$assert(1 === count($panelRules), 'the panel copy keeps its own recovered type', implode("\n", array_column($navWide, 'rule')));
$assert(
    isset($panelRules[0]) && str_contains($panelRules[0]['declarations'], 'Arial') && str_contains($panelRules[0]['declarations'], 'font-size:14px'),
    'the panel copy\'s recovered rule carries the panel\'s inherited type',
    $panelRules[0]['rule'] ?? ''
);
$assert(
    str_contains($css, 'font:700 17px Georgia,serif'),
    'the inline menu\'s item box still carries its source type',
    ''
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation shared-class inherited presentation FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "navigation shared-class inherited presentation passed: {$passes} assertions\n");
