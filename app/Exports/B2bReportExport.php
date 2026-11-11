<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class B2bReportExport implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    public function __construct(
        protected array $rows,
        protected array $columns,
        protected string $title = 'Report'
    ) {
    }

    public function array(): array
    {
        return array_map(function ($row) {
            return array_map(function ($column) use ($row) {
                return is_array($row) ? ($row[$column] ?? null) : (is_object($row) ? ($row->{$column} ?? null) : null);
            }, $this->columns);
        }, $this->rows);
    }

    public function headings(): array
    {
        return array_map(function ($column) {
            return ucwords(str_replace('_', ' ', $column));
        }, $this->columns);
    }

    public function title(): string
    {
        return substr($this->title, 0, 30) ?: 'Report';
    }
}
