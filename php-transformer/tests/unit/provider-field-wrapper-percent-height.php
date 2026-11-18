<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A contact form's textarea fills its column through an authored percentage
// height (`.form-group-textarea textarea{height:100%}`). The provider renders
// its field wrapper between that column box and the textarea, so the
// percentage must pass through the wrapper or it resolves to auto and the
// textarea collapses to its min-height.
$html = '<html><head><title>Contact</title><link rel="stylesheet" href="css/styles.css"></head><body>'
    . '<section id="contact"><form id="contactForm"><div class="row">'
    . '<div class="col"><div class="form-group"><input class="form-control" id="name" type="text" placeholder="Your Name *"></div>'
    . '<div class="form-group"><input class="form-control" id="email" type="email" placeholder="Your Email *"></div></div>'
    . '<div class="col"><div class="form-group form-group-textarea"><textarea class="form-control" id="message" placeholder="Your Message *"></textarea></div></div>'
    . '</div><button class="btn" type="submit">Send</button></form></section></body></html>';
$css = 'section#contact .row{display:flex}section#contact .col{flex:1}'
    . 'section#contact form#contactForm .form-group-textarea{height:100%}'
    . '@media (min-width:768px){section#contact form#contactForm .form-group-textarea textarea{height:100%;min-height:10rem}}'
    . 'section#contact form#contactForm .form-group input{height:auto}';

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'website/css/styles.css', 'content' => $css),
    ),
))->toWordPressSitePlanView();

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$stylesheet = '';
foreach ($result['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('text/css' === ($asset['mime_type'] ?? '') && str_ends_with((string) ($asset['target_path'] ?? ''), 'css/styles.css')) $stylesheet = (string) $asset['content'];
}
$check('' !== $stylesheet, 'the authored stylesheet is projected');

// Every control the rule selects sits in the provider wrapper, so the rule
// itself passes the column's height through that wrapper, inside the source
// rule's own media condition. Inputs, with no percentage height, are untouched.
$check(1 === substr_count($stylesheet, ':has(>&)'), 'only the percentage-height control rule reaches through the provider wrapper');
$check(1 === preg_match('/@media \(min-width:768px\)\{[^{}]*\.form-group-textarea textarea\{min-height:10rem;&,:has\(>&\)\{height:100%\}\}\}/', $stylesheet), 'the textarea rule shares its height with the provider wrapper holding it: ' . $stylesheet);
echo "Provider field wrapper percent-height regression passed\n";
