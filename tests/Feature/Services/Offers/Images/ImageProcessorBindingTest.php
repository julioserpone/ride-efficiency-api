<?php

use App\Services\Offers\Images\GdImageProcessor;
use App\Services\Offers\Images\ImageProcessor;

it('resolves gd as the default image processor', function (?string $driver): void {
    config()->set('ride_offer_ocr.preprocess.driver', $driver);

    expect(app(ImageProcessor::class))->toBeInstanceOf(GdImageProcessor::class)
        ->and(app(ImageProcessor::class)->name())->toBe('gd');
})->with([
    'unset' => [null],
    'empty' => [''],
    'auto' => ['auto'],
    'gd' => ['gd'],
]);

it('rejects an unsupported image driver', function (): void {
    config()->set('ride_offer_ocr.preprocess.driver', 'magic');

    expect(fn () => app(ImageProcessor::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported ride offer image driver [magic].');
});
