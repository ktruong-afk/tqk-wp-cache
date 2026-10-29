# -*- coding: utf-8 -*-
"""Build the installable zip for WordPress.org: dist/tqk-wp-cache-<version>.zip

Ships only what runs on a WordPress site:
- tqk-wp-cache.php
- uninstall.php
- readme.txt
- src/
- assets/
- languages/

Run: python tools/build-release.py
"""
import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
INCLUDE = ['tqk-wp-cache.php', 'uninstall.php', 'readme.txt', 'src', 'assets', 'languages']
SKIP_NAMES = {'.DS_Store', 'Thumbs.db'}


def versions():
    main = (ROOT / 'tqk-wp-cache.php').read_text(encoding='utf-8')
    readme = (ROOT / 'readme.txt').read_text(encoding='utf-8')
    header = re.search(r'^\s*\*\s*Version:\s*([\w.\-]+)', main, re.M)
    const = re.search(r"define\('TQK_WP_CACHE_VERSION',\s*'([^']+)'\)", main)
    stable = re.search(r'^Stable tag:\s*([\w.\-]+)', readme, re.M)
    return {k: (m.group(1) if m else None) for k, m in [('header', header), ('constant', const), ('readme', stable)]}


def main():
    v = versions()
    if len(set(v.values())) != 1 or None in v.values():
        sys.exit('Version mismatch, fix before release: %s' % v)
    version = v['header']

    dist = ROOT / 'dist'
    dist.mkdir(exist_ok=True)
    out = dist / ('tqk-wp-cache-%s.zip' % version)
    count = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for item in INCLUDE:
            p = ROOT / item
            files = [p] if p.is_file() else sorted(f for f in p.rglob('*') if f.is_file())
            for f in files:
                if f.name in SKIP_NAMES:
                    continue
                z.write(f, 'tqk-wp-cache/' + f.relative_to(ROOT).as_posix())
                count += 1
    print('built %s (%d files, %.1f KB)' % (out.relative_to(ROOT), count, out.stat().st_size / 1024))


if __name__ == '__main__':
    main()
