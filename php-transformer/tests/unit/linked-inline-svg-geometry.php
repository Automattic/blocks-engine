<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An inline source SVG keeps its line-box geometry when it becomes a
// core/image: the figure's inline geometry carrier restores `display:inline`
// and the source's vertical alignment. Three things decide whether that
// carrier actually renders:
// - core/image wraps a linked image in `<a>`, so the rule has to reach
//   `figure > a > img` as well as `figure > img`;
// - the generic author image bridge
//   (`:where(figure):not(<type>).wp-block-image > a > img{display:block}`,
//   (0,1,3)) must not outrank it;
// - an author `img,svg{vertical-align:middle}` centers the icon on the line,
//   and core/image's `vertical-align:bottom` must not replace it.
// A browser otherwise gives the linked badge a block image inside an
// inline-block anchor, and the row grows by the line's descent (28px -> 35px).
$svg = '<svg width="145" height="26" viewBox="0 0 145 26" fill="none"><path d="M0 0h145v26H0z" fill="#000"></path></svg>';

/** @return array{figure: string, css: string} */
$compile = static function (string $styles, string $body): array {
    $source = $styles . '<main><p>Body</p></main><footer>' . $body . '</footer>';
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $output = stripslashes(json_encode($compiled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if ( ! preg_match('/<figure class="[^"]*(be-inline-geometry-[a-f0-9]+)[^"]*">/', $output, $figure) ) {
        throw new RuntimeException("Fixture must lower the inline SVG to a core/image with an inline geometry carrier.\n");
    }
    preg_match_all('/[^{}]*' . preg_quote($figure[1], '/') . '[^{}]*\{[^}]*\}/', $output, $rules);

    return array( 'class' => $figure[1], 'css' => implode("\n", array_unique(array_map('trim', $rules[0]))) );
};

$assertions = 0;
$assert = static function (bool $condition, string $message, string $css) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message . "\n" . $css);
    }
};

$styles = '<style>img,svg{vertical-align:middle}.badge{display:flex}.logo{display:block}</style>';
$linked = $compile($styles, '<a class="badge" href="https://example.com/"><span class="logo">' . $svg . '</span></a>');
$class = preg_quote($linked['class'], '/');
$assert(
    1 === preg_match('/\.wp-block-image\.' . $class . '>a>img[,{]/', $linked['css']),
    'the inline geometry rule must reach a linked image (figure > a > img)',
    $linked['css']
);
$assert(
    1 === preg_match('/\.wp-block-image\.' . $class . '>img[,{]/', $linked['css']),
    'the inline geometry rule must carry the native wrapper class to outrank the author image bridge',
    $linked['css']
);
$assert(
    1 === preg_match('/>img\{display:inline;vertical-align:middle[;}]/', $linked['css']),
    'the inline image must keep the author vertical-align the source SVG had',
    $linked['css']
);

$unlinked = $compile($styles, '<div class="badge"><span class="logo">' . $svg . '</span></div>');
$assert(
    1 === preg_match('/\.wp-block-image\.' . preg_quote($unlinked['class'], '/') . '>img[,{]/', $unlinked['css'])
        && str_contains($unlinked['css'], '{display:inline;vertical-align:middle'),
    'an unlinked inline SVG keeps the same weighted inline geometry',
    $unlinked['css']
);

$plain = $compile('<style>.badge{display:flex}.logo{display:block}</style>', '<a class="badge" href="https://example.com/"><span class="logo">' . $svg . '</span></a>');
$assert(
    str_contains($plain['css'], '{display:inline;vertical-align:baseline'),
    'without an authored vertical-align the inline image stays on the baseline',
    $plain['css']
);

$unsafe = $compile('<style>svg{vertical-align:var(--x)}.badge{display:flex}.logo{display:block}</style>', '<a class="badge" href="https://example.com/"><span class="logo">' . $svg . '</span></a>');
$assert(
    str_contains($unsafe['css'], '{display:inline;vertical-align:baseline'),
    'an unresolvable vertical-align falls back to baseline',
    $unsafe['css']
);

echo "Linked inline SVG geometry passed: {$assertions} assertions\n";
