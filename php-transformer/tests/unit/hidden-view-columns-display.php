<?php
declare(strict_types=1);

/**
 * Regression coverage: a container the source hides at rest must not be
 * promoted to core/columns.
 *
 * Single-page apps ship inactive views as `.view{display:none}` and reveal
 * the active one with a state class. A view whose children are an `aside`
 * and a `main` reads like a sidebar/content split, but core/columns carries
 * its own `display:flex`, which beats the source hide and renders the
 * inactive view on the page. The view must stay a core/group like every
 * other hidden view, keeping its source classes so the hide still applies.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

/**
 * @return array{top: list<string>, serialized: string}
 */
$transform = static function (string $html, string $css): array {
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
    $top = array();
    foreach ( $result['blocks'] ?? array() as $block ) {
        $top[] = (string) ($block['blockName'] ?? '');
    }

    return array( 'top' => $top, 'serialized' => (string) ($result['serialized_blocks'] ?? '') );
};

$active = '<div id="home-view" class="view active"><h1>Home</h1></div>';
$split = '<div id="dashboard-view" class="view dash-view"%s><aside class="dash-side"><button>One</button><button>Two</button></aside><main class="dash-main"><h2>Dashboard</h2><p>Body</p></main></div>';
$stateCss = '.view.active{display:block}.dash-view.active{display:grid!important;grid-template-columns:230px 1fr}';

$hidden = array(
    'view hidden by a class rule with state-class reveals' => array( sprintf($split, ''), '.view{display:none}' . $stateCss ),
    'view hidden by a class rule only' => array( sprintf($split, ''), '.view{display:none}' ),
    'view hidden by an inline style' => array( sprintf($split, ' style="display:none"'), $stateCss ),
);

foreach ( $hidden as $label => [ $view, $css ] ) {
    $out = $transform($active . $view, $css);
    $assert(! str_contains($out['serialized'], '<!-- wp:columns'), $label . ': hidden view is not core/columns', 'top=' . implode(',', $out['top']));
    $assert(array( 'core/group', 'core/group' ) === $out['top'], $label . ': hidden view stays a core/group', 'top=' . implode(',', $out['top']));
    $assert((bool) preg_match('/<div id="dashboard-view" class="wp-block-group view dash-view[\s"]/', $out['serialized']), $label . ': hidden view keeps its source classes', substr($out['serialized'], 0, 300));
}

// Control: the same sidebar/content view without a base hide is still columns.
$out = $transform($active . sprintf($split, ''), $stateCss);
$assert(str_contains($out['serialized'], '<!-- wp:columns'), 'visible split view is still core/columns', 'top=' . implode(',', $out['top']));

if ( 0 < $failures ) {
    fwrite(STDERR, "Hidden view columns display contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Hidden view columns display contract passed: {$passes} assertions\n");
