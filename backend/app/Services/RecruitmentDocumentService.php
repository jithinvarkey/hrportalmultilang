<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobApplication;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use ZipArchive;

class RecruitmentDocumentService
{
    public function generateOfferPdf(JobApplication $application, array $data = []): array
    {
        $application->loadMissing(['jobPosting.department', 'jobPosting.designation']);
        $payload = $this->payloadForApplication($application, $data);
        $path = "recruitment/offers/application-{$application->id}-job-offer.pdf";

        $this->putPdf($path, 'recruitment.documents.job-offer', $payload);

        return $this->documentMeta($path, 'Job Offer.pdf');
    }

    public function generateNdaPdf(JobApplication|Employee $subject, array $data = []): array
    {
        $payload = $subject instanceof Employee
            ? $this->payloadForEmployee($subject, $data)
            : $this->payloadForApplication($subject, $data);

        $id = $subject instanceof Employee ? "employee-{$subject->id}" : "application-{$subject->id}";
        $path = "recruitment/nda/{$id}-nda.pdf";

        if (is_file($this->ndaTemplatePath())) {
            $this->putPrefilledNdaPdf($path, $payload);
        } else {
            $this->putPdf($path, 'recruitment.documents.nda', $payload);
        }

        return $this->documentMeta($path, 'NDA.pdf');
    }

    public function generateJoiningDateForm(Employee $employee, array $data = []): array
    {
        $employee->loadMissing(['department', 'designation', 'manager']);
        $payload = $this->payloadForEmployee($employee, $data);
        $path = "employees/{$employee->id}/documents/joining-date-form.pdf";

        $this->putPdf($path, 'recruitment.documents.joining-date-form', $payload);

        return $this->documentMeta($path, 'Joining Date Form.pdf');
    }

