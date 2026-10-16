/**
 * Render a source page and its converted page in Chromium and diff the
 * computed style and box of every marked source element.
 *
 * Source elements are marked by `id`. The transformer keeps an `id` as the
 * block anchor, but a recognizer can move it to a wrapper (a link becomes
 * `div.wp-block-button#id > a`), so a match whose tag differs descends to
 * the same-tag element inside it. When the id is gone the match falls back
 * to the element's text, which ends in its unique marker, and for media to
 * its `src`.
 */
import { chromium } from 'playwright';

export const DEFAULT_VIEWPORTS = [390, 1440];
export const VIEWPORT_HEIGHT = 900;
export const BOX_TOLERANCE_PX = 0.5;

export const COMPARED_PROPERTIES = [
  'display',
  'position',
  'visibility',
  'opacity',
  'color',
  'background-color',
  'background-image',
  'font-family',
  'font-size',
  'font-weight',
  'font-style',
  'line-height',
  'letter-spacing',
  'text-transform',
  'text-align',
  'text-decoration-line',
  'list-style-type',
  'padding-top',
  'padding-right',
  'padding-bottom',
  'padding-left',
  'border-top-width',
  'border-right-width',
  'border-bottom-width',
  'border-left-width',
  'border-top-style',
  'border-right-style',
  'border-bottom-style',
  'border-left-style',
  'border-top-color',
  'border-right-color',
  'border-bottom-color',
  'border-left-color',
  'border-top-left-radius',
  'border-top-right-radius',
  'border-bottom-right-radius',
  'border-bottom-left-radius',
  'outline-style',
  'outline-color',
];
export const BOX_PROPERTIES = ['x', 'y', 'width', 'height'];

/** Runs in the page: describe every element carrying an id in the source. */
function sourceMarkers() {
  const normalize = (text) => text.replace(/\s+/g, ' ').trim();
  return [...document.body.querySelectorAll('[id]')].map((element) => ({
    id: element.id,
    tag: element.tagName.toLowerCase(),
    text: normalize(element.textContent),
    src: element.getAttribute('src'),
  }));
}

/** Runs in the page: locate each marker and read its style and box. */
function readMarkers({ markers, properties, locate }) {
  const normalize = (text) => text.replace(/\s+/g, ' ').trim();
  const byText = (text) => {
    if (!text) return null;
    const candidates = [...document.body.querySelectorAll('*')].filter((element) => normalize(element.textContent) === text);
    return candidates.find((element) => ![...element.children].some((child) => normalize(child.textContent) === text)) ?? null;
  };
  const sameTagInside = (element, marker) => {
    const inside = [...element.querySelectorAll(marker.tag)];
    return inside.find((candidate) => normalize(candidate.textContent) === marker.text) ?? (inside.length === 1 ? inside[0] : null);
  };
  const find = (marker) => {
    const byId = document.getElementById(marker.id);
    if (!locate) return byId ? [byId, 'id'] : [null, null];
    if (byId) {
      if (byId.tagName.toLowerCase() === marker.tag) return [byId, 'id'];
      const inner = sameTagInside(byId, marker);
      return inner ? [inner, 'id-descendant'] : [byId, 'id'];
    }
    const textual = byText(marker.text);
    if (textual) return [textual, 'text'];
    // Media carries no text; its source URL is the marker instead.
    const decoded = (value) => {
      try {
        return decodeURIComponent(value ?? '');
      } catch {
        return value ?? '';
      }
    };
    const media = marker.src ? [...document.querySelectorAll(marker.tag)].find((element) => decoded(element.getAttribute('src')) === decoded(marker.src)) : null;
    return media ? [media, 'src'] : [null, null];
  };
  const read = (element) => {
    const style = getComputedStyle(element);
    const values = {};
    for (const property of properties) {
      let value = style.getPropertyValue(property);
      if (property === 'text-align' && (value === 'start' || value === 'end')) {
        const rtl = style.direction === 'rtl';
        value = (value === 'start') !== rtl ? 'left' : 'right';
      }
      values[property] = value;
    }
    const box = element.getBoundingClientRect();
    return { values, box: { x: box.x + scrollX, y: box.y + scrollY, width: box.width, height: box.height } };
  };
  return markers.map((marker) => {
    const [element, match] = find(marker);
    if (!element) return { id: marker.id, found: false };
    return { id: marker.id, found: true, match, tag: element.tagName.toLowerCase(), ...read(element) };
  });
}

