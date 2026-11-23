<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A class recipe owns its colour even where a context rule restates the
// same value (`.label` and `.writing .label`): every element with the class
// renders alike, so the site plan binds the class's colour to the palette on
// each of them instead of leaving it in imported CSS Global Styles cannot reach.
$html = '<!doctype html><html><head><style>
:root{--accent:9 77% 48%}
body{color:#222;background:#fff;font-family:Arial,sans-serif}
.label{color:hsl(var(--accent));letter-spacing:.15em;text-transform:uppercase;font-size:12px}
.writing .label{color:hsl(var(--accent))}
.title{color:#111}
</style></head><body><main>
<section class="about"><p class="label">About</p><h2 class="title">About heading</h2></section>
<section class="speaking"><p class="label">Speaking</p><h2 class="title">Speaking heading</h2></section>
<section class="writing"><p class="label">Writing</p><h2 class="title">Writing heading</h2></section>
</main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = implode("\n", array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
foreach (array('About', 'Speaking', 'Writing') as $text) {
    if (!preg_match('/<p\b([^>]*)>' . $text . '<\/p>/', $markup, $match)) throw new RuntimeException($text . ' label stays a native paragraph.');
    if (!str_contains($match[1], 'has-text-color') || !preg_match('/has-[a-z0-9-]+-color/', $match[1])) {
        throw new RuntimeException($text . ' label colour must be owned by a palette preset when a context rule only restates its class recipe: ' . $match[0]);
    }
}
echo "Context rule restating recipe colour passed.\n";
