<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixtures = array();
foreach (array(
    'neutral' => array('button,input,textarea,select{font:inherit}', ''),
    'equal-parent-and-nav' => array('button,input,textarea,select{font:inherit}', 'header nav{font-weight:400}'),
    'longhands' => array('button{font-weight:inherit;font-style:inherit;font-family:inherit}', ''),
    'no-inherit' => array('', ''),
    'conditional-parent' => array('button{font:inherit}', '@media(max-width:800px){header{font-weight:400;font-style:italic}}'),
) as $name => [$controlCss, $extraCss]) {
    // Neutral sibling-toggle repro from #7163; variations share the same page.
    $html = '<!doctype html><html><head><style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif}' . $controlCss . '
header{display:flex;justify-content:space-between;align-items:center;padding:20px 5%;background:#111;color:#fff}
header nav{display:flex;gap:31px;font-size:13px;font-weight:700}
.menu{display:none}
@media(max-width:800px){header nav{display:none}header nav.open{display:flex}.menu{display:block;background:none;color:#fff;border:0;font-size:28px}}
' . $extraCss . '</style></head><body>
<header><a class="brand" href="/">Brand</a><button class="menu" id="menu" aria-label="Open menu">☰</button>
<nav id="navlinks"><a href="/a">One</a><a href="/b">Two</a><a href="/c">Three</a></nav></header></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(array('path' => 'index.html', 'content' => $html)),
    ))->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && !str_contains($asset['path'], 'editor-static')), 'content'));
    preg_match('/<!-- wp:navigation (\{[^<]*?\}) (?:\/)?-->/', $result['serialized_blocks'], $match);
    $attrs = json_decode($match[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
    if ('☰' !== ($attrs['metadata']['blocksEngineNavigationOpener']['text'] ?? '')) throw new RuntimeException("$name: missing projected opener");
    preg_match_all('/[^{}]*>\.wp-block-navigation__responsive-container-open\{([^}]*)\}/', $css, $rules);
    $opener = implode(';', $rules[1]);
    if (str_contains($opener, 'font:inherit')) throw new RuntimeException("$name: opener still inherits from nav");
    if ('no-inherit' === $name) {
        if (!str_contains($opener, 'font:revert!important') || str_contains($opener, 'font-weight:400!important')) throw new RuntimeException('Unauthored font replay changed');
    } else {
        foreach (array('font-weight:400!important', 'font-style:' . ('conditional-parent' === $name ? 'italic' : 'normal') . '!important', 'font-family:Inter,Arial,sans-serif!important') as $declaration) {
            if (!str_contains($opener, $declaration)) throw new RuntimeException("$name: missing $declaration");
        }
    }
    $fixtures[$name] = array('source' => $html, 'css' => $css, 'classes' => $attrs['className']);
}
if (in_array('--browser-fixtures', $argv, true)) echo json_encode($fixtures, JSON_THROW_ON_ERROR);
else echo "navigation-opener-inherited-font: ok\n";
