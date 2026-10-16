/**
 * Shared grammar for generated differential cases.
 *
 * A family receives a CaseBuilder and a seeded random stream, adds one
 * fragment (a small element tree) and the rules that style it, and records
 * feature tags. The builder owns naming, the element budget, and rendering
 * to a standalone HTML document.
 */

export const MARKER_PREFIX = 'dx';
const VOID_TAGS = new Set(['img', 'hr', 'br', 'input']);
const WORDS = ['Alpha', 'Bravo', 'Cedar', 'Delta', 'Ember', 'Fable', 'Garnet', 'Harbor', 'Indigo', 'Juniper', 'Kestrel', 'Lumen', 'Meadow', 'Nimbus', 'Orchid', 'Pebble', 'Quartz', 'Ripple', 'Saffron', 'Tundra'];
const CLASS_WORDS = ['card', 'lead', 'note', 'item', 'panel', 'title', 'meta', 'intro', 'tile', 'badge', 'block', 'entry', 'cta', 'media', 'body', 'aside', 'hero', 'row', 'cell', 'chip'];
const CUSTOM_PROPERTY_HEADS = ['brand', 'accent', 'card', 'space', 'btn', 'surface', 'ink', 'gutter', 'heading', 'tone'];
const CUSTOM_PROPERTY_TAILS = ['color', 'bg', 'gap', 'pad', 'size', 'shade', 'edge', 'main'];
const LAYER_NAMES = ['reset', 'base', 'theme', 'components', 'utilities', 'overrides'];

export const COLORS = ['#c0392b', '#1e8449', '#2471a3', '#7d3c98', '#b9770e', 'rgb(20, 90, 160)', 'rgb(180 40 90)', 'hsl(200 60% 35%)', 'hsl(30, 80%, 40%)', 'teal', 'navy', 'maroon', 'darkgreen', 'rebeccapurple', 'crimson'];
export const BACKGROUNDS = ['#fdebd0', '#d6eaf8', '#e8f8f5', '#f5eef8', '#fef9e7', 'rgb(240, 240, 230)', 'hsl(120 40% 90%)', 'lavender', 'honeydew', 'mistyrose', 'aliceblue', 'beige'];
export const FONT_WEIGHTS = ['400', '500', '600', '700', 'bold', 'normal', 'lighter'];
export const MEDIA_QUERIES = [
  ['(min-width: 600px)', 'min'],
  ['(min-width: 768px)', 'min'],
  ['(min-width: 1024px)', 'min'],
  ['(max-width: 599px)', 'max'],
  ['(max-width: 800px)', 'max'],
  ['(width >= 48rem)', 'range'],
  ['screen and (min-width: 40em)', 'min'],
];

/** Properties whose value is directly visible on the element that declares it. */
export const PAINT_PROPERTIES = ['color', 'background-color', 'font-size', 'padding', 'font-weight', 'letter-spacing', 'border', 'text-transform', 'font-style', 'text-align'];

/** A random value for one of PAINT_PROPERTIES, as `[property, value]`. */
export function declaration(builder, property = builder.random.pick(PAINT_PROPERTIES)) {
  const random = builder.random;
  switch (property) {
    case 'color':
      return [property, random.pick(COLORS)];
    case 'background-color':
      return [property, random.pick(BACKGROUNDS)];
    case 'font-size': {
      const [value, unit] = fontSize(random, ['px', 'em', 'rem']);
      builder.tag(`unit:${unit}`);
      return [property, value];
    }
    case 'padding': {
      const [value, unit] = length(random, { min: 2, max: 20, units: ['px', 'em', 'rem'] });
      builder.tag(`unit:${unit}`);
      return [property, value];
    }
    case 'font-weight':
      return [property, random.pick(FONT_WEIGHTS)];
    case 'letter-spacing':
      return [property, random.pick(['1px', '2px', '0.1em', '0.05em', '-0.5px'])];
    case 'border':
      return [property, `${random.int(1, 5)}px ${random.pick(['solid', 'dashed', 'double'])} ${random.pick(COLORS)}`];
    case 'text-transform':
      return [property, random.pick(['uppercase', 'lowercase', 'capitalize'])];
    case 'font-style':
      return [property, random.pick(['italic', 'oblique'])];
    case 'text-align':
      return [property, random.pick(['center', 'right', 'end', 'justify'])];
    default:
      throw new Error(`No value pool for ${property}`);
  }
}

