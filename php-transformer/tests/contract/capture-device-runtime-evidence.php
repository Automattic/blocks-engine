<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Issue #4494's two-page footer, with only script ownership varied.
foreach (array('capture' => ' data-blocks-engine-capture-device-body=""', 'author' => '') as $case => $marker) {
    $files = array();
    foreach (array('index.html' => 'Home', 'coffee-tables/index.html' => 'Coffee tables') as $path => $title) {
        $footer = '© 2026 Example Co.' . ('Home' === $title ? '' : ' (coffee)');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head><body><script' . $marker . '>document.querySelectorAll(\'[data-blocks-engine-capture-device-inline]\');</script>';
        foreach (array('desktop' => '18', 'mobile' => '15') as $device => $size) {
            $html .= '<div data-blocks-engine-capture-device-document="' . $device . '"><header><nav><a href="/">Home</a> <a href="/coffee-tables/">Coffee tables</a></nav></header><main><h1>' . $title . '</h1><p>Body text.</p></main><footer><p style="font-size:' . $size . 'px" data-blocks-engine-capture-device-inline="{&quot;mobile&quot;:&quot;font-size:15px&quot;}">' . $footer . '</p></footer></div>';
        }
        $files[$path] = $html . '</body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $markup = implode('', array_column($plan['pages'], 'canonical_block_markup'))
        . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
    $assert(str_contains($markup, 'data-blocks-engine-capture-device-inline'), "$case: overlay binding survives");
    if ('capture' === $case) {
        $assert(!str_contains($markup, '<!-- wp:html'), 'Capture script must not force HTML fallback');
        $assert(4 === preg_match_all('/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>© 2026 Example Co\./u', $markup), 'Both pages and devices retain native footer paragraphs');
        preg_match_all('/<!-- wp:paragraph (\{.*?\}) -->/s', $markup, $paragraphs);
        $bindings = array();
        foreach ($paragraphs[1] as $json) {
            $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $binding = $attrs['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-device-inline'] ?? null;
            if (null !== $binding) $bindings[] = json_decode($binding, true, 512, JSON_THROW_ON_ERROR);
        }
        $assert(array_fill(0, 4, array('mobile' => 'font-size:15px')) === $bindings, 'Native blocks retain exact device styles in render metadata');
    } else {
        $assert(4 === substr_count($markup, '<!-- wp:html'), 'Author selector still makes all four paragraphs runtime targets');
        $assert(str_contains($markup, 'data-blocks-engine-capture-device-inline='), 'Author runtime target retains its source hook');
    }
}
echo "Capture device runtime evidence: capture excluded, author targets preserved.\n";
