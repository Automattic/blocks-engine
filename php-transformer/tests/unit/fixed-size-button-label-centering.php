<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return $css;
};

$nest = static function (string $inner, int $depth): string {
    for ( $i = 0; $i < $depth; ++$i ) {
        $inner = '<div class="shell-' . $i . '">' . $inner . '</div>';
    }

    return $inner;
};

$style = '<style>'
    . '.cards{display:grid;grid-template-columns:1fr 1fr;gap:16px}'
    . '.body{display:flex;flex-direction:column;height:100%}'
    . '.copy{display:flex;flex-direction:column;flex-grow:1;height:100%;justify-content:space-between}'
    . '.cta{display:inline-flex;align-items:center;justify-content:center;width:160px;height:42px;background:#111;color:#fff}'
    . '.label{display:block;margin:auto;padding:6px 16px}'
    . '.line{display:inline-block;width:160px;height:42px;text-align:center;line-height:42px;background:#111;color:#fff}'
    . '</style>';
$cards = '<ul class="cards">'
    . '<li class="card"><div class="body"><div class="copy"><p>Short</p><a class="cta" href="/a"><span class="label">Item 1</span></a></div></div></li>'
    . '<li class="card"><div class="body"><div class="copy"><p>A longer title that wraps onto a second line</p><a class="line" href="/b">Item 2</a></div></div></li>'
    . '</ul><img src="photo.jpg" alt="Photo">';
$result = ( new HtmlTransformer() )->transform($style . '<section><main>' . $nest($cards, 22) . '</main></section>')->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = $cssOf($result);

$assert(str_contains($markup, 'responsive-layout'), 'deep card grid is preserved as captured layout markup');
$assert(
    1 === preg_match('/\.body\{[^}]*height:100%/', $css) && ! preg_match('/\.body\{[^}]*height:auto/', $css),
    'a card body that fills a stretched grid item keeps height:100% so sibling buttons share a baseline'
);
$assert(
    1 === preg_match('/\.copy\{[^}]*height:100%/', $css) && ! preg_match('/\.copy\{[^}]*height:auto/', $css),
    'the card copy column keeps height:100% so space-between pins the button to the card baseline'
);

$content = '';
if ( preg_match('/"content":"(.*)"\s*}/s', $markup, $contentMatch) ) {
    $content = json_decode('"' . $contentMatch[1] . '"') ?? '';
    if ( ! is_string($content) ) {
        $content = '';
    }
}
$assert('' !== $content && str_contains($content, 'Item 1'), 'captured layout keeps the fixed-size link label');

$labelClass = '';
if ( preg_match('/<span class="([^"]*\blabel\b[^"]*)"/', $content, $labelMatch) ) {
    $labelClass = $labelMatch[1];
}
$marginRule = '';
if ( preg_match('/[^{}]*\{[^}]*margin:auto[^}]*\}/', $css, $marginMatch) ) {
    $marginRule = $marginMatch[0];
}
$markerOnLabel = false;
if ( preg_match_all('/blocks-engine-semantic-[a-f0-9]+-\d+/', $marginRule, $markerMatches) ) {
    foreach ( $markerMatches[0] as $marker ) {
        if ( str_contains($labelClass, $marker) ) {
            $markerOnLabel = true;
            break;
        }
    }
}
$classRuleMatchesLabel = str_contains($marginRule, '.label') && str_contains($labelClass, 'label');
$assert(
    $markerOnLabel || $classRuleMatchesLabel,
    'flex-centered label margin:auto still matches the emitted label, got class [' . $labelClass . '] rule [' . substr($marginRule, 0, 240) . ']'
);
$assert(
    1 === preg_match('/\.cta\{[^}]*align-items:center[^}]*justify-content:center/', $css)
    && 1 === preg_match('/class="[^"]*\bcta\b/', $content),
    'a fixed-size flex link keeps align-items and justify-content on a selector that matches the emitted control'
);
$assert(
    1 === preg_match('/\.line\{[^}]*text-align:center[^}]*line-height:42px/', $css)
    && 1 === preg_match('/class="[^"]*\bline\b/', $content),
    'a fixed-size link centered with text-align and line-height keeps both declarations on the emitted control'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Fixed-size button label centering: {$failures} failed\n");
    exit(1);
}

echo "Fixed-size button label centering passed\n";
