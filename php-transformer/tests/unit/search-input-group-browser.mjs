import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./search-input-group-presentation.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const attrs = JSON.parse(fixture.result.serialized_blocks.match(/<!-- wp:search (.*?) \/-->/s)[1]);
assert.equal(attrs.buttonUseIcon, true);
assert.equal(attrs.showLabel, false);
// core/search's public rendering contract and its relevant shipped styles.
const candidate = `<form class="wp-block-search wp-block-search__button-outside wp-block-search__icon-button ${attrs.className}"><div class="wp-block-search__inside-wrapper"><input type="search" class="wp-block-search__input"><button class="wp-block-search__button has-icon wp-element-button"><svg width="24" height="24"></svg></button></div></form>`;
const coreCss = `.wp-block-search__inside-wrapper{display:flex;flex:auto;flex-wrap:nowrap;max-width:100%}
.wp-block-search__input{appearance:none;border:1px solid #949494;flex-grow:1;margin:0;min-width:3rem;padding:8px;text-decoration:unset!important}
.wp-block-search__button{margin-left:10px;word-break:normal}
.wp-block-search__button.has-icon{line-height:0}
.wp-block-search__button svg{min-width:24px;min-height:24px;width:1.25em;height:1.25em;fill:currentColor;vertical-align:text-bottom}
:where(.wp-block-search__button){border:1px solid #ccc;padding:6px 10px}
:root :where(.wp-element-button){background-color:#32373c;color:#fff;padding:calc(.667em + 2px) calc(1.333em + 2px)}`;
// Bootstrap's reset and icon-font metrics, independent of the search rules.
const baseline = `*{box-sizing:border-box}body{margin:15px;font:14px/1.42857 Arial}
.glyphicon{display:inline-block;width:1em}.glyphicon:before{content:"\\2315"}
.sr-only{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}`;
const browser = await chromium.launch({headless:true});
try {
    const page = await browser.newPage();
    const measure = async (markup, css) => {
        await page.setContent(`<style>${baseline}${css}</style>${markup}`);
        return page.locator('input,button').evaluateAll(elements => elements.map(element => ({
            width: element.getBoundingClientRect().width,
            height: element.getBoundingClientRect().height,
            background: getComputedStyle(element).backgroundColor,
        })));
    };
    for (const width of [390, 1440]) {
        await page.setViewportSize({width, height:900});
        const source = await measure(fixture.source, fixture.css);
        const output = await measure(candidate, coreCss + fixture.stylesheet);
        assert.equal(output[1].background, 'rgb(126, 202, 236)', `${width}: ID-compound paint`);
        for (const index of [0, 1]) {
            for (const dimension of ['width', 'height']) {
                assert.ok(Math.abs(source[index][dimension] - output[index][dimension]) <= 1,
                    `${width}: control ${index} ${dimension}: ${source[index][dimension]} vs ${output[index][dimension]}`);
            }
        }
    }
    console.log('Search input-group dimensions and paint match at mobile and desktop widths.');
} finally {
    await browser.close();
}
