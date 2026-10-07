#!/usr/bin/env python3
"""
Qistas logo builder.

Generates every master logo file from geometry: filled outlines only, no live text,
no strokes, no rasters. Re-run after any geometry change:

    python brand/tools/build_logo.py

Concept: "Plumb q". A calm lowercase wordmark whose q ends in a gold plumb-bob
(the true, level measure: qistas = the just balance). In Arabic, the two dots of
the qaf become two gold coins.

Latin letters are custom-constructed from circles, bands and rectangles (monoline,
x-height 96, stroke 14). Arabic letters are Cairo Regular outlines (SIL OFL 1.1),
baked by brand/tools/arabic-outline.html into brand/tools/arabic-wordmark.json.
"""
import json
import math
import os

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.normpath(os.path.join(HERE, "..", "logo"))
os.makedirs(OUT, exist_ok=True)

NAVY, GOLD, IVORY = "#0B1F44", "#C9A25B", "#F7F3EA"
BLACK, WHITE = "#000000", "#FFFFFF"

XH = 96          # x-height of the Latin wordmark
W = 14           # monoline stroke of the Latin wordmark
OV = 1.2         # optical overshoot for round letters (vertical only)


def f(v):
    return ("%.2f" % v).rstrip("0").rstrip(".")


# ---------------------------------------------------------------- geometry
def ellipse(cx, cy, rx, ry, cw=True):
    sw = 1 if cw else 0
    return (f"M{f(cx - rx)} {f(cy)}A{f(rx)} {f(ry)} 0 1 {sw} {f(cx + rx)} {f(cy)}"
            f"A{f(rx)} {f(ry)} 0 1 {sw} {f(cx - rx)} {f(cy)}Z")


def ring(cx, cy, r_out, w, ov=0.0):
    """Outer contour clockwise, counter-form anticlockwise (fill-rule nonzero safe)."""
    return (ellipse(cx, cy, r_out, r_out + ov, True) +
            ellipse(cx, cy, r_out - w, r_out + ov - w, False))


def rect(x1, y1, x2, y2):
    return f"M{f(x1)} {f(y1)}H{f(x2)}V{f(y2)}H{f(x1)}Z"


def band(cx, cy, r, w, a_lo, a_hi):
    """Arc band of centre-line radius r, thickness w, sweeping clockwise a_lo -> a_hi (deg, y-down)."""
    ro, ri = r + w / 2, r - w / 2

    def p(rad, a):
        return cx + rad * math.cos(math.radians(a)), cy + rad * math.sin(math.radians(a))

    large = 1 if (a_hi - a_lo) > 180 else 0
    o0, o1, i1, i0 = p(ro, a_lo), p(ro, a_hi), p(ri, a_hi), p(ri, a_lo)
    return (f"M{f(o0[0])} {f(o0[1])}A{f(ro)} {f(ro)} 0 {large} 1 {f(o1[0])} {f(o1[1])}"
            f"L{f(i1[0])} {f(i1[1])}A{f(ri)} {f(ri)} 0 {large} 0 {f(i0[0])} {f(i0[1])}Z")


