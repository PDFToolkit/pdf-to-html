"""Reproducible MIT inline-image PDF fixture, independently generated with pypdf."""
from pathlib import Path
import argparse
import base64
import hashlib
import io
import json
import struct
import zlib

from pypdf import PdfWriter
from pypdf.generic import ArrayObject as A, BooleanObject, ByteStringObject as B, DictionaryObject as D, NameObject as N, NumberObject as I, TextStringObject as T, DecodedStreamObject
from build_reading_fixture import HELVETICA_ASCII_WIDTHS


def lzw_literals(data):
    bits = ''.join(f'{code:09b}' for code in [256, *data, 257])
    return int(bits.ljust((len(bits) + 7) // 8 * 8, '0'), 2).to_bytes((len(bits) + 7) // 8, 'big')


def fixture():
    root = Path(__file__).resolve().parents[1]
    jpeg = (root / 'tests/Fixtures/inline-gray.jpg').read_bytes()
    # APP payload contains both a PDF EI lookalike and a JPEG EOI lookalike.
    payload = b' EI \xff\xd9'
    jpeg = jpeg[:2] + b'\xff\xef' + struct.pack('>H', len(payload) + 2) + payload + jpeg[2:]
    gray = b' EI '
    rgb = b'\x10\x80\xf0'
    cases = [
        ('gray-raw', b'/W 4 /H 1 /BPC 8 /CS /G', gray),
        ('rgb-raw', b'/W 1 /H 1 /BPC 8 /CS /RGB', rgb),
        ('rgb-flate', b'/W 1 /H 1 /BPC 8 /CS /RGB /F /Fl', zlib.compress(rgb)),
        ('rgb-asciihex', b'/W 1 /H 1 /BPC 8 /CS /RGB /F /AHx', rgb.hex().encode() + b'>'),
        ('rgb-ascii85', b'/W 1 /H 1 /BPC 8 /CS /RGB /F /A85', base64.a85encode(rgb) + b'~>'),
        ('gray-runlength', b'/W 4 /H 1 /BPC 8 /CS /G /F /RL', b'\x03' + gray + b'\x80'),
        ('gray-jpeg', b'/W 80 /H 40 /BPC 8 /CS /G /F [/DCT]', jpeg),
        ('cmyk-placeholder', b'/W 1 /H 1 /BPC 8 /CS /CMYK', b'\x00\x00\x00\xff'),
        ('mask-placeholder', b'/W 1 /H 1 /IM true', b'\x80'),
        ('lzw-placeholder', b'/W 4 /H 1 /BPC 8 /CS /G /F /LZW', lzw_literals(gray)),
        ('indexed-placeholder', b'/W 1 /H 1 /BPC 8 /CS /Palette', b'\x01'),
        ('two-bit-placeholder', b'/W 4 /H 1 /BPC 2 /CS /G', b'\x1b'),
    ]
    writer = PdfWriter()
    writer.pdf_header = '%PDF-2.0'
    writer.add_metadata({'/Producer': 'Independent pypdf inline-image derivation fixture'})
    page = writer.add_blank_page(width=300, height=500)
    descriptor = writer._add_object(D({N('/Type'): N('/FontDescriptor'), N('/FontName'): N('/Helvetica'), N('/Flags'): I(32), N('/FontBBox'): A([I(-166), I(-225), I(1000), I(931)]), N('/ItalicAngle'): I(0), N('/Ascent'): I(718), N('/Descent'): I(-207), N('/CapHeight'): I(718), N('/StemV'): I(88)}))
    font = writer._add_object(D({N('/Type'): N('/Font'), N('/Subtype'): N('/Type1'), N('/BaseFont'): N('/Helvetica'), N('/Encoding'): N('/WinAnsiEncoding'), N('/FirstChar'): I(32), N('/LastChar'): I(126), N('/Widths'): A([I(width) for width in HELVETICA_ASCII_WIDTHS]), N('/FontDescriptor'): descriptor}))
    page[N('/Resources')] = D({N('/Font'): D({N('/F1'): font}), N('/ColorSpace'): D({N('/Palette'): A([N('/Indexed'), N('/DeviceRGB'), I(1), B(b'\x00\x00\x00\xff\xff\xff')])})})
    tree = D({N('/Type'): N('/StructTreeRoot')})
    tree_ref = writer._add_object(tree)
    document = D({N('/Type'): N('/StructElem'), N('/S'): N('/Document'), N('/P'): tree_ref})
    document_ref = writer._add_object(document)
    tree[N('/K')] = document_ref
    parents = []
    content = bytearray()
    for mcid, (name, dictionary, data) in enumerate(cases):
        element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/Figure'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(mcid), N('/Alt'): T(name)}))
        parents.append(element)
        content.extend(f'/Figure <</MCID {mcid}>> BDC q 20 0 0 10 20 {450 - mcid * 30} cm BI '.encode() + dictionary + b' ID ' + data + b' EI Q EMC\n')
    element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/P'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(len(cases))}))
    parents.append(element)
    content.extend(f'/P <</MCID {len(cases)}>> BDC BT /F1 12 Tf 20 50 Td (Text after all inline images) Tj ET EMC\n'.encode())
    document[N('/K')] = A(parents)
    page[N('/StructParents')] = I(0)
    tree[N('/ParentTree')] = writer._add_object(D({N('/Nums'): A([I(0), A(parents)])}))
    tree[N('/ParentTreeNextKey')] = I(1)
    stream = DecodedStreamObject()
    stream.set_data(bytes(content))
    page[N('/Contents')] = writer._add_object(stream)
    writer._root_object.update({N('/StructTreeRoot'): tree_ref, N('/MarkInfo'): D({N('/Marked'): BooleanObject(True)}), N('/Lang'): T('en')})
    output = io.BytesIO()
    writer.write(output)
    data = output.getvalue()
    manifest = {'license': 'MIT', 'sha256': hashlib.sha256(data).hexdigest(), 'images': [name for name, _, _ in cases], 'graySamplesHex': gray.hex(), 'rgbSamplesHex': rgb.hex(), 'jpegSha256': hashlib.sha256(jpeg).hexdigest(), 'jpegSource': 'PDFToolkit/pdf-images original MIT example gray.jpg', 'logicalCssPixels': [27, 13]}
    return data, (json.dumps(manifest, indent=2) + '\n').encode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1] / 'tests/Fixtures'
    for name, data in zip(('inline-images.pdf', 'inline-manifest.json'), fixture()):
        if args.check:
            assert (root / name).read_bytes() == data, f'{name} differs from its independent generator'
        else:
            (root / name).write_bytes(data)
    print('Verified independent inline-image fixture' if args.check else 'Built independent inline-image fixture')


if __name__ == '__main__':
    main()
