<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<!doctype html><html><head><meta charset="utf-8"><title>M</title><style>p::before{content:""}</style></head>
<body><main><h1>Cart</h1><p>Added.</p><control-wrapper><button type="button" aria-label="Close"><svg viewBox="0 0 20 20" width="20" height="20"><path d="M5 5l10 10"/></svg></button></control-wrapper></main></body></html>';
$transformer = new HtmlTransformer();
foreach (array('p::before{content:""}', 'input::placeholder{color:red}', 'date-control input[type="date"]::-webkit-calendar-picker-indicator{display:none}', 'p:hover::before{content:""}', 'main > p::after{content:""}', '[data-note="::before"] input::placeholder{color:red}') as $rule) {
    $source = str_replace('p::before{content:""}', $rule, $html);
    $result = $transformer->transform($source)->toArray();
    $reference = $transformer->transform(str_replace($rule, '', $source))->toArray();
    if (array() !== ($result['fallbacks'] ?? array())
        || ($result['serialized_blocks'] ?? '') !== ($reference['serialized_blocks'] ?? '')
        || ! str_contains($result['serialized_blocks'] ?? '', '<button type="button"')
        || ! str_contains($result['serialized_blocks'] ?? '', 'inline-svg-')
    ) {
        fwrite(STDERR, "FAIL: unrelated pseudo-element rule must not veto native control lowering: $rule\n" . json_encode($result['fallbacks']) . "\n");
        exit(1);
    }
}
foreach (array('control-wrapper::before{content:"x"}', 'control-wrapper::after{content:"x"}', 'control-wrapper:hover::before{content:"x"}', 'control-wrapper{padding:10px}') as $rule) {
    $result = $transformer->transform(str_replace('p::before{content:""}', $rule, $html))->toArray();
    $neutral = $transformer->transform(str_replace('p::before{content:""}', '', $html))->toArray();
    if (($result['serialized_blocks'] ?? '') === ($neutral['serialized_blocks'] ?? '') && array() === ($result['fallbacks'] ?? array())) {
        fwrite(STDERR, "FAIL: wrapper presentation must still veto render-neutral lowering: $rule\n");
        exit(1);
    }
}
echo "Render-neutral unrelated pseudo-element tests passed\n";