/** Two different values for the same property, so a cascade outcome is visible. */
export function conflictingDeclarations(builder, property = builder.random.pick(PAINT_PROPERTIES)) {
  const first = declaration(builder, property);
  let second = declaration(builder, property);
  for (let attempt = 0; attempt < 8 && second[1] === first[1]; attempt++) second = declaration(builder, property);
  return [first, second];
}

/** One length in the requested unit family; returns `[value, unitTag]`. */
export function length(random, { min = 4, max = 24, units = ['px', 'em', 'rem', '%', 'vw', 'clamp'] } = {}) {
  const unit = random.pick(units);
  const px = random.int(min, max);
  switch (unit) {
    case 'px':
      return [`${px}px`, 'px'];
    case 'em':
      return [`${roundTo(px / 16, 0.125)}em`, 'em'];
    case 'rem':
      return [`${roundTo(px / 16, 0.125)}rem`, 'rem'];
    case '%':
      return [`${random.int(1, 10)}%`, '%'];
    case 'vw':
      return [`${random.int(1, 4)}vw`, 'vw'];
    case 'clamp':
      return [`clamp(${Math.max(1, Math.round(px / 2))}px, ${random.int(1, 5)}vw, ${px * 2}px)`, 'clamp'];
    default:
      throw new Error(`Unknown unit ${unit}`);
  }
}

export function fontSize(random, units = ['px', 'em', 'rem', '%', 'vw', 'clamp']) {
  const unit = random.pick(units);
  switch (unit) {
    case 'px':
      return [`${random.int(12, 28)}px`, 'px'];
    case 'em':
      return [`${random.pick(['0.875', '1.125', '1.25', '1.5'])}em`, 'em'];
    case 'rem':
      return [`${random.pick(['0.875', '1.125', '1.375', '1.75'])}rem`, 'rem'];
    case '%':
      return [`${random.pick([90, 110, 125, 150])}%`, '%'];
    case 'vw':
      return [`${random.pick(['1.5', '2', '3'])}vw`, 'vw'];
    case 'clamp':
      return [`clamp(${random.int(12, 16)}px, ${random.pick(['2', '3', '4'])}vw, ${random.int(22, 34)}px)`, 'clamp'];
    default:
      throw new Error(`Unknown unit ${unit}`);
  }
}

function roundTo(value, step) {
  return Math.max(step, Math.round(value / step) * step);
}