/**
 * Runs in the source page: the features behind each element. An element
 * belongs to the feature that created it and to every feature with a rule
 * matching it or an ancestor (inherited values and containing layouts).
 */
function attributeElements({ ids, attribution }) {
  const strip = (selector) => selector.replace(/::?(?:before|after|marker|placeholder|first-line|first-letter)\b/g, '');
  const matches = (element, selector) => {
    try {
      return Boolean(element.closest(strip(selector) || '*'));
    } catch {
      return false;
    }
  };
  return Object.fromEntries(ids.map((id) => {
    const element = document.getElementById(id);
    const features = new Set(attribution.elements[id] ? [attribution.elements[id]] : []);
    if (element) {
      for (const rule of attribution.rules) {
        if (matches(element, rule.selector)) features.add(rule.feature);
      }
    }
    return [id, [...features].sort()];
  }));
}

const round = (value) => Math.round(value * 100) / 100;

export class Renderer {
  constructor({ viewports = DEFAULT_VIEWPORTS, properties = COMPARED_PROPERTIES, tolerance = BOX_TOLERANCE_PX } = {}) {
    this.viewports = viewports;
    this.properties = properties;
    this.tolerance = tolerance;
  }

  async start() {
    this.browser = await chromium.launch({ headless: true });
    const context = await this.browser.newContext({ viewport: { width: this.viewports[0], height: VIEWPORT_HEIGHT }, deviceScaleFactor: 1 });
    this.sourcePage = await context.newPage();
    this.outputPage = await context.newPage();
  }

  async close() {
    await this.browser?.close();
  }

  /**
   * Compare one case. Returns `{ elements, matches, diffs, missing,
   * features }` where diffs are per viewport, per element, per property and
   * `features` maps each differing or missing element to the generator
   * features behind it (when the case carries an attribution).
   */
  async compare(sourceHtml, outputHtml, attribution = null) {
    await Promise.all([
      this.sourcePage.setContent(sourceHtml, { waitUntil: 'load' }),
      this.outputPage.setContent(outputHtml, { waitUntil: 'load' }),
    ]);
    const markers = await this.sourcePage.evaluate(sourceMarkers);
    const diffs = [];
    const missing = new Map();
    const matches = {};
    for (const width of this.viewports) {
      await Promise.all([
        this.sourcePage.setViewportSize({ width, height: VIEWPORT_HEIGHT }),
        this.outputPage.setViewportSize({ width, height: VIEWPORT_HEIGHT }),
      ]);
      const [source, output] = await Promise.all([
        this.sourcePage.evaluate(readMarkers, { markers, properties: this.properties, locate: false }),
        this.outputPage.evaluate(readMarkers, { markers, properties: this.properties, locate: true }),
      ]);
      source.forEach((before, index) => {
        const after = output[index];
        const marker = markers[index];
        if (!after.found) {
          missing.set(marker.id, { element: marker.id, tag: marker.tag, text: marker.text });
          return;
        }
        matches[marker.id] = { match: after.match, output_tag: after.tag };
        for (const property of this.properties) {
          if (before.values[property] !== after.values[property]) {
            diffs.push({ viewport: width, element: marker.id, tag: marker.tag, property, source: before.values[property], output: after.values[property] });
          }
        }
        for (const property of BOX_PROPERTIES) {
          if (Math.abs(before.box[property] - after.box[property]) > this.tolerance) {
            diffs.push({ viewport: width, element: marker.id, tag: marker.tag, property: `box.${property}`, source: round(before.box[property]), output: round(after.box[property]) });
          }
        }
      });
    }
    // Box diffs ripple into siblings and ancestors; when some element differs
    // in computed style, those elements are the likelier cause.
    const styled = diffs.filter((diff) => !diff.property.startsWith('box.'));
    const failing = [...new Set([...(styled.length ? styled : diffs).map((diff) => diff.element), ...missing.keys()])];
    const features = attribution && failing.length
      ? await this.sourcePage.evaluate(attributeElements, { ids: failing, attribution })
      : {};
    return { elements: markers.length, matches, diffs, missing: [...missing.values()], features };
  }
}
