<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static function (string $html, string $css = ''): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html, 'style.css' => $css,
    )))->toArray();
};
$cssOf = static fn (array $result): string => implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_ends_with($asset['path'], '.css')), 'content'));
$fixture = dirname(__DIR__) . '/fixtures/table-width-hints/';
$result = $compile(file_get_contents($fixture . 'index.html'), file_get_contents($fixture . 'style.css'));
$markup = $result['serialized_blocks'];
$css = $cssOf($result);
$assert(1 === preg_match('/\b(blocks-engine-table-[a-z0-9-]+)/', $markup, $match), 'table has its existing isolated marker');
$marker = $match[1];
$assert(str_contains($css, ':where(.' . $marker . '>table){width:100%}'), '1000px: table retains its 100% width hint');
$assert(str_contains($css, '@layer blocks-engine-presentational-hints{'), 'hints occupy a low-priority layer so layered author rules also win');
foreach (array('thead', 'tbody') as $section) {
    foreach (array(1, 2) as $cell) {
        $assert(str_contains($css, ':where(.' . $marker . '>table>' . $section . '>tr:nth-child(1)>:nth-child(' . $cell . ')){width:50%}'), '1000px: first row cells retain equal 50% widths at zero specificity');
    }
}
$assert(preg_match('/@media\s*\(max-width:768px\)\{[^@]*width:auto/', $css) === 1, '500px: author reset remains conditional and overrides zero-specificity hints');
$assert(strpos($css, '{width:50%}') < strpos($css, '{width:auto}'), 'hint CSS is emitted before author CSS (including zero-specificity resets)');
$assert(! str_contains($markup, 'width=') && ! str_contains($markup, '<td style=') && str_contains($markup, '<!-- wp:table'), 'hints do not change core/table save markup');
$assert('pass' === (new Runtime())->validateBlockSerialization($markup)['status'], 'compiled table is editor-valid');

$extra = $compile('<main><table width="640"><colgroup><col width="25%"><col width="120" span="2"></colgroup><tbody><tr><td>A</td><td width="75%">B</td><td>C</td></tr><tr><td width="-1">D</td><td width="calc(1px)">E</td><td width="10; color:red">F</td></tr></tbody></table></main>');
$extraCss = $cssOf($extra);
$assert(str_contains($extraCss, '>table){width:640px}'), 'numeric table hints become px');
$assert(str_contains($extraCss, '>tr:nth-child(1)>:nth-child(1)){width:25%}') && str_contains($extraCss, '>tr:nth-child(1)>:nth-child(3)){width:120px}'), 'col widths and spans map to cells');
$assert(str_contains($extraCss, '>tr:nth-child(1)>:nth-child(2)){width:75%}'), 'cell hints take precedence over column fallback');
$assert(! str_contains($extraCss, 'width:-1') && ! str_contains($extraCss, 'width:calc') && ! str_contains($extraCss, 'color:red'), 'invalid hints are ignored');
foreach (array(' 42 ' => '42px', '12.5%' => '12.5%', '20px' => '20px', '.5' => '.5px', 'auto' => null, '1em' => null, '20%%' => null, '-20' => null) as $input => $expected) {
    $caseCss = $cssOf($compile('<main><table width="' . $input . '"><tr><th>A</th><th>B</th></tr><tr><td>C</td><td>D</td></tr></table></main>'));
    $assert(null === $expected ? ! str_contains($caseCss, 'blocks-engine-presentational-hints') : str_contains($caseCss, '>table){width:' . $expected . '}'), 'width hint validation: ' . $input);
}
fwrite(STDOUT, "Table width hint tests passed.\n");
