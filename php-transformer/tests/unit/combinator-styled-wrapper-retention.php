<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A wrapper styled only through a combinator (`.card>div`) has no class of its
// own: its rule is projected onto the source tag marker. Eliding the wrapper
// leaves that marker without a carrier and drops the rule's box.
$cases = array(
    'ratio frame around an image' => array(
        'css' => '.card{display:block}.card>div{overflow:hidden;aspect-ratio:16/9;background:#ddd}.card img{width:100%;height:100%;object-fit:cover}',
        'inner' => '<img src="a.webp" alt="A" width="1600" height="900">',
        'declaration' => 'aspect-ratio:16/9',
        'kept' => true,
    ),
    'padded panel around a paragraph' => array(
        'css' => '.card{display:block}.card>div{padding:20px;background:#ddd}',
        'inner' => '<p>Panel</p>',
        'declaration' => 'padding:20px',
        'kept' => true,
    ),
    'reset-only wrapper' => array(
        'css' => '.card{display:block}.card>div{margin:0;padding:0;display:block;position:relative}',
        'inner' => '<p>Panel</p>',
        'declaration' => null,
        'kept' => false,
    ),
);

foreach ($cases as $name => $case) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><title>t</title><style>' . $case['css'] . '</style></head><body><main>'
                . '<div class="grid"><a class="card" href="/x/"><div>' . $case['inner'] . '</div><p>Caption</p></a></div></main></body></html>',
        ),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        $css .= (string) ($asset['content'] ?? '');
    }
    // grid > card (> wrapper): the wrapper is the third group when retained.
    $groups = substr_count($markup, '<!-- wp:group');
    if (($case['kept'] ? 3 : 2) !== $groups) {
        throw new RuntimeException($name . ': ' . ($case['kept'] ? 'the combinator-styled wrapper was elided' : 'a reset-only wrapper must still be elided') . ': ' . $markup);
    }
    if ($case['kept']) {
        $wrapperOpen = strpos($markup, '<!-- wp:group', strpos($markup, '<!-- wp:group', strpos($markup, '<!-- wp:group') + 1) + 1);
        if (!preg_match('/blocks-engine-source-div-[0-9a-f]+-\d+/', substr($markup, (int) $wrapperOpen, 400), $marker)
            || !preg_match('/\.card>:where\(\.' . preg_quote($marker[0], '/') . '\)[^{]*\{[^}]*' . preg_quote((string) $case['declaration'], '/') . '/', $css)) {
            throw new RuntimeException($name . ': the projected wrapper rule does not reach the retained carrier: ' . $css);
        }
    }
    echo $name . ": ok\n";
}
