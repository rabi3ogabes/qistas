#!/usr/bin/env python3
"""
Static validation of everything that is configuration or data (no browser needed).

  python scripts/validate-assets.py

Checks
  - every .json parses; every SVG is well-formed XML
  - vercel.json: redirect/rewrite destinations exist, CSP has the required directives
  - .vercelignore never excludes a file that a deployed page references
  - the theme seed: schema, unique ids, known tokens, valid #RRGGBB, valid scopes and recurrences,
    exactly one published base theme, greetings only in the five launch languages
  - every deployed HTML page has lang, title, viewport and the noindex meta (test deployment)
"""
import fnmatch
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
errors, checks = [], 0


def ok(cond, msg):
    global checks
    checks += 1
    if not cond:
        errors.append(msg)


def rel(p):
    return os.path.relpath(p, ROOT).replace("\\", "/")


def walk(exts, skip=(".git", "node_modules", ".claude")):
    for dp, dns, fns in os.walk(ROOT):
        dns[:] = [d for d in dns if d not in skip]
        for fn in fns:
            if fn.lower().endswith(exts):
                yield os.path.join(dp, fn)


# ---------------------------------------------------------------- JSON + SVG
for p in walk((".json", ".webmanifest")):
    try:
        json.load(open(p, encoding="utf-8"))
        ok(True, "")
    except Exception as e:  # noqa: BLE001
        ok(False, f"{rel(p)}: invalid JSON ({e})")
for p in walk((".svg",)):
    try:
        ET.parse(p)
        ok(True, "")
    except Exception as e:  # noqa: BLE001
        ok(False, f"{rel(p)}: invalid SVG ({e})")

# ---------------------------------------------------------------- vercel.json
cfg = json.load(open(os.path.join(ROOT, "vercel.static.json"), encoding="utf-8"))


def exists(url_path):
    p = os.path.join(ROOT, url_path.lstrip("/"))
    return os.path.isfile(p) or os.path.isfile(os.path.join(p, "index.html"))


for r in cfg.get("redirects", []):
    ok(exists(r["destination"]), f"vercel.json redirect {r['source']} -> {r['destination']}: destination missing")
for r in cfg.get("rewrites", []):
    ok(exists(r["destination"]), f"vercel.json rewrite {r['source']} -> {r['destination']}: destination missing")
hdrs = {h["key"].lower(): h["value"] for rule in cfg.get("headers", []) if rule["source"] == "/(.*)" for h in rule["headers"]}
csp = hdrs.get("content-security-policy", "")
for needle in ("default-src 'self'", "script-src 'self'", "object-src 'none'", "base-uri 'self'", "frame-ancestors 'none'"):
    ok(needle in csp, f"CSP is missing: {needle}")
ok("unsafe-eval" not in csp and not re.search(r"script-src[^;]*unsafe-inline", csp), "CSP allows unsafe script execution")
for k in ("x-content-type-options", "x-frame-options", "referrer-policy", "permissions-policy"):
    ok(k in hdrs, f"missing header {k}")

# ---------------------------------------------------------------- .vercelignore vs deployed pages
patterns = []
vi = os.path.join(ROOT, ".vercelignore.static")
if os.path.exists(vi):
    patterns = [l.strip() for l in open(vi, encoding="utf-8") if l.strip() and not l.startswith("#")]


def ignored(path):
    path = "/" + path.lstrip("/")
    for pat in patterns:
        pat_n = pat if pat.startswith("/") else "/**/" + pat
        if fnmatch.fnmatch(path, pat_n) or path == pat_n or path.startswith(pat_n.rstrip("/") + "/"):
            return True
    return False


