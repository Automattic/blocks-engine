<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$transformer = new CssStylesheetTransformer();
// Inserting a same-body rule must not consume the next unchanged rule.
// Exercise root anchors, body anchors, nested contexts and real differences.
foreach (array('body', ':root', 'html') as $anchor) {
    foreach (array('', '@media(min-width:1px)') as $context) {
        $common = '';
        for ($i = 0; $i < 20; ++$i) $common .= $anchor . ' .item-' . $i . '{outline:none}';
        $wrap = static fn(string $css): string => '' === $context ? $css : $context . '{' . $css . '}';
        $base = $wrap($common . '.different{color:red}');
        $variant = $wrap('.inserted{outline:none}' . $common . '.different{color:blue}');
        $result = $transformer->scopeRouteVariants(array($base), array('a' => array($base), 'b' => array($variant)));
        $css = $result['stylesheet'];
        $assert(!$result['reasons'], 'route reconciliation is safe');
        $assert(str_contains($css, $common), $anchor . ': unchanged rules remain contiguous and unguarded');
        $assert(1 === substr_count($css, $anchor . ' .item-0{'), 'common rule emitted exactly once');
        $assert(str_contains($css, 'be-r-') && str_contains($css, 'color:blue'), 'actual difference retains guards');
        echo $anchor . ' ' . ($context ?: 'top-level') . ': ' . strlen($css) . " bytes\n";
    }
}
// End-to-end repeated subjects share their emitted target class. The links
// differ in text/URL but not in their authored focus presentation.
foreach (array(2, 24, 80) as $count) {
    $links = '';
    for ($i = 0; $i < $count; ++$i) $links .= '<li><a href="/target-' . $i . '">Item ' . $i . '</a></li>';
    $source = 'nav li a:focus{outline:none}';
    $files = array(array('path' => 'site.css', 'kind' => 'css', 'content' => $source));
    foreach (array('alpha', 'beta', 'gamma') as $i => $root) {
        $files[] = array('path' => $i ? 'page-' . $i . '.html' : 'index.html', 'kind' => 'html', 'content' => '<html class="' . $root . '"><head><link rel="stylesheet" href="site.css"></head><body class="' . $root . '"><header><nav><ul>' . $links . '</ul></nav></header><main><p>Copy</p></main></body></html>');
    }
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $css = array_column($result['assets'], 'content', 'path')['site.css'] ?? '';
    $assert('' !== $css && str_contains($css, 'navigation-instance-'), 'fixture exercises per-instance projection');
    $assert(!str_contains($css, 'nth-child'), 'no per-instance position enumeration');
    $assert(1 === substr_count($css, 'outline:none'), 'focus rule emitted once');
    $assert(strlen($css) < 512, 'repeated targets use a constant-size shared selector');
    echo 'navigation ' . $count . ': ' . strlen($css) . " bytes\n";
}
// A positional source rule makes otherwise identical links distinct subjects.
// Preserve those per-item addresses and verify the compiled cascade, including
// retained source wrappers, rather than merely checking selector spelling.
$source = '<html><head><style>nav li a:focus{outline:none}nav li:nth-child(2) a{color:blue}nav li:nth-child(1) a{color:red}.label{font-size:18px}</style></head><body><nav><ul><li><div class="label"><a href="/a">A</a></div></li><li><div class="label"><a href="/b">B</a></div></li></ul></nav></body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$html = \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']);
$dom = new DOMDocument();
@$dom->loadHTML($html);
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$cascade = new \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade($dom, $css);
$colors = array();
foreach ($dom->getElementsByTagName('a') as $link) {
    $style = $cascade->resolve($link, array('color', 'font-size'), array('font-size'));
    $colors[] = $style['color'] ?? '';

}
$assert(str_contains($result['serialized_blocks'], '"boxes":[{"tag":"div"') && str_contains($css, '.wp-block-navigation-item>div') && str_contains($css, 'font-size:18px'), 'shared typography stays on retained label wrappers');
$assert(array('red', 'blue') === $colors, 'different source styling retains separate per-item targets');
$assert(str_contains($css, 'nth-child(1)') && str_contains($css, 'nth-child(2)'), 'only distinct subjects retain positional addresses');
exit($failures ? 1 : 0);
