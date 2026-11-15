<?php

namespace App\Services\Offers\Images;

class PreprocessedImage
{
    /**
     * @param  array<string, ImageVariant>  $variants
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly CropRect $cropRect,
        public readonly float $scale,
        public readonly array $variants,
        public readonly array $warnings = [],
        public readonly string $driver = 'gd',
    ) {}

    public function variant(string $label): ?ImageVariant
    {
        return $this->variants[$label] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'driver' => $this->driver,
            'crop_rect' => $this->cropRect->toArray(),
            'scale' => $this->scale,
            'variants' => array_map(
                fn (ImageVariant $variant): array => $variant->toArray(),
                array_values($this->variants),
            ),
            'warnings' => $this->warnings,
        ];
    }
}
