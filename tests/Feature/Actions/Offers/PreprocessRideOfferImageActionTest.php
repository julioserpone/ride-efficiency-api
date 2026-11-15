<?php

use App\Actions\Offers\PreprocessRideOfferImageAction;
use App\Services\Offers\Images\CropRect;
use App\Services\Offers\Images\GdImageProcessor;
use App\Services\Offers\Images\ImageProcessor;

class FakeOfferImageProcessor implements ImageProcessor
{
    /** @var list<array<string, mixed>> */
    public array $renders = [];

    /**
     * @param  array{width: int, height: int}  $dimensions
     * @param  list<float>  $luminance
     */
    public function __construct(
        private readonly array $dimensions,
        private readonly array $whiteness,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function dimensions(string $absolutePath): array
    {
        return $this->dimensions;
    }

    public function rowWhiteness(string $absolutePath, float $centralWidthRatio = 0.8): array
    {
        return $this->whiteness;
    }

    public function render(
        string $sourcePath,
        string $targetPath,
        ?CropRect $crop,
        float $scale,
        bool $grayscale,
    ): array {
        $this->renders[] = compact('crop', 'scale', 'grayscale');

        $width = (int) round(($crop?->width ?? $this->dimensions['width']) * $scale);
        $height = (int) round(($crop?->height ?? $this->dimensions['height']) * $scale);

        file_put_contents($targetPath, 'png');

        return ['width' => $width, 'height' => $height];
    }
}

function offerImageSource(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'offer-source-');
    file_put_contents($path, 'image-bytes');

    return $path;
}

function offerImageTarget(): string
{
    return sys_get_temp_dir().'/offer-preprocess-'.uniqid('', true);
}

/**
 * @return list<float>
 */
function whitenessWithPanel(int $height, int $panelTop, int $panelBottom): array
{
    $rows = [];

    for ($row = 0; $row < $height; $row++) {
        $rows[] = $row >= $panelTop && $row <= $panelBottom ? 0.99 : 0.2;
    }

    return $rows;
}

it('crops the bright panel and renders colour and greyscale variants', function (): void {
    $processor = new FakeOfferImageProcessor(
        ['width' => 1000, 'height' => 2000],
        whitenessWithPanel(2000, 900, 1499),
    );

    $result = (new PreprocessRideOfferImageAction($processor))
        ->execute(offerImageSource(), offerImageTarget());

    expect($result->cropRect->toArray())->toBe([
        'left' => 0,
        'top' => 898,
        'width' => 1000,
        'height' => 604,
    ])
        ->and($result->scale)->toBe(2.4)
        ->and($result->warnings)->toBe([])
        ->and($result->driver)->toBe('fake')
        ->and(array_keys($result->variants))->toBe(['panel_color', 'panel_gray'])
        ->and($result->variant('panel_color')?->width)->toBe(2400)
        ->and($result->variant('panel_gray')?->height)->toBe(1450)
        ->and($processor->renders)->toHaveCount(2)
        ->and($processor->renders[0]['grayscale'])->toBeFalse()
        ->and($processor->renders[1]['grayscale'])->toBeTrue();
});

it('falls back to the bottom half when no bright panel is found', function (): void {
    $processor = new FakeOfferImageProcessor(
        ['width' => 800, 'height' => 1600],
        array_fill(0, 1600, 0.3),
    );

    $result = (new PreprocessRideOfferImageAction($processor))
        ->execute(offerImageSource(), offerImageTarget());

    expect($result->cropRect->toArray())->toBe([
        'left' => 0,
        'top' => 800,
        'width' => 800,
        'height' => 800,
    ])
        ->and($result->warnings)->toBe(['panel_not_detected']);
});

it('uses the card strategy for the fallback crop', function (): void {
    config()->set('ride_offer_ocr.preprocess.crop_strategy', 'card');

    $processor = new FakeOfferImageProcessor(
        ['width' => 800, 'height' => 1600],
        array_fill(0, 1600, 0.3),
    );

    $result = (new PreprocessRideOfferImageAction($processor))
        ->execute(offerImageSource(), offerImageTarget());

    expect($result->cropRect->toArray())->toBe([
        'left' => 0,
        'top' => 880,
        'width' => 800,
        'height' => 720,
    ]);
});

it('rejects an unreadable source image', function (): void {
    $processor = new FakeOfferImageProcessor(['width' => 1, 'height' => 1], [0.99]);

    expect(fn () => (new PreprocessRideOfferImageAction($processor))
        ->execute('/missing/offer.jpg'))
        ->toThrow(RuntimeException::class);
});

it('preprocesses a real image with gd', function (): void {
    $width = 600;
    $height = 1200;
    $panelTop = 500;
    $panelHeight = 400;

    $image = imagecreatetruecolor($width, $height);
    $map = imagecolorallocate($image, 210, 225, 250);
    $panel = imagecolorallocate($image, 252, 252, 252);
    $ink = imagecolorallocate($image, 15, 15, 15);

    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $map);
    imagefilledrectangle($image, 0, $panelTop, $width - 1, $panelTop + $panelHeight - 1, $panel);
    imagefilledrectangle($image, 40, $panelTop + 60, 200, $panelTop + 90, $ink);

    $source = (string) tempnam(sys_get_temp_dir(), 'offer-real-').'.jpg';
    imagejpeg($image, $source);
    imagedestroy($image);

    $target = offerImageTarget();

    $result = (new PreprocessRideOfferImageAction(new GdImageProcessor))
        ->execute($source, $target);

    expect($result->driver)->toBe('gd')
        ->and($result->cropRect->top)->toBeGreaterThanOrEqual($panelTop - 3)
        ->and($result->cropRect->top)->toBeLessThanOrEqual($panelTop + 3)
        ->and($result->cropRect->height)->toBeGreaterThanOrEqual($panelHeight)
        ->and($result->warnings)->toBe([])
        ->and(is_file($target.'/panel_color.png'))->toBeTrue()
        ->and(is_file($target.'/panel_gray.png'))->toBeTrue()
        ->and($result->variant('panel_color'))->not->toBeNull();
});
