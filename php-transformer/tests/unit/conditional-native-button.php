<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = require dirname(__DIR__) . '/fixtures/conditional-native-button.php';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$buttons = array();
$walk = static function (array $blocks) use (&$walk, &$buttons): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks'] ?? array());
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(4 === count($buttons), 'all controls remain native buttons');
foreach (array(0, 1, 3) as $index) {
    $style = $buttons[$index]['attrs']['style'] ?? array();
    $assert(!isset($style['typography']['fontSize']), 'conditional font size stays stylesheet-owned');
    $assert(!isset($style['color']['background']), 'conditional fill stays stylesheet-owned');
    $assert(!isset($style['spacing']['padding']), 'conditional padding stays stylesheet-owned');
}
$explicit = $buttons[2]['attrs']['style'] ?? array();
$assert('23px' === ($explicit['typography']['fontSize'] ?? ''), 'explicit inline typography retains priority');
$assert('#983456' === ($explicit['color']['background'] ?? ''), 'explicit inline fill retains priority');
$assert('7px' === ($explicit['spacing']['padding']['top'] ?? ''), 'explicit inline padding retains priority');
$assert('button' === ($buttons[1]['attrs']['tagName'] ?? '') && 'submit' === ($buttons[1]['attrs']['type'] ?? ''), 'submit control retains native runtime attributes');
$assert(0 === ($result['metrics']['fallback_count'] ?? -1), 'native responsive controls introduce no fallback');
echo "Conditional native button ownership: passed\n";
