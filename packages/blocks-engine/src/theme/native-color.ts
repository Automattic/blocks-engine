export interface PaletteToken {
  slug: string;
  hex: string;
}

export type RGBA = { r: number; g: number; b: number; a: number };

function byteToHexByte(value: string): number {
  return parseInt(value.length === 1 ? value + value : value, 16);
}

function parseAlpha(value: string): number | null {
  const raw = value.trim();
  if (!/^(?:\d+(?:\.\d+)?|\.\d+)$/.test(raw)) return null;
  const alpha = Number(raw);
  if (!Number.isFinite(alpha) || alpha < 0 || alpha > 1) return null;
  return alpha;
}

function parseAlphaByte(value: string): number {
  return byteToHexByte(value) / 255;
}

function parseRgbChannel(value: string): number | null {
  const raw = value.trim();
  if (/^(?:\d+(?:\.\d+)?|\.\d+)%$/.test(raw)) {
    const pct = Number(raw.slice(0, -1));
    if (!Number.isFinite(pct) || pct < 0 || pct > 100) return null;
    return Math.round((pct / 100) * 255);
  }
  if (!/^\d+$/.test(raw)) return null;
  const channel = Number(raw);
  if (!Number.isInteger(channel) || channel < 0 || channel > 255) return null;
  return channel;
}

function parsePercent(value: string): number | null {
  const raw = value.trim();
  if (!/^(?:\d+(?:\.\d+)?|\.\d+)%$/.test(raw)) return null;
  const pct = Number(raw.slice(0, -1));
  if (!Number.isFinite(pct) || pct < 0 || pct > 100) return null;
  return pct / 100;
}

function parseHue(value: string): number | null {
  const raw = value.trim();
  const match = /^(-?(?:\d+(?:\.\d+)?|\.\d+))(?:deg)?$/i.exec(raw);
  if (!match) return null;
  const hue = Number(match[1]);
  if (!Number.isFinite(hue)) return null;
  return ((hue % 360) + 360) % 360;
}

function parseHexColor(input: string): RGBA | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(input);
  if (!m) return null;
  const h = m[1];
  if (h.length === 3 || h.length === 4) {
    return {
      r: byteToHexByte(h[0]),
      g: byteToHexByte(h[1]),
      b: byteToHexByte(h[2]),
      a: h.length === 4 ? parseAlphaByte(h[3]) : 1,
    };
  }
  return {
    r: byteToHexByte(h.slice(0, 2)),
    g: byteToHexByte(h.slice(2, 4)),
    b: byteToHexByte(h.slice(4, 6)),
    a: h.length === 8 ? parseAlphaByte(h.slice(6, 8)) : 1,
  };
}

function parseFunctionArgs(body: string): { channels: string[]; alpha: string | null } | null {
  if (body.includes(',')) {
    if (body.includes('/')) return null;
    const parts = body.split(',').map((part) => part.trim());
    if (parts.length !== 3 && parts.length !== 4) return null;
    return { channels: parts.slice(0, 3), alpha: parts[3] ?? null };
  }

  const slashParts = body.split('/').map((part) => part.trim());
  if (slashParts.length > 2) return null;
  const channels = slashParts[0].split(/\s+/).filter(Boolean);
  if (channels.length !== 3) return null;
  const alpha = slashParts.length === 2 ? slashParts[1] : null;
  if (alpha !== null && /\s/.test(alpha)) return null;
  return { channels, alpha };
}

function parseRgbFunction(name: string, body: string): RGBA | null {
  const args = parseFunctionArgs(body);
  if (!args) return null;
  if (args.alpha !== null && name === 'rgb' && body.includes(',')) return null;
  const channels = args.channels.map(parseRgbChannel);
  if (channels.some((channel) => channel === null)) return null;
  const alpha = args.alpha === null ? 1 : parseAlpha(args.alpha);
  if (alpha === null) return null;
  return {
    r: channels[0] as number,
    g: channels[1] as number,
    b: channels[2] as number,
    a: alpha,
  };
}

function hslToRgb(h: number, s: number, l: number): [number, number, number] {
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const hp = h / 60;
  const x = c * (1 - Math.abs((hp % 2) - 1));
  let r = 0;
  let g = 0;
  let b = 0;
  if (hp < 1) {
    [r, g, b] = [c, x, 0];
  } else if (hp < 2) {
    [r, g, b] = [x, c, 0];
  } else if (hp < 3) {
    [r, g, b] = [0, c, x];
  } else if (hp < 4) {
    [r, g, b] = [0, x, c];
  } else if (hp < 5) {
    [r, g, b] = [x, 0, c];
  } else {
    [r, g, b] = [c, 0, x];
  }
  const m = l - c / 2;
  return [Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255)];
}

function parseHslFunction(body: string): RGBA | null {
  const args = parseFunctionArgs(body);
  if (!args) return null;
  const hue = parseHue(args.channels[0]);
  const saturation = parsePercent(args.channels[1]);
  const lightness = parsePercent(args.channels[2]);
  const alpha = args.alpha === null ? 1 : parseAlpha(args.alpha);
  if (hue === null || saturation === null || lightness === null || alpha === null) return null;
  const [r, g, b] = hslToRgb(hue, saturation, lightness);
  return { r, g, b, a: alpha };
}

export function parseColor(input: string): RGBA | null {
  const s = input.trim();
  const hex = parseHexColor(s);
  if (hex) return hex;

  const fn = /^(rgba?|hsla?)\(\s*(.*?)\s*\)$/i.exec(s);
  if (!fn) return null;
  const name = fn[1].toLowerCase();
  if (name === 'rgb' || name === 'rgba') return parseRgbFunction(name, fn[2]);
  return parseHslFunction(fn[2]);
}

export function parseHex(color: string): [number, number, number] | null {
  const parsed = parseColor(color);
  return parsed ? [parsed.r, parsed.g, parsed.b] : null;
}

export function nearestToken(hex: string, tokens: PaletteToken[]): string | null {
  const c = parseHex(hex);
  if (!c) return null;
  let best: string | null = null;
  let bestD = Infinity;
  for (const t of tokens) {
    const tc = parseHex(t.hex);
    if (!tc) continue;
    const d = (c[0] - tc[0]) ** 2 + (c[1] - tc[1]) ** 2 + (c[2] - tc[2]) ** 2;
    if (d < bestD) {
      bestD = d;
      best = t.slug;
    }
  }
  return best;
}

export function brightness(hex: string): number {
  const c = parseHex(hex);
  if (!c) return 255;
  return Math.round(0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]);
}
