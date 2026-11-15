<?php
declare(strict_types=1);

/**
 * A menu item that capture wired to its own dropdown panel stays that panel's
 * opener. A separate hidden drawer menu elsewhere in the header belongs to the
 * hamburger: offering it to the dropdown trigger consumes the trigger, which
 * orphans the panel in its captured open state.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$panelState = htmlspecialchars((string) json_encode(array(
    'resting' => array('class' => 'data-blocks-engine-capture-dropdown', 'style' => null, 'hidden' => false),
    'opened' => array('class' => 'open data-blocks-engine-capture-dropdown', 'style' => 'display:block;visibility:visible!important'),
)), ENT_QUOTES);
$key = 'data-blocks-engine-capture-dialog-0';
$html = <<<HTML
<!doctype html><html><head><style>
.desktop-row{display:flex}.burger{display:none}.drawer{display:none}
.main-nav ul{display:flex;gap:12px;list-style:none}
#mega>[id]{transform:translateY(-100vh);opacity:0}
#mega>[id].open{transform:none;opacity:1}
@media (max-width:767px){.desktop-row .main-nav{display:none}.burger{display:block}.drawer.is-open{display:block}}
</style><script data-blocks-engine-capture-disclosure-runtime="true">{$runtime}</script></head><body>
<header>
 <div class="desktop-row">
  <nav class="main-nav"><ul>
   <li><a href="/">Home</a></li>
   <li><a href="/about/">About</a></li>
   <li><button type="button" class="nav-item" aria-expanded="false" aria-controls="{$key}" data-blocks-engine-capture-dialog-trigger="{$key}">Services</button></li>
   <li><a href="/contact/">Contact</a></li>
  </ul></nav>
  <button type="button" class="burger" aria-label="Open menu"><span></span><span></span></button>
 </div>
 <div class="drawer"><nav class="drawer-nav"><ul>
  <li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/design/">Design</a></li><li><a href="/build/">Build</a></li><li><a href="/contact/">Contact</a></li>
 </ul></nav></div>
 <div id="mega"><div id="{$key}" class="open data-blocks-engine-capture-dropdown" style="display:block;visibility:visible!important" data-blocks-engine-capture-dialog-panel="{$key}" data-blocks-engine-capture-existing-panel="{$panelState}">
  <p>Services</p><nav class="services-nav"><ul><li><a href="/design/">Design</a></li><li><a href="/build/">Build</a></li></ul></nav>
 </div></div>
</header>
<main><h1>Home</h1><p>Body.</p></main>
</body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
)))->toArray();
$markup = (string) $result['serialized_blocks'];

$failures = array();
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<body>' . $markup . '</body>');
$xpath = new DOMXPath($dom);
$triggers = $xpath->query('//button[@data-blocks-engine-capture-dialog-trigger="' . $key . '"][@aria-controls="' . $key . '"]');
if (!$triggers || 1 !== $triggers->length || 'Services' !== trim((string) $triggers->item(0)?->textContent)) {
    $failures[] = 'The Services menu item must remain the captured opener of its dropdown panel.';
}
if (1 !== $xpath->query('//*[@id="' . $key . '"]')?->length) {
    $failures[] = 'The captured dropdown panel must be emitted once.';
}
$drawer = null;
foreach ($xpath->query('//comment()') ?: array() as $comment) {
    if (preg_match('/^ wp:navigation (\{.*\}) $/s', (string) $comment->data, $match)) {
        $attributes = json_decode($match[1], true);
        if (str_contains((string) ($attributes['className'] ?? ''), 'drawer-nav')) $drawer = $attributes;
    }
}
if (!is_array($drawer) || 'mobile' !== ($drawer['overlayMenu'] ?? '')) {
    $failures[] = 'The hidden drawer menu must become the hamburger\'s responsive navigation.';
}

foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
