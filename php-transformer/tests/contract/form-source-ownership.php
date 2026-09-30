<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$classes = implode(' ', array_map(static fn(int $i): string => 'hook-' . $i, range(1, 20))) . ' wide-copy';
$html = '<!doctype html><html><head><link rel="stylesheet" href="source.css"></head><body><main class="page"><form method="post">'
    . '<div class="intro-box"><p class="' . $classes . '">A neutral introduction.</p></div>'
    . '<label for="email">Email</label><input id="email" type="email" name="email" required>'
    . '<div class="submit-box"><button type="submit">Send</button></div>'
    . '<div class="note-box"><p class="note">Please review your details.</p></div></form></main></body></html>';
$css = '.page{--copy:21px;font-family:Georgia;color:#123456}.wide-copy{font-size:var(--copy)}'
    . '.intro-box{padding:3px 5px}.submit-box{min-height:73px;padding-bottom:19px}'
    . '.note-box{padding:7px 0 13px}.note{font-size:11px}'
    . '@media (min-width:1200px){.page{--copy:27px}.wide-copy{letter-spacing:2px}.note-box{padding-bottom:17px}}';
$compile = static function (string $html) use ($css): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'source.css' => $css)))->toArray();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('forms' === ($declaration['type'] ?? null)) return $declaration['payload']['entities'][0] ?? array();
    }
    throw new RuntimeException('Compiler did not emit a runtime form entity.');
};
$form = $compile($html);
$graph = $form['layout_graph'] ?? array();
$byClass = array();
foreach ($graph['nodes'] ?? array() as $node) foreach ($node['source']['classes'] ?? array() as $class) $byClass[$class] = $node;
$intro = $form['form']['context_before'][0] ?? array();
$note = $form['form']['context_after'][0] ?? array();
$assert('generic/computed-layout-graph/v3' === ($graph['schema'] ?? null), 'Runtime declaration carries the source box graph capability.');
$assert(isset($intro['source_selector']) && ($intro['source_selector'] ?? null) === ($byClass['wide-copy']['source']['selector'] ?? null), 'Context and graph share stable element identity beyond sixteen class hooks.');
$assert(isset($byClass['wide-copy'], $byClass['intro-box'], $byClass['note'], $byClass['note-box']) && ($byClass['wide-copy']['parent'] ?? null) === ($byClass['intro-box']['id'] ?? null) && ($byClass['note']['parent'] ?? null) === ($byClass['note-box']['id'] ?? null), 'Context-only wrappers and copy retain exact parentage.');
$assert('73px' === ($byClass['submit-box']['layout']['min_height'] ?? null) && '19px' === ($byClass['submit-box']['layout']['padding_bottom'] ?? null), 'Submit minimum height and padding belong to the wrapper.');
$controls = array_column(array_filter($graph['nodes'] ?? array(), static fn(array $node): bool => 'control' === $node['kind']), null, 'id');
$assert(!isset($controls['control-1']['layout']['min_height'], $controls['control-1']['layout']['padding_bottom']) && 'email' === ($form['controls'][0]['name'] ?? null) && true === ($form['controls'][0]['required'] ?? null), 'Box ownership preserves native field and submit semantics.');
$presentation = $byClass['wide-copy']['presentation'] ?? array();
$assert('21px' === ($presentation['styles']['font_size'] ?? null) && 'Georgia' === ($presentation['styles']['font_family'] ?? null) && '#123456' === ($presentation['styles']['color'] ?? null), 'Context presentation resolves inherited typography and ancestor variables.');
$patches = $presentation['variants'] ?? array();
$assert(1 === count($patches) && '27px' === ($patches[0]['styles']['font_size'] ?? null) && '2px' === ($patches[0]['styles']['letter_spacing'] ?? null) && !isset($patches[0]['styles']['padding']), 'Responsive patches carry changing properties only, including variable-only changes.');
$assert('source.css' === ($presentation['provenance'][0]['source_path'] ?? null) && hash('sha256', $css) === ($presentation['provenance'][0]['source_sha256'] ?? null), 'Resolved presentation keeps stylesheet provenance.');
$assert('7px 0 13px' === ($byClass['note-box']['layout']['padding'] ?? null) && isset($note['source_selector']), 'Disclaimer-only source box reaches actual compiler output.');
$ariaNamed = $compile('<!doctype html><html><head><link rel="stylesheet" href="source.css"></head><body><main class="page"><form method="post"><label for="n">Name</label><input id="n" name="n"><textarea aria-label="Message" placeholder="Message" name="m"></textarea><button type="submit">Send</button></form></main></body></html>');
$ariaControls = $ariaNamed['controls'] ?? array();
$assert('Message' === ($ariaControls[1]['label'] ?? null) && false === ($ariaControls[1]['label_visible'] ?? null) && !array_key_exists('label_visible', $ariaControls[0] ?? array()) && !array_key_exists('label_visible', $ariaControls[2] ?? array()), 'An aria-only accessible name is marked as having no rendered label box.');
$tooMany = implode(' ', array_map(static fn(int $i): string => 'hook-' . $i, range(1, 70)));
$bounded = $compile(str_replace($classes, $tooMany, $html));
$assert(!isset($bounded['layout_graph']) && in_array('source_class_limit', $bounded['source_contract_losses'] ?? array(), true), 'Source identity exhaustion is visible in runtime declarations and its partial graph is excluded.');
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Form source ownership compiler contract passed (11 assertions).\n";
