<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = <<<'HTML'
<style>.name{font:600 12px/1.45 sans-serif;color:#35463f}.page .card .name{font-size:14px;font-weight:750;color:#315a0b}</style>
<div class="page"><div class="card"><div class="detail"><span class="city">Makkah</span><strong class="name"><a href="/h/a/">Hotel A</a></strong></div><div class="detail"><span class="city">Madinah</span><strong class="name">Hotel B</strong></div></div></div>
HTML;

// The issue's exact input, plus equivalent format tags and an explicit inline
// override: no dispatch decision may depend on the class names or label text.
$cases = array(
    'issue fixture' => array($fixture, 'strong', '14px', '750', '#315a0b'),
    'emphasis' => array(str_replace(array('<strong', '</strong>'), array('<em', '</em>'), $fixture), 'em', '14px', '750', '#315a0b'),
    'authored inline override' => array(str_replace('class="name"', 'class="name" style="font-size:16px;font-weight:800;color:#123456"', $fixture), 'strong', '16px', '800', '#123456'),
);

foreach ($cases as $label => [$html, $tag, $size, $weight, $color]) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    preg_match_all('/<mark\b[^>]*class="[^"]*\bname\b[^"]*"[^>]*style="([^"]*)"[^>]*>(.*?)<\/mark>/s', $markup, $marks, PREG_SET_ORDER);
    $ok = 2 === count($marks) && str_contains($markup, '<' . $tag . '>')
        && str_contains($markup, '<a href="/h/a/">Hotel A</a>')
        && str_contains($css, '.card .name{font-size:14px;font-weight:750;color:#315a0b}')
        && str_contains($css, 'font:600 12px/1.45 sans-serif;color:#35463f')
        && str_contains($css, ':where(mark.be-inline-geometry-')
        && str_contains($css, 'background-color:transparent');
    foreach ($marks as $mark) {
        if ('16px' === $size) {
            $ok = $ok && str_contains($mark[1], 'font-size:' . $size)
                && str_contains($mark[1], 'font-weight:' . $weight)
                && str_contains($mark[1], 'color:' . $color);
        } else {
            // The retained contextual author selector supplies these values;
            // no context-free inferred declaration may outrank it inline.
            $ok = $ok && ! preg_match('/(?:font|font-size|font-weight|color):/', $mark[1]);
        }
        $ok = $ok && ! str_contains($mark[1], 'font-size:12px');
    }
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}: linked and plain typography must retain the source cascade.\n{$markup}\n{$css}\n");
        exit(1);
    }
}
fwrite(STDOUT, "Contextual inline link typography contract passed\n");
