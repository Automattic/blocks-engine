/**
 * Layout: block flow and margin collapse, flex, grid (templates, gaps and
 * the automatic minimum size of grid items), positioning and aspect-ratio.
 */
import { BACKGROUNDS, imageSource, length, simpleSelector } from '../grammar.mjs';

const FEATURES = ['block-flow', 'margin-collapse', 'flex', 'grid-template', 'grid-gap', 'grid-automatic-minimum', 'positioning', 'aspect-ratio'];
const ITEM_TAGS = ['div', 'p', 'div', 'h3'];
const LONG_WORD = 'Pneumonoultramicroscopicsilicovolcanoconiosis';

export const layout = {
  name: 'layout',
  features: FEATURES,
  generate(builder, budget) {
    const random = builder.random;
    builder.feature('layout');
    const count = budget >= 9 && random.chance(0.3) ? 2 : 1;
    const features = random.sample(FEATURES, count);
    const share = Math.floor(budget / features.length);
    for (const feature of features) {
      builder.feature(`layout:${feature}`);
      FEATURE_BUILDERS[feature](builder, Math.min(share, builder.remaining()));
    }
  },
};

/** A container with `count` text items; returns `[container, items]`. */
function container(builder, count, { tag = 'div', itemTags = ITEM_TAGS, longText = 0 } = {}) {
  const random = builder.random;
  const root = builder.root(builder.element(tag));
  builder.classFor(root, random.pick(['layout', 'frame', 'track', 'deck', 'rail']));
  const items = [];
  for (let index = 0; index < count; index++) {
    const item = builder.append(root, builder.element(random.pick(itemTags)));
    builder.classFor(item, 'item');
    item.text = random.chance(longText) ? builder.longText(item, random.int(6, 14)) : builder.text(item);
    items.push(item);
  }
  return [root, items];
}

function px(builder, min, max) {
  return `${builder.random.int(min, max)}px`;
}

function background(builder) {
  return ['background-color', builder.random.pick(BACKGROUNDS)];
}

