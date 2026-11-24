<?php

declare(strict_types=1);

namespace PeopleSuite;

final class CsvDownload
{
    public function __construct(
        private readonly string $filename,
        private readonly array $columns,
        private readonly iterable $rows,
    ) {
    }

    public function send(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->filename . '"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $this->columns, ',', '"', '', "\r\n");
        foreach ($this->rows as $row) {
            $cells = [];
            foreach ($this->columns as $column) {
                $value = $row[$column] ?? '';
                if (is_string($value) && preg_match('/^[\p{Z}\p{C}]*[=+\-@]/u', $value)) {
                    $value = "'" . $value;
                }
                $cells[] = $value;
            }
            fputcsv($output, $cells, ',', '"', '', "\r\n");
        }
        fclose($output);
    }
}
