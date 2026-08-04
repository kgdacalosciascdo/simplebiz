<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\Attachment;
use App\Models\Company;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AttachmentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function upload(UploadedFile $file, Model $record, Company $company, Request $request): Attachment
    {
        if ((int) $record->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The requested record is outside the current company scope.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $recordType = $record::class;
        $existing = Attachment::where('company_id', $company->id)->where('record_type', $recordType)->where('record_id', $record->id)->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = "cash-accounts/{$company->id}/movement-evidence/{$id}.{$extension}";
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $attachment = Attachment::create(['id' => $id, 'company_id' => $company->id, 'owner_module' => 'cash-accounts', 'record_type' => $recordType, 'record_id' => $record->id, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'disk' => 'local', 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize(), 'file_hash' => $hash, 'sensitivity' => 'confidential', 'uploaded_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit->record($request, 'cash-account.evidence.uploaded', $record, $company->id, [], ['attachment_id' => $attachment->id, 'filename' => $attachment->original_filename, 'file_hash' => $hash], null, 'Evidence uploaded', 'Evidence was uploaded to a governed Cash Account document.');

        return $attachment;
    }

    public function download(Attachment $attachment, Company $company, Request $request)
    {
        if ((int) $attachment->company_id !== (int) $company->id || $attachment->status !== 'active') {
            throw new RegistryConflictException('The requested evidence is outside the current company scope.');
        }
        if (! Storage::disk($attachment->disk)->exists($attachment->stored_path)) {
            throw new RegistryConflictException('The requested evidence is no longer available.');
        }
        $this->audit->record($request, 'cash-account.evidence.downloaded', null, $company->id, [], ['attachment_id' => $attachment->id, 'filename' => $attachment->original_filename], null, 'Evidence downloaded', 'Cash Account evidence was downloaded.');

        return Storage::disk($attachment->disk)->download($attachment->stored_path, $attachment->original_filename, ['Content-Type' => $attachment->mime_type]);
    }
}
