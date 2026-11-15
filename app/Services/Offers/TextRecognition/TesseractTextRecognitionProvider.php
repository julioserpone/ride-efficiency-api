<?php

namespace App\Services\Offers\TextRecognition;

use App\Exceptions\TextRecognitionUnavailableException;
use Illuminate\Support\Facades\Process;
use Throwable;

class TesseractTextRecognitionProvider implements TextRecognitionProvider
{
    public function __construct(private readonly TesseractTsvParser $parser) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function recognize(string $absolutePath, TextRecognitionOptions $options): OcrResult
    {
        if (! is_readable($absolutePath)) {
            throw new TextRecognitionUnavailableException("The image [{$absolutePath}] is not readable.");
        }

        $startedAt = microtime(true);

        try {
            $result = Process::timeout($this->timeout())->run([
                $this->binary(),
                $absolutePath,
                'stdout',
                '-l',
                $options->languages,
                '--psm',
                (string) $options->psm,
                'tsv',
            ]);
        } catch (Throwable $exception) {
            throw new TextRecognitionUnavailableException(
                'Tesseract could not be started: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $result->successful()) {
            throw new TextRecognitionUnavailableException(
                trim($result->errorOutput()) ?: 'Tesseract exited with an error.',
            );
        }

        $parsed = $this->parser->parse($result->output());

        return new OcrResult(
            provider: $this->name(),
            tokens: $parsed->tokens,
            meta: [
                'psm' => $options->psm,
                'languages' => $options->languages,
                'variant' => $options->variant,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
            recognizedText: $parsed->text(),
        );
    }

    public function isAvailable(): bool
    {
        try {
            return Process::timeout(5)->run([$this->binary(), '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function binary(): string
    {
        return (string) config('ride_offer_ocr.tesseract.binary', 'tesseract');
    }

    private function timeout(): int
    {
        return (int) config('ride_offer_ocr.tesseract.timeout_seconds', 20);
    }
}
