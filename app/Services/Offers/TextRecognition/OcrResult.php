<?php

namespace App\Services\Offers\TextRecognition;

class OcrResult
{
    /**
     * @param  list<OcrToken>  $tokens
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $tokens,
        public readonly array $meta = [],
        public readonly ?string $recognizedText = null,
    ) {}

    /**
     * Lines rebuilt from the tokens, ordered top-to-bottom and left-to-right.
     *
     * Tesseract supplies explicit page/block/paragraph/line numbers; providers
     * that do not are clustered by vertical position with an 8 px tolerance.
     *
     * @return list<list<OcrToken>>
     */
    public function lines(): array
    {
        $groups = [];

        foreach ($this->tokens as $token) {
            $key = $token->line > 0
                ? sprintf('%d:%d:%d:%d', $token->page, $token->block, $token->paragraph, $token->line)
                : sprintf('top:%d', (int) round($token->centreY() / 8));

            $groups[$key][] = $token;
        }

        foreach ($groups as &$group) {
            usort($group, fn (OcrToken $a, OcrToken $b): int => $a->left <=> $b->left);
        }
        unset($group);

        uasort($groups, function (array $a, array $b): int {
            return [$a[0]->page, $a[0]->top, $a[0]->left] <=> [$b[0]->page, $b[0]->top, $b[0]->left];
        });

        return array_values($groups);
    }

    /**
     * Text rebuilt from the tokens when the provider did not supply it.
     */
    public function text(): string
    {
        if ($this->recognizedText !== null) {
            return $this->recognizedText;
        }

        return implode("\n", array_map(
            fn (array $line): string => implode(' ', array_map(
                fn (OcrToken $token): string => $token->text,
                $line,
            )),
            $this->lines(),
        ));
    }

    /**
     * Text whose token centre falls inside the given rectangle.
     */
    public function textIn(int $left, int $top, int $right, int $bottom): string
    {
        $tokens = array_values(array_filter(
            $this->tokens,
            fn (OcrToken $token): bool => $token->centreX() >= $left
                && $token->centreX() <= $right
                && $token->centreY() >= $top
                && $token->centreY() <= $bottom,
        ));

        return (new self($this->provider, $tokens, $this->meta))->text();
    }

    /**
     * @return list<OcrToken>
     */
    public function tokensWithMinimumConfidence(float $minimum): array
    {
        return array_values(array_filter(
            $this->tokens,
            fn (OcrToken $token): bool => $token->confidence >= $minimum,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'text' => $this->text(),
            'tokens' => array_map(fn (OcrToken $token): array => $token->toArray(), $this->tokens),
            'meta' => $this->meta,
        ];
    }
}