/** An inline SVG image with intrinsic dimensions; the transformer keeps data URIs. */
export function imageSource(width, height, fill = '#8fb8de') {
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='${width}' height='${height}'><rect width='100%' height='100%' fill='${fill}'/></svg>`;
  return `data:image/svg+xml,${svg.replace(/#/g, '%23').replace(/</g, '%3C').replace(/>/g, '%3E').replace(/ /g, '%20')}`;
}

export class CaseBuilder {
  constructor(random, { maxElements = 15 } = {}) {
    this.random = random;
    this.maxElements = maxElements;
    this.roots = [];
    this.rules = [];
    this.tags = new Set();
    this.layerOrder = [];
    this.count = 0;
    this.markerCount = 0;
    this.classNames = new Set();
    this.customProperties = new Set();
    this.preamble = [];
    this.scope = null;
  }

  remaining() {
    return this.maxElements - this.count;
  }

  tag(...tags) {
    for (const tag of tags) this.tags.add(tag);
  }

  /**
   * Tag the case with a family or `family:feature` and attribute the
   * elements and rules created from here on to it, so a failure can be
   * traced back to the features that produced the differing elements.
   */
  feature(name) {
    this.tag(name);
    this.scope = name;
  }

  /**
   * Create an element. Marked elements get a stable `id` (the transformer
   * keeps it as the block anchor) and, when they carry text, a unique text
   * marker the comparison falls back to when the id does not survive.
   */
  element(tag, { classes = [], attrs = {}, style = '', text = null, marked = true, children = [] } = {}) {
    if (this.count >= this.maxElements) throw new Error(`Case element budget of ${this.maxElements} exceeded`);
    this.count++;
    const node = { tag, classes: [...classes], attrs: { ...attrs }, style, text: null, children: [], parent: null, id: null, feature: this.scope };
    if (marked) node.id = `${MARKER_PREFIX}${++this.markerCount}`;
    if (null !== text) node.text = true === text ? this.text(node) : text;
    for (const child of children) this.append(node, child);
    return node;
  }

  /** Short readable text ending in the element's marker, e.g. "Cedar Ember dx3". */
  text(node, words = 2) {
    const parts = this.random.sample(WORDS, words);
    return `${parts.join(' ')} ${node.id ?? `tx${this.count}`}`;
  }

  /** Long text so wrapping and min-content sizing come into play. */
  longText(node, words = 8) {
    const parts = Array.from({ length: words }, () => this.random.pick(WORDS).toLowerCase());
    return `${parts.join(' ')} ${node.id ?? `tx${this.count}`}`;
  }

  append(parent, child) {
    child.parent = parent;
    parent.children.push(child);
    return child;
  }

  root(node) {
    this.roots.push(node);
    return node;
  }

  className(base = this.random.pick(CLASS_WORDS)) {
    let name = base;
    for (let suffix = 2; this.classNames.has(name); suffix++) name = `${base}-${suffix}`;
    this.classNames.add(name);
    return name;
  }

  /** Give `node` a fresh class and return it. */
  classFor(node, base) {
    const name = this.className(base);
    node.classes.push(name);
    return name;
  }

  /** A custom property name with mixed case, e.g. `--brandColor`, `--Card-Bg`. */
  customProperty({ mixedCase = true } = {}) {
    const head = this.random.pick(CUSTOM_PROPERTY_HEADS);
    const tail = this.random.pick(CUSTOM_PROPERTY_TAILS);
    const capital = (word) => word[0].toUpperCase() + word.slice(1);
    const styles = mixedCase
      ? [`${head}${capital(tail)}`, `${capital(head)}${capital(tail)}`, `${capital(head)}-${capital(tail)}`, `${head}_${capital(tail)}`, `${head.toUpperCase()}-${tail}`]
      : [`${head}-${tail}`, `${head}${tail}`];
    let name = `--${this.random.pick(styles)}`;
    for (let suffix = 2; this.customProperties.has(name.toLowerCase()); suffix++) name = `${name}${suffix}`;
    this.customProperties.add(name.toLowerCase());
    if (name !== name.toLowerCase()) this.tag('custom-property:mixed-case');
    return name;
  }

  layerName() {
    const unused = LAYER_NAMES.filter((name) => !this.layerOrder.includes(name));
    const name = this.random.pick(unused.length ? unused : LAYER_NAMES);
    if (!this.layerOrder.includes(name)) this.layerOrder.push(name);
    return name;
  }

  mediaQuery(kinds = ['min', 'max', 'range']) {
    return this.random.pick(MEDIA_QUERIES.filter(([, kind]) => kinds.includes(kind)))[0];
  }

  /**
   * Add a rule. `declarations` is a list of `[property, value]` or
   * `[property, value, true]` for `!important`.
   */
  rule(selector, declarations, { media = null, layer = null } = {}) {
    const rule = { selector, declarations, media, layer, feature: this.scope };
    this.rules.push(rule);
    return rule;
  }

  /**
   * The cross-family modifier: wrap some existing rules in `@media` or
   * `@layer`. Rules that already carry a wrapper keep it.
   */
  wrapSomeRules(probability = 0.15) {
    for (const rule of this.rules) {
      if (rule.media || rule.layer || !this.random.chance(probability)) continue;
      if (this.random.chance(0.5)) {
        rule.media = this.mediaQuery();
        this.tag('wrap:media');
      } else {
        rule.layer = this.layerName();
        this.tag('wrap:layer');
      }
    }
  }

  css() {
    const lines = [...this.preamble];
    if (this.layerOrder.length > 1 && this.random.chance(0.6)) {
      lines.push(`@layer ${this.layerOrder.join(', ')};`);
    }
    for (const rule of this.rules) {
      const body = rule.declarations
        .map(([property, value, important]) => `${property}: ${value}${important ? ' !important' : ''};`)
        .join(' ');
      let text = `${rule.selector} { ${body} }`;
      if (rule.layer) text = `@layer ${rule.layer} { ${text} }`;
      if (rule.media) text = `@media ${rule.media} { ${text} }`;
      lines.push(text);
    }
    return lines.join('\n');
  }

  body() {
    return this.roots.map((node) => renderNode(node, 1)).join('\n');
  }

  /**
   * Which feature produced what: each marked element's creating feature and
   * each rule's selector with the feature that emitted it.
   */
  attribution() {
    return {
      elements: Object.fromEntries(this.markers().filter((marker) => marker.feature).map((marker) => [marker.id, marker.feature])),
      rules: this.rules.filter((rule) => rule.feature).map((rule) => ({ selector: rule.selector, feature: rule.feature })),
    };
  }

  /** Marked elements in document order, with their text markers. */
  markers() {
    const markers = [];
    const visit = (node) => {
      if (node.id) markers.push({ id: node.id, tag: node.tag, text: textContent(node), feature: node.feature });
      node.children.forEach(visit);
    };
    this.roots.forEach(visit);
    return markers;
  }
}

function textContent(node) {
  return [node.text ?? '', ...node.children.map(textContent)].join(' ').replace(/\s+/g, ' ').trim();
}

function escapeAttribute(value) {
  return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

function escapeText(value) {
  return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;');
}

export function renderNode(node, depth = 0) {
  const indent = '  '.repeat(depth);
  const attributes = [];
  if (node.id) attributes.push(`id="${node.id}"`);
  if (node.classes.length) attributes.push(`class="${node.classes.join(' ')}"`);
  for (const [name, value] of Object.entries(node.attrs)) attributes.push(`${name}="${escapeAttribute(value)}"`);
  if (node.style) attributes.push(`style="${escapeAttribute(node.style)}"`);
  const open = `<${[node.tag, ...attributes].join(' ')}>`;
  if (VOID_TAGS.has(node.tag)) return `${indent}${open}`;
  if (!node.children.length) return `${indent}${open}${escapeText(node.text ?? '')}</${node.tag}>`;
  const inner = [];
  if (node.text) inner.push(`${'  '.repeat(depth + 1)}${escapeText(node.text)}`);
  for (const child of node.children) inner.push(renderNode(child, depth + 1));
  return `${indent}${open}\n${inner.join('\n')}\n${indent}</${node.tag}>`;
}

/**
 * The simplest selector naming `node` in the requested shape. Missing
 * classes or attributes are added to the node so the selector matches.
 */
export function simpleSelector(builder, node, shape) {
  switch (shape) {
    case 'type':
      return node.tag;
    case 'class':
      return `.${node.classes[0] ?? builder.classFor(node)}`;
    case 'type-class':
      return `${node.tag}.${node.classes[0] ?? builder.classFor(node)}`;
    case 'id':
      return `#${node.id ?? (node.id = `${MARKER_PREFIX}${++builder.markerCount}`)}`;
    case 'attribute': {
      if (!node.attrs['data-tone']) node.attrs['data-tone'] = builder.random.pick(['warm', 'cool', 'muted', 'bold']);
      return `[data-tone="${node.attrs['data-tone']}"]`;
    }
    default:
      throw new Error(`Unknown selector shape ${shape}`);
  }
}

export function ancestors(node) {
  const list = [];
  for (let current = node.parent; current; current = current.parent) list.push(current);
  return list;
}

export function previousSiblings(node) {
  if (!node.parent) return [];
  const siblings = node.parent.children;
  return siblings.slice(0, siblings.indexOf(node));
}

const ROOT_TAGS = ['div', 'section', 'article', 'aside'];
const LEAF_TAGS = ['p', 'p', 'p', 'h2', 'h3', 'h4', 'div', 'blockquote'];
const INLINE_TAGS = ['span', 'strong', 'em', 'a'];

/**
 * A small container of text-bearing children, nested up to `depth` levels.
 * Returns the root; the caller decides whether to `builder.root()` it.
 */
export function flowTree(builder, { budget, depth = 1, minChildren = 2, maxChildren = 5, rootTag = null, leafTags = LEAF_TAGS, inline = 0.2 } = {}) {
  const random = builder.random;
  let left = Math.min(budget, builder.remaining()) - 1;
  const root = builder.element(rootTag ?? random.pick(ROOT_TAGS));
  builder.classFor(root, random.pick(['wrap', 'group', 'stack', 'box', 'shell']));
  const fill = (parent, level) => {
    const count = Math.min(left, random.int(minChildren, maxChildren));
    for (let index = 0; index < count && left > 0; index++) {
      if (level < depth && left >= 3 && random.chance(0.35)) {
        left--;
        const wrapper = builder.append(parent, builder.element('div'));
        fill(wrapper, level + 1);
        if (!wrapper.children.length) wrapper.text = builder.text(wrapper);
        continue;
      }
      left--;
      const leaf = builder.append(parent, builder.element(random.pick(leafTags), { text: true }));
      if (leaf.tag === 'p' && left > 0 && random.chance(inline)) {
        left--;
        const tag = random.pick(INLINE_TAGS);
        const attrs = tag === 'a' ? { href: '#' } : {};
        builder.append(leaf, builder.element(tag, { attrs, text: true }));
      }
    }
  };
  fill(root, 1);
  return root;
}

/** All nodes of a fragment, depth first. */
export function descendants(node) {
  return node.children.flatMap((child) => [child, ...descendants(child)]);
}