    public function generateJoiningDateFormDocx(Employee $employee): array
    {
        $employee->loadMissing('designation');

        $template = resource_path('hr-templates/joining_date_form.docx');
        if (!is_file($template)) {
            throw new \RuntimeException('Joining date form DOCX template is missing.');
        }

        $path = "recruitment/joining-date-forms/employee-{$employee->id}-joining-date-form.docx";
        Storage::put($path, (string) file_get_contents($template));

        $archive = new ZipArchive();
        if ($archive->open(Storage::path($path)) !== true) {
            throw new \RuntimeException('Unable to open the joining date form DOCX.');
        }

        try {
            $documentXml = $archive->getFromName('word/document.xml');
            if ($documentXml === false) {
                throw new \RuntimeException('The joining date form has no document content.');
            }

            $document = new \DOMDocument();
            $document->preserveWhiteSpace = true;
            if (!$document->loadXML($documentXml)) {
                throw new \RuntimeException('Unable to read the joining date form content.');
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $rows = $xpath->query('(//w:tbl)[1]/w:tr');

            $this->setDocxTableCellText($document, $xpath, $rows?->item(1), 2, $employee->full_name);
            $this->setDocxTableCellText($document, $xpath, $rows?->item(2), 2, $employee->designation?->title ?? '');

            if (!$archive->addFromString('word/document.xml', $document->saveXML())) {
                throw new \RuntimeException('Unable to save the joining date form content.');
            }
        } finally {
            $archive->close();
        }

        return $this->documentMeta(
            $path,
            'joining_date_form.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
    }

    public function storeJoiningDateFormDocument(Employee $employee, array $data = []): EmployeeDocument
    {
        $meta = $this->generateJoiningDateForm($employee, $data);

        return $employee->documents()->updateOrCreate(
            ['title' => 'Joining Date Form'],
            [
                'type' => 'other',
                'file_path' => $meta['path'],
                'file_name' => $meta['name'],
                'mime_type' => 'application/pdf',
                'file_size' => Storage::size($meta['path']),
                'is_verified' => true,
                'uploaded_by' => auth()->id(),
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]
        );
    }

    public function onboardingAttachments(Employee $employee, array $data = []): array
    {
        return [
            $this->generateNdaPdf($employee, $data),
            $this->generateJoiningDateFormDocx($employee),
        ];
    }

    private function setDocxTableCellText(
        \DOMDocument $document,
        \DOMXPath $xpath,
        ?\DOMNode $row,
        int $cellIndex,
        string $value
    ): void {
        if (!$row) {
            throw new \RuntimeException('The joining date form structure is invalid.');
        }

        $cells = $xpath->query('./w:tc', $row);
        $cell = $cells?->item($cellIndex);
        if (!$cell) {
            throw new \RuntimeException('The joining date form field is missing.');
        }

        $paragraph = $xpath->query('./w:p', $cell)?->item(0);
        if (!$paragraph) {
            $paragraph = $document->createElementNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                'w:p'
            );
            $cell->appendChild($paragraph);
        }

        foreach (iterator_to_array($xpath->query('.//w:r', $paragraph) ?: []) as $run) {
            $paragraph->removeChild($run);
        }

        $run = $document->createElementNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'w:r'
        );
        $text = $document->createElementNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'w:t'
        );
        $text->appendChild($document->createTextNode($value));
        $run->appendChild($text);
        $paragraph->appendChild($run);
    }

    private function putPdf(string $path, string $view, array $payload): void
    {
        $pdf = Pdf::loadView($view, $payload)->setPaper('a4');
        Storage::put($path, $pdf->output());
    }

    private function putPrefilledNdaPdf(string $path, array $payload): void
    {
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($this->ndaTemplatePath());

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            if ($pageNumber === 1) {
                $this->stampNdaEmployeeDetails($pdf, $payload);
            }
        }

        Storage::put($path, $pdf->Output('S'));
    }

    private function stampNdaEmployeeDetails(Fpdi $pdf, array $payload): void
    {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);

        $name = trim((string) ($payload['name'] ?? ''));
        $idNumber = trim((string) ($payload['national_id'] ?? ''));

        if ($name !== '') {
            $pdf->SetXY(68, 218);
            $pdf->Cell(82, 5, $this->fpdfText($name), 0, 0, 'L');
        }

        if ($idNumber !== '') {
            $pdf->SetXY(68, 236);
            $pdf->Cell(82, 5, $this->fpdfText($idNumber), 0, 0, 'L');
        }
    }

    private function fpdfText(string $value): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $value);
    }

    private function documentMeta(string $path, string $name, string $mime = 'application/pdf'): array
    {
        return [
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
        ];
    }

    private function payloadForApplication(JobApplication $application, array $data = []): array
    {
        $salary = (float) ($data['basic_salary'] ?? $data['offered_salary'] ?? $data['salary'] ?? $application->expected_salary ?? 0);

        return $this->basePayload([
            'name' => $application->applicant_name,
            'email' => $application->applicant_email,
            'phone' => $application->applicant_phone,
            'position' => $application->jobPosting?->designation?->title ?: $application->jobPosting?->title ?: $application->position_applied,
            'department' => $application->jobPosting?->department?->name ?: $application->department?->name,
            'joining_date' => $data['joining_date'] ?? $data['hire_date'] ?? $application->available_from?->format('Y-m-d'),
            'salary' => $salary,
            'basic_salary' => $data['basic_salary'] ?? $salary,
            'housing_allowance' => $data['housing_allowance'] ?? null,
            'transport_allowance' => $data['transport_allowance'] ?? null,
            'other_allowance' => $data['other_allowance'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function payloadForEmployee(Employee $employee, array $data = []): array
    {
        $salary = (float) ($data['basic_salary'] ?? $data['offered_salary'] ?? $data['salary'] ?? $employee->salary ?? 0);

        return $this->basePayload([
            'employee_code' => $employee->employee_code,
            'name' => $employee->full_name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'national_id' => $data['national_id'] ?? $employee->national_id,
            'position' => $data['position'] ?? $employee->designation?->title,
            'department' => $data['department'] ?? $employee->department?->name,
            'nationality' => $data['nationality'] ?? $employee->nationality,
            'manager_name' => $data['manager_name'] ?? $employee->manager?->full_name,
            'joining_date' => $data['joining_date'] ?? $data['hire_date'] ?? $employee->hire_date?->format('Y-m-d'),
            'salary' => $salary,
            'basic_salary' => $data['basic_salary'] ?? $salary,
            'housing_allowance' => $data['housing_allowance'] ?? $employee->housing_allowance ?? null,
            'transport_allowance' => $data['transport_allowance'] ?? $employee->transport_allowance ?? null,
            'other_allowance' => $data['other_allowance'] ?? $employee->other_allowances ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function basePayload(array $data): array
    {
        $basic = (float) ($data['basic_salary'] ?? $data['salary'] ?? 0);
        $housing = round((float) ($data['housing_allowance'] ?? ($basic * 0.25)), 2);
        $transport = round((float) ($data['transport_allowance'] ?? ($basic * 0.10)), 2);
        $other = round((float) ($data['other_allowance'] ?? 0), 2);

        return array_merge($data, [
            'date' => now()->format('d M Y'),
            'basic_salary' => $basic,
            'housing_allowance' => $housing,
            'transport_allowance' => $transport,
            'other_allowance' => $other,
            'gross_salary' => $basic + $housing + $transport + $other,
            'logo' => $this->assetDataUri('diamond-logo.png'),
            'seal' => $this->assetDataUri('companyseal.jpg'),
            'signature' => $this->assetDataUri('md_sign.png'),
            'arabic_font_regular' => $this->fontDataUri('traditional-arabic.ttf'),
            'arabic_font_bold' => $this->fontDataUri('traditional-arabic-bold.ttf'),
            'ar' => fn (string $text): string => $this->shapeArabicForPdf($text),
            'contract_period' => $data['contract_period'] ?? 'Limited duration',
            'probation_period' => $data['probation_period'] ?? '3 months Renewable',
            'annual_vacation' => $data['annual_vacation'] ?? '22 working days',
            'city_of_origin' => $data['city_of_origin'] ?? 'Riyadh',
            'medical_insurance' => $data['medical_insurance'] ?? 'As per company policy',
            'reference' => 'HR-' . now()->format('Ymd') . '-' . Str::upper(Str::random(5)),
        ]);
    }

    private function assetDataUri(string $filename): ?string
    {
        $path = resource_path("hr-templates/assets/{$filename}");
        if (!is_file($path)) {
            return null;
        }

        $mime = str_ends_with(strtolower($filename), '.png') ? 'image/png' : 'image/jpeg';
        return "data:{$mime};base64," . base64_encode((string) file_get_contents($path));
    }

    private function fontDataUri(string $filename): ?string
    {
        $path = resource_path("fonts/{$filename}");
        if (!is_file($path)) {
            return null;
        }

        return 'data:font/truetype;base64,' . base64_encode((string) file_get_contents($path));
    }

    private function ndaTemplatePath(): string
    {
        return resource_path('hr-templates/assets/nda-template.pdf');
    }

    private function shapeArabicForPdf(string $text): string
    {
        $forms = $this->arabicForms();
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $shaped = [];

        foreach ($chars as $index => $char) {
            $code = mb_ord($char, 'UTF-8');
            if (!isset($forms[$code])) {
                $shaped[] = $char;
                continue;
            }

            [$isolated, $final, $initial, $medial] = $forms[$code];
            $previous = $index > 0 ? mb_ord($chars[$index - 1], 'UTF-8') : null;
            $next = $index + 1 < count($chars) ? mb_ord($chars[$index + 1], 'UTF-8') : null;
            $joinsPrevious = $previous !== null && isset($forms[$previous])
                && $forms[$previous][2] !== null && $final !== null;
            $joinsNext = $next !== null && isset($forms[$next])
                && $initial !== null && $forms[$next][1] !== null;

            $shaped[] = match (true) {
                $joinsPrevious && $joinsNext && $medial !== null => $medial,
                $joinsPrevious && $final !== null => $final,
                $joinsNext && $initial !== null => $initial,
                default => $isolated,
            };
        }

        $runs = preg_split(
            '/([\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]+)/u',
            implode('', $shaped),
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        return implode('', array_map(function (string $run): string {
            return preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $run)
                ? implode('', array_reverse(preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY)))
                : $run;
        }, array_reverse($runs)));
    }

    /** @return array<int, array{string, ?string, ?string, ?string}> */
    private function arabicForms(): array
    {
        $hex = fn (string $value): string => mb_chr(hexdec($value), 'UTF-8');
        $rows = [
            0x0621 => ['FE80', null, null, null], 0x0622 => ['FE81', 'FE82', null, null],
            0x0623 => ['FE83', 'FE84', null, null], 0x0624 => ['FE85', 'FE86', null, null],
            0x0625 => ['FE87', 'FE88', null, null], 0x0626 => ['FE89', 'FE8A', 'FE8B', 'FE8C'],
            0x0627 => ['FE8D', 'FE8E', null, null], 0x0628 => ['FE8F', 'FE90', 'FE91', 'FE92'],
            0x0629 => ['FE93', 'FE94', null, null], 0x062A => ['FE95', 'FE96', 'FE97', 'FE98'],
            0x062B => ['FE99', 'FE9A', 'FE9B', 'FE9C'], 0x062C => ['FE9D', 'FE9E', 'FE9F', 'FEA0'],
            0x062D => ['FEA1', 'FEA2', 'FEA3', 'FEA4'], 0x062E => ['FEA5', 'FEA6', 'FEA7', 'FEA8'],
            0x062F => ['FEA9', 'FEAA', null, null], 0x0630 => ['FEAB', 'FEAC', null, null],
            0x0631 => ['FEAD', 'FEAE', null, null], 0x0632 => ['FEAF', 'FEB0', null, null],
            0x0633 => ['FEB1', 'FEB2', 'FEB3', 'FEB4'], 0x0634 => ['FEB5', 'FEB6', 'FEB7', 'FEB8'],
            0x0635 => ['FEB9', 'FEBA', 'FEBB', 'FEBC'], 0x0636 => ['FEBD', 'FEBE', 'FEBF', 'FEC0'],
            0x0637 => ['FEC1', 'FEC2', 'FEC3', 'FEC4'], 0x0638 => ['FEC5', 'FEC6', 'FEC7', 'FEC8'],
            0x0639 => ['FEC9', 'FECA', 'FECB', 'FECC'], 0x063A => ['FECD', 'FECE', 'FECF', 'FED0'],
            0x0641 => ['FED1', 'FED2', 'FED3', 'FED4'], 0x0642 => ['FED5', 'FED6', 'FED7', 'FED8'],
            0x0643 => ['FED9', 'FEDA', 'FEDB', 'FEDC'], 0x0644 => ['FEDD', 'FEDE', 'FEDF', 'FEE0'],
            0x0645 => ['FEE1', 'FEE2', 'FEE3', 'FEE4'], 0x0646 => ['FEE5', 'FEE6', 'FEE7', 'FEE8'],
            0x0647 => ['FEE9', 'FEEA', 'FEEB', 'FEEC'], 0x0648 => ['FEED', 'FEEE', null, null],
            0x0649 => ['FEEF', 'FEF0', null, null], 0x064A => ['FEF1', 'FEF2', 'FEF3', 'FEF4'],
            0x0671 => ['FB50', 'FB51', null, null], 0x067E => ['FB56', 'FB57', 'FB58', 'FB59'],
            0x0686 => ['FB7A', 'FB7B', 'FB7C', 'FB7D'], 0x0698 => ['FB8A', 'FB8B', null, null],
            0x06A4 => ['FB6A', 'FB6B', 'FB6C', 'FB6D'], 0x06A9 => ['FB8E', 'FB8F', 'FB90', 'FB91'],
            0x06AF => ['FB92', 'FB93', 'FB94', 'FB95'], 0x06CC => ['FBFC', 'FBFD', 'FBFE', 'FBFF'],
        ];

        return array_map(fn (array $row): array => array_map(
            fn ($form) => $form === null ? null : $hex($form),
            $row,
        ), $rows);
    }

}
