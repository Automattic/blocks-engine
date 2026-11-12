<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Contract\HtmlValidationOutcome;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DiagnosticsCollector;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$contract = array(
    'materialization_path' => 'preserved_runtime_island',
    'required_scripts' => array('runtime.js'),
    'acceptability' => 'acceptable_preservation',
);
// The projection carries producer-owned fields for any fallback, not only forms.
foreach (array($contract, array('required_scripts' => array()), array()) as $fields) {
    $diagnostics = (new DiagnosticsCollector())->collect(
        'test', array(), array(array('diagnostic_code' => 'generic_fallback') + $fields),
        array(), array(), array(), new HtmlValidationOutcome()
    );
    $finding = $diagnostics[1];
    ConversionFindingContract::assertFinding($finding);
    foreach ($contract as $key => $unused) {
        $assert(array_key_exists($key, $fields) === array_key_exists($key, $finding), $key . ': preserve presence');
        if (array_key_exists($key, $fields)) {
            $assert($fields[$key] === $finding[$key], $key . ': preserve value');
        }
    }
}
foreach (array('materialization_path' => array(), 'required_scripts' => 'runtime.js', 'acceptability' => false) as $key => $invalid) {
    try {
        ConversionFindingContract::assertFinding(array('code' => 'generic_fallback', $key => $invalid));
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException($key . ': invalid type accepted');
}
echo "Fallback island diagnostics passed.\n";
