<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<html><body><main><button role="combobox" aria-expanded="false">Select country</button></main></body></html>';
$dialog = '<div role="listbox"><div role="option" aria-selected="false">Afghanistan</div><div role="option" aria-selected="false">Canada</div></div>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/registration/index.html',
    'files' => array(
        array('path' => 'website/registration/index.html', 'content' => $source),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array(
            'schema' => 'data-liberation/capture-receipt/v1',
            'routes' => array(array('url' => 'https://example.test/registration', 'path' => 'website/registration/index.html')),
        ), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array(
            'schema' => 'data-liberation/captured-interactions/v1',
            'pages' => array(array(
                'sourceUrl' => 'https://example.test/registration',
                'states' => array(array(
                    'status' => 'captured',
                    'trigger' => array('selector' => 'body > main > button', 'tag' => 'button', 'role' => 'combobox', 'label' => 'Select country'),
                    'dialog' => array('role' => 'listbox', 'html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false),
                )),
            )),
        ), JSON_UNESCAPED_SLASHES)),
    ),
))->toArray();

$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(in_array($result['status'] ?? null, array('success', 'success_with_warnings'), true), 'captured listbox artifact compiles successfully');
$assert(str_contains($markup, 'custom/authored-select'), 'projected listbox uses the editable native select companion');
$assert(!str_contains($markup, 'wp:details'), 'projected listbox is not materialized as a disclosure');
$assert(3 === substr_count($markup, '<option'), 'placeholder and captured options are retained');
$assert(str_contains($markup, '<option value="" selected disabled>Select country</option>'), 'unselected trigger retains its placeholder state');

fwrite(STDOUT, "Captured listbox materialization passed\n");
