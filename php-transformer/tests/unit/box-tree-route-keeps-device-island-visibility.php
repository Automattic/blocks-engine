<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A responsive capture keeps both device copies of an island in one document
// and ships the sheet that shows only one of them per viewport. A route with
// box-tree evidence retires its author CSS, but that sheet is the capture's
// device gate, not author styling: without it both copies render at every
// width (a Wix route showed each hero photo twice on phones).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$html = (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$islands = '<div class="photo data-blocks-engine-capture-desktop-only"><p>Desktop photo</p></div><div class="photo data-blocks-engine-capture-mobile-only"><p>Phone photo</p></div>';
$html = str_replace('</head>', '<link rel="stylesheet" href="author.css"><link rel="stylesheet" href="device.css"></head>', $html);
$html = str_replace('</main>', $islands . '</main>', $html);
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$files = array(
    'index.html' => $html,
    'author.css' => 'h1{opacity:.25}.photo{outline:1px solid red}',
    'device.css' => '@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}',
    'box-tree/index.json' => json_encode($tree),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route owns its styles through box-tree evidence.');
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $delivered .= $assets[$row['target_path']]['content'] ?? '';
$assert(!str_contains($delivered, 'opacity:.25') && !str_contains($delivered, 'solid red'), 'Author rules are still retired on the evidence route.');
$assert(1 === preg_match('/@media\s*\(min-width:\s*1024px\)\s*\{[^{}]*\.data-blocks-engine-capture-mobile-only[^{}]*\{display:none!important\}/', $delivered), 'The mobile-only island stays hidden at desktop widths.');
$assert(1 === preg_match('/@media\s*\(max-width:\s*1023px\)\s*\{[^{}]*\.data-blocks-engine-capture-desktop-only[^{}]*\{display:none!important\}/', $delivered), 'The desktop-only island stays hidden at phone widths.');