# ---------------------------------------------------------------- Latin wordmark
def latin_wordmark(w=W):
    """Returns dict(ink=[d...], gold=[d...], width, bbox) in a coordinate space with baseline y=96."""
    ink, gold = [], []
    x = 0.0

    # q : ring + stem + plumb-bob
    ink.append(ring(x + 48, 48, 48, w, OV))
    stem_end = 124
    ink.append(rect(x + 96 - w, 0, x + 96, stem_end))
    bob_r = 15.5
    gold.append(ellipse(x + 96 - w / 2, stem_end + 8, bob_r, bob_r))
    q_bob = (x + 96 - w / 2, stem_end + 8, bob_r)
    x += 96 + 16

    # i : stem + round dot
    ink.append(rect(x, 0, x + w, XH))
    dot_r = 10.5
    ink.append(ellipse(x + w / 2, -(13 + dot_r), dot_r, dot_r))
    x += w + 12

    # s : two stacked bowls
    rs = (XH - w) / 4
    cx = x + rs + w / 2
    ink.append(band(cx, w / 2 + rs, rs, w, -270, -30))
    ink.append(band(cx, XH - w / 2 - rs, rs, w, -90, 150))
    x += 2 * rs + w + 9

    # t : stem + hook + crossbar
    xs = x + 24
    rt = 16
    hook_cy = XH - w / 2 - rt
    ink.append(rect(xs - w / 2, -12, xs + w / 2, hook_cy))
    ink.append(band(xs + rt, hook_cy, rt, w, 90, 180))
    ink.append(rect(xs + rt, XH - w, xs + rt + 10, XH))
    ink.append(rect(x, 0, xs + 26, w))
    x = xs + rt + 10 + 9

    # a : single-storey
    ink.append(ring(x + 48, 48, 48, w, OV))
    ink.append(rect(x + 96 - w, 0, x + 96, XH))
    x += 96 + 12

    # s
    cx = x + rs + w / 2
    ink.append(band(cx, w / 2 + rs, rs, w, -270, -30))
    ink.append(band(cx, XH - w / 2 - rs, rs, w, -90, 150))
    x += 2 * rs + w

    bbox = (0, -(13 + 2 * dot_r), x, q_bob[1] + bob_r)
    assert len(ink) == 14 and len(gold) == 1
    # per-letter groups (for the animated intro): name, ink-path slice, gold paths
    letters = [
        dict(name="q", ink=ink[0:2], gold=gold[0:1]),
        dict(name="i", ink=ink[2:4], gold=[]),
        dict(name="s1", ink=ink[4:6], gold=[]),
        dict(name="t", ink=ink[6:10], gold=[]),
        dict(name="a", ink=ink[10:12], gold=[]),
        dict(name="s2", ink=ink[12:14], gold=[]),
    ]
    geom = dict(xHeight=XH, stroke=w, overshoot=OV, qRing=dict(cx=48, cy=48, r=48),
                qStem=dict(x=96 - w, y1=0, y2=stem_end, w=w), bob=dict(cx=q_bob[0], cy=q_bob[1], r=bob_r),
                iDot=dict(r=dot_r), qWidth=96)
    return dict(ink=ink, gold=gold, width=x, bbox=bbox, letters=letters, geom=geom)


# ---------------------------------------------------------------- Arabic wordmark
def arabic_wordmark(dot_r=9.5):
    data = json.load(open(os.path.join(HERE, "arabic-wordmark.json"), encoding="utf-8"))
    gold = [ellipse(d["cx"], d["cy"], dot_r, dot_r) for d in data["dots"]]
    b = data["bbox"]
    return dict(ink=[data["d"]], gold=gold, width=b["x2"] - b["x1"],
                bbox=(b["x1"], min(b["y1"], data["dots"][0]["cy"] - dot_r), b["x2"], b["y2"]), raw=True)


# ---------------------------------------------------------------- symbol (q + plumb-bob)
def symbol(t=26, bob_r=22, drop=36, gap=8, R=70):
    """q mark. Origin top-left, ring outer radius R."""
    ink = [ring(R, R, R, t)]
    stem_end = 2 * R + drop
    ink.append(rect(2 * R - t, 0, 2 * R, stem_end))
    gold = [ellipse(2 * R - t / 2, stem_end + gap, bob_r, bob_r)]
    return dict(ink=ink, gold=gold, width=2 * R, bbox=(0, 0, 2 * R, stem_end + gap + bob_r))


# ---------------------------------------------------------------- assembly
PALETTES = {
    "color":    (NAVY, GOLD),
    "reversed": (IVORY, GOLD),
    "black":    (BLACK, BLACK),
    "white":    (WHITE, WHITE),
    "mono":     (NAVY, NAVY),
}


def group(mark, ink, gold, tx=0.0, ty=0.0, s=1.0):
    t = f' transform="translate({f(tx)} {f(ty)}) scale({f(s)})"' if (tx or ty or s != 1.0) else ""
    paths = [f'<path fill="{ink}" d="{"".join(mark["ink"])}"/>']
    if mark["gold"]:
        paths.append(f'<path fill="{gold}" d="{"".join(mark["gold"])}"/>')
    return f"<g{t}>" + "".join(paths) + "</g>"


