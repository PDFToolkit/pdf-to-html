"""Independent MAC-protected tagged-PDF derivation with pyHanko and HTML5.

No certificate trust anchors, network retrieval or oracle overrides are used.
PDF MAC verification authenticates the source key, not its signer identity.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile

import html5lib
from asn1crypto import keys, x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.pdfmac import validate_pdf_mac
from pyhanko.pdf_utils.crypt.permissions import StandardPermissions
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import copy_into_new_writer
from pyhanko.sign import signers
from pyhanko_certvalidator.registry import SimpleCertificateStore

ROOT = Path(__file__).resolve().parents[1]
USER_MASK = -7488


def identity():
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(x509.NameOID.COMMON_NAME, 'Untrusted PDFToolkit HTML fixture')])
    start = datetime(2026, 1, 1, tzinfo=timezone.utc)
    certificate = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
        .public_key(private.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(start).not_valid_after(start + timedelta(days=3650)).sign(private, hashes.SHA256()))
    return signers.SimpleSigner(
        signing_cert=asn1_x509.Certificate.load(certificate.public_bytes(serialization.Encoding.DER)),
        signing_key=keys.PrivateKeyInfo.load(private.private_bytes(serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())),
        cert_registry=SimpleCertificateStore(),
    )


def generate(directory):
    directory.mkdir(parents=True, exist_ok=True)
    plain = (ROOT / 'tests/Fixtures/tagged.pdf').read_bytes()
    records = []
    signer = identity()
    for revision, attached in ((6, False), (6, True), (7, False)):
        writer = copy_into_new_writer(PdfFileReader(io.BytesIO(plain), strict=True), writer_kwargs={'stream_xrefs': True})
        writer.encrypt('native-owner', 'native-user', perms=StandardPermissions.from_sint32(USER_MASK), pdf_mac=True, use_gcm=revision == 7)
        output = io.BytesIO()
        if attached:
            signers.PdfSigner(signers.PdfSignatureMetadata(field_name='integrity', md_algorithm='sha256'), signer=signer).sign_pdf(writer, output=output, bytes_reserved=16384)
        else:
            writer.write(output)
        data = output.getvalue()
        filename = f'tagged-mac-r{revision}-' + ('attached' if attached else 'standalone') + '.pdf'
        (directory / filename).write_bytes(data)
        records.append({'file': filename, 'revision': revision, 'location': 'AttachedToSig' if attached else 'Standalone', 'sha256': hashlib.sha256(data).hexdigest(), 'permissions': USER_MASK})
    manifest = {'generator': 'pyHanko 0.37.0 / cryptography 50.0.2; no PHP writer', 'plainSourceSha256': hashlib.sha256(plain).hexdigest(), 'notice': 'Public test passwords native-user/native-owner; ephemeral signing key discarded; no certificate trust.', 'fixtures': records}
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8', newline='\n')
    return records


def php_report(args, path, password, mode):
    command = [args.php]
    if args.bootstrap:
        command += ['-d', f'auto_prepend_file={args.bootstrap}']
    command += [str(ROOT / 'tests/export-source-integrity.php'), str(path), password, mode]
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, encoding='utf-8')
    assert result.returncode == 0, result.stdout + result.stderr
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--bootstrap', help='Development-only source override, never a production dependency.')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory() as temporary:
        directory = args.work_dir.resolve() if args.work_dir else Path(temporary)
        records = generate(directory)
        for row in records:
            path = directory / row['file']
            data = path.read_bytes()
            for password, access, mask in (('native-user', AuthStatus.USER, USER_MASK), ('native-owner', AuthStatus.OWNER, -4)):
                reader = PdfFileReader(io.BytesIO(data), strict=True)
                authentication = reader.decrypt(password)
                assert authentication.status == access and authentication.mac_status == PdfMacStatus.SUCCESSFUL
                validate_pdf_mac(reader)
                assert reader.trailer_view['/Encrypt']['/P'] == USER_MASK
                if row['revision'] == 6:
                    result = subprocess.run([args.qpdf, '--password=' + password, '--check', str(path)], capture_output=True)
                    assert result.returncode == 0, result.stderr.decode(errors='replace')
                for mode in ('native', 'provider'):
                    report = php_report(args, path, password, mode)
                    assert report['ok'], report
                    document = html5lib.parse(report['html'], namespaceHTMLElements=False)
                    body = document.find('body')
                    assert body is not None and body.attrib['data-pdf-perms'] == str(mask)
                    assert 'External tagged PDF' in ''.join(body.itertext())
            for mode in ('native', 'provider'):
                assert not php_report(args, path, 'incorrect', mode)['ok']
            changed = bytearray(data)
            changed[10] ^= 1
            bad = directory / ('tampered-' + row['file'])
            bad.write_bytes(changed)
            for mode in ('native', 'provider'):
                assert not php_report(args, bad, 'native-user', mode)['ok']
        print(json.dumps({'independent_tagged_sources': len(records), 'native_and_provider_paths': True, 'user_effective_mask': USER_MASK, 'owner_effective_mask': -4, 'html5_and_pyhanko': 'passed', 'tampering_and_wrong_password_refused': True}, indent=2))


if __name__ == '__main__':
    main()
