<?php

namespace App\Services\Offers\TextRecognition;

class OcrToken
{
    public function __construct(
        public readonly string $text,
        public readonly int $left,
        public readonly int $top,
        public readonly int $width,
        public readonly int $height,
        public readonly float $confidence,
        public readonly int $page = 1,
        public readonly int $block = 0,
        public readonly int $paragraph = 0,
        public readonly int $line = 0,
    ) {}

    public function right(): int
    {
        return $this->left + $this->width;
    }

    public function bottom(): int
    {
        return $this->top + $this->height;
    }

    public function centreX(): int
    {
        return $this->left + intdiv($this->width, 2);
    }

    public function centreY(): int
    {
        return $this->top + intdiv($this->height, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'left' => $this->left,
            'top' => $this->top,
            'width' => $this->width,
            'height' => $this->height,
            'confidence' => $this->confidence,
            'page' => $this->page,
            'block' => $this->block,
            'paragraph' => $this->paragraph,
            'line' => $this->line,
        ];
    }
}
