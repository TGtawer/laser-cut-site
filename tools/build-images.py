"""
Готовит фото для сайта из исходников assets/photos/*.png.

Для каждого исходника создаёт:
  <имя>-640.webp  / <имя>-640.jpg   — превью для сетки «Наши работы»
  <имя>-1280.webp / <имя>-1280.jpg  — крупный вариант для лайтбокса
Отдельно: assets/og-image.jpg (1200×630) — картинка для превью ссылки в соцсетях.

Исходные PNG не изменяются. Если фото обрежут или заменят (TODO #11),
положите новые PNG в assets/photos/ и запустите скрипт ещё раз.

Запуск из корня проекта:  python tools/build-images.py
Нужен Pillow:             pip install Pillow
"""
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
PHOTOS = ROOT / "assets" / "photos"
WIDTHS = (640, 1280)
WEBP_QUALITY = 80
JPEG_QUALITY = 82

# Источник og-image и вертикальный отступ кадра 1200×630 (подобран так,
# чтобы в кадр не попал водяной знак в левом нижнем углу).
OG_SOURCE = "02-kuzov-bortovoy.png"
OG_SIZE = (1200, 630)
OG_TOP = 0.12  # доля высоты исходника, с которой начинается кадр


def save_pair(im: Image.Image, base: Path) -> None:
    im.save(base.with_suffix(".webp"), "WEBP", quality=WEBP_QUALITY, method=6)
    im.save(base.with_suffix(".jpg"), "JPEG", quality=JPEG_QUALITY,
            optimize=True, progressive=True)


def build_photo(src: Path) -> None:
    with Image.open(src) as im:
        im = im.convert("RGB")
        for width in WIDTHS:
            w = min(width, im.width)
            h = round(im.height * w / im.width)
            resized = im.resize((w, h), Image.LANCZOS) if w != im.width else im
            save_pair(resized, PHOTOS / f"{src.stem}-{width}")
            print(f"{src.name} -> {src.stem}-{width}.webp/.jpg  {w}x{h}")


def build_og() -> None:
    with Image.open(PHOTOS / OG_SOURCE) as im:
        im = im.convert("RGB")
        ratio = OG_SIZE[1] / OG_SIZE[0]
        crop_h = round(im.width * ratio)
        top = min(round(im.height * OG_TOP), im.height - crop_h)
        frame = im.crop((0, top, im.width, top + crop_h)).resize(OG_SIZE, Image.LANCZOS)
        out = ROOT / "assets" / "og-image.jpg"
        frame.save(out, "JPEG", quality=JPEG_QUALITY, optimize=True, progressive=True)
        print(f"{OG_SOURCE} -> {out.relative_to(ROOT)}  {OG_SIZE[0]}x{OG_SIZE[1]}")


if __name__ == "__main__":
    for path in sorted(PHOTOS.glob("*.png")):
        build_photo(path)
    build_og()
