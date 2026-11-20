<?php
declare(strict_types=1);

// #6778's reduced page, shared by the public-transform and browser probes.
return static function (string $extraCss = ''): string {
    return '<!doctype html><html><head><style>body{margin:0;font-family:sans-serif}'
        . '.stack{display:flex;flex-direction:column;gap:20px;padding:34px;background:#eee}'
        . '.painted{display:flex;flex-direction:column;gap:20px;padding:34px;min-height:360px;justify-content:center;align-items:flex-start;background:linear-gradient(90deg,#0009,#0004),url(https://picsum.photos/id/10/800/400) center/cover;color:#fff}'
        . $extraCss . '</style></head><body><main>'
        . '<div class="stack"><h3>Plain stack</h3><p>Paragraph with its default margins.</p><a href="https://example.com/">Link</a></div>'
        . '<div class="painted"><h3>Painted stack</h3><p>Paragraph with its default margins.</p><a href="https://example.com/">Link</a></div>'
        . '</main></body></html>';
};
