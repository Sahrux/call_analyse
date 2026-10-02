<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperatorController extends Controller
{
    public function index(Request $request): JsonResponse
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
            ->paginate((int) $request->integer('per_page', 12));

        return response()->json([
            'operators' => $operators,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:50', 'unique:operators,employee_code'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $operator = Operator::query()->create([
            ...$data,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Operator əlavə olundu.',
            'operator' => $operator,
        ], 201);
    }

    public function show(Operator $operator): JsonResponse
    {
        return response()->json(['operator' => $operator]);
    }

    public function update(Request $request, Operator $operator): JsonResponse
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

        return response()->json([
            'message' => 'Operator yeniləndi.',
            'operator' => $operator->fresh(),
        ]);
    }

    public function destroy(Operator $operator): JsonResponse
    {
        $operator->delete();

        return response()->json(['message' => 'Operator silindi.']);
    }
}
