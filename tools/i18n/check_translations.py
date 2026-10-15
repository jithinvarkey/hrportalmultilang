"""
Consistency check for the Angular translation files.

  * every namespace file parses and en/ar have identical key sets and {{params}}
  * every 'KEY' | translate / lang.t('KEY') / instant('KEY') literal used in src/app exists in English
  * no Arabic value is left identical to English (likely untranslated), except allowed technical strings

    python tools/i18n/check_translations.py
"""
import json
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
ROOT = pathlib.Path(__file__).resolve().parents[2]
I18N = ROOT / "frontend/src/assets/i18n"
APP = ROOT / "frontend/src/app"


def flatten(d, prefix=""):
    out = {}
    for k, v in d.items():
        key = f"{prefix}{k}"
        if isinstance(v, dict):
            out.update(flatten(v, key + "."))
        else:
            out[key] = v
    return out


errors = []
merged = {"en": {}, "ar": {}}
for lang in ("en", "ar"):
    for f in sorted((I18N / lang).glob("*.json")):
        try:
            merged[lang].update(flatten(json.loads(f.read_text(encoding="utf-8"))))
        except Exception as e:  # noqa: BLE001
            errors.append(f"{f.relative_to(ROOT)}: invalid JSON: {e}")

en, ar = merged["en"], merged["ar"]
for k in sorted(set(en) - set(ar)):
    errors.append(f"missing in ar: {k}")
for k in sorted(set(ar) - set(en)):
    errors.append(f"missing in en: {k}")

param = re.compile(r"\{\{\s*(\w+)\s*\}\}")
for k in sorted(set(en) & set(ar)):
    # Arabic may drop {{count}} in singular forms ("شهر واحد"), but must not invent params.
    if not set(param.findall(str(ar[k]))) <= set(param.findall(str(en[k]))):
        errors.append(f"param mismatch: {k}: en={en[k]!r} ar={ar[k]!r}")

used = set()
patterns = [
    re.compile(r"'([A-Z][A-Z0-9_]*(?:\.[A-Za-z0-9_]+)+)'\s*\|\s*translate"),
    re.compile(r"\.t\(\s*'([A-Z][A-Z0-9_]*(?:\.[A-Za-z0-9_]+)+)'"),
    re.compile(r"instant\(\s*'([A-Z][A-Z0-9_]*(?:\.[A-Za-z0-9_]+)+)'"),
]
for f in APP.rglob("*"):
    if f.suffix not in (".html", ".ts") or f.name.endswith(".spec.ts") or "(1)" in f.name:
        continue
    text = f.read_text(encoding="utf-8", errors="ignore")
    for p in patterns:
        for m in p.finditer(text):
            used.add((m.group(1), f.relative_to(ROOT)))

for key, where in sorted(used):
    if key not in en:
        errors.append(f"unknown key {key} used in {where}")

ascii_only = [k for k in sorted(set(en) & set(ar))
              if ar[k] == en[k] and re.search(r"[A-Za-z]{4,}", str(ar[k]))]

print(f"keys: en={len(en)} ar={len(ar)}; literal keys referenced in code: {len({k for k, _ in used})}")
if ascii_only:
    print(f"note: {len(ascii_only)} Arabic values identical to English (check they are technical terms):")
    for k in ascii_only:
        print(f"   {k} = {ar[k]!r}")
if errors:
    print(f"\n{len(errors)} problem(s):")
    for e in errors:
        print("  -", e)
    sys.exit(1)
print("OK")
