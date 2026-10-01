<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>*{box-sizing:border-box}body{margin:0;font:14px/20px Arial}.bar{display:flex;justify-content:flex-end;align-items:center;gap:12px;width:900px;height:80px}.trigger{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;background:#edf2fa;color:#253858}.trigger svg{display:block}.next{padding:10px 20px}summary{list-style:none}</style><div class="bar"><details><summary class="trigger">More<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6"/></svg></summary><p>Content</p></details><span class="next">Next</span></div>';
$html = str_replace('<details>', '<details class="disclosure">', $html);
$html = str_replace('</style>', '.trigger:hover{background:#d0dfed}:where(details.disclosure>summary){display:inline-block}</style>', $html);
$out = (new HtmlTransformer())->transform($html)->toArray();
$css = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string)($asset['content'] ?? '') : '', $out['assets'] ?? array()));
echo json_encode(array('source'=>$html, 'candidate'=>'<style>'.$css.'</style>'.$out['serialized_blocks'], 'validity'=>$out['source_reports']['wp_block_validity'] ?? null));
