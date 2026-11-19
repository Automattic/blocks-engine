<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\CompleteMarkerLists;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A positional stack rule reaches every link-button on the site. The buttons
// carry different markers, so projection folds their control arms into one
// `:where(.marker,.marker)` list. Once markup is final that list names every
// control carrier the site has, which is exactly what the control role class
// addresses, so the sheet ships the role class instead of the enumeration.
$css = '.stack>:not([hidden])~:not([hidden]){margin-top:12px}.btn{padding:8px 16px;border-radius:6px}'
    . '.primary{background:#0f3d2e;color:#fff}.ghost{background:#fff;color:#0f3d2e}.outline{border:1px solid #0f3d2e}';
$page = static fn (string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body><main>' . $body . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('Home', '<div class="stack"><p>Intro copy</p><a class="btn primary" href="/book">Book</a><a class="btn ghost" href="/menu">Menu</a><a class="btn outline" href="/call">Call</a></div>'),
        'about.html' => $page('About', '<div class="stack"><h2>About</h2><p>More</p><a class="btn ghost" href="/menu">Menu</a></div>'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(1 === preg_match('/(?<!:not\():where\(\.blocks-engine-role-control\)[^{}]*\{margin-top:12px\}/', $sheets), 'The stack rule reaches the controls through the control role class.');
$assert(1 !== preg_match('/:where\(\.blocks-engine-control-[0-9a-f]{12}-[0-9]+,[^{}]*\{margin-top:12px\}/', $sheets), 'No marker list naming every control ships.');

// A list naming most carriers is written as the role class minus the rest,
// when that is shorter; a short list stays as it is.
$marker = static fn (string $seed): string => 'blocks-engine-control-' . substr(hash('sha256', $seed), 0, 12) . '-0';
$all = array_map($marker, array('a', 'b', 'c', 'd', 'e'));
$block = '';
foreach ($all as $name) {
    $block .= '<!-- wp:button {"className":"' . $name . ' blocks-engine-role-control"} --><div class="wp-block-button ' . $name . ' blocks-engine-role-control"></div><!-- /wp:button -->';
}
$most = ':where(.' . implode(',.', array_slice($all, 0, 4)) . ')>a{color:red}';
$few = ':where(.' . $all[0] . ',.' . $all[1] . ')>a{color:blue}';
$sheet = static fn (string $content): array => array('kind' => 'css', 'content' => $content);
[$compacted] = CompleteMarkerLists::compact(array($sheet($most . $few)), $block, array());
$assert(':where(.blocks-engine-role-control:not(.' . $all[4] . '))>a{color:red}' . $few === $compacted['content'], 'Most of the carriers are the role class minus the one left out; two of five stay listed.');

// Proof is required: a carrier with two markers, or a role class named
// outside any class list, leaves every list of that kind as it is.
$doubled = $block . '<div class="' . $all[0] . ' ' . $all[1] . ' blocks-engine-role-control"></div>';
[$kept] = CompleteMarkerLists::compact(array($sheet($most)), $doubled, array());
$assert($most === $kept['content'], 'A carrier with two markers keeps the enumeration.');
[$kept] = CompleteMarkerLists::compact(array($sheet($most)), $block, array(array('script' => 'el.classList.add("blocks-engine-role-control")')));
$assert($most === $kept['content'], 'A role class added outside markup keeps the enumeration.');

// Runtime records name a carrier as a list of class tokens. That carrier
// counts like any other, so a list naming all five compacts in full.
$five = ':where(.' . implode(',.', $all) . ')>a{color:red}';
$entity = array(array('payload' => array('controls' => array(array('control_carrier_classes' => array($all[4], 'blocks-engine-role-control'))))));
[$compacted] = CompleteMarkerLists::compact(array($sheet($five)), substr($block, 0, (int) strrpos($block, '<!-- wp:button')), $entity);
$assert(':where(.blocks-engine-role-control)>a{color:red}' === $compacted['content'], 'A carrier named by a runtime class-token list counts as one carrier.');

echo "Complete marker lists passed\n";
