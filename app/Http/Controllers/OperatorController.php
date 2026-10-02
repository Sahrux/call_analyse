<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Operator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OperatorController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $operators = Operator::query()
            ->withCount([
                'calls',
                'calls as completed_calls_count' => fn ($q) => $q->where('status', Call::STATUS_COMPLETED),
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Operators/Index', [
            'operators' => $operators,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Operators/Form', [
            'operator' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:50', 'unique:operators,employee_code'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        Operator::query()->create([
            ...$data,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('operators.index')->with('success', 'Operator əlavə olundu.');
    }

    public function edit(Operator $operator): Response
    {
        return Inertia::render('Operators/Form', [
            'operator' => $operator,
        ]);
    }

    public function update(Request $request, Operator $operator): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('operators', 'employee_code')->ignore($operator->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $operator->update([
            ...$data,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('operators.index')->with('success', 'Operator yeniləndi.');
    }

    public function destroy(Operator $operator): RedirectResponse
    {
        $operator->delete();

        return redirect()->route('operators.index')->with('success', 'Operator silindi.');
    }
}
