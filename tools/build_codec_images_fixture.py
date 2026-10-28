"""Original tagged codec images: pypdf packaging, OpenJPEG sample goldens, Pillow JPEG."""
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import struct

from pypdf import PdfWriter
from pypdf.generic import ArrayObject as A, BooleanObject as Bool, DictionaryObject as D, EncodedStreamObject, NameObject as N, NumberObject as I, TextStringObject as T, DecodedStreamObject

ROOT = Path(__file__).resolve().parents[1]
SOURCES = ROOT / 'tests/Fixtures/codec-images'


def pack_samples(planes, width, height, bits):
    output = bytearray()
    for y in range(height):
        row = bytearray((width * len(planes) * bits + 7) // 8)
        position = 0
        for x in range(width):
            for plane in planes:
                value = plane['samples'][y * width + x] * (((1 << bits) - 1) // ((1 << plane['precision']) - 1))
                for shift in range(bits - 1, -1, -1):
                    row[position // 8] |= ((value >> shift) & 1) << (7 - position % 8)
                    position += 1
        output.extend(row)
    return bytes(output)


def fixture():
    writer = PdfWriter()
    writer.pdf_header = '%PDF-2.0'
    writer.add_metadata({'/Producer': 'Independent pypdf codec image semantics fixture'})
    tree = D({N('/Type'): N('/StructTreeRoot')})
    tree_ref = writer._add_object(tree)
    document = D({N('/Type'): N('/StructElem'), N('/S'): N('/Document'), N('/P'): tree_ref})
    document_ref = writer._add_object(document)
    tree[N('/K')] = document_ref
    profile = DecodedStreamObject()
    profile.set_data((SOURCES / 'gray.icc').read_bytes())
    profile[N('/N')] = I(1)
    profile_ref = writer._add_object(profile)
    variants = [(f'jpx-gray-{b}', f'precision-{b}-signed-0.jp2', {}, None) for b in [1, 2, 4, 8, 16]]
    variants += [
        ('jpx-rgb-8', 'rgb-8.jp2', {}, None), ('jpx-rgb-16', 'rgb-16.jp2', {}, None),
        ('jpx-icc', 'icc-gray.jp2', {}, None),
        ('jpx-bpc-decode-ignored', 'precision-4-signed-0.jp2', {'BitsPerComponent': I(8), 'Decode': A([I(1), I(0)])}, None),
        ('jpx-pdf-device-priority', 'icc-gray.jp2', {'ColorSpace': N('/DeviceGray')}, None),
        ('jpx-pdf-icc-priority', 'precision-8-signed-0.jp2', {'ColorSpace': A([N('/ICCBased'), profile_ref])}, None),
        ('jpx-alpha-ignored', 'alpha-1.jp2', {}, None),
        ('jpx-alpha-straight', 'alpha-1.jp2', {'SMaskInData': I(1)}, None),
        ('jpx-alpha-associated-exact', 'associated-exact.jp2', {'SMaskInData': I(2)}, None),
        ('jpx-alpha-associated-rounding', 'associated-rounded.jp2', {'SMaskInData': I(2)}, 'rounding'),
        ('jpx-signed', 'precision-8-signed-1.jp2', {}, 'signedness'),
        ('jpx-depth-12', 'precision-12-signed-0.jp2', {}, 'precision'),
        ('jpx-grid', 'subsampled-odd-origin.j2k', {'ColorSpace': N('/DeviceRGB')}, 'grids'),
        ('jpeg-native', 'rgb.jpg', {}, None),
        ('jpeg-pdf-transform-zero', 'rgb.jpg', {'DecodeParms': D({N('/ColorTransform'): I(0)})}, None),
        ('jpeg-adobe-priority', 'rgb.jpg', {'DecodeParms': D({N('/ColorTransform'): I(1)})}, None),
        ('jpeg-identifiers-priority', 'rgb.jpg', {}, None),
        ('jpeg-adobe-zero', 'rgb.jpg', {'DecodeParms': D({N('/ColorTransform'): I(1)})}, None),
    ]
    elements, parents, cases = [], [], []
    for index, (name, filename, options, fallback) in enumerate(variants):
        encoded = (SOURCES / filename).read_bytes()
        jpeg = filename.endswith('.jpg')
        alpha = options.get('SMaskInData') in [1, 2]
        icc = None
        if jpeg:
            width, height, bits, colors = 13, 7, 8, 3
            samples = None
            if name in ['jpeg-identifiers-priority', 'jpeg-adobe-zero']:
                assert encoded[2:4] == b'\xff\xe0'
                encoded = encoded[:2] + encoded[4 + int.from_bytes(encoded[4:6], 'big'):]
            if name == 'jpeg-identifiers-priority':
                encoded = bytearray(encoded)
                at = 2
                while at < len(encoded):
                    marker = encoded[at + 1]; length = int.from_bytes(encoded[at + 2:at + 4], 'big'); body = at + 4
                    if marker in [0xc0, 0xc1, 0xc2]:
                        for component, identifier in enumerate(b'RGB'): encoded[body + 6 + 3 * component] = identifier
                    if marker == 0xda:
                        for component, identifier in enumerate(b'RGB'): encoded[body + 1 + 2 * component] = identifier
                        break
                    at += length + 2
                encoded = bytes(encoded)
            if name in ['jpeg-adobe-priority', 'jpeg-adobe-zero']:
                adobe = b'Adobe' + struct.pack('>HHHB', 100, 0, 0, 0)
                encoded = encoded[:2] + b'\xff\xee' + struct.pack('>H', len(adobe) + 2) + adobe + encoded[2:]
        else:
            golden = json.loads((SOURCES / (filename + '.json')).read_text())
            width, height = golden['width'], golden['height']
            planes = golden['components']
            if filename.startswith('alpha-') and not alpha:
                planes = planes[:3]
            if name == 'jpx-alpha-associated-exact':
                alpha_values = planes[-1]['samples']
                planes = [dict(plane, samples=[value * 255 // opacity if opacity else 0 for value, opacity in zip(plane['samples'], alpha_values)]) for plane in planes[:-1]] + [planes[-1]]
            colors = len(planes) - int(alpha)
            bits = max(p['precision'] for p in planes)
            if colors == 3 or alpha:
                bits = max(bits, 8)
            samples = None if fallback else base64.b64encode(pack_samples(planes, width, height, bits)).decode()
            if name in ['jpx-icc', 'jpx-pdf-icc-priority']:
                icc = hashlib.sha256((SOURCES / 'gray.icc').read_bytes()).hexdigest()
        image = EncodedStreamObject()
        image.update({N('/Type'): N('/XObject'), N('/Subtype'): N('/Image'), N('/Width'): I(width), N('/Height'): I(height), N('/Filter'): N('/DCTDecode' if jpeg else '/JPXDecode')})
        if jpeg:
            image.update({N('/ColorSpace'): N('/DeviceRGB'), N('/BitsPerComponent'): I(8)})
        image.update({N('/' + key): value for key, value in options.items()})
        image._data = encoded
        page = writer.add_blank_page(width=width, height=height)
        page[N('/StructParents')] = I(index)
        page[N('/Resources')] = D({N('/XObject'): D({N('/I'): writer._add_object(image)})})
        stream = DecodedStreamObject()
        stream.set_data(f'/Figure <</MCID 0>> BDC q {width} 0 0 {height} 0 0 cm /I Do Q EMC'.encode())
        page[N('/Contents')] = writer._add_object(stream)
        element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/Figure'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(0), N('/Alt'): T(name)}))
        elements.append(element);parents.extend([I(index), A([element])])
        cases.append({'name': name, 'page': index + 1, 'width': width, 'height': height, 'components': colors, 'bits': bits, 'alpha': alpha, 'samples': samples, 'fallback': fallback, 'iccSha256': icc, 'source': filename, 'sourceSha256': hashlib.sha256(encoded).hexdigest(), 'mediaType': 'image/jpeg' if name in ['jpeg-native', 'jpeg-adobe-zero'] else 'image/png'})
    document[N('/K')] = A(elements)
    tree[N('/ParentTree')] = writer._add_object(D({N('/Nums'): A(parents)}))
    tree[N('/ParentTreeNextKey')] = I(len(cases))
    writer._root_object.update({N('/StructTreeRoot'): tree_ref, N('/MarkInfo'): D({N('/Marked'): Bool(True)}), N('/Lang'): T('en')})
    output = io.BytesIO();writer.write(output)
    data = output.getvalue()
    manifest = {'schema': 1, 'license': 'MIT', 'sha256': hashlib.sha256(data).hexdigest(), 'generator': 'tools/build_codec_images_fixture.py', 'cases': cases}
    return data, (json.dumps(manifest, indent=2) + '\n').encode()


def main():
    parser = argparse.ArgumentParser(description=__doc__);parser.add_argument('--check', action='store_true');args = parser.parse_args()
    for name, data in zip(['codec-images.pdf', 'codec-images.json'], fixture()):
        path = ROOT / 'tests/Fixtures' / name
        if args.check:
            assert path.read_bytes() == data, name
        else:
            path.write_bytes(data)
    print('Verified independent codec image fixture')


if __name__ == '__main__':
    main()
