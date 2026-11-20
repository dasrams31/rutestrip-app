"""Build the RuteStrip app icons (favicon, Apple touch icon, PWA icons).

Usage (from the repo root):
    python python/tools/build_icons.py

Only needs Pillow. The artwork is redrawn from public/images/logo.svg
(viewBox 48x48: emerald rounded square, white mountains, green trail, yellow sun),
supersampled 4x and downscaled for smooth edges.

Outputs (public/):
    images/icons/icon-192.png, icon-512.png      rounded square, transparent corners ("any")
    images/icons/favicon-32.png                  same, 32 px
    images/icons/maskable-192.png, maskable-512.png
                                                 full-bleed square, artwork inside the 80 % safe zone
    images/icons/apple-touch-icon.png            180 px, full-bleed and opaque: iOS rounds the
                                                 corners itself and paints transparent pixels black
    favicon.ico                                  16/32/48 px, transparent corners

Transparent corners are real alpha (RGBA), never black: the old icons were
flattened without alpha, which is what showed up as black corners.
"""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / "public"
ICONS = PUBLIC / "images" / "icons"
SS = 4  # supersampling

GRAD_FROM = (0x10, 0xB9, 0x81)  # #10B981
GRAD_TO = (0x0D, 0x94, 0x88)    # #0D9488
TRAIL = (0x05, 0x96, 0x69, 255)  # #059669
SUN = (0xFC, 0xD3, 0x4D, 235)    # #FCD34D @ 0.9


def gradient(size: int) -> Image.Image:
    """Diagonal #10B981 -> #0D9488 gradient (top-left to bottom-right), like the SVG."""
    small = Image.new("RGB", (64, 64))
    px = small.load()
    for y in range(64):
        for x in range(64):
            t = (x + y) / 126
            px[x, y] = tuple(round(a + (b - a) * t) for a, b in zip(GRAD_FROM, GRAD_TO))
    return small.resize((size, size), Image.BILINEAR).convert("RGBA")


def bezier(p0, p1, p2, p3, n=48):
    for i in range(n + 1):
        t = i / n
        m = 1 - t
        yield (m ** 3 * p0[0] + 3 * m * m * t * p1[0] + 3 * m * t * t * p2[0] + t ** 3 * p3[0],
               m ** 3 * p0[1] + 3 * m * m * t * p1[1] + 3 * m * t * t * p2[1] + t ** 3 * p3[1])


def glyph(size: int, scale: float) -> Image.Image:
    """Mountains, trail and sun on a transparent canvas. `scale` < 1 shrinks the
    48-unit artwork around the centre (for maskable / touch icons)."""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    k = size / 48 * scale
    off = size * (1 - scale) / 2

    def P(points):
        return [(off + x * k, off + y * k) for x, y in points]

    d.polygon(P([(10, 36), (19, 16), (24, 24), (28, 18), (38, 36)]), fill=(255, 255, 255, 242))
    d.polygon(P([(19, 16), (24, 24), (22, 28), (14, 36), (10, 36)]), fill=(209, 250, 229, 255))
    trail = (list(bezier((16, 36), (18, 32), (20, 30), (22, 28)))
             + list(bezier((22, 28), (24, 26), (26, 25), (28, 28)))
             + list(bezier((28, 28), (30, 31), (32, 33), (34, 36))))
    # Stroke as a chain of round dots: smooth curve with round caps at any size.
    half = max(0.5, k)  # stroke-width 2 -> radius 1 unit
    pts = P(trail)
    for (x0, y0), (x1, y1) in zip(pts, pts[1:]):
        steps = max(1, int(((x1 - x0) ** 2 + (y1 - y0) ** 2) ** 0.5 / (half / 2)))
        for i in range(steps + 1):
            x, y = x0 + (x1 - x0) * i / steps, y0 + (y1 - y0) * i / steps
            d.ellipse([x - half, y - half, x + half, y + half], fill=TRAIL)
    cx, cy, r = off + 35 * k, off + 14 * k, 4 * k
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=SUN)
    return img


def render(px: int, *, rounded: bool, scale: float = 1.0) -> Image.Image:
    size = px * SS
    art = Image.alpha_composite(gradient(size), glyph(size, scale))
    if rounded:
        mask = Image.new("L", (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=round(size * 12 / 48), fill=255)
        # Corners: alpha 0 but the emerald colour underneath (not black), so any
        # scaler that ignores premultiplied alpha still blends to green, not to a dark fringe.
        alpha = Image.composite(art.getchannel("A"), Image.new("L", (size, size), 0), mask)
        art = art.copy()
        art.putalpha(alpha)
    return art.resize((px, px), Image.LANCZOS)


def main() -> None:
    ICONS.mkdir(parents=True, exist_ok=True)

    for px in (192, 512):
        render(px, rounded=True).save(ICONS / f"icon-{px}.png", optimize=True)
        # Maskable safe zone is a centred circle of 80 % diameter: 0.74 keeps the sun inside it.
        render(px, rounded=False, scale=0.74).save(ICONS / f"maskable-{px}.png", optimize=True)

    render(32, rounded=True).save(ICONS / "favicon-32.png", optimize=True)
    render(180, rounded=False, scale=0.86).convert("RGB").save(ICONS / "apple-touch-icon.png", optimize=True)

    ico = render(256, rounded=True)
    ico.save(PUBLIC / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])

    for f in sorted(ICONS.glob("*.png")) + [PUBLIC / "favicon.ico"]:
        im = Image.open(f)
        corner = im.convert("RGBA").getpixel((0, 0))
        print(f"{f.relative_to(ROOT)}  {im.size[0]}x{im.size[1]}  {im.mode}  corner={corner}")


if __name__ == "__main__":
    main()
