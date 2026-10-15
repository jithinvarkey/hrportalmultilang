<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\EmployeeDocumentUploadedMail;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboardingLink;
use App\Models\User;
use App\Services\HdfTemplateService;
use App\Services\OnboardingTaskNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PublicOnboardingController extends Controller
{
    public function __construct(
        private OnboardingTaskNotificationService $onboardingNotifications,
        private HdfTemplateService $hdfTemplates
    )
    {
    }

    private array $documentFields = [
        'signed_offer_letter' => ['title' => 'Signed Offer Letter', 'type' => 'contract', 'required' => true],
        'signed_nda' => ['title' => 'Signed NDA', 'type' => 'contract', 'required' => true],
        'id_iqama' => ['title' => 'Iqama / ID Copy', 'type' => 'id', 'required' => true],
        'national_address' => ['title' => 'National Address Copy', 'type' => 'other', 'required' => true],
        'passport_document' => ['title' => 'Passport Copy', 'type' => 'passport', 'required' => false],
        'experience_letter' => ['title' => 'Experience Letter', 'type' => 'certificate', 'required' => false],
        'educational_documents' => ['title' => 'Educational Documents', 'type' => 'certificate', 'required' => false],
    ];

    public function show(string $token): JsonResponse
    {
        $link = $this->findValidLink($token);
        if (!$link) {
            return response()->json(['message' => 'This onboarding link is invalid or expired.'], 404);
        }

        $employee = $link->employee()->with(['department:id,name', 'designation:id,title'])->firstOrFail();

        return response()->json([
            'employee' => [
                'full_name' => $employee->full_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'dob' => optional($employee->dob)->format('Y-m-d'),
                'gender' => $employee->gender,
                'marital_status' => $employee->marital_status,
                'address' => $employee->address,
                'city' => $employee->city,
                'country' => $employee->country,
                'national_id' => $employee->national_id,
                'id_expiry_date' => optional($employee->id_expiry_date)->format('Y-m-d'),
                'passport_number' => $employee->passport_number,
                'passport_expiry_date' => optional($employee->passport_expiry_date)->format('Y-m-d'),
                'bank_name' => $employee->bank_name,
                'bank_account' => $employee->bank_account,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->title,
            ],
            'documents' => array_map(fn ($item, $field) => ['field' => $field] + $item, $this->documentFields(), array_keys($this->documentFields())),
            'expires_at' => optional($link->expires_at)->toIso8601String(),
        ]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $link = $this->findValidLink($token);
        if (!$link) {
            return response()->json(['message' => 'This onboarding link is invalid or expired.'], 404);
        }

        $rules = [
            'phone' => 'required|string|max:30',
            'dob' => 'required|date|before:today',
            'gender' => 'required|in:male,female,other',
            'marital_status' => 'required|in:single,married,divorced,widowed',
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'national_id' => 'required|string|max:50',
            'id_expiry_date' => 'nullable|date',
            'passport_number' => 'nullable|string|max:50',
            'passport_expiry_date' => 'nullable|date',
            'bank_name' => 'required|string|max:100',
            'bank_account' => ['required', 'string', 'max:24', 'regex:/^[SA0-9]+$/i'],
            'emergency_contact_name' => 'required|string|max:100',
            'emergency_contact_phone' => 'required|string|max:30',
        ];

        foreach ($this->documentFields() as $field => $meta) {
            $mimes = $field === 'filled_hdf'
                ? 'pdf,doc,docx'
                : 'pdf,doc,docx,jpg,jpeg,png,xls,xlsx';
            $rules[$field] = ($meta['required'] ? 'required' : 'nullable') . "|file|mimes:{$mimes}|max:10240";
        }

        $data = $request->validate($rules);
        $employee = $link->employee;
        $shouldNotifyDepartments = $link->submitted_at === null;

        $response = DB::transaction(function () use ($request, $data, $employee, $link): JsonResponse {
            $employee->update(collect($data)->only([
                'phone',
                'dob',
                'gender',
                'marital_status',
                'address',
                'city',
                'country',
                'national_id',
                'id_expiry_date',
                'passport_number',
                'passport_expiry_date',
                'bank_name',
                'bank_account',
                'emergency_contact_name',
                'emergency_contact_phone',
            ])->toArray());

            $uploaded = [];
            foreach ($this->documentFields() as $field => $meta) {
                if (!$request->hasFile($field)) {
                    continue;
                }

                $file = $request->file($field);
                $path = $file->store("employees/{$employee->id}/documents");
                $documentData = [
                    'employee_id' => $employee->id,
                    'title' => $meta['title'],
                    'type' => $meta['type'],
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'is_verified' => false,
                    'uploaded_by' => null,
                ];

                if ($field === 'filled_hdf') {
                    $previous = EmployeeDocument::where('employee_id', $employee->id)
                        ->where('title', 'Filled HDF')
                        ->first();
                    if ($previous?->file_path && $previous->file_path !== $path) {
                        \Illuminate\Support\Facades\Storage::delete($previous->file_path);
                    }
                    $uploaded[] = EmployeeDocument::updateOrCreate(
                        ['employee_id' => $employee->id, 'title' => 'Filled HDF'],
                        $documentData
                    );
                } else {
                    $uploaded[] = EmployeeDocument::create($documentData);
                }
            }

            $link->update(['submitted_at' => now()]);

            foreach ($uploaded as $document) {
                $this->notifyHrDocumentUploaded($employee, $document);
            }

            return response()->json([
                'message' => 'Onboarding details submitted successfully.',
                'uploaded_documents' => count($uploaded),
            ]);
        });

        if ($shouldNotifyDepartments) {
            $this->onboardingNotifications->notifyResponsibleDepartments($employee);
        }

        return $response;
    }

    private function documentFields(): array
    {
        $fields = $this->documentFields;
        if ($this->hdfTemplates->exists()) {
            $fields['filled_hdf'] = [
                'title' => 'Filled HDF',
                'type' => 'medical',
                'required' => false,
            ];
        }

        return $fields;
    }

    private function findValidLink(string $token): ?EmployeeOnboardingLink
    {
        if (strlen($token) < 40) {
            return null;
        }

        return EmployeeOnboardingLink::with('employee')
            ->where('token_hash', hash('sha256', $token))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->first();
    }

    private function notifyHrDocumentUploaded($employee, EmployeeDocument $document): void
    {
        try {
            $hrEmails = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['hr_manager', 'hr_staff']))
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values();

            if ($hrEmails->isEmpty()) {
                return;
            }

            $primaryEmail = $hrEmails->shift();
            Mail::to($primaryEmail)
                ->cc($hrEmails->all())
                ->send(new EmployeeDocumentUploadedMail($employee, $document));
        } catch (\Throwable $e) {
            Log::warning('Public onboarding document verification email failed.', [
                'employee_id' => $employee->id,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
