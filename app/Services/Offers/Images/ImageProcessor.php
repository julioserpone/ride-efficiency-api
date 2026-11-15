<?php

namespace App\Services\Offers\Images;

interface ImageProcessor
{
    public function name(): string;

    /**
     * @return array{width: int, height: int}
     */
    public function dimensions(string $absolutePath): array;

    /**
     * Fraction (0..1) of near-white pixels for every row, sampled across the
     * central band. Text inside the panel lowers the average luminance but
     * leaves most pixels white, so a fraction is more robust than a mean.
     *
     * @return list<float>
     */
    public function rowWhiteness(string $absolutePath, float $centralWidthRatio = 0.8): array;

    /**
     * Crop, scale and optionally greyscale the image into the target path.
     *
     * @return array{width: int, height: int}
     */
    public function render(
        string $sourcePath,
        string $targetPath,
        ?CropRect $crop,
        float $scale,
        bool $grayscale,
    ): array;
}
