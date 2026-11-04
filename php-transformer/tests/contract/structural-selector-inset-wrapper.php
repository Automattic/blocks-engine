<?php
declare(strict_types=1);

// A classless section whose box is inset only by a structural rule keeps its wrapper and padding (#4157).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inset</title>
<style>
:root{--space-inline:clamp(1rem,4vw,3rem)}
body{margin:0;font-family:sans-serif}
main section:not(.section__no-padding, :has(.section)){padding-left:var(--space-inline);padding-right:var(--space-inline)}
.grid{display:grid;gap:24px;max-width:1340px;margin:0 auto}
.card{border-radius:16px;background:#2b4c7e;color:#fff;padding:32px 24px}
.card img{display:block;width:100%;height:auto;border-radius:12px}
</style></head>
<body><main>
<section>
<div class="grid">
<div><img src="hero.jpg" width="800" height="600" alt="Office"></div>
<div class="card"><h1>Section heading</h1><p>A short descriptive line about the section.</p><a href="/a">Primary</a></div>
</div>
</section>
<section class="section__no-padding">
<div class="grid"><div><img src="hero.jpg" width="800" height="600" alt="Plain"></div></div>
</section>
<section><div class="section"><p>Nested</p></div></section>
</main></body></html>
HTML;

$markup = (string) ((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'] ?? '');

$failures = array();
// The rule's section matches: it names no exclusion, so its wrapper is kept
// as a section element and the rule's inset is restated on the block and
// applied at runtime through the inline style.
foreach (array(
    '"tagName":"section"',
    '"right":"var(\u002d\u002dspace-inline)"',
    '"left":"var(\u002d\u002dspace-inline)"',
    'style="padding-right:var(--space-inline)',
    'padding-left:var(--space-inline)"',
) as $needle) {
    if (!str_contains($markup, $needle)) {
        $failures[] = 'missing ' . $needle;
    }
}
// The media-text grid stays inside the kept section wrapper.
$close = strpos($markup, '</section><!-- /wp:group -->');
if (false === $close
    || !str_contains((string) substr($markup, 0, $close), '<!-- wp:media-text')
    || !str_contains((string) substr($markup, 0, $close), '<!-- wp:group {"style":{"spacing":{"padding":{"right":"var(\u002d\u002dspace-inline)"')) {
    $failures[] = 'the padded section wrapper no longer contains its media-text grid';
}
// Both exclusion arms still withhold the inset: exactly one section is padded.
foreach (array('padding-left:var(--space-inline)', 'padding-right:var(--space-inline)') as $needle) {
    if (1 !== substr_count($markup, $needle)) {
        $failures[] = 'expected exactly one section restated for ' . $needle;
    }
}
if (array() !== $failures) {
    fwrite(STDERR, "FAIL:\n" . implode("\n", $failures) . "\n" . $markup . "\n");
    exit(1);
}
echo "structural-selector-inset-wrapper: passed\n";
