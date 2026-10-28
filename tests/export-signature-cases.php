<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\PdfToHtml;
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Signatures\SignatureValidationProvider;
use PDF\Html\Signatures\SignatureValidationRequest;
use PDF\Html\Signatures\SignatureValidationResult;
use PDF\Html\Signatures\SignatureValidationStatus;
use PDF\Html\Tests\Signatures\SignatureFixture;
use PDF\Reading\FileBytes;

$fixtures = new SignatureFixture();
$cases = [];
foreach ($fixtures->cases() as $case) {
    $provider = $fixtures->provider($case['certificate']);
    $message = $provider->validate($fixtures->request($case['file']))->message;
    $converter = new PdfToHtml(source: new ReadPdfSource(signatureValidation: $provider));
    $cases[$case['name']] = ['html' => $converter->convert(new FileBytes($fixtures->bytes($case['file'])), $case['file'])->html, 'status' => $case['status'], 'message' => $message];
}
foreach (['not-validated', 'unsigned'] as $status) {
    $bytes = $fixtures->bytes('signed-padded.pdf');
    if ($status === 'unsigned') {
        $bytes = str_replace('/V 6 0 R', '        ', $bytes);
    }
    $cases[$status] = ['html' => (new PdfToHtml())->convert(new FileBytes($bytes), 'signature.pdf')->html, 'status' => $status, 'message' => 'PDF signature: ' . $status . '. The derived HTML is not signed.'];
}
$message = '<img src="https://example.test/x" onerror="throw 1"> & "untrusted" status';
$provider = new class ($message) implements SignatureValidationProvider {
    public function __construct(private readonly string $message)
    {
    }
    public function validate(SignatureValidationRequest $request): SignatureValidationResult
    {
        return new SignatureValidationResult($request, SignatureValidationStatus::Indeterminate, $this->message);
    }
};
$cases['escaped-message'] = ['html' => (new PdfToHtml(source: new ReadPdfSource(signatureValidation: $provider)))->convert(new FileBytes($fixtures->bytes('signed-padded.pdf')), 'signature.pdf')->html, 'status' => 'indeterminate', 'message' => $message];
$policy = new DerivationPolicy(allowActiveContent: true);
$bytes = str_replace('/F 4 /StructParent', '/Ff 1/StructParent', $fixtures->bytes('signed-padded.pdf'));
$converter = new PdfToHtml(source: new ReadPdfSource(policy: $policy, signatureValidation: $provider), deriver: new HtmlDeriver(policy: $policy));
$cases['readonly-active-runtime'] = ['html' => $converter->convert(new FileBytes($bytes), 'readonly-signature.pdf')->html, 'status' => 'indeterminate', 'message' => $message, 'activeRuntime' => true];
echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
