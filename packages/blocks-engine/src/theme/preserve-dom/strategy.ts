import * as cheerio from 'cheerio';
import type { Cheerio, CheerioAPI } from 'cheerio';
import { isTag, isText } from 'domhandler';
import type { Element } from 'domhandler';
import { escapeHtmlAttr as escapeHtml } from '../../escape.js';
import type { NativeSectionDecision, SectionStrategy, StrategyState } from '../native-reconstruct-types.js';
import type { SectionSpec } from '../section-spec.js';
import { InstanceStyleSheet } from './instance-styles.js';

type SourceIdentitySection = SectionSpec & {
  sourceId?: string;
  sourceClasses?: string[];
};

interface ChildResult {
  markup: string;
  clean: boolean;
}

function attrJson(value: string): string {
  return JSON.stringify(value).replace(/--/g, '\\u002d\\u002d');
}

function classNameOf($el: Cheerio<Element>): string {
  return ($el.attr('class') ?? '').split(/\s+/).filter(Boolean).join(' ');
}

function blockAttrs(pairs: string[], className: string): string {
  const all = className ? [...pairs, `"className":${attrJson(className)}`] : pairs;
  return all.length ? ` {${all.join(',')}}` : '';
}

function classNameWithInstance($el: Cheerio<Element>, sheet: InstanceStyleSheet): string {
  const base = classNameOf($el);
  const instance = sheet.classFor($el.attr('style'));
  return [base, instance].filter(Boolean).join(' ');
}

function paragraphBlock(inner: string): string {
  return `<!-- wp:paragraph -->\n<p>${inner}</p>\n<!-- /wp:paragraph -->`;
}

const HEADING = /^h([1-6])$/;
const INLINE_ALLOWED = new Set(['a', 'strong', 'em', 'b', 'i', 'br', 'span']);

function inlineHtml($: CheerioAPI, el: Element): string {
  let out = '';
  for (const node of $(el).contents().get()) {
    if (isText(node)) {
      out += escapeHtml(node.data);
    } else if (isTag(node)) {
      const tag = node.tagName?.toLowerCase() ?? '';
      if (tag === 'br') {
        out += '<br/>';
        continue;
      }
      const cls = ($(node).attr('class') ?? '').trim();
      const styleA = ($(node).attr('style') ?? '').trim();
      if (INLINE_ALLOWED.has(tag)) {
        const inner = inlineHtml($, node);
        const clsAttr = cls ? ` class="${escapeHtml(cls)}"` : '';
        if (tag === 'a') {
          const href = escapeHtml($(node).attr('href') ?? '');
          out += `<a${clsAttr} href="${href}">${inner}</a>`;
        } else {
          const styleAttr = styleA ? ` style="${escapeHtml(styleA)}"` : '';
          out += `<${tag}${clsAttr}${styleAttr}>${inner}</${tag}>`;
        }
      } else {
        out += inlineHtml($, node);
      }
    }
  }
  return out;
}

function imageBlock($: CheerioAPI, imgEl: Element, sheet: InstanceStyleSheet): string {
  const src = escapeHtml($(imgEl).attr('src') ?? '');
  const alt = escapeHtml($(imgEl).attr('alt') ?? '');
  const cls = classNameWithInstance($(imgEl), sheet);
  const attrs = blockAttrs([], cls);
  const figCls = ['wp-block-image', cls].filter(Boolean).join(' ');
  return `<!-- wp:image${attrs} -->\n<figure class="${escapeHtml(figCls)}"><img src="${src}" alt="${alt}"/></figure>\n<!-- /wp:image -->`;
}

