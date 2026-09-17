<?php
declare(strict_types=1);

/**
 * Contract for keeping the band an authored menu item reserved once its
 * wrapper chain collapses into navigation links.
 *
 * core/navigation takes navigation-link children, so the wrappers a source item
 * sat inside cannot survive. In a mesh-authored menu those wrappers carry the
 * item's vertical placement: a repeater stamps one template per row and the
 * designer's drag position inside the row compiles to block-axis margins. Drop
 * them and the menu loses its y coordinate, not merely some padding — it jumps
 * to the top of its landmark.
 *
 * core/navigation supports blockGap and no padding, so the band is projected
 * onto the rendered host behind a marker that states the band it carries.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

/** @return array{markup: string, css: string} */
$transform = static function (string $itemCss, string $itemMarkup = ''): array {
    $item = static function (string $id, string $href, string $label) use ($itemMarkup): string {
        $inner = '' !== $itemMarkup
            ? str_replace(array('{id}', '{href}', '{label}'), array($id, $href, $label), $itemMarkup)
            : '<div role="listitem" class="gutter"><div id="' . $id . '" class="cell"><h1><a href="' . $href . '">' . $label . '</a></h1></div></div>';
        return '<div class="row">' . $inner . '</div>';
    };
    $header = '<header id="SITE_HEADER"><h1><a href="/">Studio</a></h1>'
        . '<div id="rep" class="wixui-repeater"><div role="list" class="menu">'
        . $item('i1', '/work', 'Work')
        . $item('i2', '/about', 'About')
        . $item('i3', '/resume', 'Resume')
        . $item('i4', '/contact', 'Contact')
        . '</div></div></header>';
    $result = (new HtmlTransformer())->transform(
        '<!doctype html><html><body>' . $header . '<main><h2>Home</h2></main></body></html>',
        array('source' => 'index.html', 'static_css' => '.menu{display:flex;position:relative}.row{display:flex}' . $itemCss)
    );
    $css = '';
    foreach ((is_array($result->toArray()['assets'] ?? null) ? $result->toArray()['assets'] : array()) as $asset) {
        if (is_array($asset)) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    return array('markup' => $result->serializedBlocks, 'css' => $css);
};

// A repeater stamps identical rows, so the wrappers agree by construction.
$banded = $transform('.gutter{margin:3px 0}.cell{margin:28px 0 30px;position:relative}');
$assert(
    str_contains($banded['markup'], 'blocks-engine-navigation-band-t31-b33'),
    'The collapsed chain\'s block-axis margins mark the navigation host.'
);
$assert(
    str_contains($banded['css'], '.wp-block-navigation.blocks-engine-navigation-band-t31-b33{padding-top:31px;padding-bottom:33px}'),
    'The band is projected onto the rendered host, scoped to that marker.'
);

// A shorthand that only states the inline axis reserves no band.
$inlineOnly = $transform('.gutter{margin:0 3px}.cell{margin:0 12px;position:relative}');
$assert(
    ! str_contains($inlineOnly['markup'], 'blocks-engine-navigation-band-'),
    'Inline-axis margins alone reserve no band.'
);

// Rows that disagree are not one authored band.
$mismatched = $transform('.gutter{margin:3px 0}.cell{margin:28px 0 30px}#i3.cell{margin:10px 0 4px}');
$assert(
    ! str_contains($mismatched['markup'], 'blocks-engine-navigation-band-'),
    'Rows that disagree on their band are left alone.'
);

// A length this cannot state in pixels is not restated at all.
$relative = $transform('.gutter{margin:3px 0}.cell{margin:2em 0 3em;position:relative}');
$assert(
    ! str_contains($relative['markup'], 'blocks-engine-navigation-band-'),
    'A band that is not a resolved pixel length is not projected.'
);

// An anchor authored directly in the menu never had a chain to collapse.
$direct = (new HtmlTransformer())->transform(
    '<!doctype html><html><body><header id="SITE_HEADER"><h1><a href="/">Studio</a></h1>'
        . '<nav class="menu"><a href="/work">Work</a><a href="/about">About</a><a href="/resume">Resume</a></nav>'
        . '</header><main><h2>Home</h2></main></body></html>',
    array('source' => 'index.html', 'static_css' => '.menu{display:flex;margin:28px 0 30px}')
);
$assert(
    ! str_contains($direct->serializedBlocks, 'blocks-engine-navigation-band-'),
    'A directly authored anchor list reserves no collapsed band.'
);

if (0 < $failures) {
    fwrite(STDERR, sprintf('navigation collapsed item band: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('navigation collapsed item band tests: %d passed%s', $passes, PHP_EOL));
