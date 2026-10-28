#!/usr/bin/env python3
"""Independent raw-PDF/OpenSSL fixture builder and pypdf/CMS signature oracle."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile

from asn1crypto import cms
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from pypdf import PdfReader
from pypdf.generic import IndirectObject

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / 'tests/Fixtures/signatures'


def execute(argv, *, check=True):
    result = subprocess.run(list(map(str, argv)), capture_output=True)
    if check and result.returncode:
        raise AssertionError(result.stderr.decode('utf-8', errors='replace'))
    return result


def create_identity(directory, name, retain_key=False):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(x509.NameOID.COMMON_NAME, 'PDFToolkit public untrusted signature fixture ' + name)])
    # Intentionally expired: mathematical integrity must never imply PKI trust.
    certificate = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
        .public_key(key.public_key()).serial_number(1001 if name == 'signer' else 1002)
        .not_valid_before(datetime(2020, 1, 1, tzinfo=timezone.utc))
        .not_valid_after(datetime(2021, 1, 1, tzinfo=timezone.utc))
        .add_extension(x509.KeyUsage(True, False, False, False, False, False, False, None, None), critical=True)
        .sign(key, hashes.SHA256()))
    (directory / (name + '.der')).write_bytes(certificate.public_bytes(serialization.Encoding.DER))
    (directory / (name + '.pem')).write_bytes(certificate.public_bytes(serialization.Encoding.PEM))
    if retain_key:
        (directory / 'PUBLIC-TEST-ONLY-private-key.pem').write_bytes(key.private_bytes(
            serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))


def sign(directory, data):
    with tempfile.TemporaryDirectory() as temporary:
        work = Path(temporary)
        (work / 'covered').write_bytes(data)
        execute(['openssl', 'cms', '-sign', '-binary', '-noattr', '-md', 'sha256',
                 '-in', work / 'covered', '-signer', directory / 'signer.pem',
                 '-inkey', directory / 'PUBLIC-TEST-ONLY-private-key.pem',
                 '-outform', 'DER', '-out', work / 'cms.der'])
        return (work / 'cms.der').read_bytes()


def stream(data):
    return b'<< /Length ' + str(len(data)).encode() + b' >>\nstream\n' + data + b'\nendstream'


def pdf_bytes(reserved, subfilter='adbe.pkcs7.detached'):
    objects = {
        1: b'<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [7 0 R] /SigFlags 3 >> /StructTreeRoot 8 0 R /MarkInfo << /Marked true >> /Lang (en) >>',
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 10 0 R >> >> /Contents 4 0 R /Annots [7 0 R] /StructParents 0 >>',
        4: stream(b'/P <</MCID 0>> BDC BT /F1 12 Tf 20 150 Td (Signed sample.) Tj ET EMC'),
        6: b'<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /' + subfilter.encode() + b' /ByteRange [0000000000 0000000000 0000000000 0000000000] /Contents <' + b'0' * (reserved * 2) + b'> /M (D:20261028000000Z) >>',
        7: b'<< /Type /Annot /Subtype /Widget /FT /Sig /T (signature) /V 6 0 R /P 3 0 R /Rect [20 20 160 50] /F 4 /StructParent 1 >>',
        8: b'<< /Type /StructTreeRoot /K 9 0 R /ParentTree 12 0 R /ParentTreeNextKey 2 >>',
        9: b'<< /Type /StructElem /S /Document /P 8 0 R /K [11 0 R 13 0 R] >>',
        10: b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        11: b'<< /Type /StructElem /S /P /P 9 0 R /Pg 3 0 R /K 0 >>',
        12: b'<< /Nums [0 [11 0 R] 1 13 0 R] >>',
        13: b'<< /Type /StructElem /S /Form /P 9 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 7 0 R /Pg 3 0 R >> >>',
    }
    data = b'%PDF-2.0\n'
    offsets = {}
    for number, value in objects.items():
        offsets[number] = len(data)
        data += str(number).encode() + b' 0 obj\n' + value + b'\nendobj\n'
    xref = len(data)
    data += b'xref\n0 14\n0000000000 65535 f \n'
    for number in range(1, 14):
        data += f'{offsets[number]:010d} 00000 n \n'.encode() if number in offsets else b'0000000000 00000 f \n'
    return data + b'trailer << /Size 14 /Root 1 0 R >>\nstartxref\n' + str(xref).encode() + b'\n%%EOF\n'


def signed_pdf(directory, reserved=4096, *, extra_gap=False, subfilter='adbe.pkcs7.detached', prefix_only=False):
    data = pdf_bytes(reserved, subfilter)
    before = data.index(b'/Contents <') + len(b'/Contents ')
    after = before + reserved * 2 + 2 + int(extra_gap)
    after_length = 0 if prefix_only else len(data) - after
    ranges = f'[{0:010d} {before:010d} {after:010d} {after_length:010d}]'.encode()
    data = data.replace(b'[0000000000 0000000000 0000000000 0000000000]', ranges, 1)
    signature = sign(directory, data[:before] + data[after:after + after_length])
    assert len(signature) <= reserved
    encoded = (signature + b'\0' * (reserved - len(signature))).hex().upper().encode()
    return data[:before + 1] + encoded + data[before + 1 + reserved * 2:]


def append_unsigned_revision(data):
    previous = int(re.findall(rb'startxref\s+(\d+)', data)[-1])
    position = len(data)
    data += b'4 0 obj\n' + stream(b'/P <</MCID 0>> BDC BT /F1 12 Tf 20 150 Td (Unsigned update.) Tj ET EMC') + b'\nendobj\n'
    xref = len(data)
    return data + f'xref\n0 1\n0000000000 65535 f \n4 1\n{position:010d} 00000 n \ntrailer << /Size 14 /Root 1 0 R /Prev {previous} >>\nstartxref\n{xref}\n%%EOF\n'.encode()


def append_contents_alias(data):
    previous = int(re.findall(rb'startxref\s+(\d+)', data)[-1])
    signature = re.search(rb'6 0 obj\n(.*?)\nendobj', data, re.S).group(1)
    signature_offset = len(data)
    data += b'6 0 obj\n' + signature + b'\nendobj\n'
    xref = len(data)
    return data + f'xref\n0 1\n0000000000 65535 f \n6 1\n{signature_offset:010d} 00000 n \ntrailer << /Size 14 /Root 1 0 R /Prev {previous} >>\nstartxref\n{xref}\n%%EOF\n'.encode()


def generate(directory):
    directory.mkdir(parents=True, exist_ok=True)
    if not (directory / 'PUBLIC-TEST-ONLY-private-key.pem').exists():
        create_identity(directory, 'signer', retain_key=True)
        create_identity(directory, 'wrong')
    size = len(sign(directory, b'size probe'))
    padded = signed_pdf(directory)
    pdfs = {
        'signed-padded.pdf': padded,
        'signed-unpadded.pdf': signed_pdf(directory, size),
        'signed-wide-gap.pdf': signed_pdf(directory, extra_gap=True),
        'signed-unsupported.pdf': signed_pdf(directory, subfilter='Unsupported.fixture'),
        'signed-tampered.pdf': padded.replace(b'Signed sample.', b'Forged sample.', 1),
        'signed-later-revision.pdf': append_unsigned_revision(padded),
        'signed-prefix-only.pdf': signed_pdf(directory, prefix_only=True),
        'signed-contents-alias.pdf': append_contents_alias(padded),
    }
    end = padded.index(b'> /M ')
    pdfs['signed-nonzero-padding.pdf'] = padded[:end - 2] + b'01' + padded[end:]
    for filename, data in pdfs.items():
        (directory / filename).write_bytes(data)
    records = [
        ('padded', 'signed-padded.pdf', 'signer', 'integrity-verified', True, True, True),
        ('unpadded', 'signed-unpadded.pdf', 'signer', 'integrity-verified', True, True, True),
        ('tampered', 'signed-tampered.pdf', 'signer', 'invalid', False, True, True),
        ('wrong-certificate', 'signed-padded.pdf', 'wrong', 'invalid', False, True, True),
        ('wide-gap', 'signed-wide-gap.pdf', 'signer', 'invalid', True, False, True),
        ('nonzero-padding', 'signed-nonzero-padding.pdf', 'signer', 'invalid', True, True, False),
        ('unsupported', 'signed-unsupported.pdf', 'signer', 'indeterminate', True, True, True),
        ('later-revision', 'signed-later-revision.pdf', 'signer', 'integrity-verified', True, True, True),
        ('prefix-only', 'signed-prefix-only.pdf', 'signer', 'invalid', True, True, True),
        ('contents-alias', 'signed-contents-alias.pdf', 'signer', 'invalid', True, True, True),
    ]
    manifest = {
        'generator': 'Independent raw PDF syntax; OpenSSL CMS detached RSA-2048/SHA-256 without signed attributes; no PDFToolkit writer.',
        'warning': 'Public test key and expired self-signed certificates. Never use for real signing. Integrity is distinct from certificate trust and later-revision validity.',
        'cases': [dict(zip(('name', 'file', 'certificate', 'status', 'cmsValid', 'exactGap', 'zeroPadding'), row), physicalContentsToken=row[5] and row[0] != 'contents-alias', completeRevision=row[0] != 'prefix-only') for row in records],
        'sha256': {name: hashlib.sha256((directory / name).read_bytes()).hexdigest() for name in sorted([*pdfs, 'signer.der', 'signer.pem', 'wrong.der', 'wrong.pem', 'PUBLIC-TEST-ONLY-private-key.pem'])},
    }
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')


def verify(directory, row):
    data = (directory / row['file']).read_bytes()
    reader = PdfReader(io.BytesIO(data), strict=True)
    field = reader.trailer['/Root']['/AcroForm']['/Fields'][0].get_object()
    assert field['/T'] == 'signature'
    signature = field['/V']
    start, before, after, length = map(int, signature['/ByteRange'])
    assert start == 0 and 0 < before < after <= after + length <= len(data)
    contents = bytes(signature['/Contents'])
    der = cms.ContentInfo.load(contents).dump()
    exact_gap = data[before:after] == b'<' + contents.hex().upper().encode() + b'>'
    raw_contents = signature.raw_get('/Contents')
    if isinstance(raw_contents, IndirectObject):
        offset = reader.xref[raw_contents.generation][raw_contents.idnum]
        actual_start = offset + re.match(rb'\d+\s+\d+\s+obj\s*', data[offset:]).end()
    else:
        ref = field.raw_get('/V')
        offset = reader.xref[ref.generation][ref.idnum]
        actual_start = offset + re.search(rb'/Contents\s*(<)', data[offset:]).start(1)
    physical_token = exact_gap and actual_start == before
    padding = contents[len(der):] == b'\0' * (len(contents) - len(der))
    signed_prefix = data[:after + length]
    # Inspect a separately parsed complete PDF, rather than accepting an arbitrary
    # authentic prefix or treating CMS success as proof of a PDF revision boundary.
    complete_revision = re.search(rb'(?:\r|\n)startxref\s+\d+\s+%%EOF\s*\Z', signed_prefix) is not None
    if complete_revision:
        complete_revision = PdfReader(io.BytesIO(signed_prefix), strict=True).trailer['/Root']['/Type'] == '/Catalog'
    with tempfile.TemporaryDirectory() as temporary:
        work = Path(temporary)
        (work / 'covered').write_bytes(data[:before] + data[after:after + length])
        (work / 'signature').write_bytes(der)
        result = execute(['openssl', 'cms', '-verify', '-binary', '-inform', 'DER', '-noverify', '-nointern',
            '-certfile', directory / (row['certificate'] + '.pem'), '-in', work / 'signature',
            '-content', work / 'covered', '-out', work / 'verified'], check=False)
    assert (result.returncode == 0) == row['cmsValid'], row['name']
    assert exact_gap == row['exactGap'], row['name']
    assert padding == row['zeroPadding'], row['name']
    assert physical_token == row['physicalContentsToken'], row['name']
    assert complete_revision == row['completeRevision'], row['name']
    if row['name'] == 'later-revision':
        assert after + length < len(data)
        assert 'Unsigned update.' in reader.pages[0].extract_text()
    return {'name': row['name'], 'cmsValid': result.returncode == 0, 'exactGap': exact_gap, 'physicalContentsToken': physical_token, 'zeroPadding': padding, 'completeRevision': complete_revision, 'signedRevisionLength': after + length, 'sourceLength': len(data)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--generate-fixtures', action='store_true')
    parser.add_argument('--php', help='Also compare the actual PHP provider with the independent verdicts.')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    if args.generate_fixtures:
        generate(FIXTURES)
    manifest = json.loads((FIXTURES / 'manifest.json').read_text())
    for filename, digest in manifest['sha256'].items():
        assert hashlib.sha256((FIXTURES / filename).read_bytes()).hexdigest() == digest, filename
    results = [verify(FIXTURES, row) for row in manifest['cases']]
    actual = None
    if args.php:
        actual = json.loads(execute([args.php, ROOT / 'tests/export-signature-validation.php']).stdout)
        assert {row['name']: row['status'] for row in actual} == {row['name']: row['status'] for row in manifest['cases']}
    report = {'passed': True, 'openssl': execute(['openssl', 'version']).stdout.decode().strip(), 'cases': results, 'phpProvider': actual}
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'passed': True, 'cases': len(results), 'phpCompared': actual is not None, 'report': str(args.report) if args.report else None}))


if __name__ == '__main__':
    main()
