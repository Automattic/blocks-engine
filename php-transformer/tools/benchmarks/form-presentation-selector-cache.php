<?php
declare(strict_types=1);

/**
 * Compile cost of repeated selector matching in the form presentation analyses
 * (Automattic/blocks-engine#3791).
 *
 * Builds a synthetic page with 10 forms (labels, inputs, a button each) and a
 * few thousand generic CSS rules repeated across 3 scoped stylesheet copies,
 * then times full page compiles and hashes the output. Run it on trunk and on
 * a branch: the wall times compare the cost of re-matching every selector
 * against the same elements on every CssRuleAnalyzer pass, and the hashes must
 * be identical in both runs.
 *
 * Usage: php form-presentation-selector-cache.php [forms] [rules-per-copy] [copies] [runs]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$forms = max(1, (int) ($argv[1] ?? 10));
$rulesPerCopy = max(1, (int) ($argv[2] ?? 1000));
$copies = max(1, (int) ($argv[3] ?? 3));
$runs = max(1, (int) ($argv[4] ?? 3));

$body = '';
for ( $form = 0; $form < $forms; ++$form ) {
    $fields = '';
    foreach ( array( 'name' => 'Name', 'email' => 'Email', 'subject' => 'Subject' ) as $field => $label ) {
        $fields .= "<div class=\"field-row\"><label class=\"field-label\" for=\"form-{$form}-{$field}\">{$label}</label>"
            . "<input class=\"field-input\" id=\"form-{$form}-{$field}\" type=\"text\" name=\"form-{$form}-{$field}\"></div>";
    }
    $body .= "<section class=\"scope-page\"><form class=\"contact-form\" id=\"form-{$form}\">{$fields}"
        . "<div class=\"field-row\"><button class=\"field-button\" type=\"submit\">Send</button></div></form></section>";
}

// Rules that match the forms plus many that do not: the analyses scan every
// selector of every copy against the same elements on every pass.
$matchingRules = ".contact-form{margin:16px;padding:24px;border:1px solid #ddd}"
    . ".field-row{display:flex;flex-direction:column;gap:4px;margin-block-end:12px}"
    . ".field-label{font-weight:600;color:#1a1a1a;text-transform:none}"
    . ".field-input{padding:8px;border-color:#ccc;background:#fff;height:40px}"
    . ".field-button{padding:8px 16px;background:#0675c4;color:#fff}"
    . ".field-button:hover{background:#005a9c}"
    . ".contact-form > .field-row > .field-input:not(.disabled){font-size:16px}";
for ( $index = 0; $index < 32; ++$index ) {
    $matchingRules .= ".module-{$index} .field-input{border-radius:{$index}px}.module-{$index} .field-label{letter-spacing:0.0{$index}em}";
}

$genericRules = '';
for ( $index = 0; $index < $rulesPerCopy; ++$index ) {
    $genericRules .= ".module-{$index}{color:#333;margin:{$index}px}.module-{$index} .widget{padding:4px;border-color:#eee}"
        . ".module-{$index} .widget .title{font-weight:600}.module-{$index}>.content>*{line-height:1.5}"
        . "div.widget-{$index}:not(.hidden){display:block}.widget-{$index}:first-child{margin-block-start:0}";
}

$stylesheets = '';
for ( $copy = 0; $copy < $copies; ++$copy ) {
    $stylesheets .= "<style>.scope-{$copy} .contact-form{--form-scope:{$copy}}.scope-{$copy} {$matchingRules}</style>"
        . "<style>.scope-{$copy} {$genericRules}</style>";
}

$html = "<!doctype html><html><head><title>forms</title>{$stylesheets}</head><body>{$body}</body></html>";
printf("page: %d forms, %d selectors per copy x %d copies, %.2f MB of CSS\n", $forms, 5 + 2 * $rulesPerCopy, $copies, strlen($stylesheets) / 1048576);

$transformer = new HtmlTransformer();
$result = null;
$times = array();
for ( $run = 0; $run < $runs; ++$run ) {
    $startedAt = hrtime(true);
    $result = $transformer->transform($html);
    $times[] = (hrtime(true) - $startedAt) / 1000000;
}

$resultArray = $result->toArray();
fwrite(STDOUT, json_encode(array(
    'runs_ms' => $times,
    'status' => $resultArray['status'],
    'serialized_blocks_sha256' => hash('sha256', (string) $resultArray['serialized_blocks']),
    'assets_sha256' => hash('sha256', serialize($resultArray['assets'])),
    'peak_memory_bytes' => memory_get_peak_usage(true),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
