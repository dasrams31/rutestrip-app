"""Build the RuteStrip link-preview card (Open Graph / Twitter, 1200x630 PNG).

Usage (from the repo root):
    python python/tools/build_og_image.py
    python python/tools/build_og_image.py --out public/images/og/rutestrip-og.png

Only needs Pillow. The logo is redrawn from public/images/logo.svg geometry
(48x48 viewBox), so no SVG renderer is required. Fonts: the first available
of Inter / Segoe UI / Arial / DejaVu Sans is used; pass --font-dir to point at
a folder that holds Inter-Bold.ttf, Inter-SemiBold.ttf and Inter-Regular.ttf.
"""
from __future__ import annotations

import argparse
import math
import os
import random
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

W, H = 1200, 630
SS = 2  # supersampling factor, downscaled at the end for smooth edges

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_OUT = ROOT / "public" / "images" / "og" / "rutestrip-og.png"

FONT_CANDIDATES = {
    "bold": ["Inter-Bold.ttf", "Inter-ExtraBold.ttf", "segoeuib.ttf", "arialbd.ttf", "DejaVuSans-Bold.ttf"],
    "semibold": ["Inter-SemiBold.ttf", "seguisb.ttf", "segoeuib.ttf", "arialbd.ttf", "DejaVuSans-Bold.ttf"],
    "regular": ["Inter-Regular.ttf", "segoeui.ttf", "arial.ttf", "DejaVuSans.ttf"],
}
FONT_DIRS = [
    Path(os.environ.get("WINDIR", "C:/Windows")) / "Fonts",
    Path.home() / "AppData/Local/Microsoft/Windows/Fonts",
    Path("/usr/share/fonts/truetype/dejavu"),
    Path("/usr/share/fonts/truetype/inter"),
    Path("/usr/share/fonts/opentype/inter"),
    Path("/Library/Fonts"),
]


def font(kind: str, size: int, extra_dir: Path | None) -> ImageFont.FreeTypeFont:
    dirs = ([extra_dir] if extra_dir else []) + FONT_DIRS
    for name in FONT_CANDIDATES[kind]:
        for d in dirs:
            p = d / name
            if p.is_file():
                return ImageFont.truetype(str(p), size * SS)
    return ImageFont.load_default(size * SS)


def hex_rgb(h: str) -> tuple[int, int, int]:
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))  # type: ignore[return-value]


def lerp(a, b, t):
    return tuple(round(x + (y - x) * t) for x, y in zip(a, b))


def s(v: float) -> int:
    return round(v * SS)