def svg(w, h, body, title, bg=None):
    bgr = f'<rect width="{f(w)}" height="{f(h)}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(w)} {f(h)}" role="img" '
            f'aria-label="{title}"><title>{title}</title>{bgr}{body}</svg>\n')


def write(name, content):
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as fh:
        fh.write(content)


def tight(mark, pad):
    x1, y1, x2, y2 = mark["bbox"]
    return x1 - pad, y1 - pad, (x2 - x1) + 2 * pad, (y2 - y1) + 2 * pad


def stacked(lat, ara, ink, gold, scale, pad):
    """Latin wordmark above the Arabic wordmark, centred. Returns (svg body, width, height)."""
    s_ar = 0.60 * scale
    lw = lat["width"] * scale
    ly0 = lat["bbox"][1] * scale
    aw = ara["width"] * s_ar
    total_w = max(lw, aw) + 2 * pad
    base1 = 96 * scale - ly0 + pad                       # Latin baseline in the frame
    base2 = base1 + 138 * scale                          # Arabic baseline (clears the plumb-bob)
    body = group(lat, ink, gold, (total_w - lw) / 2, -ly0 + pad, scale)
    body += group(ara, ink, gold, (total_w - aw) / 2 - ara["bbox"][0] * s_ar, base2 - 96 * s_ar, s_ar)
    total_h = base2 + (ara["bbox"][3] - 96) * s_ar + pad
    return body, total_w, total_h


def build_wordmarks():
    lat, ara = latin_wordmark(), arabic_wordmark()
    pad = 12
    for key, (ink, gold) in PALETTES.items():
        # Latin
        x0, y0, w, h = tight(lat, pad)
        write(f"qistas-wordmark-{key}.svg",
              svg(w, h, group(lat, ink, gold, -x0, -y0), "Qistas"))
        # Arabic
        x0, y0, w, h = tight(ara, pad)
        write(f"qistas-wordmark-ar-{key}.svg",
              svg(w, h, group(ara, ink, gold, -x0, -y0), "قسطاس"))

        # Dual, stacked (Latin above Arabic, centred)
        body, total_w, total_h = stacked(lat, ara, ink, gold, 1.0, pad)
        write(f"qistas-lockup-dual-{key}.svg", svg(total_w, total_h, body, "Qistas — قسطاس"))

        # Dual, horizontal (Latin | divider | Arabic) on shared baseline
        s_ar_h = 0.82
        gap = 56
        lw, ly0 = lat["width"], lat["bbox"][1]
        aw_h = ara["width"] * s_ar_h
        total_w = lw + gap + 3 + gap + aw_h + 2 * pad
        base = 96 - ly0 + pad
        body = group(lat, ink, gold, pad, -ly0 + pad)
        div_x = pad + lw + gap
        body += f'<path fill="{gold}" d="{rect(div_x, base - 100, div_x + 3, base + 40)}"/>'
        ar_tx = div_x + 3 + gap - ara["bbox"][0] * s_ar_h
        body += group(ara, ink, gold, ar_tx, base - 96 * s_ar_h, s_ar_h)
        total_h = max(lat["bbox"][3] - ly0 + 2 * pad, base + (ara["bbox"][3] - 96) * s_ar_h + pad)
        write(f"qistas-lockup-dual-h-{key}.svg", svg(total_w, total_h, body, "Qistas | قسطاس"))


def build_symbols():
    reg, small = symbol(), symbol(t=34, bob_r=28, drop=34, gap=6)
    pad = 10
    for key, (ink, gold) in PALETTES.items():
        for name, m in (("symbol", reg), ("symbol-small", small)):
            x0, y0, w, h = tight(m, pad)
            write(f"qistas-{name}-{key}.svg", svg(w, h, group(m, ink, gold, -x0, -y0), "Qistas symbol"))
    return reg, small


