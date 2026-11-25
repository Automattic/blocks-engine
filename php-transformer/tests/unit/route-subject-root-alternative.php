<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// A route-set gate on a lone-compound subject tests the document root as well
// as its descendants: `.x:where(:root.be-rs-…,:root.be-rs-… *)`. The root
// alternative only pays when some document root can carry the subject's
// class. Tailwind utilities are escaped (`sm\:plain`), and an escaped class no
// root carries must not keep that alternative in every gated arm.
$compile = static function (string $rootClass): string {
    $files = array('site.css' => '.sm\:plain{margin:1px}.md\:wide{padding-bottom:3px}');
    for ($i = 0; $i < 6; ++$i) {
        $files['page-' . $i . '.html'] = '<html' . ('' !== $rootClass ? ' class="' . $rootClass . '"' : '') . '><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body><main><nav><ul><li class="' . ($i % 2 ? 'md:wide' : 'sm:plain') . '"><a href="/page-0.html">Page ' . $i . '</a></li></ul></nav><p class="md:wide">Hi</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    return implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) ? $asset['content'] : '', $plan['assets']));
};

$css = $compile('');
$assert(1 === preg_match('/\.sm\\\\:plain[^{,]*:where\(:root\.be-rs-[0-9a-f]+ \*\)/', $css), 'Fixture must gate the escaped subject by route set: ' . $css);
$assert(0 === preg_match('/\.sm\\\\:plain[^{,]*:where\(:root\.be-rs-[0-9a-f]+,/', $css), 'No document root carries sm:plain, so its gate keeps no root alternative: ' . $css);

// A root that does carry the class keeps the root alternative.
$css = $compile('sm:plain');
$assert(1 === preg_match('/\.sm\\\\:plain[^{,]*:where\((:root\.be-rs-[0-9a-f]+),\1 \*\)/', $css), 'A root carrying sm:plain keeps the root alternative: ' . $css);

echo "route-subject-root-alternative: ok\n";
