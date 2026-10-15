"""Independent pyHanko/HTML5 checks of Reader 0.5 public-key tagged derivation."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import logging
from datetime import datetime, timedelta, timezone
from pathlib import Path
import subprocess
import tempfile

import html5lib
from asn1crypto import keys, x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.permissions import PubKeyPermissions
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import copy_into_new_writer
from pyhanko.sign import signers
from pyhanko_certvalidator.registry import SimpleCertificateStore

ROOT = Path(__file__).resolve().parents[1]
logging.getLogger('pyhanko.pdf_utils.reader').setLevel(logging.CRITICAL)


def identity(directory, name):
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(x509.NameOID.COMMON_NAME, 'PDFToolkit untrusted HTML ' + name)])
    start = datetime(2026, 1, 1, tzinfo=timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
        .public_key(private.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(start).not_valid_after(start + timedelta(days=3650))
        .add_extension(x509.KeyUsage(True, False, True, False, False, False, False, None, None), critical=True)
        .sign(private, hashes.SHA256()))
    der = cert.public_bytes(serialization.Encoding.DER)
    (directory / (name + '.der')).write_bytes(der)
    (directory / (name + '-key.pem')).write_bytes(private.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    return asn1_x509.Certificate.load(der), keys.PrivateKeyInfo.load(private.private_bytes(
        serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))


def invoke(args, directory, filename, *, wrong=False, required=False):
    command = [args.php]
    if args.bootstrap:
        command += ['-d', 'auto_prepend_file=' + str(args.bootstrap.resolve())]
    command += [str(ROOT / 'tests/export-public-key.php'), str(directory), filename,
        'wrong' if wrong else 'recipient', 'require-mac' if required else 'compatible']
    return subprocess.run(command, cwd=ROOT, capture_output=True, text=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--bootstrap', type=Path, help='Development-only autoload override.')
    args = parser.parse_args()
    source = (ROOT / 'tests/Fixtures/tagged.pdf').read_bytes()
    manifest = json.loads((ROOT / 'tests/Fixtures/authentication-manifest.json').read_text())
    assert hashlib.sha256(source).hexdigest() == manifest['plainSourceSha256']
    original = PdfFileReader(io.BytesIO(source))
    original_content = original.root['/Pages']['/Kids'][0]['/Contents'].data
    with tempfile.TemporaryDirectory(prefix='html-public-key-') as temporary:
        directory = Path(temporary)
        cert, key = identity(directory, 'recipient')
        identity(directory, 'wrong')
        credential = SimpleEnvelopeKeyDecrypter.load(str(directory / 'recipient-key.pem'), str(directory / 'recipient.der'))
        wrong = SimpleEnvelopeKeyDecrypter.load(str(directory / 'wrong-key.pem'), str(directory / 'wrong.der'))
        signer = signers.SimpleSigner(signing_cert=cert, signing_key=key, cert_registry=SimpleCertificateStore())
        reports = []
        for owner in (False, True):
            for location in (None, 'Standalone', 'AttachedToSig'):
                writer = copy_into_new_writer(PdfFileReader(io.BytesIO(source)))
                perms = PubKeyPermissions.ALLOW_PRINTING | PubKeyPermissions.ALLOW_ASSISTIVE_TECHNOLOGY | PubKeyPermissions.TOLERATE_MISSING_PDF_MAC
                if owner:
                    perms |= PubKeyPermissions.ALLOW_ENCRYPTION_CHANGE
                writer.encrypt_pubkey([cert], perms=perms, pdf_mac=location is not None)
                output = io.BytesIO()
                if location == 'AttachedToSig':
                    signers.PdfSigner(signers.PdfSignatureMetadata(field_name='approval', md_algorithm='sha256'),
                        signer=signer).sign_pdf(writer, output=output, bytes_reserved=16384)
                else:
                    writer.write(output)
                data = output.getvalue()
                name = f'owner{int(owner)}-{location or "unprotected"}.pdf'
                (directory / name).write_bytes(data)
                independently_read = PdfFileReader(io.BytesIO(data))
                assert independently_read.decrypt_pubkey(wrong).status == AuthStatus.FAILED
                result = independently_read.decrypt_pubkey(credential)
                assert result.status == AuthStatus.USER
                assert bool(result.permission_flags & PubKeyPermissions.ALLOW_ENCRYPTION_CHANGE) == owner
                assert independently_read.root['/Pages']['/Kids'][0]['/Contents'].data == original_content
                assert '/StructTreeRoot' in independently_read.root
                if location:
                    assert result.mac_status == PdfMacStatus.SUCCESSFUL
                expected = -4 if owner else (-7484 if location else -3388)
                for required in ([False, True] if location else [False]):
                    process = invoke(args, directory, name, required=required)
                    assert process.returncode == 0, process.stderr
                    actual = json.loads(process.stdout)
                    body = html5lib.parse(actual['html'], namespaceHTMLElements=False).find('body')
                    assert body is not None and body.attrib['data-pdf-perms'] == str(expected)
                    assert 'External tagged PDF' in ''.join(body.itertext())
                    assert actual['mask'] == expected and actual['mac'] == location
                    assert any(item['code'] == 'viewer-permissions' for item in actual['diagnostics'])
                    if not required:
                        plain = html5lib.parse(actual['plainReuseHtml'], namespaceHTMLElements=False).find('body')
                        assert plain is not None and 'data-pdf-perms' not in plain.attrib
                        assert 'External tagged PDF' in ''.join(plain.itertext())
                failed = invoke(args, directory, name, wrong=True)
                assert failed.returncode != 0 and 'PublicKeyAuthenticationFailed' in failed.stderr + failed.stdout, (failed.returncode, failed.stdout, failed.stderr)
                if location is None:
                    failed = invoke(args, directory, name, required=True)
                    assert failed.returncode != 0 and 'MAC' in failed.stderr + failed.stdout, (failed.returncode, failed.stdout, failed.stderr)
                reports.append({'source': name, 'mask': expected, 'mac': location, 'html5': True, 'wrong_identity_refused': True})
        print(json.dumps({'pyhanko_sources': reports, 'required_integrity_and_converter_reuse': 'passed'}, indent=2))


if __name__ == '__main__':
    main()
