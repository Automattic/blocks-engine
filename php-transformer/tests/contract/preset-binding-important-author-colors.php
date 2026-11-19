<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array('plain' => array('', false), 'important' => array(' !important', false), 'important-hover' => array(' !important', true)) as $label => [$priority, $hover]) {
    $css = 'body{background:#fff;color:#000;font-family:Georgia}main{background:#e1e4e6;padding:20px}'
        . '.wsite-button{display:inline-block;padding:0;background-color:#E1E4E6' . $priority . ';color:#000000' . $priority . '}'
        . ($hover ? '.wsite-button:hover{background-color:#c5cbcf !important}' : '')
        . '.wsite-button .wsite-button-inner{display:inline-block;padding:15px 32px;font-size:11px;font-weight:700;text-transform:uppercase}';
    $html = '<html><head><link rel="stylesheet" href="style.css"></head><body><main><h1>Title</h1><p>Some text.</p>'
        . str_repeat('<div><a class="wsite-button" href="/x.pdf"><span class="wsite-button-inner">Sermon Notes</span></a></div>', 3) . '</main></body></html>';
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'style.css' => $css)))->toArray()['source_reports']['wordpress_site_plan'];
    $metrics = $plan['theme']['design_system']['metrics'];
    if (0 !== $metrics['literal_preset_color_count']) throw new RuntimeException($label . ': preset-equal colours must bind.');
    if (0 !== $metrics['refused_value_count']) throw new RuntimeException($label . ': exact bindings must not count as refusals: ' . json_encode($metrics['refusal_reasons']));
    $markup = $plan['pages'][0]['canonical_block_markup'];
    preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons);
    if (3 !== count($buttons[1])) throw new RuntimeException($label . ': all three buttons survive.');
    foreach ($buttons[1] as $json) {
        $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ('contrast' !== ($attributes['textColor'] ?? null) && 'var:preset|color|contrast' !== ($attributes['style']['color']['text'] ?? null)) {
            throw new RuntimeException($label . ': button text must reference the exact black preset.');
        }
    }
    $emitted = implode("\n", array_column($plan['assets'], 'content'));
    if ($hover && (!str_contains($emitted, ':hover') || !str_contains($emitted, '#c5cbcf') || !str_contains($emitted, '!important'))) {
        throw new RuntimeException($label . ': the author hover paint and priority must survive.');
    }
}

echo "Important author colour binding contract passed.\n";
