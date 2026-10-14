import { describe, expect, it } from 'vitest';
import * as cheerio from 'cheerio';

import { compose } from '../compose';
import { parseHtmlForm } from '../form-html';

function parse(html: string) {
  const $ = cheerio.load(html, null, false);
  const form = $.root().children('form').get(0) as unknown as Parameters<typeof parseHtmlForm>[1];
  return parseHtmlForm($, form);
}

describe('parseHtmlForm', () => {
  it('returns null for forms with no representable fields', () => {
    expect(parse('<form action="/search"><span>decor</span></form>')).toBeNull();
  });

  it('maps input types to field kinds and carries required, placeholder, and value', () => {
    const parsed = parse(
      '<form><label for="e">Email</label><input type="email" id="e" required placeholder="you@x.test"/><label for="t">Team</label><input type="text" id="t" value="Blue"/><label for="p">Pass</label><input type="password" id="p"/><input type="search" name="q"/></form>',
    );
    expect(parsed?.fields).toEqual([
      { kind: 'email', label: 'Email', required: true, placeholder: 'you@x.test' },
      { kind: 'text', label: 'Team', required: false, defaultValue: 'Blue' },
      { kind: 'text', label: 'Pass', required: false },
      { kind: 'text', label: 'q', required: false },
    ]);
  });

  it('maps textarea and select controls, dropping empty-value placeholder options', () => {
    const parsed = parse(
      '<form><label for="m">Message</label><textarea id="m" placeholder="Say hi">Hello there</textarea><label for="s">Topic</label><select id="s"><option value="">Choose…</option><option value="buy">Buying</option><option value="sell">Selling</option></select></form>',
    );
    expect(parsed?.fields).toEqual([
      {
        kind: 'textarea',
        label: 'Message',
        required: false,
        placeholder: 'Say hi',
        defaultValue: 'Hello there',
      },
      { kind: 'select', label: 'Topic', required: false, options: ['Buying', 'Selling'] },
    ]);
  });

  it('collapses a same-name radio group into one field with options in document order', () => {
    const parsed = parse(
      '<form><fieldset><legend>Plan</legend><input type="radio" id="p1" name="plan" value="basic"/><label for="p1">Basic</label><input type="radio" id="p2" name="plan" value="pro"/><label for="p2">Pro</label></fieldset></form>',
    );
    expect(parsed?.fields).toEqual([
      { kind: 'radio', label: 'Basic', required: false, options: ['Basic', 'Pro'] },
    ]);
  });

  it('collapses a same-name checkbox group into options and keeps a lone checkbox optionless', () => {
    const parsed = parse(
      '<form><input type="checkbox" id="a" name="days" value="mon"/><label for="a">Mon</label><input type="checkbox" id="b" name="days" value="tue"/><label for="b">Tue</label><input type="checkbox" id="c" name="news" value="yes"/><label for="c">Newsletter</label></form>',
    );
    expect(parsed?.fields).toEqual([
      { kind: 'checkbox', label: 'Mon', required: false, options: ['Mon', 'Tue'] },
      { kind: 'checkbox', label: 'Newsletter', required: false },
    ]);
  });

  it('resolves labels from wrapping labels without echoing the control, then aria-label, placeholder, name', () => {
    const parsed = parse(
      '<form><label>Email <input type="email" name="mail"/></label><label for="n">Name</label><input id="n"/><input type="text" aria-label="Company"/><input type="text" name="org" placeholder="Org name"/></form>',
    );
    expect(parsed?.fields.map((field) => field.label)).toEqual([
      'Email',
      'Name',
      'Company',
      'Org name',
    ]);
  });

  it('reads aria-required as required and keeps file and hidden kinds for downstream skipping', () => {
    const parsed = parse(
      '<form><label for="r">Resume</label><input type="file" id="r"/><input type="hidden" name="campaign" value="ads"/><input type="text" name="x" aria-required="true"/></form>',
    );
    expect(parsed?.fields).toEqual([
      { kind: 'file', label: 'Resume', required: false },
      { kind: 'hidden', label: 'campaign', required: false },
      { kind: 'text', label: 'x', required: true },
    ]);
  });

  it('derives the submit label from button[type=submit], typeless button, then input[type=submit] value', () => {
    expect(parse('<form><input name="a"/><button type="submit">Send it</button></form>')?.submitLabel).toBe(
      'Send it',
    );
    expect(parse('<form><input name="a"/><button>Go</button></form>')?.submitLabel).toBe('Go');
    expect(
      parse('<form><input name="a"/><input type="submit" value="Request a Quote"/></form>')
        ?.submitLabel,
    ).toBe('Request a Quote');
    expect(parse('<form><input name="a"/></form>')?.submitLabel).toBe('Submit');
    expect(parse('<form><input name="a"/><button type="submit">   </button></form>')?.submitLabel).toBe(
      'Submit',
    );
  });

  it('ignores non-submit button/reset/image inputs as fields', () => {
    const parsed = parse(
      '<form><input name="a"/><button type="button">Preview</button><input type="reset" value="Clear"/><input type="image" src="/go.png" alt="Go"/></form>',
    );
    expect(parsed?.fields).toEqual([{ kind: 'text', label: 'a', required: false }]);
  });

  it('is deterministic across calls', () => {
    const html =
      '<form><label for="e">Email</label><input id="e" type="email"/><textarea id="m"></textarea><button type="submit">Send</button></form>';
    expect(parse(html)).toEqual(parse(html));
  });
});

