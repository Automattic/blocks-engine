import { mkdir, writeFile } from 'node:fs/promises';
import { execFileSync, spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { generateCases, familyNames } from './catalog.mjs';
const opts={seed:1,count:40,viewports:[390,1440],families:familyNames};
for(let i=2;i<process.argv.length;i++){const k=process.argv[i]; if(k==='--seed'||k==='--count')opts[k.slice(2)]=Number(process.argv[++i]); else if(k==='--families')opts.families=process.argv[++i].split(','); else if(k==='--viewports')opts.viewports=process.argv[++i].split(',').map(Number); else if(k==='--report')opts.report=process.argv[++i]; else if(k==='--out')opts.out=process.argv[++i]; else throw Error(`Unknown option ${k}`);}
const root=new URL('../../../',import.meta.url).pathname;
const phpCode='require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
const cases=generateCases(opts); const browser=await chromium.launch({headless:true}); const page=await browser.newPage(); const results=[];
try { for(const c of cases){const html=`<!doctype html><html><head><style>${c.css}</style></head><body>${c.html}</body></html>`;
 const transformed=JSON.parse(execFileSync('php',['-r',phpCode,root,Buffer.from(html).toString('base64')],{encoding:'utf8'}));
 const output=`<!doctype html><html><head><style>${(transformed.assets||[]).map(a=>a.content||'').join('\n')}</style></head><body>${transformed.serialized_blocks}</body></html>`;
 const diffs=[],missing=[]; for(const width of opts.viewports){await page.setViewportSize({width,height:900}); const read=async markup=>{await page.setContent(markup);return page.evaluate(ids=>Object.fromEntries(ids.map(id=>{const e=document.getElementById(id); if(!e)return[id,null]; const s=getComputedStyle(e),r=e.getBoundingClientRect();return[id,{display:s.display,position:s.position,left:r.left,top:r.top,width:r.width,height:r.height,margin:s.margin,padding:s.padding,color:s.color,backgroundColor:s.backgroundColor,fontSize:s.fontSize,fontWeight:s.fontWeight,lineHeight:s.lineHeight,gap:s.gap,gridTemplateColumns:s.gridTemplateColumns,flexDirection:s.flexDirection}]})),c.ids)};
 const [a,b]=await Promise.all([read(html),read(output)]); for(const id of c.ids){if(!b[id]){missing.push({viewport:width,id});continue;} if(JSON.stringify(a[id])!==JSON.stringify(b[id])) diffs.push({viewport:width,id,source:a[id],output:b[id]});}}
 results.push({id:c.id,tags:c.tags,pass:!diffs.length&&!missing.length,diffs,missing}); if(!results.at(-1).pass&&opts.out){await mkdir(opts.out,{recursive:true});await writeFile(`${opts.out}/${c.id}.html`,html);}
 }} finally {await browser.close();}
const groups={};for(const r of results)if(!r.pass){const key=[...r.tags].sort().join('+');groups[key]=(groups[key]||0)+1;}
const report={schema:'blocks-engine/php-transformer/differential-report/v1',seed:opts.seed,cases:results,failures_by_tag_set:groups}; if(opts.report){await mkdir(new URL('.',`file://${process.cwd()}/${opts.report}`).pathname,{recursive:true}).catch(()=>{});await writeFile(opts.report,JSON.stringify(report,null,2));}
console.log(JSON.stringify({seed:opts.seed,count:results.length,passed:results.filter(x=>x.pass).length,failed:results.filter(x=>!x.pass).length,top_failing_tag_groups:Object.entries(groups).sort((a,b)=>b[1]-a[1]).slice(0,5)}));
