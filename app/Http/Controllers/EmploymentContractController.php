<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Employment contracts -- HR Phase B. One Employee can have many contracts over time (a renewal is a new
 * row, never an edit of the old one's dates); see EmploymentContract's own docblock for the immutability
 * rule once a contract leaves draft. Document attachments are private (never on the public disk) and are
 * only ever served through the authorized `document()` action below, following the exact same pattern
 * PaymentController::proof() already established for payment-proof uploads. */
class EmploymentContractController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('contract-view') || $user->can('contract-manage') || $user->hasRole('auditor');
    }

    private function assertEmployeeAccess(User $user, Employee $employee): void
    {
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);
    }

    private function rules(): array
    {
        return [
            'contract_type' => ['required', Rule::in(EmploymentContract::CONTRACT_TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'status' => ['required', Rule::in(EmploymentContract::STATUSES)],
            'salary_reference' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'working_schedule' => ['nullable', 'string', 'max:255'],
            'renewal_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['pdf'])],
        ];
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('contract-manage'), 403);
        $this->assertEmployeeAccess($user, $employee);

        $validated = $request->validate($this->rules());

        $contract = $employee->contracts()->create([
            ...$validated,
            'created_by' => $user->id,
        ]);

        if ($request->hasFile('document')) {
            $this->storeDocument($contract, $request->file('document'));
        }

        return redirect()->route('employees.show', $employee)->with('success', 'Contract added.');
    }

    public function update(Request $request, EmploymentContract $employmentContract): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('contract-manage'), 403);
        $this->assertEmployeeAccess($user, $employmentContract->employee);

        $validated = $request->validate($this->rules());

        $employmentContract->update($validated);

        if ($request->hasFile('document')) {
            $this->storeDocument($employmentContract, $request->file('document'));
        }

        return redirect()->route('employees.show', $employmentContract->employee)->with('success', 'Contract updated.');
    }

    private function storeDocument(EmploymentContract $contract, $file): void
    {
        $old = $contract->document_path;
        $contract->update([
            'document_path' => SafeUpload::storePrivate($file, 'employment-contracts', ['pdf']),
            'document_original_name' => $file->getClientOriginalName(),
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }

    public function destroy(Request $request, EmploymentContract $employmentContract): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('contract-manage'), 403);
        $this->assertEmployeeAccess($user, $employmentContract->employee);
        $employee = $employmentContract->employee;

        try {
            if ($employmentContract->document_path) {
                Storage::disk('local')->delete($employmentContract->document_path);
            }
            $employmentContract->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['contract' => $e->getMessage()]);
        }

        return redirect()->route('employees.show', $employee)->with('success', 'Contract removed.');
    }

    public function document(Request $request, EmploymentContract $employmentContract): SymfonyResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $this->assertEmployeeAccess($user, $employmentContract->employee);
        abort_unless($employmentContract->document_path && Storage::disk('local')->exists($employmentContract->document_path), 404);

        [$ext, $mime] = SafeUpload::servingType($employmentContract->document_path);

        return Storage::disk('local')->response($employmentContract->document_path, "contract-{$employmentContract->id}.{$ext}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
