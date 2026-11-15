<?php

namespace App\Actions\Offers;

use App\Services\Offers\Images\CropRect;
use App\Services\Offers\Images\ImageProcessor;
use App\Services\Offers\Images\ImageVariant;
use App\Services\Offers\Images\PreprocessedImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class PreprocessRideOfferImageAction
{
    private const PANEL_WHITENESS_THRESHOLD = 0.60;

    private const MINIMUM_PANEL_HEIGHT_RATIO = 0.10;

    public function __construct(private readonly ImageProcessor $processor) {}

    public function execute(string $sourcePath, ?string $targetDirectory = null): PreprocessedImage
    {
        if (! is_readable($sourcePath)) {
            throw new RuntimeException("The offer image [{$sourcePath}] is not readable.");
        }

        $dimensions = $this->processor->dimensions($sourcePath);
        $whiteness = $this->processor->rowWhiteness($sourcePath);

        [$crop, $detected] = $this->detectPanel($dimensions, $whiteness);

        $scale = $this->scaleFor($crop);

        $targetDirectory ??= storage_path('app/private/offer-scans/tmp/'.Str::uuid());
        File::ensureDirectoryExists($targetDirectory);

        $variants = [];

        foreach (['panel_color' => false, 'panel_gray' => true] as $label => $grayscale) {
            $path = $targetDirectory.'/'.$label.'.png';
            $size = $this->processor->render($sourcePath, $path, $crop, $scale, $grayscale);

            $variants[$label] = new ImageVariant($label, $path, $size['width'], $size['height']);
        }

        return new PreprocessedImage(
            sourcePath: $sourcePath,
            cropRect: $crop,
            scale: $scale,
            variants: $variants,
            warnings: $detected ? [] : ['panel_not_detected'],
            driver: $this->processor->name(),
        );
    }

    /**
     * @param  array{width: int, height: int}  $dimensions
     * @param  list<float>  $whiteness
     * @return array{0: CropRect, 1: bool}
     */
    private function detectPanel(array $dimensions, array $whiteness): array
    {
        $height = $dimensions['height'];
        $width = $dimensions['width'];
        $minimumHeight = max(16, (int) round($height * self::MINIMUM_PANEL_HEIGHT_RATIO));

        $best = null;
        $runStart = null;

        foreach ($whiteness as $row => $value) {
            $isPanelRow = $value >= self::PANEL_WHITENESS_THRESHOLD;

            if ($isPanelRow && $runStart === null) {
                $runStart = $row;
            }

            if (! $isPanelRow && $runStart !== null) {
                $best = $this->longerRun($best, $runStart, $row - 1);

                $runStart = null;
            }
        }

        if ($runStart !== null) {
            $best = $this->longerRun($best, $runStart, count($whiteness) - 1);
        }

        if ($best !== null && $minimumHeight <= $best[1] - $best[0] + 1) {
            $top = max(0, $best[0] - 2);
            $bottom = min($height - 1, $best[1] + 2);

            return [
                new CropRect(0, $top, $width, $bottom - $top + 1),
                true,
            ];
        }

        return [$this->fallbackCrop($dimensions), false];
    }

    /**
     * @param  array{0: int, 1: int}|null  $best
     * @return array{0: int, 1: int}
     */
    private function longerRun(?array $best, int $start, int $end): array
    {
        if ($best === null || ($end - $start) > ($best[1] - $best[0])) {
            return [$start, $end];
        }

        return $best;
    }

    /**
     * @param  array{width: int, height: int}  $dimensions
     */
    private function fallbackCrop(array $dimensions): CropRect
    {
        $strategy = (string) config('ride_offer_ocr.preprocess.crop_strategy', 'auto');
        $height = $dimensions['height'];
        $width = $dimensions['width'];

        [$topRatio, $heightRatio] = match ($strategy) {
            'card' => [0.55, 0.45],
            default => [0.50, 0.50],
        };

        $top = (int) round($height * $topRatio);
        $cropHeight = max(1, min($height - $top, (int) round($height * $heightRatio)));

        return new CropRect(0, $top, $width, $cropHeight);
    }

    private function scaleFor(CropRect $crop): float
    {
        $scale = max(1.0, (float) config('ride_offer_ocr.preprocess.scale', 2.5));
        $maxSide = (int) config('ride_offer_ocr.preprocess.max_side', 2400);
        $largestSide = max($crop->width, $crop->height);

        if ($maxSide > 0 && $largestSide * $scale > $maxSide) {
            $scale = $maxSide / $largestSide;
        }

        return round(max(1.0, $scale), 4);
    }
}
