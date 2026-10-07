import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = await chromium.launch({headless:true});
try {
  for (const listStyle of ['disc','square','none']) {
    const source = `<style>body{margin:0;font:16px/24px Arial}li{list-style-type:${listStyle}}</style><div style="padding:32px"><li>First point</li><li>Second point</li></div>`;
    const program = 'require "vendor/autoload.php"; $r=(new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[1])->toArray(); echo json_encode(["markup"=>$r["serialized_blocks"],"css"=>implode("\\n",array_map(static fn($a)=>$a["content"]??"",array_filter($r["assets"],static fn($a)=>"css"===($a["kind"]??""))))]);';
    const compiled = JSON.parse(execFileSync('php',['-r',program,source],{cwd:root,encoding:'utf8'}));
    const pages = [];
    for (const html of [source,`<style>${compiled.css}</style>${compiled.markup}`]) {
      const page=await browser.newPage({viewport:{width:390,height:200}});
      await page.setContent(html);
      const geometry=await page.locator('li').evaluateAll(nodes=>nodes.map(node=>{const r=node.getBoundingClientRect(),s=getComputedStyle(node);return {x:r.x,y:r.y,width:r.width,height:r.height,display:s.display,marker:s.listStyleType};}));
      pages.push({page,geometry,pixels:await page.screenshot()});
    }
    assert.equal(pages[0].geometry.length,2);
    assert.deepEqual(pages[1].geometry,pages[0].geometry,`${listStyle}: source marker semantics and item geometry`);
    assert(pages[0].pixels.equals(pages[1].pixels),`${listStyle}: exact rendered pixels`);
    for(const item of pages)await item.page.close();
  }
  console.log('PASS: orphan disc/square/none markers, item geometry and pixels match');
} finally {await browser.close();}
