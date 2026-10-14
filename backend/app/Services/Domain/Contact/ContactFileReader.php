<?php

namespace HiEvents\Services\Domain\Contact;

use HiEvents\Exceptions\ContactImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Throwable;

class ContactFileReader
{
    public const MAX_ROWS = 20000;

    /**
     * @return array{headers: string[], rows: array<int, string[]>}
     *
     * @throws ContactImportException
     */
    public function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);

            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::guessEncoding($path, 'Windows-1252'));
                $reader->setDelimiter($this->guessDelimiter($path));
            }

            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (Throwable) {
            throw new ContactImportException(__('This file could not be read. Use a CSV or Excel file.'));
        }

        $headers = null;
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator() as $cell) {
                $values[] = trim((string) $cell->getFormattedValue());
            }

            if (implode('', $values) === '') {
                continue;
            }

            if ($headers === null) {
                $headers = $values;

                continue;
            }

            $rows[] = array_pad(array_slice($values, 0, count($headers)), count($headers), '');

            if (count($rows) > self::MAX_ROWS) {
                throw new ContactImportException(__('This file has more than :max contacts. Split it into several files.', ['max' => self::MAX_ROWS]));
            }
        }

        if ($headers === null || $rows === []) {
            throw new ContactImportException(__('This file is empty.'));
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function guessDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $firstLine = (string) fgets($handle);
        fclose($handle);
        $counts = [];
        foreach ([';', ',', "\t", '|'] as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
