<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$address = '<p class="adresse"><span>1 Main St</span><br><span>Town</span></p>';
$phone = '<p>Tel: <span>555</span></p>';
$cases = array(
    'issue fixture' => array('<h2>Club</h2>' . $address . '<br>' . $phone . '<p><a href="mailto:a@b.c">a@b.c</a></p>', '', 1, null),
    'two breaks' => array($address . "\n<br> \n<br>\n" . $phone, '', 2, null),
    'resolved line height' => array($address . '<br>' . $phone, 'section{line-height:24px}', 1, '24px'),
    'two resolved lines' => array($address . '<br><br>' . $phone, 'section{line-height:24px}', 1, '48px'),
    'unitless inherited leading' => array($address . '<br><br>' . $phone, 'main{font-size:20px;line-height:1.5}', 1, '60px'),
    'em leading' => array($address . '<br>' . $phone, 'section{font-size:20px;line-height:1.5em}', 1, '30px'),
    'percentage leading' => array($address . '<br>' . $phone, 'section{font-size:20px;line-height:150%}', 1, '30px'),
    'normal leading fallback' => array($address . '<br>' . $phone, 'section{line-height:normal}', 1, null),
    'whitespace alone' => array($address . "\n  " . $phone, '', 0, null),
    'leading break' => array('<br>' . $address . $phone, '', 0, null),
    'trailing break' => array($address . $phone . '<br>', '', 0, null),
    'inline paragraph break' => array($address . $phone, '', 0, null),
    'adjacent inline content' => array('<span>Before</span><br><span>After</span>', '', 0, null),
);
foreach ($cases as $name => [$html, $css, $expected, $height]) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<html><head><style>' . $css . '</style></head><body><main><section>' . $html . '</section></main></body></html>'),
    ))->toArray();
    // Inspect the serialized native blocks, not generated support CSS or diagnostics.
    $markup = $result['serialized_blocks'];
    preg_match_all('/<!-- wp:(spacer|paragraph)(\s+\{.*?\})?\s*(?:\/-->|-->(.*?)<!-- \/wp:\1 -->)/s', $markup, $matches, PREG_SET_ORDER);
    $lines = array_values(array_filter($matches, static fn (array $match): bool => 'spacer' === $match[1] || '' === trim(strip_tags($match[3] ?? ''))));
    $assert(count($lines) === $expected, $name . ': expected blank-line blocks ' . $expected . ', got ' . count($lines));
    if (null !== $height) $assert(str_contains($lines[0][0], '"height":"' . $height . '"'), $name . ': spacer height');
    if ($expected > 0) {
        $assert(strpos($markup, 'Town') < strpos($markup, $lines[0][0]) && strpos($markup, $lines[0][0]) < strpos($markup, 'Tel:'), $name . ': blank lines between paragraphs');
    }
    if (str_contains($html, 'Town')) $assert(1 === preg_match('/1 Main St.*?<br\s*\/?>.*?Town/s', $markup), $name . ': inline break retained');
    $assert(!str_contains($markup, '<!-- wp:html'), $name . ': no HTML fallback');
    $assert(array() === $result['fallbacks'], $name . ': no content loss or empty-conversion finding');
    $metrics = $result['source_reports']['editability_report']['metrics'];
    foreach (array('empty_wrapper_count', 'empty_visual_group_count', 'empty_runtime_group_count', 'raw_html_block_count') as $counter) {
        $assert(0 === $metrics[$counter], $name . ': ' . $counter);
    }
}
fwrite(STDOUT, "Standalone break lines passed\n");