const FEATURE_BUILDERS = {
  'block-flow'(builder, budget) {
    const random = builder.random;
    const [root, items] = container(builder, Math.min(budget - 1, random.int(2, 4)));
    builder.rule(simpleSelector(builder, root, 'class'), [background(builder), ['padding', px(builder, 0, 16)]]);
    for (const item of items) {
      const declarations = [];
      const shape = random.pick(['percent-width', 'max-width-auto', 'inline-auto', 'border-box', 'fit-content']);
      builder.tag(`layout:block-${shape}`);
      if (shape === 'percent-width') declarations.push(['width', `${random.pick([40, 50, 75, 90])}%`]);
      if (shape === 'max-width-auto') declarations.push(['max-width', px(builder, 120, 320)], ['margin', `${px(builder, 0, 12)} auto`]);
      if (shape === 'inline-auto') declarations.push(['width', px(builder, 100, 260)], ['margin-inline', 'auto']);
      if (shape === 'border-box') declarations.push(['box-sizing', 'border-box'], ['width', '100%'], ['padding', px(builder, 4, 24)], ['border', `${px(builder, 1, 6)} solid #333`]);
      if (shape === 'fit-content') declarations.push(['width', 'fit-content'], background(builder));
      builder.rule(simpleSelector(builder, item, 'class'), declarations);
    }
  },

  'margin-collapse'(builder, budget) {
    const random = builder.random;
    const [root, items] = container(builder, Math.min(budget - 1, random.int(2, 4)), { itemTags: ['p', 'h2', 'div', 'p'] });
    const separator = random.pick(['none', 'padding', 'border', 'flow-root', 'overflow']);
    builder.tag(`layout:collapse-${separator}`);
    const containerDeclarations = [background(builder), ['margin-top', px(builder, 0, 30)]];
    if (separator === 'padding') containerDeclarations.push(['padding-top', px(builder, 1, 10)]);
    if (separator === 'border') containerDeclarations.push(['border-top', `${px(builder, 1, 4)} solid #555`]);
    if (separator === 'flow-root') containerDeclarations.push(['display', 'flow-root']);
    if (separator === 'overflow') containerDeclarations.push(['overflow', 'hidden']);
    builder.rule(simpleSelector(builder, root, 'class'), containerDeclarations);
    for (const item of items) {
      const top = random.chance(0.2) ? `-${px(builder, 2, 12)}` : length(random, { min: 0, max: 40, units: ['px', 'em'] })[0];
      const bottom = length(random, { min: 0, max: 40, units: ['px', 'em', 'rem'] })[0];
      builder.rule(simpleSelector(builder, item, 'class'), [['margin-top', top], ['margin-bottom', bottom]]);
    }
    if (builder.remaining() > 0 && random.chance(0.3)) {
      builder.tag('layout:collapse-through-empty');
      const empty = builder.element('div');
      root.children.splice(1, 0, empty);
      empty.parent = root;
      builder.rule(simpleSelector(builder, empty, 'class'), [['margin', `${px(builder, 10, 40)} 0`]]);
    }
  },

  flex(builder, budget) {
    const random = builder.random;
    const [root, items] = container(builder, Math.min(budget - 1, random.int(2, 5)), { longText: 0.3 });
    const declarations = [['display', random.pick(['flex', 'flex', 'inline-flex'])]];
    const direction = random.pick(['row', 'row', 'column', 'row-reverse']);
    if (direction !== 'row' || random.chance(0.3)) declarations.push(['flex-direction', direction]);
    if (random.chance(0.5)) declarations.push(['flex-wrap', 'wrap']);
    if (random.chance(0.6)) declarations.push(['gap', length(random, { min: 4, max: 24, units: ['px', 'rem', 'em'] })[0]]);
    if (random.chance(0.5)) declarations.push(['justify-content', random.pick(['space-between', 'center', 'flex-end', 'space-around'])]);
    if (random.chance(0.5)) declarations.push(['align-items', random.pick(['center', 'flex-start', 'stretch', 'baseline'])]);
    builder.tag(`layout:flex-${direction}`);
    builder.rule(simpleSelector(builder, root, 'class'), [...declarations, background(builder)]);
    for (const item of items) {
      if (!random.chance(0.6)) continue;
      const child = random.pick([
        ['flex', '1'],
        ['flex', `0 0 ${px(builder, 60, 180)}`],
        ['flex-grow', String(random.int(1, 3))],
        ['flex-basis', `${random.pick([20, 30, 50])}%`],
        ['order', '-1'],
        ['align-self', 'flex-end'],
        ['min-width', '0'],
      ]);
      builder.tag(`layout:flex-item-${child[0]}`);
      builder.rule(simpleSelector(builder, item, 'class'), [child, ['padding', px(builder, 2, 10)]]);
    }
  },

  'grid-template'(builder, budget) {
    const random = builder.random;
    const [root, items] = container(builder, Math.min(budget - 1, random.int(2, 6)), { longText: 0.2 });
    const template = random.pick(['repeat(3, 1fr)', '200px 1fr', '1fr 2fr', 'repeat(auto-fit, minmax(140px, 1fr))', 'repeat(2, minmax(0, 1fr))', 'min-content auto', '30% 70%']);
    builder.tag(`layout:grid-template-${template.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}`);
    builder.rule(simpleSelector(builder, root, 'class'), [['display', 'grid'], ['grid-template-columns', template], background(builder)]);
    if (random.chance(0.4)) {
      builder.tag('layout:grid-template-media');
      builder.rule(simpleSelector(builder, root, 'class'), [['grid-template-columns', random.pick(['1fr', 'repeat(4, 1fr)', '1fr 1fr'])]], { media: builder.mediaQuery() });
    }
    if (items.length > 2 && random.chance(0.4)) {
      builder.tag('layout:grid-span');
      builder.rule(simpleSelector(builder, random.pick(items), 'class'), [['grid-column', random.pick(['span 2', '1 / -1', '2'])]]);
    }
    if (random.chance(0.3)) builder.rule(simpleSelector(builder, root, 'class'), [[random.pick(['justify-items', 'align-items']), random.pick(['center', 'start', 'end'])]]);
  },

  'grid-gap'(builder, budget) {
    const random = builder.random;
    const [root] = container(builder, Math.min(budget - 1, random.int(3, 6)));
    const [value, unit] = length(random, { min: 4, max: 32, units: ['px', 'rem', 'em', '%', 'vw'] });
    const property = random.pick(['gap', 'row-gap', 'column-gap', 'grid-gap', 'gap-two']);
    builder.tag(`layout:grid-${property}`, `unit:${unit}`);
    const gap = property === 'gap-two' ? ['gap', `${value} ${px(builder, 2, 30)}`] : [property, value];
    builder.rule(simpleSelector(builder, root, 'class'), [['display', 'grid'], ['grid-template-columns', random.pick(['1fr 1fr', 'repeat(3, 1fr)', '1fr'])], gap]);
  },

  'grid-automatic-minimum'(builder, budget) {
    const random = builder.random;
    const root = builder.root(builder.element(random.pick(['section', 'div', 'ul'])));
    const listed = root.tag === 'ul';
    builder.classFor(root, 'grid');
    const template = random.pick(['none', '1fr', 'repeat(2, 1fr)', 'auto']);
    const declarations = [['display', 'grid'], ['gap', px(builder, 8, 32)]];
    if (template !== 'none') declarations.push(['grid-template-columns', template]);
    builder.rule(simpleSelector(builder, root, 'class'), declarations);
    builder.tag(`layout:grid-minimum-${template.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'implicit'}`);
    if (random.chance(0.5)) builder.rule(simpleSelector(builder, root, 'class'), [['grid-template-columns', 'repeat(2, minmax(0, 1fr))']], { media: '(min-width: 640px)' });
    const itemCount = Math.max(1, Math.min(random.int(1, 3), Math.floor((budget - 1) / 3)));
    const content = random.pick(['long-word', 'nowrap', 'wide-image', 'wrapped-image']);
    builder.tag(`layout:grid-minimum-${content}`);
    const frameClass = builder.className('frame');
    for (let index = 0; index < itemCount; index++) {
      const item = builder.append(root, builder.element(listed ? 'li' : random.pick(['figure', 'div', 'article'])));
      builder.classFor(item, 'cell');
      if (content === 'long-word') item.text = `${LONG_WORD}${LONG_WORD} ${item.id}`;
      if (content === 'nowrap') {
        item.text = builder.longText(item, 10);
        builder.rule(`.${item.classes[0]}`, [['white-space', 'nowrap']]);
      }
      if (content === 'wide-image' || content === 'wrapped-image') {
        // The wrapper is unmarked so the transformer is free to coalesce it.
        const parent = content === 'wrapped-image' ? builder.append(item, builder.element('div', { classes: [frameClass], marked: false })) : item;
        const width = random.pick([640, 900, 1600]);
        builder.append(parent, builder.element('img', { attrs: { src: imageSource(width, Math.round(width * 0.625)), alt: '', width: String(width), height: String(Math.round(width * 0.625)) } }));
        if (item.tag === 'figure' && builder.remaining() > 0) builder.append(item, builder.element('figcaption', { text: true }));
      }
    }
    if (content === 'wrapped-image') builder.rule(`.${frameClass}`, [['overflow', 'hidden'], ['border', '1px solid #999']]);
    if (content.endsWith('image')) builder.rule(`.${root.classes[0]} img`, [['width', '100%'], ['aspect-ratio', '8 / 5'], ['object-fit', 'cover']]);
    if (random.chance(0.3)) {
      builder.tag('layout:grid-minimum-author-zero');
      builder.rule(`.${root.classes[0]} > *`, [['min-width', '0']]);
    }
  },

  positioning(builder, budget) {
    const random = builder.random;
    const [root, items] = container(builder, Math.min(budget - 1, random.int(2, 4)));
    builder.rule(simpleSelector(builder, root, 'class'), [['position', 'relative'], ['min-height', px(builder, 80, 200)], ['padding', px(builder, 4, 20)], background(builder)]);
    const absolute = random.pick(items);
    const offsets = random.pick([
      [['top', px(builder, 0, 20)], ['right', px(builder, 0, 20)]],
      [['bottom', '0'], ['left', `${random.int(5, 40)}%`]],
      [['inset', `auto 0 0 auto`]],
      [['inset-inline-start', px(builder, 0, 30)], ['top', '50%']],
    ]);
    builder.tag(`layout:position-${offsets[0][0]}`);
    builder.rule(simpleSelector(builder, absolute, 'class'), [['position', 'absolute'], ...offsets, ['width', random.pick(['auto', '40%', '120px'])]]);
    const others = items.filter((item) => item !== absolute);
    if (others.length && random.chance(0.6)) {
      builder.tag('layout:position-relative-offset');
      builder.rule(simpleSelector(builder, random.pick(others), 'class'), [['position', 'relative'], ['top', px(builder, -10, 20)], ['left', px(builder, -10, 30)]]);
    }
    if (random.chance(0.3)) {
      builder.tag('layout:position-z-index');
      builder.rule(simpleSelector(builder, absolute, 'class'), [['z-index', String(random.int(-1, 3))]]);
    }
  },

  'aspect-ratio'(builder, budget) {
    const random = builder.random;
    const ratio = random.pick(['16 / 9', '1', '4 / 3', '2 / 1', '3 / 4']);
    const subject = random.pick(['box', 'image', 'box-with-content']);
    builder.tag(`layout:aspect-ratio-${subject}`);
    const root = builder.root(builder.element('div'));
    builder.classFor(root, 'media');
    builder.rule(simpleSelector(builder, root, 'class'), [['width', random.pick(['60%', '320px', '100%', '50vw'])]]);
    if (subject === 'image') {
      const node = builder.append(root, builder.element('img', { attrs: { src: imageSource(400, 300), alt: '' } }));
      builder.rule(`.${root.classes[0]} img`, [['display', 'block'], ['width', '100%'], ['height', 'auto'], ['aspect-ratio', ratio], ['object-fit', random.pick(['cover', 'contain'])]]);
      if (budget > 2 && random.chance(0.5)) builder.append(root, builder.element('p', { text: true }));
      return node;
    }
    const box = builder.append(root, builder.element('div'));
    builder.classFor(box, 'ratio');
    box.text = subject === 'box-with-content' ? builder.longText(box, 30) : builder.text(box);
    builder.rule(simpleSelector(builder, box, 'class'), [['aspect-ratio', ratio], background(builder), ...(random.chance(0.4) ? [['max-height', px(builder, 80, 200)]] : [])]);
    return box;
  },
};
