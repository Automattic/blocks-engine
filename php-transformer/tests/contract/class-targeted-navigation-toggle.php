<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Reduced dgti.uerj.br navbar from #4828, including its class-only binding.
$html = <<<'HTML'
<!doctype html><html><head><style>
.collapse{display:none}
@media (min-width:768px){.navbar-collapse.collapse{display:block!important}.navbar-toggle{display:none}}
@media (max-width:767px){.navbar-toggle{color:#016ba5;float:left;padding:9px 10px;margin:5px 5px 5px 15px;background:transparent;border:1px solid #016ba5;border-radius:4px}}
.menu-navegacao{background:#0080c6}
</style></head><body>
<nav class="menu-navegacao" role="navigation"><div class="container"><div class="row">
  <button type="button" class="btn navbar-toggle" data-toggle="collapse" data-target=".navbar-ex1-collapse">
    <span class="sr-only">Menu</span><span class="glyphicon glyphicon-menu-hamburger"></span></button>
  <div class="col-xs-12 col-md-9 menu-container collapse navbar-collapse navbar-ex1-collapse">
    <ul id="menu-menuprincipal" class="nav navbar-nav"><li><a href="/">INÍCIO</a></li><li><a href="/servicos/">SERVIÇOS</a></li></ul></div>
</div></div></nav>
</body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$blocks = (string) $result['serialized_blocks'];
if (1 !== preg_match('/<!-- wp:navigation (\{.*?\}) -->/', $blocks, $match) || 'mobile' !== (json_decode($match[1], true)['overlayMenu'] ?? null)) {
    fwrite(STDERR, "FAIL: class-targeted collapse must use the operable native mobile overlay.\n");
    exit(1);
}
$attrs = json_decode($match[1], true);
$failures = array();
if (1 !== substr_count($blocks, '<!-- wp:navigation ') || str_contains($blocks, '<!-- wp:button')) {
    $failures[] = 'Exactly one native navigation and no separate source button must remain.';
}
foreach (array('INÍCIO', 'SERVIÇOS') as $label) {
    if (!str_contains($blocks, $label)) $failures[] = 'Native overlay must retain menu item ' . $label;
}
if (!str_contains($attrs['className'] ?? '', 'blocks-engine-native-responsive-navigation')) {
    $failures[] = 'Navigation must own the responsive opener.';
}
// Exercise the emitted attributes and CSS against Core\'s responsive states.
// Core hides the opener at >=600px and hides the closed panel below 600px;
// author-boundary overrides must not hide the opened panel or affect desktop.
$dom = new DOMDocument();
$dom->loadHTML('<html><body></body></html>');
$host = $dom->createElement('nav');
$host->setAttribute('class', 'wp-block-navigation ' . ($attrs['className'] ?? ''));
$dom->getElementsByTagName('body')->item(0)->appendChild($host);
$targets = array();
foreach (array('opener' => 'wp-block-navigation__responsive-container-open', 'closed' => 'wp-block-navigation__responsive-container', 'opened' => 'wp-block-navigation__responsive-container is-menu-open') as $state => $class) {
    $target = $dom->createElement('div');
    $target->setAttribute('class', $class);
    $host->appendChild($target);
    $targets[$state] = $target;
}
foreach (array(390, 700, 1440) as $width) {
    $display = array('opener' => $width < 600 ? 'flex' : 'none', 'closed' => $width < 600 ? 'none' : 'contents', 'opened' => 'flex');
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body, array $conditions) use ($width, $targets, &$display): void {
            if (!str_contains($selector, 'blocks-engine-native-') || !preg_match('/(?:^|;)display:(flex|none|contents)!important/', $body, $value)) return;
            foreach ($conditions as $condition) {
                if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) return;
                if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) return;
            }
            $parsed = CssSelectorMatcher::parse($selector);
            foreach ($targets as $state => $target) {
                if (CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false) $display[$state] = $value[1];
            }
        });
    }
    if (($width < 768 ? 'flex' : 'none') !== $display['opener'] || ($width < 768 ? 'none' : 'contents') !== $display['closed'] || 'none' === $display['opened']) {
        $failures[] = "$width: one mobile opener must reveal the panel; desktop must stay inline without an opener.";
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
