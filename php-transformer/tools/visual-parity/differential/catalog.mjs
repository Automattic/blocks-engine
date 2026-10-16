// Small deterministic corpus. Each generator returns standalone markup, styles and comparison ids.
export function rngFor(seed) {
  let state = seed >>> 0;
  return () => { state = (state * 1664525 + 1013904223) >>> 0; return state / 4294967296; };
}
const families = {
 selectors: r => ({ html:'<div id="a"><p id="b" class="x">marker-b</p><p id="c">marker-c</p></div>', css:':where(div) p{color:red} div > .x{padding:2px} p + p{margin-left:3px} div p:not(.z){font-size:14px}', ids:['b','c'], tags:['selectors','where','combinators'] }),
 cascade: r => ({ html:'<div id="a"><p id="b">marker-b</p></div>', css:':root{--MiXeD: red}@media(min-width:600px){:root{--MiXeD:blue}} p{color:var(--MiXeD, green)} p{color:purple}', ids:['b'], tags:['cascade','custom-properties','media'] }),
 layout: r => ({ html:'<div id="a"><div id="b">marker-b</div><div id="c">marker-c</div></div>', css:'#a{display:grid;grid-template-columns:1fr 1fr;gap:8px} #b{min-width:auto} #c{aspect-ratio:2}', ids:['a','b','c'], tags:['layout','grid','automatic-minimum'] }),
 box: r => ({ html:'<div id="a"><p id="b">marker-b typography</p></div>', css:'#a{font-size:16px;color:teal} #b{margin:2px 4px;padding-inline:1em;font-size:clamp(12px,4vw,20px);line-height:1.4}', ids:['a','b'], tags:['box','typography','logical-properties'] }),
 recognizers: r => ({ html:'<section id="a"><h2 id="b">marker-b</h2><ul><li id="c">marker-c</li></ul><figure><img alt="" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="><figcaption id="d">marker-d</figcaption></figure><a id="e" href="#">marker-e</a></section>', css:'h2{color:navy} a{display:inline-block;padding:8px;background:orange} figure{display:flex}', ids:['a','b','c','d','e'], tags:['recognizers','semantic-html'] })
};
export const familyNames = Object.keys(families);
export function generateFamily(name, rng = Math.random) { if (!families[name]) throw new Error(`Unknown family ${name}`); return families[name](rng); }
export function generateCases({seed=1,count=40,families:names=familyNames}={}) {
 const rng=rngFor(seed); return Array.from({length:count},(_,i)=>{const name=names[Math.floor(rng()*names.length)]; return {id:`case-${String(i+1).padStart(4,'0')}-${name}`, family:name,...generateFamily(name,rng)};});
}
export { families };
