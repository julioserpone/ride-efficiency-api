<?php

namespace App\Services\Offers\Images;

class CropRect
{
    public function __construct(
        public readonly int $left,
        public readonly int $top,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public function right(): int
    {
        return $this->left + $this->width;
    }

    public function bottom(): int
    {
        return $this->top + $this->height;
    }

    /**
     * @return array{left: int, top: int, width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'left' => $this->left,
            'top' => $this->top,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
