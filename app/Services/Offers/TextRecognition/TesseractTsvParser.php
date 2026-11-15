<?php

namespace App\Services\Offers\TextRecognition;

class TesseractTsvParser
{
    /**
     * Convert Tesseract TSV output into tokens and lines.
     *
     * Only word rows (level 5) with text and a usable confidence are kept;
     * rows with confidence -1 are structural/page rows and are ignored.
     */
    public function parse(string $tsv): OcrResult
    {
        $tokens = [];
        $headerSeen = false;

        foreach (preg_split('/\R/', $tsv) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! $headerSeen) {
                $headerSeen = true;

                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < 12) {
                continue;
            }

            if ((int) $columns[0] !== 5) {
                continue;
            }

            $text = trim($columns[11]);
            $confidence = (float) $columns[10];

            if ($text === '' || $confidence < 0) {
                continue;
            }

            $tokens[] = new OcrToken(
                text: $text,
                left: (int) $columns[6],
                top: (int) $columns[7],
                width: (int) $columns[8],
                height: (int) $columns[9],
                confidence: round($confidence / 100, 4),
                page: (int) $columns[1],
                block: (int) $columns[2],
                paragraph: (int) $columns[3],
                line: (int) $columns[4],
            );
        }

        return new OcrResult('tesseract', $tokens);
    }
}
