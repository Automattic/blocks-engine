<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$tokens = array();
for ($i = 0; $i < 500; ++$i) $tokens[] = array('token' => 'asset-' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT), 'target_path' => "assets/images/image-$i.png");
$index = array_column($tokens, null, 'token');
$select = new ReflectionMethod(WordPressSitePlanResolver::class, 'tokensInPayload');
$resolve = new ReflectionMethod(WordPressSitePlanResolver::class, 'resolveIndexedWritePayload');
foreach ($tokens as $i => $token) {
    $canonical = WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}';
    $payload = '.image{background:url(' . $canonical . ');mask:url(' . $canonical . ')}';
    if (array($token) !== $select->invoke(null, $payload, $index)) throw new RuntimeException('Write selected unrelated tokens.');
    $expected = str_replace($canonical, "../images/image-$i.png", $payload);
    if ($expected !== $resolve->invoke(null, $payload, $index, 'https://example.org/theme', 'assets/css/site.css')) throw new RuntimeException('Relative URL output changed.');
}
if (array() !== $select->invoke(null, 'No references', $index)) throw new RuntimeException('Token-free payload selected tokens.');
try {
    $resolve->invoke(null, '{{wordpress-site-plan:asset:unknown}}', $index, 'https://example.org/theme', 'assets/css/site.css');
    throw new RuntimeException('Unknown token was accepted.');
} catch (InvalidArgumentException) {
}
echo "Write token scaling passed.\n";
