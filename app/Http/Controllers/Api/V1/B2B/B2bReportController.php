<?php

namespace App\Http\Controllers\Api\V1\B2B;

use App\Exports\B2bReportExport;
use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Services\B2B\B2bReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * P2-9: the 14 platform reports with JSON / CSV / XLSX / PDF output.
 */
class B2bReportController extends B2bBaseController
{
    public function __construct(protected B2bReportService $reports)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $portal = $this->portal($request);

        $items = [];
        foreach (B2bReportService::REPORTS as $key => $title) {
            $items[] = [
                'key' => $key,
                'title' => $title,
                'endpoint' => "/api/v1/{$this->endpointPrefix($portal)}/reports/{$key}",
                'formats' => ['json', 'csv', 'xlsx', 'pdf'],
            ];
        }

        return $this->successResponse([
            'portal' => $portal,
            'default_range' => ['from' => now()->subDays(90)->toDateString(), 'to' => now()->toDateString()],
            'reports' => $items,
        ], 'Reports catalogue retrieved successfully');
    }

    public function show($report, Request $request): JsonResponse
    {
        $portal = $this->portal($request);
        $filters = $this->filters($request);

        if ($filters === null) {
            return $this->errorResponse('Invalid date range: use from <= to', 422);
        }

        try {
            $data = $this->reports->build($report, $portal, $request->user(), $filters);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }

        return $this->successResponse($data, "{$data['title']} generated successfully");
    }

    public function export($report, Request $request)
    {
        $portal = $this->portal($request);
        $filters = $this->filters($request);

        if ($filters === null) {
            return $this->errorResponse('Invalid date range: use from <= to', 422);
        }

        $format = strtolower($request->get('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return $this->errorResponse('Unsupported export format; use csv, xlsx or pdf', 422);
        }

        try {
            $data = $this->reports->build($report, $portal, $request->user(), $filters);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }

        $filename = $report . '-' . $filters['from'] . '-to-' . $filters['to'];

        if ($format === 'xlsx') {
            if (!class_exists(Excel::class)) {
                return $this->errorResponse('XLSX export is not installed on this server', 500);
            }

            return Excel::download(
                new B2bReportExport($data['rows'], $data['columns'], $data['title']),
                $filename . '.xlsx'
            );
        }

        if ($format === 'pdf') {
            return $this->pdf($data, $filename);
        }

        return $this->csv($data, $filename);
    }

    private function csv(array $data, string $filename)
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['columns']);
            foreach ($data['rows'] as $row) {
                $line = [];
                foreach ($data['columns'] as $column) {
                    $value = $row[$column] ?? null;
                    $line[] = is_array($value) ? json_encode($value) : $value;
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function pdf(array $data, string $filename)
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            return $this->errorResponse('PDF export is not installed on this server', 500);
        }

        $html = '<h2 style="font-family:sans-serif">' . e($data['title']) . '</h2>';
        if (!empty($data['summary'])) {
            $html .= '<p style="font-size:10px;color:#555">';
            foreach ($data['summary'] as $key => $value) {
                $html .= e(ucwords(str_replace('_', ' ', $key))) . ': ' . e(is_scalar($value) ? (string) $value : json_encode($value)) . ' &nbsp;|&nbsp; ';
            }
            $html .= '</p>';
        }

        $html .= '<table width="100%" border="1" cellpadding="4" cellspacing="0" style="font-size:9px;border-collapse:collapse">';
        $html .= '<thead><tr>';
        foreach ($data['columns'] as $column) {
            $html .= '<th style="background:#eee">' . e(ucwords(str_replace('_', ' ', $column))) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($data['rows'])) {
            $html .= '<tr><td colspan="' . count($data['columns']) . '">No data for the selected range</td></tr>';
        }

        foreach ($data['rows'] as $row) {
            $html .= '<tr>';
            foreach ($data['columns'] as $column) {
                $value = $row[$column] ?? null;
                $html .= '<td>' . e(is_scalar($value) ? (string) $value : json_encode($value)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $tempDir = storage_path('app/mpdf');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => $tempDir,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output($filename . '.pdf', \Mpdf\Output\Destination::STRING_RETURN);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
        ]);
    }

    private function filters(Request $request): ?array
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return null;
        }

        $from = $request->get('from', now()->subDays(90)->toDateString());
        $to = $request->get('to', now()->toDateString());

        if ($from > $to) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    private function portal(Request $request): string
    {
        $portal = $request->route('portal');

        if (!$portal) {
            $portal = match ($request->user()->user_type) {
                'seller' => 'exporter',
                'admin', 'staff' => 'admin',
                default => 'importer',
            };
        }

        return $portal;
    }

    private function endpointPrefix(string $portal): string
    {
        return $portal === 'admin' ? 'admin' : $portal;
    }
}
