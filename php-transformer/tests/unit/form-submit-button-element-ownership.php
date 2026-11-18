<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A dominant button look becomes the theme's button element style only for
 * the fields every button block owns. A declared form's submit control is a
 * button block too: the form provider materializes it as a `core/button`
 * carrying only its declared presentation style. A newsletter submit that
 * inherits the body's letter spacing and weight must not take the dominant
 * look's `letter-spacing` and `font-weight` from the element style, while
 * the native buttons keep those values as their own.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = 'body{font-family:Georgia,serif}.btn{font-family:Oswald,sans-serif;font-size:.92rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;border-radius:9px;padding:13px 22px;display:inline-block;background:#e2622a;color:#fff}'
    . '.news button{font-family:inherit;font-size:15px;text-transform:uppercase;background:#262626;color:#fff;padding:1em 2.5em;border:1px solid #262626}';
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $route) {
    $body = '<nav><a href="index.html">Home</a><a href="about.html">About</a><a href="contact.html">Contact</a></nav>';
    for ($i = 0; $i < 4; ++$i) $body .= '<a href="#" class="btn">Continue</a>';
    $body .= '<form class="news" action="/subscribe" method="post"><label for="e">Email</label><input id="e" type="email" name="email" required><button type="submit"><span class="label">Sign Up</span></button></form>';
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];

$submits = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['controls'] ?? array() as $control) {
        if ('submit' === ($control['type'] ?? null)) $submits[] = $control['presentation']['style'] ?? array();
    }
}
$assert(array() !== $submits, 'The newsletter submit is a declared form control.');
foreach ($submits as $style) $assert(!isset($style['typography']['letterSpacing']), 'The submit declares no letter spacing of its own: ' . json_encode($style));

$theme = array();
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    else $markup .= (string) $write['payload']['data'];
}
$button = $theme['styles']['elements']['button'] ?? array();
$assert(array() !== $button, 'The dominant look still projects a button element style.');
$assert(!isset($button['typography']['letterSpacing']), 'The element style states no letter spacing the submit would take: ' . json_encode($button));
$assert(!isset($button['typography']['fontWeight']), 'The element style states no weight the submit would take: ' . json_encode($button));
$assert('uppercase' === ($button['typography']['textTransform'] ?? null), 'A field the submit owns stays on the element: ' . json_encode($button));

preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches);
$assert(count($matches[1]) >= 4, 'The native buttons convert to core/button.');
foreach ($matches[1] as $json) {
    $typography = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['style']['typography'] ?? array();
    $assert('.07em' === ($typography['letterSpacing'] ?? null), 'A native button keeps its own letter spacing: ' . $json);
    $assert('600' === ($typography['fontWeight'] ?? null), 'A native button keeps its own weight: ' . $json);
}

echo "Form submit controls veto the button element fields they do not carry.\n";
