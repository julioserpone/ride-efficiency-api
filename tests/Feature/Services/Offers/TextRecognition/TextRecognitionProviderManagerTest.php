<?php

use App\Services\Offers\TextRecognition\TesseractTextRecognitionProvider;
use App\Services\Offers\TextRecognition\TextRecognitionProviderManager;

it('resolves tesseract as the default provider', function (): void {
    config()->set('ride_offer_ocr.provider', 'tesseract');

    $provider = app(TextRecognitionProviderManager::class)->driver();

    expect($provider)->toBeInstanceOf(TesseractTextRecognitionProvider::class)
        ->and($provider->name())->toBe('tesseract');
});

it('rejects a provider that is not registered', function (): void {
    expect(fn () => app(TextRecognitionProviderManager::class)->driver('azure-read'))
        ->toThrow(InvalidArgumentException::class);
});
