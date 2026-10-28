<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Signatures\SignatureOutputValidation;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ReaderLimits;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** CLI-owned file access; the validation/derivation objects perform no implicit I/O. */
final readonly class VerifyPdfSignatureExample
{
    public function run(string $input, string $certificateFile, string $output): void
    {
        $input = $this->localFile($input);
        $certificateFile = $this->localFile($certificateFile);
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $output) === 1) {
            throw new InvalidArgumentException('Choose a local HTML output path.');
        }
        $pdf = $this->localBytes($input, (new ReaderLimits())->maxFileBytes);
        $certificateBytes = $this->localBytes($certificateFile, 1024 * 1024);
        if (str_contains($certificateBytes, '-----BEGIN')) {
            if (preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----\s*\z/D', $certificateBytes, $matches) !== 1) {
                throw new InvalidArgumentException('Supply exactly one signer certificate in PEM or DER format.');
            }
            $certificateBytes = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
            if ($certificateBytes === false || $certificateBytes === '') {
                throw new InvalidArgumentException('The PEM certificate has invalid base64 data.');
            }
        }
        $certificate = new CertificateChain(new X509Certificate(new HexadecimalString($certificateBytes)));
        $validation = new SignatureOutputValidation(new OpenSslSignatureOutputVerifier(), $certificate);
        $document = (new DocumentReader())->read(new FileBytes($pdf));
        $source = (new ReadPdfSource(signatureValidation: $validation))->read($document, basename($input));
        $result = (new HtmlDeriver())->derive($source);

        // Integrity against this chosen certificate does not establish PKI trust,
        // revocation, permission for later revisions, or authenticity of this HTML.
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the explicit output directory.');
        }
        // Validate every destination before writing any HTML or asset bytes.
        $destinations = [];
        $outputs = [[$output, $result->html]];
        foreach ($result->assets as $asset) {
            $outputs[] = [$directory . '/' . $asset->filename, $asset->bytes];
        }
        foreach ($outputs as [$filename, $bytes]) {
            $parent = realpath(dirname($filename));
            if ($parent === false || is_dir($filename)) {
                throw new InvalidArgumentException('Each output needs an existing parent and a file destination.');
            }
            $destination = realpath($filename);
            if ($destination === false) {
                $destination = $parent . '/' . basename($filename);
            }
            if (in_array($destination, [$input, $certificateFile], true) || isset($destinations[$destination])) {
                throw new InvalidArgumentException('HTML and assets must not collide with either input or another output.');
            }
            $destinations[$destination] = $bytes;
        }
        foreach ($destinations as $filename => $bytes) {
            if (file_put_contents($filename, $bytes) === false) {
                throw new RuntimeException('Cannot write the derived HTML or asset.');
            }
        }
        echo json_encode([
            'input' => basename($input),
            'sourceSha256' => hash('sha256', $pdf),
            'certificateSha256' => hash('sha256', $certificateBytes),
            'html' => basename($output),
            'htmlSha256' => hash('sha256', $result->html),
            'diagnostics' => $result->diagnostics,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    private function localFile(string $filename): string
    {
        $path = realpath($filename);
        if ($path === false || !is_file($path)) {
            throw new InvalidArgumentException('Choose an existing filesystem input path, without a stream wrapper.');
        }
        return $path;
    }

    private function localBytes(string $filename, int $maximum): string
    {
        if (!stream_is_local($filename) || !is_file($filename)) {
            throw new InvalidArgumentException('Choose an existing local input file.');
        }
        $stream = fopen($filename, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open the chosen input.');
        }
        try {
            $bytes = stream_get_contents($stream, $maximum + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) > $maximum) {
            throw new RuntimeException('The input exceeds the example byte limit.');
        }
        return $bytes;
    }
}

if (count($argv) !== 4) {
    throw new InvalidArgumentException('Usage: php examples/05-verify-pdf-signature.php input.pdf signer.der|signer.pem output.html');
}
(new VerifyPdfSignatureExample())->run($argv[1], $argv[2], $argv[3]);
