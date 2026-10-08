"""Builds the app's bundled fonts (assets/fonts/*.ttf) from the website's font packages, so the app never needs the
network to draw its type and looks the same offline as online.

    cd web && npm ci               # the @fontsource packages are the source
    pip install fonttools brotli
    python app/tool/fonts.py

Flutter on a phone reads TrueType, not WOFF, and wants one file per weight, so the website's WOFF2 files are
converted and the variable Geist is cut into the four weights the app uses. All four families are under the SIL
Open Font License; their licence texts are copied next to the fonts.
"""
import shutil
from pathlib import Path

from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

ROOT = Path(__file__).resolve().parents[2]
MODULES = ROOT / 'web' / 'node_modules'
OUT = ROOT / 'app' / 'assets' / 'fonts'

STATIC = {
    # output name: source file
    'CormorantGaramond-SemiBold': '@fontsource/cormorant-garamond/files/cormorant-garamond-latin-600-normal.woff2',
    'CormorantGaramond-Bold': '@fontsource/cormorant-garamond/files/cormorant-garamond-latin-700-normal.woff2',
    'IBMPlexSansArabic-Regular': '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-400-normal.woff2',
    'IBMPlexSansArabic-Medium': '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-500-normal.woff2',
    'IBMPlexSansArabic-SemiBold': '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-600-normal.woff2',
    'IBMPlexSansArabic-Bold': '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-700-normal.woff2',
    'NotoNastaliqUrdu-Medium': '@fontsource/noto-nastaliq-urdu/files/noto-nastaliq-urdu-arabic-500-normal.woff2',
    'NotoNastaliqUrdu-SemiBold': '@fontsource/noto-nastaliq-urdu/files/noto-nastaliq-urdu-arabic-600-normal.woff2',
}

GEIST = '@fontsource-variable/geist/files/geist-latin-wght-normal.woff2'
GEIST_WEIGHTS = {'Regular': 400, 'Medium': 500, 'SemiBold': 600, 'Bold': 700}

LICENCES = {
    'CormorantGaramond': '@fontsource/cormorant-garamond/LICENSE',
    'IBMPlexSansArabic': '@fontsource/ibm-plex-sans-arabic/LICENSE',
    'NotoNastaliqUrdu': '@fontsource/noto-nastaliq-urdu/LICENSE',
    'Geist': '@fontsource-variable/geist/LICENSE',
}


def save(font: TTFont, name: str) -> None:
    font.flavor = None
    font.save(OUT / f'{name}.ttf')


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    for name, source in STATIC.items():
        save(TTFont(MODULES / source), name)

    for label, weight in GEIST_WEIGHTS.items():
        variable = TTFont(MODULES / GEIST)
        save(instancer.instantiateVariableFont(variable, {'wght': weight}), f'Geist-{label}')

    for family, licence in LICENCES.items():
        source = MODULES / licence
        if source.exists():
            shutil.copyfile(source, OUT / f'OFL-{family}.txt')

    total = sum(f.stat().st_size for f in OUT.glob('*.ttf'))
    print(f'{len(list(OUT.glob("*.ttf")))} fonts, {total / 1024:.0f} KiB')


if __name__ == '__main__':
    main()
