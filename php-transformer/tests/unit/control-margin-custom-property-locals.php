<?php
declare(strict_types=1);

/**
 * Tailwind's `space-y-*` rule declares `--tw-space-y-reverse:0` beside the
 * margins that read it. A link lowered to core/buttons takes that margin on
 * its core/buttons wrapper while the rule's other declarations go to the
 * link, so the wrapper must also get the custom property. Without it the
 * wrapper's `var()` is invalid, the margin computes to 0, and every block
 * after the button moves up by the gap.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fail = static function (string $message): void {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
};

$source = '<style>.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem * var(--tw-space-y-reverse))}'
    . '.space-y-reverse>:not([hidden])~:not([hidden]){--tw-space-y-reverse:1}'
    . '.card{border:1px solid #ddd;padding:24px}'
    . '.btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:8px 16px;width:100%;background:#1c3;color:#fff;border-radius:10px}</style>'
    . '<main><div class="space-y-6"><div class="card"><h2>Address</h2><p>Bangi</p></div>'
    . '<div class="card"><h3>Hours</h3><p>Mon</p></div><a class="btn" href="/contact">Book Now</a></div></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));

if (1 !== preg_match('/class="wp-block-buttons (blocks-engine-control-[^ "]+)/', $result['serialized_blocks'], $match)) {
    $fail('the source link must become a core/buttons control');
}
$wrapper = ':where(.' . $match[1] . ')';
$declares = false;
foreach (preg_split('/(?<=\})/', $css) ?: array() as $rule) {
    if (1 !== preg_match('/^\s*([^{]+)\{([^}]*)\}/', $rule, $parts) || !str_contains($parts[2], '--tw-space-y-reverse:0')) {
        continue;
    }
    foreach (explode(',', $parts[1]) as $selector) {
        if (str_contains($selector, $wrapper) && str_contains($selector, ':where(.wp-block-buttons)')) {
            $declares = true;
        }
    }
}
if (!$declares) {
    $fail('the core/buttons wrapper that takes the space-y margin never gets --tw-space-y-reverse');
}

// Measure the gap above the button, in the source and in the block markup.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $probe = '<script>addEventListener("load",()=>{const c=document.querySelectorAll(".card"),a=document.querySelector("a");'
        . 'parent.document.body.dataset.gap=String(a.getBoundingClientRect().top-c[c.length-1].getBoundingClientRect().bottom)})</script>';
    $core = '.wp-block-buttons{display:flex;flex-wrap:wrap;align-items:center;gap:.5em}.wp-block-button{display:inline-block}';
    $temporary = tempnam(sys_get_temp_dir(), 'control-margin-locals-');
    $file = $temporary . '.html';
    try {
        $gaps = array();
        foreach (array($source, '<style>' . $core . '</style><style>' . $css . '</style>' . $result['serialized_blocks']) as $page) {
            $frame = '<!doctype html><html><body style="margin:0">' . $page . $probe . '</body></html>';
            file_put_contents($file, '<!doctype html><body><iframe style="border:0;width:1024px;height:900px" srcdoc="' . htmlspecialchars($frame, ENT_QUOTES) . '"></iframe></body>');
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            $gaps[] = preg_match('/data-gap="([^"]+)"/', (string) $output, $gap) ? (float) $gap[1] : null;
        }
        if (null === $gaps[0] || null === $gaps[1] || abs($gaps[0] - 24) > 0.5 || abs($gaps[0] - $gaps[1]) > 0.5) {
            $fail('the gap above the lowered button differs from the source: ' . json_encode($gaps));
        }
    } finally {
        @unlink($file);
        @unlink($temporary);
    }
}

echo 'PASS: margins carry the custom properties their rule declares onto core/buttons wrappers' . PHP_EOL;