def background() -> Image.Image:
    """Deep slate -> emerald diagonal gradient with a soft glow on the right."""
    w, h = W * SS, H * SS
    small = Image.new("RGB", (W // 4, H // 4))
    px = small.load()
    c0, c1, c2 = hex_rgb("#0b1220"), hex_rgb("#052e26"), hex_rgb("#065f46")
    sw, sh = small.size
    for y in range(sh):
        for x in range(sw):
            t = (x / sw) * 0.65 + (y / sh) * 0.35
            col = lerp(c0, c1, min(1, t * 1.6)) if t < 0.62 else lerp(c1, c2, (t - 0.62) / 0.38 * 0.55)
            # radial glow behind the route motif
            dx, dy = (x / sw - 0.78), (y / sh - 0.45)
            g = max(0.0, 1 - math.hypot(dx * 1.4, dy * 1.9) / 0.55)
            col = lerp(col, hex_rgb("#0f766e"), g * 0.35)
            px[x, y] = col
    return small.resize((w, h), Image.BICUBIC).convert("RGBA")


def contour_layer(rng: random.Random) -> Image.Image:
    """Topographic contour rings around a 'summit' on the right side."""
    layer = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    cx, cy = 900, 300
    phases = [rng.uniform(0, math.tau) for _ in range(4)]
    for i in range(1, 17):
        r0 = 26 * i + 8
        pts = []
        for k in range(181):
            a = k / 180 * math.tau
            wob = (
                0.16 * math.sin(2 * a + phases[0])
                + 0.09 * math.sin(3 * a + phases[1] + i * 0.15)
                + 0.05 * math.sin(5 * a + phases[2] - i * 0.1)
            )
            r = r0 * (1 + wob)
            pts.append((s(cx + r * math.cos(a) * 1.25), s(cy + r * math.sin(a) * 0.95)))
        alpha = 46 if i % 4 == 0 else 22
        d.line(pts, fill=(110, 231, 183, alpha), width=s(1.6 if i % 4 == 0 else 1.1), joint="curve")
    return layer


def route_points() -> list[tuple[float, float]]:
    """A switchback trail climbing from bottom-right towards the summit."""
    ctrl = [(1210, 590), (1090, 560), (1010, 520), (1080, 470), (980, 430),
            (870, 440), (930, 380), (850, 345), (905, 300)]
    out = []
    for i in range(len(ctrl) - 1):  # Catmull-Rom through control points
        p0 = ctrl[max(i - 1, 0)]
        p1, p2 = ctrl[i], ctrl[i + 1]
        p3 = ctrl[min(i + 2, len(ctrl) - 1)]
        for k in range(24):
            t = k / 24
            t2, t3 = t * t, t * t * t
            out.append(tuple(
                0.5 * (2 * p1[j] + (-p0[j] + p2[j]) * t + (2 * p0[j] - 5 * p1[j] + 4 * p2[j] - p3[j]) * t2
                       + (-p0[j] + 3 * p1[j] - 3 * p2[j] + p3[j]) * t3)
                for j in (0, 1)))
    out.append(ctrl[-1])
    return out


def route_layer() -> Image.Image:
    pts = route_points()
    spts = [(s(x), s(y)) for x, y in pts]

    glow = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
    ImageDraw.Draw(glow).line(spts, fill=(52, 211, 153, 150), width=s(14), joint="curve")
    glow = glow.filter(ImageFilter.GaussianBlur(s(9)))

    line = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(line)
    d.line(spts, fill=(52, 211, 153, 255), width=s(5), joint="curve")
    # dashed highlight on top of the trail
    for i in range(0, len(spts) - 1, 6):
        d.line(spts[i:i + 3], fill=(236, 253, 245, 200), width=s(1.6))

    # waypoints: start (white ring) and summit (amber pin)
    sx, sy = pts[12]
    ex, ey = pts[-1]
    d.ellipse([s(sx - 9), s(sy - 9), s(sx + 9), s(sy + 9)], fill=(236, 253, 245, 255))
    d.ellipse([s(sx - 5), s(sy - 5), s(sx + 5), s(sy + 5)], fill=(5, 150, 105, 255))
    d.ellipse([s(ex - 16), s(ey - 16), s(ex + 16), s(ey + 16)], fill=lerp(hex_rgb("#fcd34d"), hex_rgb("#0e4034"), 4 / 6) + (255,))  # opaque halo, no dither noise
    d.ellipse([s(ex - 9), s(ey - 9), s(ex + 9), s(ey + 9)], fill=(252, 211, 77, 255))
    d.ellipse([s(ex - 4), s(ey - 4), s(ex + 4), s(ey + 4)], fill=(120, 53, 15, 255))

    return Image.alpha_composite(glow, line)


def bezier(p0, p1, p2, p3, n=40):
    for k in range(n + 1):
        t = k / n
        mt = 1 - t
        yield (mt ** 3 * p0[0] + 3 * mt * mt * t * p1[0] + 3 * mt * t * t * p2[0] + t ** 3 * p3[0],
               mt ** 3 * p0[1] + 3 * mt * mt * t * p1[1] + 3 * mt * t * t * p2[1] + t ** 3 * p3[1])


def logo(size: int) -> Image.Image:
    """Redraw public/images/logo.svg (viewBox 48x48) at `size` px (already supersampled)."""
    k = size / 48
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))

    # rounded square with the #10B981 -> #0D9488 diagonal gradient
    grad = Image.new("RGBA", (size, size))
    gp = grad.load()
    a, b = hex_rgb("#10B981"), hex_rgb("#0D9488")
    for y in range(size):
        for x in range(size):
            gp[x, y] = lerp(a, b, (x + y) / (2 * size)) + (255,)
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=round(12 * k), fill=255)
    img.paste(grad, (0, 0), mask)

    over = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(over)
    P = lambda pts: [(x * k, y * k) for x, y in pts]  # noqa: E731
    d.polygon(P([(10, 36), (19, 16), (24, 24), (28, 18), (38, 36)]), fill=(255, 255, 255, 242))
    d.polygon(P([(19, 16), (24, 24), (22, 28), (14, 36), (10, 36)]), fill=(209, 250, 229, 255))
    trail = list(bezier((16, 36), (18, 32), (20, 30), (22, 28))) + \
        list(bezier((22, 28), (24, 26), (26, 25), (28, 28))) + \
        list(bezier((28, 28), (30, 31), (32, 33), (34, 36)))
    d.line(P(trail), fill=hex_rgb("#059669") + (255,), width=max(1, round(2 * k)), joint="curve")
    r = 4 * k
    d.ellipse([35 * k - r, 14 * k - r, 35 * k + r, 14 * k + r], fill=(252, 211, 77, 235))
    img = Image.alpha_composite(img, over)
    # clip everything to the rounded square
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(img, (0, 0), mask)
    return out