# head-snippet.html is a copy/paste fragment for other sites, not a page of this site
pages = [p for p in walk((".html",)) if not ignored("/" + rel(p)) and "head-snippet" not in p]
for page in pages:
    html = open(page, encoding="utf-8").read()
    base_dir = os.path.dirname(page)
    r = rel(page)
    ok(re.search(r"<html[^>]*\blang=", html) is not None, f"{r}: <html> has no lang")
    ok(re.search(r"<title>[^<]+</title>", html) is not None, f"{r}: no <title>")
    ok('name="viewport"' in html, f"{r}: no viewport meta")
    ok('name="robots" content="noindex' in html, f"{r}: missing noindex meta (test deployment)")
    for ref in re.findall(r'''\b(?:href|src)=["']([^"'#?]+)''', html):
        if re.match(r"^(https?:|mailto:|data:|//|javascript:)", ref):
            continue
        target = os.path.join(ROOT, ref.lstrip("/")) if ref.startswith("/") else os.path.normpath(os.path.join(base_dir, ref))
        if os.path.isdir(target):
            target = os.path.join(target, "index.html")
        served = rel(target) if os.path.exists(target) else None
        ok(served is not None or any(r_["source"] == ref for r_ in cfg.get("rewrites", [])), f"{r}: reference {ref} does not exist")
        if served:
            ok(not ignored("/" + served), f"{r}: references {ref}, which .vercelignore excludes from the deployment")

# ---------------------------------------------------------------- theme seed
seed = json.load(open(os.path.join(ROOT, "brand/tokens/themes.seed.json"), encoding="utf-8"))["themes"]
runtime = open(os.path.join(ROOT, "brand/shared/qistas-theme.js"), encoding="utf-8").read()
tokens = set(re.findall(r"'([A-Za-z]+)'", re.search(r"const TOKEN_NAMES = \[(.*?)\];", runtime, re.S).group(1)))
ok(len(tokens) == 26, f"expected 26 theme tokens, found {len(tokens)}")
ids = [t["id"] for t in seed]
ok(len(ids) == len(set(ids)), "duplicate theme ids in the seed")
bases = [t for t in seed if t["kind"] == "base" and t["status"] == "published"]
ok(len(bases) == 1, f"expected exactly one published base theme, found {len(bases)}")
for t in seed:
    tid = t["id"]
    ok(t["kind"] in ("base", "country", "event"), f"{tid}: bad kind")
    ok(t["status"] in ("draft", "published", "archived"), f"{tid}: bad status")
    ok(isinstance(t.get("name"), dict) and "en" in t["name"], f"{tid}: name needs an 'en' entry")
    for mode in ("light", "dark"):
        for k, v in t["tokens"].get(mode, {}).items():
            ok(k in tokens, f"{tid}.{mode}.{k}: unknown token")
            ok(re.fullmatch(r"#[0-9A-Fa-f]{6}", v) is not None, f"{tid}.{mode}.{k}: {v!r} is not #RRGGBB")
    if t["kind"] == "base":
        for mode in ("light", "dark"):
            ok(set(t["tokens"][mode]) == tokens, f"{tid}: base theme must define all 26 tokens in {mode}")
        ok(t["scope"]["countries"] == ["*"] and "recurrence" not in t["schedule"], "base theme must be global and unscheduled")
    for c in t["scope"]["countries"]:
        ok(c == "*" or re.fullmatch(r"[A-Z]{2}", c) is not None, f"{tid}: bad country code {c!r}")
    rec = t["schedule"].get("recurrence")
    if rec:
        ok(rec["type"] in ("gregorian_yearly", "hijri_yearly"), f"{tid}: bad recurrence type")
        ok(1 <= rec["month"] <= 12 and 1 <= rec["day"] <= (30 if rec["type"] == "hijri_yearly" else 31), f"{tid}: bad recurrence date")
        ok(1 <= rec.get("spanDays", 1) <= 60, f"{tid}: spanDays out of range")
    for key, by_locale in t.get("copy", {}).items():
        ok(set(by_locale) <= {"en", "ar", "fr", "es", "ur"}, f"{tid}.copy.{key}: unexpected language")
        ok("en" in by_locale, f"{tid}.copy.{key}: needs an English fallback")

# ---------------------------------------------------------------- manifest
man = json.load(open(os.path.join(ROOT, "brand/logo/web/site.webmanifest"), encoding="utf-8"))
for icon in man["icons"]:
    ok(exists(icon["src"]), f"manifest icon {icon['src']} missing")

print(f"{checks} checks")
if errors:
    print("\n".join("FAIL " + e for e in errors))
    sys.exit(1)
print("All asset checks passed.")
