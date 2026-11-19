<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;

/** Arms compaction factored into one `:is()` list split back out, each at its own weight. */

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$same = static fn (array $expected, string $selector): bool => $expected === CssSelectorListCompactor::factoredArms($selector);

$assert($same(array('#frame :where(.a)', '#frame :where(.b)'), '#frame :is(:where(.a),:where(.b))'), 'a factored list splits into its arms');
$assert($same(array(':where(:root:is(.be-r-1)) #frame .a .x', ':where(:root:is(.be-r-1)) #frame .b .x'), ':where(:root:is(.be-r-1)) #frame :is(.a,.b) .x'), 'context on both sides follows each arm');
$assert($same(array('#f .a:where(:root)', '#f .b:where(:root)'), '#f :is(.a,.b):where(:root)'), 'a condition appended to the list compound follows each arm');
$assert($same(array('#frame :is(.a,#b)'), '#frame :is(.a,#b)'), 'a list of unequal weights is not split');
$assert($same(array('#frame :is(.a,[data-x])'), '#frame :is(.a,[data-x])'), 'a list outside the plain compound grammar is not split');
$assert($same(array('#frame .a'), '#frame .a'), 'a selector without a list is its own arm');
$assert(implode(',', CssSelectorListCompactor::factoredArms(CssSelectorListCompactor::compact('#frame .a .x,#frame .b .x'))) === '#frame .a .x,#frame .b .x', 'factoredArms inverts compact()');

echo "selector list factored arms ok\n";
