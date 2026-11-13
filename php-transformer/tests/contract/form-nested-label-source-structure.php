<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Issue #5945 acceptance input, including its submission handler.
$fixture = <<<'HTML'
<style>.captcha-control{display:flex;height:63px;border-radius:999px;background:#efefef}</style>
<form id="q"><div class="captcha"><label class="field"><span class="sr-only">Answer</span><span class="captcha-control"><span data-q>4 + 3?</span><input name="captcha" type="number" placeholder="Answer" required></span></label><button class="refresh" type="button" aria-label="Refresh">↻</button></div><button type="submit">Send</button></form>
<script>document.getElementById('q').addEventListener('submit',e=>{e.preventDefault();})</script>
HTML;
$cases = array(
    'issue fixture' => array($fixture, 'captcha-control', 'captcha'),
    'neutral identities' => array(str_replace(array('captcha-control', 'captcha', 'Answer', '4 + 3?'), array('control-frame', 'response', 'Response', 'Question'), $fixture), 'control-frame', 'response'),
);
foreach ($cases as $name => [$html, $frameClass, $fieldName]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $frame = 'span[contains(concat(" ", normalize-space(@class), " "), " ' . $frameClass . ' ")]';
    $assert(1 === $xpath->query('//input[@name="' . $fieldName . '"]')->length, $name . ': exactly one successful source field');
    $assert(1 === $xpath->query('//' . $frame)->length, $name . ': the painted wrapper appears exactly once');
    $assert(1 === $xpath->query('//form/div/label/span[1][@class="sr-only"]')->length, $name . ': caption stays first in the label');
    $assert(1 === $xpath->query('//form/div/label/' . $frame . '/span[@data-q]')->length, $name . ': question stays inside the label frame');
    $assert(1 === $xpath->query('//form/div/label/' . $frame . '/input[@name="' . $fieldName . '"][@required]')->length, $name . ': native control stays in its source frame');
    // Inspect emitted CSS, not source CSS or metadata. Projection may expand
    // the selector, but the same frame still owns all four declarations.
    $css = '';
    foreach ($plan['writes'] as $write) {
        if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'] . "\n";
    }
    $assert(1 === preg_match('/[^{}]*' . preg_quote($frameClass, '/') . '[^{}]*\{[^{}]*display:flex;[^{}]*height:63px;[^{}]*border-radius:999px;[^{}]*background:(?:var\(--wp--preset--color--[a-z0-9-]+,)?#efefef\)?[^{}]*\}/', $css), $name . ': generated CSS retains frame layout and paint');
}
echo "Nested form labels retain source structure and frame CSS.\n";
