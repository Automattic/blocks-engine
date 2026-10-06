<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>*{box-sizing:border-box}body{margin:0;font:14px/20px Arial}.bar{display:flex;justify-content:flex-end;align-items:center;gap:12px;width:900px;height:80px}.trigger{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;background:#edf2fa;color:#253858}.trigger svg{display:block}.next{padding:10px 20px}summary{list-style:none}</style><div class="bar"><details><summary class="trigger">More<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6"/></svg></summary><p>Content</p></details><span class="next">Next</span></div>';
$html = str_replace('<details>', '<details class="disclosure">', $html);
$html = str_replace('</style>', '.trigger:hover{background:#d0dfed}:where(details.disclosure>summary){display:inline-block}</style>', $html);
$out = (new HtmlTransformer())->transform($html)->toArray();
$css = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string)($asset['content'] ?? '') : '', $out['assets'] ?? array()));

$responsive = <<<'HTML'
<style>
*{box-sizing:border-box}body{margin:0;font:16px/24px Arial,sans-serif}
.site-header{padding:24px 0;margin-bottom:8px}.bar{display:flex;align-items:center;justify-content:space-between;width:min(100%,672px);height:48px;margin:0 auto;padding:0 24px}
.brandbox{display:block;width:48px;height:48px;background:#ddd}
.navigation{display:none}.menu-icon{display:block;width:20px;height:20px}
summary{list-style:none}.p-2{padding:8px}.screen-reader-text{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
:where(details.dla-disclosure>summary){cursor:pointer;display:inline-block}
@media(min-width:640px){.site-header{padding:40px 0;margin-bottom:32px}.bar{height:24px}.brandbox{width:83px;height:24px}.navigation{display:block}.small-hidden{display:none}}
</style>
<div class="site-header"><div class="bar"><div class="brandbox"></div><nav class="navigation">About Writing Projects Speaking</nav><details class="dla-disclosure dla-dropdown"><summary class="small-hidden p-2 text-muted-foreground hover:text-foreground transition-colors touch-manipulation focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-md cursor-pointer"><svg class="menu-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M1 4h18M1 10h18M1 16h18"></path></svg><span class="screen-reader-text">Open menu</span></summary><div class="dla-dialog">Menu</div></details></div></div><div class="content"><h1>Speaking</h1></div>
HTML;
$responsiveResult = (new HtmlTransformer())->transform($responsive)->toArray();
$responsiveCss = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string)($asset['content'] ?? '') : '', $responsiveResult['assets'] ?? array()));

echo json_encode(array(
    'source' => $html,
    'candidate' => '<style>' . $css . '</style>' . $out['serialized_blocks'],
    'validity' => $out['source_reports']['wp_block_validity'] ?? null,
    'responsiveSource' => $responsive,
    'responsiveCandidate' => '<style>' . $responsiveCss . '</style>' . $responsiveResult['serialized_blocks'],
    'responsiveValidity' => $responsiveResult['source_reports']['wp_block_validity'] ?? null,
));