function emitChild($: CheerioAPI, el: Element, sheet: InstanceStyleSheet): ChildResult {
  const tag = el.tagName?.toLowerCase() ?? '';
  const $el = $(el);

  const h = HEADING.exec(tag);
  if (h) {
    const level = Number(h[1]);
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs(level === 2 ? [] : [`"level":${level}`], cls);
    const htmlCls = ['wp-block-heading', cls].filter(Boolean).join(' ');
    const inner = inlineHtml($, el).trim();
    return {
      markup: `<!-- wp:heading${attrs} -->\n<h${level} class="${escapeHtml(htmlCls)}">${inner}</h${level}>\n<!-- /wp:heading -->`,
      clean: true,
    };
  }

  if (tag === 'p') {
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs([], cls);
    const inner = inlineHtml($, el).trim();
    const clsPart = cls ? ` class="${escapeHtml(cls)}"` : '';
    const open = `<p${clsPart}>`;
    return { markup: `<!-- wp:paragraph${attrs} -->\n${open}${inner}</p>\n<!-- /wp:paragraph -->`, clean: true };
  }

  if (tag === 'img') {
    return { markup: imageBlock($, el, sheet), clean: true };
  }

  const text = $el.text().trim();
  const elementChildren = $el.children().toArray();
  if ((tag === 'div' || tag === 'span') && !$el.attr('id') && elementChildren.length === 0 && text) {
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs([], cls);
    const clsPart = cls ? ` class="${escapeHtml(cls)}"` : '';
    return {
      markup: `<!-- wp:paragraph${attrs} -->\n<p${clsPart}>${inlineHtml($, el).trim()}</p>\n<!-- /wp:paragraph -->`,
      clean: true,
    };
  }

  // TODO(P3-S3): port non-core preserve-DOM branches only after this lib-i core proof lands.
  return { markup: '', clean: false };
}

function sheetFromState(state: StrategyState): InstanceStyleSheet {
  if (state.instanceStyles instanceof InstanceStyleSheet) return state.instanceStyles;
  const sheet = new InstanceStyleSheet();
  state.instanceStyles = sheet;
  return sheet;
}

export const preserveDomStrategy: SectionStrategy = {
  name: 'preserve-dom',
  render(section, _options, _ctx, state): NativeSectionDecision | null {
    const source = section as SourceIdentitySection;
    const sourceHtml = section.sectionHtml ?? section.styledHtml ?? '';
    if (!sourceHtml) return null;

    const sheet = sheetFromState(state);
    const $ = cheerio.load(sourceHtml);
    const root = $('section, article, main, div').first();
    const container = root.length ? root : $('body');
    const childMarkup: string[] = [];
    let downgrades = 0;
    let total = 0;

    for (const node of container.contents().get()) {
      if (isTag(node)) {
        total += 1;
        const res = emitChild($, node, sheet);
        if (!res.clean) downgrades += 1;
        if (res.markup) childMarkup.push(res.markup);
      } else if (isText(node)) {
        const text = node.data.trim();
        if (!text) continue;
        total += 1;
        childMarkup.push(paragraphBlock(escapeHtml(text)));
      }
    }

    const inner = childMarkup.join('\n');
    const sourceId = source.sourceId ?? container.attr('id')?.trim();
    const sourceClasses =
      source.sourceClasses && source.sourceClasses.length > 0
        ? source.sourceClasses
        : (container.attr('class') ?? '').split(/\s+/).filter(Boolean);
    const cls = sourceClasses.join(' ');
    const sectionInstance = sheet.classFor(container.attr('style'));
    const wrapperCls = [cls, sectionInstance].filter(Boolean).join(' ');
    const wrapperPairs = ['"tagName":"section"'];
    if (sourceId) wrapperPairs.unshift(`"anchor":${attrJson(sourceId)}`);
    const attrs = blockAttrs(wrapperPairs, wrapperCls);
    const divCls = ['wp-block-group', wrapperCls].filter(Boolean).join(' ');
    const idPart = sourceId ? ` id="${escapeHtml(sourceId)}"` : '';
    const blocks =
      `<!-- wp:group${attrs} -->\n` +
      `<section${idPart} class="${escapeHtml(divCls)}">${inner}</section>\n` +
      `<!-- /wp:group -->`;

    return {
      spec: section,
      blocks,
      coverage: {
        textCoverage: downgrades > 0 ? 0 : 1,
        missingImages: [],
        lost: downgrades > 0,
      },
      expectedText: section.headings,
      bodyText: section.bodyText,
      expectedAssets: section.images.map((image) => image.url || image.sourceUrl).filter(Boolean),
      provenanceFlags: downgrades > 0 ? [`preserve-dom#${section.sectionIndex}: skipped non-core elements`] : [],
      fallbackDiagnostics: [],
      iconAssets: [],
      decision: 'native',
    };
  },
  drainDedup(state) {
    if (!(state.instanceStyles instanceof InstanceStyleSheet)) return { cssRules: [] };
    return { cssRules: state.instanceStyles.size ? state.instanceStyles.toCss().split('\n') : [] };
  },
};
