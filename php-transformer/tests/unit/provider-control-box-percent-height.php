<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A labelled textarea sits alone in its own framed box below the caption
// level (`.frame{height:92px}`), and fills that box with `height:100%`. The
// provider renders that frame around its control, so the control's parent on
// the front end is still the frame. The percentage resolves against the frame
// as authored; reaching through to the frame with `:has(>&){height:100%}`
// would override the frame's own height and collapse it to the control's
// intrinsic rows.
$html = '<html><head><title>Contact</title><link rel="stylesheet" href="css/styles.css"></head><body>'
    . '<main><form class="contact">'
    . '<div class="field"><label for="name">Name</label><div class="frame-input"><input class="control" id="name" type="text"></div></div>'
    . '<div class="field"><label for="email">Email</label><div class="frame-input"><input class="control" id="email" type="email"></div></div>'
    . '<div class="field"><div class="caption"><label for="message">Message</label></div>'
    . '<div class="slot"><div class="box"><div class="frame"><textarea class="message" id="message"></textarea></div></div><div class="error"></div></div></div>'
    . '<button class="send" type="submit">Send</button></form></main></body></html>';
$css = '.field{display:flex;flex-direction:column}'
    . '.frame-input{border:1px solid #333}'
    . '.frame{height:92px;padding:8px 0;border:1px solid #333;display:flex}'
    . '.message{height:100%;width:100%;border:0;resize:none}';

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
$check(str_contains($stylesheet, 'height:92px'), 'the frame keeps its own height: ' . $stylesheet);
$check(!str_contains($stylesheet, ':has(>&)'), 'a control inside its own rendered frame does not pass its height through to that frame: ' . $stylesheet);
$check(1 === preg_match('/\.message\{[^{}]*height:100%[^{}]*\}/', $stylesheet), 'the control keeps its authored percentage height: ' . $stylesheet);
echo "Provider control box percent-height regression passed\n";
