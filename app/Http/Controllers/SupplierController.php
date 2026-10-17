<?php

namespace App\Http\Controllers;

use App\Models\NumberSequence;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('supplier-manage'), 403);

        $search = trim((string) $request->query('search', ''));

        $suppliers = Supplier::where('school_id', $user->school_id)
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'contact_person' => $s->contact_person,
                'phone' => $s->phone,
            ]);

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'search' => $search,
            'canManage' => $user->can('supplier-manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('supplier-manage'), 403);

        return Inertia::render('Suppliers/Create');
    }

    private function nextCode(int $schoolId): string
    {
        $n = NumberSequence::next($schoolId, 'supplier', (int) date('Y'), fn () => Supplier::where('school_id', $schoolId)
            ->where('code', 'like', 'SUP-%')
            ->pluck('code')
            ->map(fn ($x) => (int) substr($x, 4))
            ->max() ?? 0);

        return 'SUP-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function rules(Request $request, ?Supplier $supplier = null): array
    {
        $schoolId = $request->user()->school_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-manage'), 403);

        $validated = $request->validate($this->rules($request));

        $supplier = Supplier::create([
            'school_id' => $user->school_id,
            'code' => $this->nextCode($user->school_id),
        ] + $validated);

        return redirect()->route('suppliers.show', $supplier)->with('success', "Supplier {$supplier->code} added.");
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $user = $request->user();
        abort_unless($supplier->school_id === $user->school_id, 403);
        abort_unless($user->canViewOrManage('supplier-manage'), 403);

        return Inertia::render('Suppliers/Show', [
            'supplier' => $supplier,
            'canManage' => $user->can('supplier-manage'),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-manage'), 403);
        abort_unless($supplier->school_id === $user->school_id, 403);

        $supplier->update($request->validate($this->rules($request, $supplier)));

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-manage'), 403);
        abort_unless($supplier->school_id === $user->school_id, 403);

        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Supplier archived (kept for history).');
    }
}
