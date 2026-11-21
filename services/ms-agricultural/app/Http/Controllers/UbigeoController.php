<?php

namespace App\Http\Controllers;

use App\Support\UbigeoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UbigeoController extends Controller
{
    public function index(Request $request, UbigeoCatalog $catalog): JsonResponse
    {
        $input = $request->validate([
            'level' => ['required', Rule::in(['departamento', 'provincia', 'distrito'])],
            'parent' => ['nullable', 'string', 'regex:/^\d{2}(\d{2})?$/D'],
        ]);
        return response()->json(['data' => $catalog->options($input['level'], $input['parent'] ?? null)]);
    }
}
