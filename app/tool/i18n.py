"""Keeps the app's translation files complete.

The English sentence is the key, written in the code as context.t('Sentence with :placeholders'). This tool reads
every such sentence from lib/, reuses the website's translation of it where the website already has one
(web/lang/app/<lang>.json), and writes assets/i18n/<lang>.json, which is exactly the sentences the app uses.

    python tool/i18n.py --missing         # sentences used in the code that have no translation yet
    python tool/i18n.py --sync            # write assets/i18n/*.json (drops sentences no longer used)
    python tool/i18n.py batch.json        # add translations, then sync

A batch is {"English sentence": {"ar": "...", "fr": "...", "es": "...", "ur": "..."}, ...}. A batch is refused if it
misses a language or changes a :placeholder.
"""
import json
import re
import sys
from pathlib import Path

LANGS = ['ar', 'fr', 'es', 'ur']
APP = Path(__file__).resolve().parent.parent
WEB = APP.parent / 'web' / 'lang' / 'app'
OUT = APP / 'assets' / 'i18n'
PLACEHOLDER = re.compile(r':[a-z_]+')
CALL = re.compile(r"\bt\(\s*'((?:[^'\\]|\\.)*)'")
SKIP = {'translations.dart'}


def decode(raw: str) -> str:
    return re.sub(r"\\(['\\$n])", lambda m: '\n' if m.group(1) == 'n' else m.group(1), raw)


def used() -> set[str]:
    found: set[str] = set()
    for path in sorted((APP / 'lib').rglob('*.dart')):
        if path.name in SKIP:
            continue
        text = '\n'.join(line for line in path.read_text(encoding='utf-8').splitlines() if not line.lstrip().startswith('//'))
        for match in CALL.finditer(text):
            sentence = decode(match.group(1))
            if '$' in sentence:
                raise SystemExit(f'{path.name}: "{sentence}" is built with $ interpolation; use :placeholders so it can be translated')
            found.add(sentence)
    return found


def read(path: Path) -> dict[str, str]:
    return json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}


def write(lang: str, data: dict[str, str]) -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    ordered = {key: data[key] for key in sorted(data)}
    (OUT / f'{lang}.json').write_bytes((json.dumps(ordered, ensure_ascii=False, indent=2) + '\n').encode('utf-8'))


def same_placeholders(english: str, translated: str) -> bool:
    return sorted(set(PLACEHOLDER.findall(english))) == sorted(set(PLACEHOLDER.findall(translated)))


def resolve(sentences: set[str]) -> tuple[dict[str, dict[str, str]], list[str]]:
    """Per language the sentences we can translate, and the sentences some language still lacks."""
    tables: dict[str, dict[str, str]] = {lang: {} for lang in LANGS}
    lacking: list[str] = []
    for lang in LANGS:
        mine, web = read(OUT / f'{lang}.json'), read(WEB / f'{lang}.json')
        for sentence in sentences:
            for source in (mine, web):
                value = source.get(sentence, '').strip()
                if value and same_placeholders(sentence, value):
                    tables[lang][sentence] = value
                    break
            else:
                lacking.append(sentence)
    return tables, sorted(set(lacking))


def main() -> int:
    args = sys.argv[1:]
    sentences = used()

    if args and not args[0].startswith('--'):
        batch = json.loads(Path(args[0]).read_text(encoding='utf-8'))
        problems = []
        for english, translations in batch.items():
            for lang in LANGS:
                text = translations.get(lang, '').strip()
                if not text:
                    problems.append(f'[{lang}] missing: {english}')
                elif not same_placeholders(english, text):
                    problems.append(f'[{lang}] placeholders differ: {english}')
        if problems:
            print('\n'.join(problems), file=sys.stderr)
            return 1
        for lang in LANGS:
            data = read(OUT / f'{lang}.json')
            data.update({english: translations[lang].strip() for english, translations in batch.items()})
            write(lang, data)
        print(f'merged {len(batch)} sentences')
        args = ['--sync']

    tables, lacking = resolve(sentences)

    if '--missing' in args:
        print('\n'.join(lacking))
        return 0

    if '--sync' in args:
        for lang in LANGS:
            write(lang, tables[lang])
        print(f'{len(sentences)} sentences used; {len(lacking)} still untranslated')
        return 1 if lacking else 0

    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main())
