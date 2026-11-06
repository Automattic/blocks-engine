<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SupportCssSubjects;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/absent-support-subjects.html');
$compile = static fn(string $source): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$result = $compile($html);
foreach ($result['assets'] as $asset) {
    if ('wordpress-compat' === ($asset['source'] ?? '')) throw new RuntimeException('Absent navigation subjects emitted replay CSS.');
    if (str_contains((string) ($asset['content'] ?? ''), 'cursor:pointer')) throw new RuntimeException('Absent disclosure emitted support CSS.');
}
$present = $compile(str_replace('<main>', '<nav class="absent-banner"><a href="/">Home</a><a href="/services/">Services</a></nav><details class="data-blocks-engine-capture-disclosure"><summary>More</summary><p>Details</p></details><main>', $html));
$css = implode("\n", array_column($present['assets'], 'content'));
if (!str_contains($css, 'cursor:pointer') || !str_contains($css, 'replay source nav')) throw new RuntimeException('Present support subjects lost their rules.');
$subjects = new SupportCssSubjects('<p class="present" data-example="yes"></p>');
foreach (array('.missing a', '[data-missing] a', ':is(.missing,.also-missing) a') as $selector) {
    if ('' !== $subjects->stylesheet($selector . '{color:red}')) throw new RuntimeException('Absent positive requirement survived: ' . $selector);
}
foreach (array('.present:hover', '.present:not(.missing)', ':is(.present,.missing)', '[data-example]') as $selector) {
    if ('' === $subjects->stylesheet($selector . '{color:red}')) throw new RuntimeException('Possible runtime subject was removed: ' . $selector);
}
echo "Support subject presence passed\n";
