<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Presenze del mese in Excel: un foglio con i turni e uno con le ore per persona. */
class AttendanceExcel
{
    public function month(Carbon $month): Spreadsheet
    {
        $shifts = Attendance::with('user')
            ->where('started_at', '>=', $month)->where('started_at', '<', $month->copy()->addMonth())
            ->orderBy('started_at')->get();

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Turni');
        $sheet->fromArray(['Data', 'Persona', 'Entrata', 'Uscita', 'Ore', 'Note']);
        $row = 2;
        foreach ($shifts as $a) {
            $sheet->fromArray([
                $a->started_at->format('d/m/Y'),
                $a->user->name,
                $a->started_at->format('H:i'),
                $a->ended_at?->format('H:i') ?? 'in corso',
                round($a->minutes() / 60, 2),
                trim(($a->ended_auto ? 'Uscita non bippata, da controllare. ' : '') . ($a->note ?? '')),
            ], null, "A{$row}", true);
            $row++;
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('E2:E' . max(2, $row))->getNumberFormat()->setFormatCode('0.00');
        foreach (['A' => 12, 'B' => 28, 'C' => 9, 'D' => 9, 'E' => 8, 'F' => 50] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $totals = $book->createSheet()->setTitle('Totali');
        $totals->fromArray(['Persona', 'Giorni', 'Ore']);
        $row = 2;
        foreach ($shifts->groupBy('user_id')->sortBy(fn ($list) => $list->first()->user->name) as $list) {
            $totals->fromArray([
                $list->first()->user->name,
                $list->map(fn ($a) => $a->started_at->toDateString())->unique()->count(),
                round($list->sum(fn ($a) => $a->minutes()) / 60, 2),
            ], null, "A{$row}", true);
            $row++;
        }
        $totals->getStyle('A1:C1')->getFont()->setBold(true);
        $totals->getColumnDimension('A')->setWidth(28);
        $book->setActiveSheetIndex(0);

        return $book;
    }
}
