"""Draws the Qistas mark into every picture the Android app needs: the adaptive icon's foreground and monochrome layers
(flutter_launcher_icons turns them into the launcher files) and the launch screen's mark for light and dark.

The mark is the brand's "Plumb q" (brand/logo/qistas-symbol-color.svg): a ring of radius 70 with a hole of 44, a stem
from x 114 to 140 and y 0 to 176, and the gold plumb of radius 22 at (127, 184), in a 149 x 206 box. It is drawn four
times larger than needed and scaled down, so the edges are smooth.

    python tool/icons.py            then    dart run flutter_launcher_icons
"""
from pathlib import Path

from PIL import Image, ImageDraw

APP = Path(__file__).resolve().parent.parent
RES = APP / 'android' / 'app' / 'src' / 'main' / 'res'

NAVY, IVORY, GOLD, GOLD_DARK, WHITE = '#0B1F44', '#F7F3EA', '#C9A25B', '#D4AE68', '#FFFFFF'
MARK_W, MARK_H = 149, 206
SUPER = 4

# The adaptive icon's 432-unit canvas (108 dp) with the mark where the brand's foreground SVG puts it: inside the
# middle 66 %, which every launcher shape keeps.
CANVAS, OFFSET, SCALE = 432, (157.28, 129.6), 0.84

# Launch-screen mark height in dp, and Android's density buckets.
LAUNCH_DP = 96
DENSITIES = {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}


def draw_mark(size: int, origin: tuple[float, float], scale: float, ink: str, plumb: str) -> Image.Image:
    """A transparent square of [size] px with the mark at [origin] (in px) drawn at [scale] px per unit."""
    big = size * SUPER
    s = scale * SUPER
    ox, oy = origin[0] * SUPER, origin[1] * SUPER

    def box(cx: float, cy: float, r: float) -> tuple[float, float, float, float]:
        return (ox + (cx - r) * s, oy + (cy - r) * s, ox + (cx + r) * s, oy + (cy + r) * s)

    ink_mask = Image.new('L', (big, big), 0)
    pen = ImageDraw.Draw(ink_mask)
    pen.ellipse(box(70, 70, 70), fill=255)
    pen.ellipse(box(70, 70, 44), fill=0)
    pen.rectangle((ox + 114 * s, oy, ox + 140 * s, oy + 176 * s), fill=255)

    plumb_mask = Image.new('L', (big, big), 0)
    ImageDraw.Draw(plumb_mask).ellipse(box(127, 184, 22), fill=255)

    image = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    image.paste(Image.new('RGBA', (big, big), ink), mask=ink_mask)
    image.paste(Image.new('RGBA', (big, big), plumb), mask=plumb_mask)

    return image.resize((size, size), Image.LANCZOS)


def adaptive_layer(size: int, ink: str, plumb: str) -> Image.Image:
    unit = size / CANVAS
    return draw_mark(size, (OFFSET[0] * unit, OFFSET[1] * unit), SCALE * unit, ink, plumb)


def bare_mark(height: int, ink: str, plumb: str) -> Image.Image:
    """Just the mark, [height] px tall, cropped to its own width."""
    scale = height / MARK_H
    square = draw_mark(height, ((height - MARK_W * scale) / 2, 0), scale, ink, plumb)
    left = round((height - MARK_W * scale) / 2)

    return square.crop((left, 0, left + round(MARK_W * scale), height))


def save(image: Image.Image, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, optimize=True)
    print(f'  {path.relative_to(APP)}  {image.size[0]}x{image.size[1]}')


def main() -> None:
    print('Adaptive icon layers')
    save(adaptive_layer(1024, IVORY, GOLD), APP / 'assets' / 'icon' / 'foreground.png')
    save(adaptive_layer(1024, WHITE, WHITE), APP / 'assets' / 'icon' / 'monochrome.png')

    print('Launch screen (Android 5 to 11): the mark as the app draws it, ivory canvas by day, navy by night')
    for bucket, factor in DENSITIES.items():
        height = round(LAUNCH_DP * factor)
        save(bare_mark(height, NAVY, GOLD), RES / f'drawable-{bucket}' / 'launch_mark.png')
        save(bare_mark(height, IVORY, GOLD_DARK), RES / f'drawable-night-{bucket}' / 'launch_mark.png')

    print('Launch screen (Android 12 and later): the mark on the system splash')
    save(adaptive_layer(768, NAVY, GOLD), RES / 'drawable-nodpi' / 'splash_mark.png')
    save(adaptive_layer(768, IVORY, GOLD_DARK), RES / 'drawable-night-nodpi' / 'splash_mark.png')


if __name__ == '__main__':
    main()
