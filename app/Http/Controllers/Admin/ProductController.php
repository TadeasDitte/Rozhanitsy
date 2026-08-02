<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        $vendors = Vendor::query()
            ->with(['products' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Vendor $vendor): array => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'products' => $vendor->products->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'type' => $product->type,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Products', [
            'vendors' => $vendors,
            'types' => Product::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'vendor_name' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(Product::TYPES)],
        ]);

        $vendor = Vendor::firstOrCreate(
            ['slug' => Str::slug($validated['vendor_name'])],
            ['name' => $validated['vendor_name']],
        );

        try {
            $vendor->products()->create([
                'name' => $validated['product_name'],
                'slug' => Str::slug($validated['product_name']),
                'type' => $validated['type'],
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'product_name' => 'This product already exists for that vendor.',
            ]);
        }

        return back();
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back();
    }
}
