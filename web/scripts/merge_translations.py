"""Merge a batch of new translations into lang/app/{ar,fr,es,ur}.json.

The batch is a JSON file shaped {"English string": {"ar": "...", "fr": "...", "es": "...", "ur": "..."}, ...}.
Refuses a batch that misses a language, is empty, or changes the :placeholders of the English string. Keeps every
file sorted the same way scripts/extract_strings.py sorts, so diffs stay small.

    python scripts/merge_translations.py path/to/batch.json
    python scripts/merge_translations.py --missing        # list strings used in code but not yet translated
"""
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import extract_strings  # noqa: E402

LANGS = ['ar', 'fr', 'es', 'ur']
ROOT = Path(__file__).resolve().parent.parent
PLACEHOLDER = re.compile(r':[a-z_]+')


def load(lang: str) -> dict:
    with open(ROOT / 'lang' / 'app' / f'{lang}.json', encoding='utf-8') as handle:
        return json.load(handle)


def save(lang: str, data: dict) -> None:
    ordered = {key: data[key] for key in sorted(data)}
    with open(ROOT / 'lang' / 'app' / f'{lang}.json', 'w', encoding='utf-8', newline='\n') as handle:
        json.dump(ordered, handle, ensure_ascii=False, indent=4)
        handle.write('\n')


def missing() -> list[str]:
    used = set(extract_strings.strings())
    return sorted(used - set(load('ar')))


def main() -> int:
    if '--missing' in sys.argv:
        for line in missing():
            print(line)
        return 0

    batch = json.load(open(sys.argv[1], encoding='utf-8'))
    problems = []
    for english, translations in batch.items():
        for lang in LANGS:
            text = translations.get(lang, '').strip()
            if not text:
                problems.append(f'[{lang}] missing: {english}')
            elif sorted(set(PLACEHOLDER.findall(text))) != sorted(set(PLACEHOLDER.findall(english))):
                problems.append(f'[{lang}] placeholders differ: {english}')
    if problems:
        print('\n'.join(problems), file=sys.stderr)
        return 1

    for lang in LANGS:
        data = load(lang)
        data.update({english: translations[lang] for english, translations in batch.items()})
        save(lang, data)

    print(f'merged {len(batch)} strings into {", ".join(LANGS)}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
