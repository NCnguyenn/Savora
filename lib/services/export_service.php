<?php
declare(strict_types=1);

function export_csv_cell(mixed $value): string
{
    $cell = (string) $value;
    if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) $cell = "'" . $cell;
    return '"' . str_replace('"', '""', $cell) . '"';
}

function export_csv_string(array $headers, iterable $rows): string
{
    $lines = [implode(',', array_map('export_csv_cell', $headers))];
    foreach ($rows as $row) $lines[] = implode(',', array_map('export_csv_cell', array_values((array) $row)));
    return implode("\r\n", $lines) . "\r\n";
}

function export_send_csv(string $filename, array $headers, iterable $rows): never
{
    if (!preg_match('/^[A-Za-z0-9_.-]+\.csv$/', $filename)) throw new InvalidArgumentException('Export filename is invalid.');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo export_csv_string($headers, $rows);
    exit;
}
