<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/form-pruned-stylesheet.html');
$absent = '';
for ($i = 0; $i < 5000; ++$i) $absent .= '.absent-' . $i . '{display:grid;color:red;--unused:1}';
$compile = static fn (string $page): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page)))->toArray();
$before = CssRuleAnalyzer::streamedRules();
$large = $compile(str_replace('</style>', $absent . '</style>', $html));
$streamed = CssRuleAnalyzer::streamedRules() - $before;
if ($streamed > 16) throw new RuntimeException('Form analyses must stream only reachable rules, not absent rules: ' . $streamed);
$small = $compile($html);
// Page-scoped source markers and stylesheet provenance intentionally depend on
// the complete author text. Compare the emitted form after normalizing these.
$normalize = static function (array $result): string {
    $text = $result['serialized_blocks'];
    return preg_replace('/[a-f0-9]{64}|[a-f0-9]{12}/', 'HASH', $text);
};
if ($normalize($large) !== $normalize($small)) throw new RuntimeException('Absent rules must not change emitted form output.');
echo "form pruned stylesheet passed ({$streamed} streamed rules)\n";
