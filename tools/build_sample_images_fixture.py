"""Original tagged PDF image depths, encoded independently with pypdf and zlib."""
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import struct
import zlib

from pypdf import PdfWriter
from pypdf.generic import ArrayObject as A, BooleanObject as Bool, DictionaryObject as D, EncodedStreamObject, NameObject as N, NumberObject as I, TextStringObject as T, DecodedStreamObject

ROOT = Path(__file__).resolve().parents[1]


def samples(width, height, components, bits):
    output = bytearray()
    for y in range(height):
        values = [(x * 719 + y * 131 + c * 11003) & ((1 << bits) - 1) for x in range(width) for c in range(components)]
        if bits == 16:
            output.extend(b''.join(struct.pack('>H', value) for value in values))
        elif bits == 8:
            output.extend(values)
        else:
            row = bytearray((len(values) * bits + 7) // 8)
            for index, value in enumerate(values):
                row[index * bits // 8] |= value << (8 - bits - index * bits % 8)
            output.extend(row)
    return bytes(output)


def fixture():
    writer = PdfWriter()
    writer.pdf_header = '%PDF-2.0'
    writer.add_metadata({'/Producer': 'Original pypdf precision and transparency fixture'})
    tree = D({N('/Type'): N('/StructTreeRoot')})
    tree_ref = writer._add_object(tree)
    document = D({N('/Type'): N('/StructElem'), N('/S'): N('/Document'), N('/P'): tree_ref})
    document_ref = writer._add_object(document)
    tree[N('/K')] = document_ref
    elements, parents, cases = [], [], []
    variants = [(f'{mode}-{components}c-{bits}b', components, bits, mode, None) for mode in ['inline', 'xobject'] for components, depths in [(1, [1, 2, 4, 8, 16]), (3, [8, 16])] for bits in depths]
    variants += [('decode-fallback', 1, 16, 'xobject', 'Decode'), ('soft-mask-fallback', 1, 16, 'xobject', 'SMask'), ('colour-key-fallback', 3, 16, 'xobject', 'Mask')]
    for index, (name, components, bits, mode, fallback) in enumerate(variants):
        width, height = 13, 5
        raw = samples(width, height, components, bits)
        space = '/DeviceGray' if components == 1 else '/DeviceRGB'
        image = EncodedStreamObject()
        image.update({N('/Type'): N('/XObject'), N('/Subtype'): N('/Image'), N('/Width'): I(width), N('/Height'): I(height), N('/BitsPerComponent'): I(bits), N('/ColorSpace'): N(space), N('/Filter'): N('/FlateDecode')})
        image._data = zlib.compress(raw)
        if fallback == 'Decode':
            image[N('/Decode')] = A([I(1), I(0)])
        elif fallback == 'Mask':
            image[N('/Mask')] = A([I(0), I(1000), I(0), I(15000), I(0), I(25000)])
        elif fallback == 'SMask':
            mask = EncodedStreamObject()
            mask.update({N('/Type'): N('/XObject'), N('/Subtype'): N('/Image'), N('/Width'): I(width), N('/Height'): I(height), N('/BitsPerComponent'): I(8), N('/ColorSpace'): N('/DeviceGray'), N('/Filter'): N('/FlateDecode')})
            mask._data = zlib.compress(bytes([128]) * (width * height))
            image[N('/SMask')] = writer._add_object(mask)
        page = writer.add_blank_page(width=width, height=height)
        page[N('/StructParents')] = I(index)
        operations = f'/Figure <</MCID 0>> BDC q {width} 0 0 {height} 0 0 cm '.encode()
        if mode == 'inline':
            operations += f'BI /W {width} /H {height} /BPC {bits} /CS {space} /F /Fl ID '.encode() + image._data + b' EI'
            page[N('/Resources')] = D()
        else:
            page[N('/Resources')] = D({N('/XObject'): D({N('/I'): writer._add_object(image)})})
            operations += b'/I Do'
        stream = DecodedStreamObject()
        stream.set_data(operations + b' Q EMC')
        page[N('/Contents')] = writer._add_object(stream)
        element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/Figure'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(0), N('/Alt'): T(name)}))
        elements.append(element)
        parents.extend([I(index), A([element])])
        cases.append({'name': name, 'page': index + 1, 'width': width, 'height': height, 'components': components, 'bits': bits, 'samples': base64.b64encode(raw).decode(), 'fallback': fallback, 'logicalCssPixels': [17, 7]})
    document[N('/K')] = A(elements)
    tree[N('/ParentTree')] = writer._add_object(D({N('/Nums'): A(parents)}))
    tree[N('/ParentTreeNextKey')] = I(len(cases))
    writer._root_object.update({N('/StructTreeRoot'): tree_ref, N('/MarkInfo'): D({N('/Marked'): Bool(True)}), N('/Lang'): T('en')})
    buffer = io.BytesIO()
    writer.write(buffer)
    data = buffer.getvalue()
    manifest = {'schema': 1, 'license': 'MIT', 'sha256': hashlib.sha256(data).hexdigest(), 'generator': 'tools/build_sample_images_fixture.py', 'cases': cases}
    return data, (json.dumps(manifest, indent=2) + '\n').encode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    for name, data in zip(['sample-depths.pdf', 'sample-depths.json'], fixture()):
        path = ROOT / 'tests/Fixtures' / name
        if args.check:
            assert path.read_bytes() == data, name
        else:
            path.write_bytes(data)
    print('Verified 17 original image depth/Decode/alpha fixtures')


if __name__ == '__main__':
    main()
