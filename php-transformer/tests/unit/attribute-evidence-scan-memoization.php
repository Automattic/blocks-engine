<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

// Many arms repeat a few custom attribute keys over large evidence: each
// distinct key must be searched for once, not once per arm.
$evidence = array(
    '<!doctype html><html><body><p class="card ' . implode(' ', array_map(static fn(int $i): string => 'n' . $i, range(0, 499))) . '">Hello</p></body></html>',
    'const filler = "' . str_repeat('lorem ipsum ', 100000) . '"; el.dataset.xOne = "on"; el.ariaXThree = "on";',
);
$pruner = new UnreachableSelectorPruner($evidence, true);
$rules = array();
for ($i = 0; $i < 500; ++$i) {
    $rules[] = '.card[data-x-one] .n' . $i . ',[data-x-two] .n' . $i . ',[aria-x-three] .n' . $i . ',[aria-x-four] .n' . $i . '{color:teal}';
}
$css = $pruner->prune(implode('', $rules));

foreach (array('[data-x-one]' => true, '[aria-x-three]' => true, '[data-x-two]' => false, '[aria-x-four]' => false) as $arm => $expected) {
    if (str_contains($css, $arm) !== $expected) throw new RuntimeException('Unexpected presence of ' . $arm);
}
if (500 !== substr_count($css, 'color:teal')) throw new RuntimeException('Rules with reachable arms must stay');

$memo = (new ReflectionProperty(UnreachableSelectorPruner::class, 'evidenceContains'))->getValue($pruner);
$expectedKeys = array('xOne', 'xTwo', 'dataset[', 'ariaXThree', 'ariaXFour');
sort($expectedKeys);
$actualKeys = array_keys($memo);
sort($actualKeys);
if ($expectedKeys !== $actualKeys) throw new RuntimeException('Evidence scans must be bounded by distinct keys: ' . implode(', ', $actualKeys));
echo "Attribute evidence scan memoization passed\n";
