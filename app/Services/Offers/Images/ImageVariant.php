<?php

namespace App\Services\Offers\Images;

class ImageVariant
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * @return array{label: string, path: string, width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'path' => $this->path,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
