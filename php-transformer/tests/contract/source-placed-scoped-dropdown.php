<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$fixture = require dirname(__DIR__) . '/fixtures/source-placed-scoped-dropdown.php';
foreach (array(false, true) as $observed) {
    $result = (new ArtifactCompiler())->compile($fixture($observed))->toArray();
    $markup = $result['serialized_blocks'];
    $dom = new DOMDocument();
    @$dom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup));
    $xpath = new DOMXPath($dom);
    $dialogs = $xpath->query('//*[@data-blocks-engine-capture-document-scope="mobile"]/*[@data-blocks-engine-triggers]');
    if (1 !== $dialogs->length) throw new RuntimeException('The source-placed nav must become a dialog under its original document parent.');
    $dialog = $dialogs->item(0);
    $triggerId = $dialog->getAttribute('data-blocks-engine-triggers');
    $trigger = $xpath->query('//*[@id="' . $triggerId . '"]')->item(0);
    if (!$trigger || !$xpath->query('ancestor::*[@data-blocks-engine-capture-document-scope="mobile"]', $trigger)->length) throw new RuntimeException('The report must not repoint the adopted trigger.');
    foreach ($xpath->query('//*[@data-blocks-engine-triggers]') as $other) {
        if (!$other->isSameNode($dialog) && in_array($triggerId, explode(' ', $other->getAttribute('data-blocks-engine-triggers')), true)) throw new RuntimeException('A trigger must have only one projected target.');
    }
    if (5 !== $dialog->getElementsByTagName('a')->length) throw new RuntimeException('All source links stay in place.');
    if (!str_contains($markup, 'Fallback')) throw new RuntimeException('The panel-less desktop scope must still use report fallback.');
    $plan = $result['source_reports']['wordpress_site_plan'];
    $mobile = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'index.html' === $page['source_path']))[0]['canonical_block_markup'];
    if (!$observed && !preg_match('/wp:group [^>]*"blocksEngineDialog"[^>]*-->.*?wp:navigation[^>]*-->.*?wp:navigation-link/s', $mobile)) throw new RuntimeException('Source-placed panel navigation retains its source-owned item style subjects rather than a shared entity copy.');
    $definition = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator())->definition('custom/captured-dialog');
    $result['browser_markup'] = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
    $result['browser_style'] = $definition['assets']['style.css'];
    $result['browser_script'] = $definition['view_js'];
    file_put_contents(sys_get_temp_dir() . '/source-placed-scoped-dropdown-' . (int) $observed . '.json', json_encode($result, JSON_THROW_ON_ERROR));
}
echo "Source-placed scoped dropdown contract passed.\n";