def build_icons(reg, small):
    # master 1024 app icon (iOS full-bleed; the OS applies the corner mask)
    def tile(size, s_frac, rounded=False, safe=None, shift=(0.0, 0.0)):
        m = reg
        x1, y1, x2, y2 = m["bbox"]
        mh = y2 - y1
        target = size * s_frac
        s = target / mh
        # optical centring: centre the ring+stem mass, bob hangs below
        tx = (size - (x2 - x1) * s) / 2 + shift[0] * size
        ty = (size - mh * s) / 2 + shift[1] * size
        grad = ('<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">'
                f'<stop offset="0" stop-color="#112C5E"/><stop offset="1" stop-color="#071634"/>'
                '</linearGradient></defs>')
        r = f' rx="{f(size * 0.2237)}"' if rounded else ""
        body = grad + f'<rect width="{size}" height="{size}"{r} fill="url(#g)"/>' + group(m, IVORY, GOLD, tx, ty, s)
        return body

    write("qistas-app-icon-1024.svg", svg(1024, 1024, tile(1024, 0.56, shift=(0.0, -0.005)), "Qistas app icon"))
    write("qistas-app-icon-rounded.svg", svg(1024, 1024, tile(1024, 0.56, rounded=True, shift=(0.0, -0.005)), "Qistas app icon"))
    # Android adaptive: 432 canvas, 264 safe circle -> mark <= ~62% of 264
    m = reg
    x1, y1, x2, y2 = m["bbox"]
    s = (432 * 0.40) / (y2 - y1)
    fg = group(m, IVORY, GOLD, (432 - (x2 - x1) * s) / 2, (432 - (y2 - y1) * s) / 2, s)
    write("qistas-app-icon-android-foreground.svg", svg(432, 432, fg, "Qistas adaptive icon foreground"))
    write("qistas-app-icon-android-background.svg",
          svg(432, 432, '<rect width="432" height="432" fill="#0B1F44"/>', "Qistas adaptive icon background"))
    # Maskable PWA icon: full-bleed, mark inside the central 80% safe zone
    write("qistas-app-icon-maskable.svg", svg(512, 512, tile(512, 0.46, shift=(0.0, -0.005)), "Qistas maskable icon"))
    # Small-size favicon with automatic dark mode
    x1, y1, x2, y2 = small["bbox"]
    pad = 6
    w, h = (x2 - x1) + 2 * pad, (y2 - y1) + 2 * pad
    sz = max(w, h)
    paths = (f'<path class="i" d="{"".join(small["ink"])}"/><path class="g" d="{"".join(small["gold"])}"/>')
    fav = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(sz)} {f(sz)}" role="img" aria-label="Qistas">'
           f'<style>.i{{fill:{NAVY}}}.g{{fill:{GOLD}}}@media (prefers-color-scheme:dark){{.i{{fill:{IVORY}}}}}</style>'
           f'<g transform="translate({f((sz - (x2 - x1)) / 2 - x1)} {f((sz - (y2 - y1)) / 2 - y1)})">{paths}</g></svg>\n')
    write("favicon.svg", fav)
    # Flat favicon tile (works on any tab colour; source for favicon.ico and the PNG icon set)
    mh = y2 - y1
    s_t = 44 / mh
    tile_body = (f'<rect width="64" height="64" rx="14" fill="{NAVY}"/>'
                 + group(small, IVORY, GOLD, (64 - (x2 - x1) * s_t) / 2, (64 - mh * s_t) / 2 - 0.5, s_t))
    write("qistas-favicon-tile.svg", svg(64, 64, tile_body, "Qistas"))
    # Social share card (1200x630): reversed dual lockup on midnight navy
    lat, ara = latin_wordmark(), arabic_wordmark()
    body, bw, bh = stacked(lat, ara, IVORY, GOLD, 1.35, 0)
    og = f'<g transform="translate({f((1200 - bw) / 2)} {f((630 - bh) / 2)})">{body}</g>'
    write("qistas-og-card.svg", svg(1200, 630, og, "Qistas — قسطاس", bg=NAVY))


if __name__ == "__main__":
    build_wordmarks()
    reg, small = build_symbols()
    build_icons(reg, small)
    print("Qistas logo masters written to", OUT)
