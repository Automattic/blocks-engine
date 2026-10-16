/**
 * Recognizer inputs: the source shapes the transformer turns into native
 * blocks (headings, lists, figures with captions, links styled as buttons,
 * image-and-text pairs), each with author styling the native block must keep.
 */
import { BACKGROUNDS, COLORS, declaration, fontSize, imageSource, simpleSelector } from '../grammar.mjs';

const FEATURES = ['heading', 'list', 'figure', 'button-link', 'media-text'];
/** The fewest elements each feature needs. */
const FEATURE_COST = { heading: 1, list: 3, figure: 2, 'button-link': 1, 'media-text': 4 };

export const recognizers = {
  name: 'recognizers',
  features: FEATURES,
  generate(builder, budget) {
    const random = builder.random;
    builder.feature('recognizers');
    const start = builder.count;
    const root = builder.root(builder.element(random.pick(['section', 'div', 'article', 'header'])));
    builder.classFor(root, random.pick(['hero', 'feature', 'promo', 'story']));
    if (random.chance(0.4)) builder.rule(simpleSelector(builder, root, 'class'), [['padding', `${random.int(4, 24)}px`], ['background-color', random.pick(BACKGROUNDS)]]);
    const features = random.sample(FEATURES, budget >= 8 ? random.int(1, 2) : 1);
    for (const preferred of features) {
      const affordable = FEATURES.filter((name) => FEATURE_COST[name] <= Math.min(budget - (builder.count - start), builder.remaining()));
      if (!affordable.length) break;
      const feature = affordable.includes(preferred) ? preferred : random.pick(affordable);
      builder.feature(`recognizers:${feature}`);
      FEATURE_BUILDERS[feature](builder, root);
    }
  },
};

function image(builder, width, height) {
  return builder.element('img', { attrs: { src: imageSource(width, height, builder.random.pick(['#8fb8de', '#c39bd3', '#f5b041'])), alt: '', width: String(width), height: String(height) } });
}

