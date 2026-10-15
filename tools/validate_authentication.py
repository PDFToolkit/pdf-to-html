"""Independent qpdf/pypdf/HTML5 verification of native encrypted tagged-PDF derivation."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

import html5lib
from pypdf import PdfReader


def command(*arguments: str, password=None, success=True):
    environment = dict(os.environ)
    if password is not None:
        environment['PDFTOOLKIT_TEST_PASSWORD'] = password
    result = subprocess.run(arguments, capture_output=True, text=True, encoding='utf-8', env=environment)
    if success and result.returncode != 0:
        raise RuntimeError('The independent authentication check failed: ' + result.stderr)
    return result


def inspect(root: Path, path: Path, php: str, qpdf: str, modern: bool, revision: int):
    source = PdfReader(path, strict=True)
    assert source.is_encrypted
    assert source.decrypt('incorrect-test-password') == 0
    assert source.decrypt('native-user') != 0
    assert int(source.trailer['/Encrypt']['/P']) == -3392
    assert int(source.trailer['/Encrypt']['/R']) == revision
    assert 'External tagged PDF' in source.pages[0].extract_text()
    result = {}
    for access, mask in [('user', -3392), ('owner', -4)]:
        password = 'native-' + access
        details = command(qpdf, '--password=' + password, '--show-encryption', str(path)).stdout
        assert re.search(r'^P = -3392$', details, re.M)
        assert 'Supplied password is ' + access + ' password' in details
        command(qpdf, '--password=' + password, '--check', str(path))
        data = json.loads(command(php, str(root / 'tests/export-authenticated.php'), str(path), 'modern' if modern else 'historical', password=password).stdout)
        html = html5lib.parse(data['html'], namespaceHTMLElements=False)
        body = html.find('body')
        assert body is not None and body.attrib['data-pdf-perms'] == str(mask)
        assert 'External tagged PDF' in ''.join(body.itertext())
        assert any(item['code'] == 'viewer-permissions' for item in data['diagnostics'])
        result[access] = mask
    wrong = command(php, str(root / 'tests/export-authenticated.php'), str(path), 'modern' if modern else 'historical', password='incorrect-test-password', success=False)
    assert wrong.returncode != 0 and 'AuthenticationFailed' in wrong.stderr + wrong.stdout
    return {'revision': revision, 'effective_masks': result, 'wrong_password_refused': True, 'qpdf': True, 'pypdf': True, 'html5': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    fixtures = root / 'tests/Fixtures'
    manifest = json.loads((fixtures / 'authentication-manifest.json').read_text(encoding='utf-8'))
    assert hashlib.sha256((fixtures / 'tagged.pdf').read_bytes()).hexdigest() == manifest['plainSourceSha256']
    reports = {}
    for row in manifest['fixtures']:
        path = fixtures / row['file']
        assert hashlib.sha256(path.read_bytes()).hexdigest() == row['sha256']
        reports[row['file']] = inspect(root, path, args.php, args.qpdf, row['revision'] >= 5, row['revision'])
    with tempfile.TemporaryDirectory(prefix='pdf-html-native-auth-') as temporary:
        path = Path(temporary) / 'fresh-object-streams-aes256.pdf'
        command(args.qpdf, '--object-streams=generate', '--encrypt', 'native-user', 'native-owner', '256', '--print=none', '--modify=none', '--extract=n', '--', str(fixtures / 'tagged.pdf'), str(path))
        reports['fresh-qpdf-object-streams'] = inspect(root, path, args.php, args.qpdf, True, 6)
    print(json.dumps(reports, indent=2))


if __name__ == '__main__':
    main()
