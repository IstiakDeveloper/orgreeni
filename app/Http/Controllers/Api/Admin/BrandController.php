<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    /**
     * Display a listing of the brands
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 15);

        $brandsQuery = Brand::query();

        // Apply search filter
        if ($search) {
            $brandsQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('name_bn', 'like', "%{$search}%");
            });
        }

        // Get paginated results ordered by name
        $brands = $brandsQuery->orderBy('name')->paginate($perPage);

        // Add product count to each brand
        foreach ($brands as $brand) {
            $brand->products_count = $brand->products()->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'brands' => $brands
            ]
        ]);
    }

    /**
     * Store a newly created brand
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'description_bn' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create new brand
        $brand = new Brand();
        $brand->name = $request->name;
        $brand->name_bn = $request->name_bn;
        $brand->slug = Str::slug($request->name);
        $brand->description = $request->description;
        $brand->description_bn = $request->description_bn;
        $brand->is_active = $request->is_active ?? true;

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo');
            $filename = time() . '_' . Str::random(10) . '.' . $logo->getClientOriginalExtension();
            $path = $logo->storeAs('public/brands', $filename);
            $brand->logo = $filename;
        }

        $brand->save();

        // Clear cache
        Cache::forget('brands_all');

        return response()->json([
            'success' => true,
            'message' => 'Brand created successfully',
            'data' => [
                'brand' => $brand
            ]
        ], 201);
    }

    /**
     * Display the specified brand
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $brand = Brand::findOrFail($id);

        // Add products count
        $brand->products_count = $brand->products()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'brand' => $brand
            ]
        ]);
    }

    /**
     * Update the specified brand
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $brand = Brand::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'description_bn' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update brand
        $brand->name = $request->name;
        $brand->name_bn = $request->name_bn;

        // Only update slug if name has changed
        if ($brand->name != $request->name) {
            $brand->slug = Str::slug($request->name);
        }

        $brand->description = $request->description;
        $brand->description_bn = $request->description_bn;
        $brand->is_active = $request->is_active ?? $brand->is_active;

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($brand->logo) {
                Storage::delete('public/brands/' . $brand->logo);
            }

            $logo = $request->file('logo');
            $filename = time() . '_' . Str::random(10) . '.' . $logo->getClientOriginalExtension();
            $path = $logo->storeAs('public/brands', $filename);
            $brand->logo = $filename;
        }

        $brand->save();

        // Clear cache
        Cache::forget('brands_all');

        return response()->json([
            'success' => true,
            'message' => 'Brand updated successfully',
            'data' => [
                'brand' => $brand
            ]
        ]);
    }

    /**
     * Remove the specified brand
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $brand = Brand::findOrFail($id);

        // Check if brand has products
        if ($brand->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete brand with associated products. Please reassign or delete the products first.'
            ], 422);
        }

        // Delete logo if exists
        if ($brand->logo) {
            Storage::delete('public/brands/' . $brand->logo);
        }

        // Delete brand
        $brand->delete();

        // Clear cache
        Cache::forget('brands_all');

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully'
        ]);
    }

    /**
     * Update brand status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $brand = Brand::findOrFail($id);
        $brand->is_active = $request->is_active;
        $brand->save();

        // Clear cache
        Cache::forget('brands_all');

        return response()->json([
            'success' => true,
            'message' => 'Brand status updated successfully',
            'data' => [
                'brand' => $brand
            ]
        ]);
    }

    /**
     * Get all brands for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBrandsForDropdown()
    {
        $brands = Brand::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'name_bn', 'logo']);

        return response()->json([
            'success' => true,
            'data' => [
                'brands' => $brands
            ]
        ]);
    }
}