const FEATURE_BUILDERS = {
  heading(builder, root) {
    const random = builder.random;
    const level = random.int(1, 4);
    const heading = builder.append(root, builder.element(`h${level}`, { text: true }));
    builder.tag(`recognizers:heading-h${level}`);
    const declarations = random.sample([
      ['font-size', fontSize(random, ['px', 'rem', 'clamp', 'vw'])[0]],
      declaration(builder, 'color'),
      ['margin', `${random.int(0, 30)}px 0 ${random.int(0, 20)}px`],
      declaration(builder, 'text-transform'),
      ['font-weight', random.pick(['300', '800', 'normal'])],
      ['line-height', random.pick(['1', '1.1', '48px'])],
      declaration(builder, 'letter-spacing'),
    ], random.int(1, 3));
    builder.rule(random.pick([`h${level}`, simpleSelector(builder, heading, 'class'), `${simpleSelector(builder, root, 'class')} h${level}`]), declarations);
    if (builder.remaining() > 1 && random.chance(0.5)) {
      builder.tag('recognizers:heading-with-kicker');
      const kicker = builder.append(root, builder.element('p', { text: true }));
      builder.rule(`h${level} + ${simpleSelector(builder, kicker, 'type')}`, [declaration(builder, 'color'), declaration(builder, 'font-size')]);
    }
  },

  list(builder, root) {
    const random = builder.random;
    const ordered = random.chance(0.4);
    const list = builder.append(root, builder.element(ordered ? 'ol' : 'ul'));
    builder.classFor(list, 'list');
    const count = Math.min(builder.remaining(), random.int(2, 4));
    const items = [];
    for (let index = 0; index < count; index++) items.push(builder.append(list, builder.element('li', { text: true })));
    if (builder.remaining() >= 2 && random.chance(0.3)) {
      builder.tag('recognizers:list-nested');
      const nested = builder.append(random.pick(items), builder.element('ul'));
      builder.append(nested, builder.element('li', { text: true }));
    }
    const style = random.pick(['none', 'square', 'decimal-leading-zero', 'inside-disc', 'default']);
    builder.tag(`recognizers:list-${style}`);
    const listDeclarations = [['padding-left', `${random.int(0, 40)}px`]];
    if (style === 'inside-disc') listDeclarations.push(['list-style', 'disc inside']);
    else if (style !== 'default') listDeclarations.push(['list-style-type', style]);
    builder.rule(simpleSelector(builder, list, 'class'), listDeclarations);
    builder.rule(`${simpleSelector(builder, list, 'class')} ${random.pick(['li', '> li', 'li + li'])}`, random.sample([['margin-bottom', `${random.int(0, 16)}px`], declaration(builder, 'color'), ['padding', `${random.int(0, 10)}px`], declaration(builder, 'font-size')], random.int(1, 2)));
    if (random.chance(0.3)) {
      builder.tag('recognizers:list-flex');
      builder.rule(simpleSelector(builder, list, 'class'), [['display', 'flex'], ['gap', `${random.int(4, 20)}px`]]);
    }
  },

  figure(builder, root) {
    const random = builder.random;
    const figure = builder.append(root, builder.element('figure'));
    builder.classFor(figure, 'figure');
    const width = random.pick([240, 480, 960]);
    builder.append(figure, image(builder, width, Math.round(width * random.pick([0.5, 0.75, 1]))));
    const caption = builder.remaining() > 0 ? builder.append(figure, builder.element('figcaption', { text: true })) : null;
    builder.tag(`recognizers:figure-${width > 400 ? 'wide' : 'narrow'}-image`);
    builder.rule(simpleSelector(builder, figure, 'class'), random.sample([['margin', `${random.int(0, 24)}px`], ['max-width', `${random.int(200, 520)}px`], ['border', '1px solid #ccc'], ['padding', `${random.int(0, 12)}px`]], random.int(1, 3)));
    builder.rule(`${simpleSelector(builder, figure, 'class')} img`, random.pick([[['width', '100%'], ['height', 'auto']], [['max-width', '100%']], [['display', 'block'], ['width', '100%'], ['object-fit', 'cover'], ['height', `${random.int(80, 200)}px`]]]));
    if (caption) builder.rule('figcaption', random.sample([declaration(builder, 'font-size'), declaration(builder, 'color'), ['text-align', random.pick(['center', 'right'])], ['margin-top', `${random.int(0, 12)}px`], declaration(builder, 'font-style')], random.int(1, 3)));
  },

  'button-link'(builder, root) {
    const random = builder.random;
    const grouped = builder.remaining() >= 3 && random.chance(0.5);
    const parent = grouped ? builder.append(root, builder.element('div')) : root;
    if (grouped) {
      builder.tag('recognizers:button-row');
      builder.rule(simpleSelector(builder, parent, 'class'), [['display', 'flex'], ['gap', `${random.int(4, 20)}px`], ...(random.chance(0.5) ? [['justify-content', random.pick(['center', 'flex-end', 'space-between'])]] : [])]);
    }
    const buttonClass = builder.className(random.pick(['btn', 'button', 'cta']));
    const count = grouped ? Math.min(builder.remaining(), random.int(1, 2)) : 1;
    for (let index = 0; index < count; index++) {
      builder.append(parent, builder.element('a', { classes: [buttonClass], attrs: { href: random.pick(['#', '/contact', 'https://example.com/']) }, text: true }));
    }
    const display = random.pick(['inline-block', 'inline-flex', 'block', 'flex']);
    builder.tag(`recognizers:button-${display}`);
    const declarations = [
      ['display', display],
      ['padding', `${random.int(4, 16)}px ${random.int(8, 32)}px`],
      ['background-color', random.pick(COLORS)],
      ['color', random.pick(['#fff', 'white', '#fefefe'])],
      ['text-decoration', 'none'],
    ];
    if (random.chance(0.6)) declarations.push(['border-radius', `${random.int(2, 30)}px`]);
    if (random.chance(0.3)) declarations.push(['border', `${random.int(1, 3)}px solid ${random.pick(COLORS)}`]);
    if (random.chance(0.4)) declarations.push(['font-weight', random.pick(['600', '700'])]);
    if (random.chance(0.3)) declarations.push(['font-size', fontSize(random, ['px', 'rem'])[0]]);
    if (display.endsWith('flex') && random.chance(0.5)) declarations.push(['align-items', 'center'], ['justify-content', 'center']);
    if (random.chance(0.25)) {
      builder.tag('recognizers:button-width');
      declarations.push(['width', random.pick(['200px', '100%', 'fit-content'])]);
    }
    builder.rule(`.${buttonClass}`, declarations);
    if (random.chance(0.3)) {
      builder.tag('recognizers:button-hover-rule');
      builder.rule(`.${buttonClass}:hover`, [['background-color', random.pick(COLORS)]]);
    }
  },

  'media-text'(builder, root) {
    const random = builder.random;
    const row = builder.append(root, builder.element('div'));
    builder.classFor(row, 'media-row');
    const imageFirst = random.chance(0.6);
    const picture = image(builder, random.pick([300, 600]), random.pick([200, 400]));
    const body = builder.element('div');
    builder.classFor(body, 'copy');
    builder.append(body, builder.element(random.pick(['h3', 'h2']), { text: true }));
    if (builder.remaining() > 0) {
      const copy = builder.append(body, builder.element('p'));
      copy.text = builder.longText(copy, random.int(6, 14));
    }
    for (const child of imageFirst ? [picture, body] : [body, picture]) builder.append(row, child);
    const layoutMode = random.pick(['flex', 'grid']);
    builder.tag(`recognizers:media-text-${layoutMode}`, `recognizers:media-text-${imageFirst ? 'image-first' : 'text-first'}`);
    if (layoutMode === 'flex') {
      builder.rule(simpleSelector(builder, row, 'class'), [['display', 'flex'], ['gap', `${random.int(8, 32)}px`], ['align-items', random.pick(['center', 'flex-start', 'stretch'])]]);
      builder.rule(`${simpleSelector(builder, row, 'class')} img`, [['width', random.pick(['40%', '200px', '50%'])], ['height', 'auto'], ['flex-shrink', random.pick(['0', '1'])]]);
    } else {
      builder.rule(simpleSelector(builder, row, 'class'), [['display', 'grid'], ['grid-template-columns', random.pick(['1fr 1fr', '200px 1fr', '1fr 2fr'])], ['gap', `${random.int(8, 32)}px`]]);
      builder.rule(`${simpleSelector(builder, row, 'class')} img`, [['width', '100%'], ['height', 'auto']]);
    }
    if (random.chance(0.4)) {
      builder.tag('recognizers:media-text-stack-media');
      builder.rule(simpleSelector(builder, row, 'class'), [['flex-direction', 'column'], ['grid-template-columns', '1fr']], { media: '(max-width: 599px)' });
    }
  },
};
