<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One reusable export for every "Export to Excel" button in the app,
 * instead of a bespoke Export class per screen. A controller builds a
 * plain 2D array -- school name on row 1, a blank row, then a header
 * row, then data rows -- and hands it here. Covers every export we
 * need (Students, Staff, Invoices, Attendance rosters, Audit Log)
 * without duplicating the same boilerplate five times.
 */
class SimpleArrayExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        private array $rows,
        private string $title = 'Export',
        private int $boldRowCount = 3,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return substr($this->title, 0, 31); // Excel sheet-title length limit
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getStyle("A1:A{$this->boldRowCount}")->getFont()->setBold(true);
            },
        ];
    }
}