describe('form conversion through compose', () => {
  it('converts an evidence-shaped label+input+submit form to jetpack contact-form markup with no core/html island', () => {
    const html =
      '<form class="space-y-4"><div class="space-y-1.5 blocks-engine-source-div-5f8c362fc7e8-4"><label class="text-sm font-medium" for="login-email">Email</label><input type="email" id="login-email" class="flex h-9 w-full rounded-md border" placeholder="m@example.com" required/><div class="relative"><svg xmlns="http://www.w3.org/2000/svg"></svg><input name="password" type="password" required/></div><button class="w-full" type="submit">Sign in</button></div></form>';
    const out = compose(html, { url: 'https://housing-resolutions.com/' });

    expect(out).toContain('<!-- wp:jetpack/contact-form ');
    expect(out).toContain(
      '<!-- wp:jetpack/field-email {"label":"Email","required":true,"placeholder":"m@example.com"} /-->',
    );
    expect(out).toContain('<!-- wp:jetpack/field-text {"label":"password","required":true} /-->');
    expect(out).toContain('>Sign in</button>');
    expect(out).not.toContain('wp:html');
    expect(out).not.toContain('<form');
  });

  it('converts forms nested in other generic converters (callout recursion)', () => {
    const html =
      '<div class="callout"><p>Join us</p><form><label for="e">Email</label><input id="e" type="email"/><button type="submit">Join</button></form></div>';
    const out = compose(html, { url: 'https://x.test/' });

    expect(out).toContain('wp:group');
    expect(out).toContain('wp:jetpack/contact-form');
    expect(out).not.toContain('<form');
  });

  it('keeps degenerate layout-only forms on the core/html fallback path', () => {
    const out = compose('<form action="/search"><span>decor</span></form>', { url: 'https://x.test/' });
    expect(out).toContain('wp:html');
  });

  it('is byte-identical across repeated compositions', () => {
    const html =
      '<form><label for="e">Email</label><input id="e" type="email"/><select id="s" name="s"><option value="a">A</option></select><button type="submit">Send</button></form>';
    expect(compose(html, { url: 'https://x.test/' })).toBe(compose(html, { url: 'https://x.test/' }));
  });

  it('keeps hostile label text inside the block comment', () => {
    const html =
      '<form><label for="e">Name --&gt; tricky &lt;script&gt;</label><input id="e" name="e"/><button type="submit">Send</button></form>';
    const out = compose(html, { url: 'https://x.test/' });
    const opener = out.split('\n')[0];

    expect(opener.startsWith('<!-- wp:jetpack/contact-form ')).toBe(true);
    expect(opener.endsWith('-->')).toBe(true);
    expect(opener.slice('<!--'.length, -'-->'.length)).not.toContain('-->');
    expect(out).toContain('{"label":"Name \\u002d\\u002d\\u003e tricky \\u003cscript\\u003e"}');
  });
});
