<?php

namespace App\Services\Offers\Images;

use GdImage;
use RuntimeException;

class GdImageProcessor implements ImageProcessor
{
    private const NEAR_WHITE_LUMINANCE = 0.95;

    public function name(): string
    {
        return 'gd';
    }

    public function dimensions(string $absolutePath): array
    {
        $size = getimagesize($absolutePath);

        if ($size === false) {
            throw new RuntimeException("The image [{$absolutePath}] could not be read.");
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    public function rowWhiteness(string $absolutePath, float $centralWidthRatio = 0.8): array
    {
        $image = $this->load($absolutePath);
        $width = imagesx($image);
        $height = imagesy($image);

        $left = (int) round($width * (1 - $centralWidthRatio) / 2);
        $right = $width - $left;
        $columnStep = max(1, intdiv(max(1, $right - $left), 120));

        $whiteness = [];

        for ($y = 0; $y < $height; $y++) {
            $white = 0;
            $samples = 0;

            for ($x = $left; $x < $right; $x += $columnStep) {
                $colour = imagecolorat($image, $x, $y);
                $red = ($colour >> 16) & 0xFF;
                $green = ($colour >> 8) & 0xFF;
                $blue = $colour & 0xFF;

                $luminance = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255;

                if ($luminance >= self::NEAR_WHITE_LUMINANCE) {
                    $white++;
                }

                $samples++;
            }

            $whiteness[] = $samples > 0 ? $white / $samples : 0.0;
        }

        imagedestroy($image);

        return $whiteness;
    }

    public function render(
        string $sourcePath,
        string $targetPath,
        ?CropRect $crop,
        float $scale,
        bool $grayscale,
    ): array {
        $image = $this->load($sourcePath);

        if ($crop !== null) {
            $cropped = imagecrop($image, [
                'x' => $crop->left,
                'y' => $crop->top,
                'width' => $crop->width,
                'height' => $crop->height,
            ]);

            if ($cropped !== false) {
                imagedestroy($image);
                $image = $cropped;
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($scale > 1.0) {
            $scaled = imagescale(
                $image,
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale)),
                IMG_BICUBIC,
            );

            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        if ($grayscale) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            imagefilter($image, IMG_FILTER_CONTRAST, -20);
        }

        $targetWidth = imagesx($image);
        $targetHeight = imagesy($image);

        imagepng($image, $targetPath);
        imagedestroy($image);

        return ['width' => $targetWidth, 'height' => $targetHeight];
    }

    private function load(string $absolutePath): GdImage
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is not available.');
        }

        $bytes = file_get_contents($absolutePath);

        if ($bytes === false) {
            throw new RuntimeException("The image [{$absolutePath}] could not be read.");
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException("The image [{$absolutePath}] is not a supported image.");
        }

        return $image;
    }
}
