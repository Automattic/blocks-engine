<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$page = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><h1>' . $title . '</h1><h2>Section</h2><p>Body copy for ' . $title . '</p></main></body></html>';
$css = "body{font-family:poppins,sans-serif}h1{font-family:'Open Sans',sans-serif;font-size:48px;font-family:Libre Caslon Display;font-weight:400}h2{font-family:Libre Caslon Display;font-size:32px}";
foreach (array(true, false) as $shared) {
    $files = array('index.html' => $page('Home'), 'about.html' => $page('About'), 'style.css' => $css);
    if (!$shared) $files['listing.html'] = '<!doctype html><html><head></head><body><main><h1>Listing</h1><p>Listing copy</p></main></body></html>';
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $theme = array();
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    $deliveredCss = '';
    foreach ($plan['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? null)) $deliveredCss .= $asset['content'] ?? '';
    }
    preg_match_all('/(?<![\w.#:-])h1\{([^}]*)\}/', $deliveredCss, $rules);
    $rule = implode(';', $rules[1]);
    $family = $theme['styles']['elements']['h1']['typography']['fontFamily'] ?? $theme['styles']['elements']['heading']['typography']['fontFamily'] ?? null;
    if ($shared) {
        if (null === $family) throw new RuntimeException('A universally loaded element font is owned by Global Styles.');
        if (str_contains($rule, 'font-family:')) throw new RuntimeException('An element font owned by Global Styles yields its stylesheet declaration.');
    } else {
        if (null !== $family) throw new RuntimeException('A partially loaded stylesheet cannot set a global heading font.');
        if (!preg_match('/font-family:[^;]*Libre Caslon Display/', $rule)) throw new RuntimeException('The scoped h1 rule must retain its winning font family when Global Styles does not own it.');
    }
}
echo "Scoped element font binding contract passed.\n";
