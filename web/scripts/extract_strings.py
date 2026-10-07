"""List every translatable string used by the application.

Scans app/ and resources/views/ for __('...') calls with a literal string and prints them, one per line, sorted.
Used to keep lang/app/*.json complete; the test suite enforces the same rule (tests/Feature/TranslationsTest.php).

    python scripts/extract_strings.py            # print the strings
    python scripts/extract_strings.py --json     # print them as a JSON array
"""
import glob
import json
import re
import sys

PATTERN = re.compile(r"""__\(\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")""", re.S)


def unquote(raw: str) -> str:
    quote, body = raw[0], raw[1:-1]
    return body.replace("\\'", "'") if quote == "'" else body.replace('\\"', '"')


def strings() -> list[str]:
    found = set()
    paths = glob.glob('app/**/*.php', recursive=True) + glob.glob('resources/views/**/*.php', recursive=True)
    for path in paths:
        with open(path, encoding='utf-8') as handle:
            for match in PATTERN.finditer(handle.read()):
                found.add(unquote(match.group(1)))
    return sorted(found)


if __name__ == '__main__':
    result = strings()
    if '--json' in sys.argv:
        print(json.dumps(result, ensure_ascii=False, indent=1))
    else:
        print(len(result), 'strings', file=sys.stderr)
        for line in result:
            print(line)
