<?php

use App\Exceptions\TextRecognitionUnavailableException;
use App\Services\Offers\TextRecognition\TesseractTextRecognitionProvider;
use App\Services\Offers\TextRecognition\TesseractTsvParser;
use App\Services\Offers\TextRecognition\TextRecognitionOptions;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function offerOcrImage(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'offer-ocr-');
    file_put_contents($path, 'fake-image-bytes');

    return $path;
}

function offerOcrTsv(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/offers/tsv/uber_priority.tsv'));
}

it('runs tesseract over the image and parses the TSV output', function (): void {
    $path = offerOcrImage();

    Process::fake(function (PendingProcess $process) use ($path) {
        expect($process->command)->toBe([
            'tesseract', $path, 'stdout', '-l', 'spa+eng', '--psm', '6', 'tsv',
        ]);

        return Process::result(offerOcrTsv());
    });

    $result = (new TesseractTextRecognitionProvider(new TesseractTsvParser))
        ->recognize($path, new TextRecognitionOptions);

    expect($result->provider)->toBe('tesseract')
        ->and($result->tokens)->toHaveCount(12)
        ->and($result->text())->toBe("\$3.45\n4.91 (377)\n11 min (6.3 km)\nViaje: 17 min (7.6 km)")
        ->and($result->meta['psm'])->toBe(6)
        ->and($result->meta['languages'])->toBe('spa+eng')
        ->and($result->meta['variant'])->toBe('panel')
        ->and($result->meta['duration_ms'])->toBeInt();
});

it('uses the requested psm and languages', function (): void {
    $path = offerOcrImage();

    Process::fake(function (PendingProcess $process) use ($path) {
        expect($process->command)->toBe([
            'tesseract', $path, 'stdout', '-l', 'eng', '--psm', '11', 'tsv',
        ]);

        return Process::result(offerOcrTsv());
    });

    (new TesseractTextRecognitionProvider(new TesseractTsvParser))
        ->recognize($path, new TextRecognitionOptions(psm: 11, languages: 'eng', variant: 'labels'));
});

it('throws an unavailable exception when the image is missing', function (): void {
    $provider = new TesseractTextRecognitionProvider(new TesseractTsvParser);

    expect(fn () => $provider->recognize('/missing/offer.jpg', new TextRecognitionOptions))
        ->toThrow(TextRecognitionUnavailableException::class);
});

it('maps a failing tesseract run to provider_unavailable', function (): void {
    Process::fake(fn () => Process::result(errorOutput: 'Failed loading language spa', exitCode: 1));

    $provider = new TesseractTextRecognitionProvider(new TesseractTsvParser);

    try {
        $provider->recognize(offerOcrImage(), new TextRecognitionOptions);
        $this->fail('Expected a TextRecognitionUnavailableException.');
    } catch (TextRecognitionUnavailableException $exception) {
        expect($exception->errorCode())->toBe('provider_unavailable')
            ->and($exception->getMessage())->toBe('Failed loading language spa');
    }
});

it('maps a process that cannot start to provider_unavailable', function (): void {
    Process::fake(fn () => new RuntimeException('Unable to launch a new process.'));

    $provider = new TesseractTextRecognitionProvider(new TesseractTsvParser);

    expect(fn () => $provider->recognize(offerOcrImage(), new TextRecognitionOptions))
        ->toThrow(TextRecognitionUnavailableException::class);
});

it('reports availability from the tesseract binary', function (): void {
    $provider = new TesseractTextRecognitionProvider(new TesseractTsvParser);

    Process::fake(fn (PendingProcess $process) => $process->command === ['tesseract', '--version']
        ? Process::result('tesseract 5.3.4')
        : Process::result(errorOutput: 'unexpected', exitCode: 1));

    expect($provider->isAvailable())->toBeTrue();

    Process::fake(fn () => Process::result(errorOutput: 'not found', exitCode: 127));

    expect($provider->isAvailable())->toBeFalse();
});
