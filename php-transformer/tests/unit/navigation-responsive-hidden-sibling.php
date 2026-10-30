<?php
declare(strict_types=1);

/**
 * A sibling of a lowered link list that a base rule hides and a media query
 * shows renders at some viewport. It must survive navigation lowering with
 * its responsive visibility, exactly like the min-width spelling of the same
 * visibility. Only a sibling hidden at every viewport is inert support.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$paragraph = '<p class="mf">Mobile text</p>';
$jumpMenu = '<form class="mf" action="/" method="post"><select><option value="/a">A</option><option value="/b">B</option></select></form>';
$hiddenBase = '.mf{display:none}@media(max-width:767px){.mf{display:block}}';

// name => [sibling markup, css, emitted tag (null = dropped), expected display at 1280px and 767px]
$rows = array(
    'hidden base shown under max-width' => array($paragraph, $hiddenBase, 'p', array(1280 => 'none', 767 => 'block')),
    'hidden under min-width' => array($paragraph, '@media(min-width:768px){.mf{display:none}}', 'p', array(1280 => 'none', 767 => '')),
    'always hidden' => array($paragraph, '.mf{display:none}', null, array()),
    'hidden base jump menu shown under max-width' => array($jumpMenu, $hiddenBase, 'select', array(1280 => 'none', 767 => 'block')),
);

/** The last applicable author display for the element at a viewport width; '' when none applies. */
$displayAt = static function (array $rules, DOMElement $element, int $width): string {
    $display = '';
    foreach ( $rules as list($prelude, $body, $conditions) ) {
        if ( ! preg_match('/(?:^|;)\s*display\s*:\s*([^;]+)/i', $body, $value) ) {
            continue;
        }
        foreach ( $conditions as $condition ) {
            if ( preg_match('/max-width\s*:\s*(\d+)px/i', $condition, $max) && $width > (int) $max[1] ) continue 2;
            if ( preg_match('/min-width\s*:\s*(\d+)px/i', $condition, $min) && $width < (int) $min[1] ) continue 2;
        }
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            if ( CssSelectorMatcher::matches($element, CssSelectorMatcher::parse(trim($selector)))['matches'] ?? false ) {
                $display = strtolower(trim($value[1]));
                break;
            }
        }
    }
    return $display;
};

foreach ( $rows as $name => list($sibling, $css, $tag, $displays) ) {
    $html = '<link rel="stylesheet" href="style.css"><main><section class="bar"><ul class="nav"><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul>'
        . $sibling . '</section><p>x</p></main>';
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array('website/index.html' => $html, 'website/style.css' => $css),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');

    $assert(1 === substr_count($markup, '<!-- wp:navigation '), $name . ': the link list lowers to one core/navigation', $markup);
    $assert(str_contains($markup, '"url":"/about/"'), $name . ': the navigation keeps its links', $markup);

    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $markup . '</body>');
    $emitted = null;
    foreach ( null === $tag ? array() : $document->getElementsByTagName($tag) as $candidate ) {
        if ( 'select' === $tag || str_contains($candidate->textContent, 'Mobile text') ) {
            $emitted = $candidate;
            break;
        }
    }
    if ( null === $tag ) {
        $assert(! str_contains($markup, 'Mobile text'), $name . ': a sibling hidden at every viewport stays inert support', $markup);
        continue;
    }
    $assert($emitted instanceof DOMElement, $name . ': the responsive sibling is emitted', $markup);
    if ( ! $emitted instanceof DOMElement ) {
        continue;
    }
    if ( 'select' === $tag ) {
        $assert(2 === $emitted->getElementsByTagName('option')->length, $name . ': the jump menu keeps its options', $markup);
        $emitted = $emitted->parentNode;
    }

    $rules = array();
    foreach ( $result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? '') ) {
            continue;
        }
        ( new CssStylesheetTransformer() )->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $conditions = array()) use (&$rules): void {
            $rules[] = array($prelude, $body, $conditions);
        });
    }
    foreach ( $displays as $width => $expected ) {
        $actual = $displayAt($rules, $emitted, $width);
        $assert($expected === $actual, $name . ': emitted CSS display at ' . $width . 'px', 'expected "' . $expected . '", got "' . $actual . '"');
    }
}

if ( 0 !== $failures ) {
    exit(1);
}
echo "Navigation responsive hidden sibling passed\n";