def shadow(size: int, radius: int, blur: int, alpha: int) -> Image.Image:
    pad = blur * 3
    sh = Image.new("RGBA", (size + pad * 2, size + pad * 2), (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle([pad, pad, pad + size, pad + size], radius=radius, fill=(0, 0, 0, alpha))
    return sh.filter(ImageFilter.GaussianBlur(blur))


def build(out: Path, font_dir: Path | None) -> None:
    rng = random.Random(31)
    img = background()
    img = Image.alpha_composite(img, contour_layer(rng))
    img = Image.alpha_composite(img, route_layer())

    # Left-side readability scrim so the text never sits on busy lines
    scrim = Image.new("RGBA", img.size, (0, 0, 0, 0))
    sp = ImageDraw.Draw(scrim)
    for x in range(0, s(760), SS):
        t = x / s(760)
        sp.line([(x, 0), (x, H * SS)], fill=(11, 18, 32, round(150 * (1 - t) ** 1.4)))
    img = Image.alpha_composite(img, scrim)

    d = ImageDraw.Draw(img, "RGBA")  # blend translucent fills
    left = 80

    # Logo + wordmark
    lsize = 96
    sh = shadow(s(lsize), s(24), s(12), 110)
    img.alpha_composite(sh, (s(left) - s(36), s(92) - s(36) + s(8)))
    img.alpha_composite(logo(s(lsize)), (s(left), s(92)))
    f_brand = font("bold", 76, font_dir)
    d.text((s(left + lsize + 28), s(92 + lsize / 2)), "RuteStrip", font=f_brand, fill=(255, 255, 255, 255), anchor="lm")

    # Tagline (two lines)
    f_tag = font("semibold", 46, font_dir)
    y = 252
    for line in ("Cari jalur pendakian gunung", "di Pulau Jawa"):
        d.text((s(left), s(y)), line, font=f_tag, fill=(236, 253, 245, 255))
        y += 58

    # Supporting line
    f_sub = font("regular", 25, font_dir)
    d.text((s(left), s(y + 18)), "Jarak, total tanjakan, estimasi waktu, dan peta GPX.",
           font=f_sub, fill=(167, 243, 208, 230))
    d.text((s(left), s(y + 52)), "Rekomendasi rute dengan AI (SBERT).",
           font=f_sub, fill=(167, 243, 208, 230))

    # Domain pill
    f_dom = font("semibold", 28, font_dir)
    label = "rutestrip.ai.id"
    tw = d.textlength(label, font=f_dom) / SS
    px, py, ph = left, 520, 54
    d.rounded_rectangle([s(px), s(py), s(px + tw + 64), s(py + ph)], radius=s(ph / 2),
                        fill=(16, 185, 129, 40), outline=(52, 211, 153, 170), width=s(1.5))
    d.ellipse([s(px + 22), s(py + ph / 2 - 5), s(px + 32), s(py + ph / 2 + 5)], fill=(52, 211, 153, 255))
    d.text((s(px + 44), s(py + ph / 2)), label, font=f_dom, fill=(255, 255, 255, 255), anchor="lm")

    final = img.resize((W, H), Image.LANCZOS).convert("RGB")
    out.parent.mkdir(parents=True, exist_ok=True)
    # 256-colour dithered PNG (~1/2 the truecolor size). Median cut alone drops
    # the small amber accents (sun, summit pin), so reserve palette slots for them.
    accents = [hex_rgb(h) for h in ("#ffffff", "#ecfdf5", "#34d399", "#78350f")]
    amber = hex_rgb("#fcd34d")
    for bg in ("#0e4034", "#10b981", "#0d9488", "#78350f"):  # anti-aliased amber edges
        accents += [lerp(amber, hex_rgb(bg), t / 6) for t in range(6)]
    n = 256 - len(accents)
    base = final.quantize(colors=n, method=Image.Quantize.MEDIANCUT).getpalette()[: n * 3]
    pal = Image.new("P", (1, 1))
    pal.putpalette(base + [c for rgb in accents for c in rgb])
    final.quantize(palette=pal, dither=Image.Dither.FLOYDSTEINBERG).save(out, optimize=True)
    print(f"wrote {out} ({out.stat().st_size / 1024:.0f} KB)")


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    ap.add_argument("--font-dir", type=Path, default=None)
    args = ap.parse_args()
    build(args.out, args.font_dir)
