<?php

use App\Services\Offers\TextRecognition\TesseractTsvParser;

function tsvFixture(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/offers/tsv/uber_priority.tsv');
}

it('parses only word rows with usable text and confidence', function (): void {
    $result = (new TesseractTsvParser)->parse(tsvFixture());

    expect($result->tokens)->toHaveCount(12)
        ->and($result->tokens[0]->text)->toBe('$3.45')
        ->and($result->tokens[0]->confidence)->toBe(0.965)
        ->and($result->tokens[0]->left)->toBe(20)
        ->and($result->tokens[0]->page)->toBe(1)
        ->and($result->tokens[0]->block)->toBe(1)
        ->and($result->tokens[0]->paragraph)->toBe(1)
        ->and($result->tokens[0]->line)->toBe(1);
});

it('rebuilds lines ordered top to bottom and left to right', function (): void {
    $lines = (new TesseractTsvParser)->parse(tsvFixture())->lines();

    expect($lines)->toHaveCount(4)
        ->and(array_map(fn ($line) => implode(' ', array_map(fn ($token) => $token->text, $line)), $lines))
        ->toBe([
            '$3.45',
            '4.91 (377)',
            '11 min (6.3 km)',
            'Viaje: 17 min (7.6 km)',
        ]);
});

it('builds the recognised text from the token lines', function (): void {
    expect((new TesseractTsvParser)->parse(tsvFixture())->text())
        ->toBe("\$3.45\n4.91 (377)\n11 min (6.3 km)\nViaje: 17 min (7.6 km)");
});

it('filters text by a rectangle', function (): void {
    $result = (new TesseractTsvParser)->parse(tsvFixture());

    expect($result->textIn(0, 120, 1000, 170))->toBe('Viaje: 17 min (7.6 km)')
        ->and($result->textIn(0, 0, 1000, 90))->toBe("\$3.45\n4.91 (377)");
});

it('keeps numeric tokens inside a rectangle by their centre', function (): void {
    $result = (new TesseractTsvParser)->parse(tsvFixture());

    expect($result->textIn(100, 50, 200, 90))->toBe('(377)');
});

it('ignores structural rows, empty words and unknown confidence', function (): void {
    $text = (new TesseractTsvParser)->parse(tsvFixture())->text();

    expect($text)->not->toContain('structure');
});

it('handles output without a header', function (): void {
    $result = (new TesseractTsvParser)->parse('5	1	1	1	1	1	10	20	30	40	80.0	UberX');

    expect($result->tokens)->toBeEmpty();
});
