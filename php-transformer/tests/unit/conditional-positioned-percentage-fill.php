<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
foreach ( require dirname(__DIR__) . '/fixtures/conditional-positioned-percentage-fill.php' as $fixture ) {
    $result = (new HtmlTransformer())->transform('<style>' . $fixture['css'] . '</style>' . $fixture['html'])->toArray();
    $css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets']));
    preg_match_all('/\.paint[^{}]*\{([^{}]*)\}/', $css, $rules);
    $heightRules = array_values(array_filter($rules[1], static fn (string $rule): bool => 1 === preg_match('/(?:^|;)height:/', $rule)));
    $expected = $fixture['preserve'] ? 'height:100%' : 'height:auto';
    if (1 !== count($heightRules) || !str_contains($heightRules[0], $expected)) {
        $failures[] = $fixture['name'] . ': expected ' . $expected . ', got ' . implode(' | ', $heightRules);
    }
}
if (array() !== $failures) {
    throw new RuntimeException(implode("\n", $failures));
}
echo "Conditional positioned percentage fill passed (12 cases)\n";
