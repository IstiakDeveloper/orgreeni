<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $parentOnly = $request->input('parent_only', false);
        $search = $request->input('search');

        $categoriesQuery = Category::query();

        // Filter by parent_id
        if ($parentOnly) {
            $categoriesQuery->whereNull('parent_id');
        }

        // Search by name
        if ($search) {
            $categoriesQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('name_bn', 'like', "%{$search}%");
            });
        }

        // Order by position
        $categories = $categoriesQuery->orderBy('order')
            ->get();

        // Add count of children and products
        foreach ($categories as $category) {
            $category->children_count = Category::where('parent_id', $category->id)->count();
            $category->products_count = $category->products()->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories
            ]
        ]);
    }

    /**
     * Store a newly created category
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'description_bn' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create new category
        $category = new Category();
        $category->name = $request->name;
        $category->name_bn = $request->name_bn;
        $category->slug = Str::slug($request->name);
        $category->parent_id = $request->parent_id;
        $category->description = $request->description;
        $category->description_bn = $request->description_bn;
        $category->is_active = $request->is_active ?? true;
        $category->order = $request->order ?? 0;

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

            // Store to public disk in the categories folder
            $path = $image->storeAs('categories', $filename, 'public');

            // Save the full path to the database
            $category->image = $path;
        }

        $category->save();

        // Clear cache
        Cache::forget('categories_all');
        Cache::forget('categories_parent_only');

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => [
                'category' => $category
            ]
        ], 201);
    }



    /**
     * Display the specified category
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $category = Category::with(['parent', 'children'])->findOrFail($id);

        // Add products count
        $category->products_count = $category->products()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category
            ]
        ]);
    }

    /**
     * Update the specified category
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'description_bn' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Prevent category from being its own parent
        if ($request->parent_id && $request->parent_id == $id) {
            return response()->json([
                'success' => false,
                'message' => 'A category cannot be its own parent'
            ], 422);
        }

        // Prevent category from having one of its descendants as parent (to avoid cycles)
        if ($request->parent_id) {
            $descendantIds = $this->getAllDescendantIds($category);
            if (in_array($request->parent_id, $descendantIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot set a descendant category as parent'
                ], 422);
            }
        }

        // Update category
        $category->name = $request->name;
        $category->name_bn = $request->name_bn;

        // Only update slug if name has changed
        if ($category->name != $request->name) {
            $category->slug = Str::slug($request->name);
        }

        $category->parent_id = $request->parent_id;
        $category->description = $request->description;
        $category->description_bn = $request->description_bn;
        $category->is_active = $request->is_active ?? $category->is_active;
        $category->order = $request->order ?? $category->order;

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }

            $image = $request->file('image');
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

            // Store to public disk in the categories folder
            $path = $image->storeAs('categories', $filename, 'public');

            // Save the full path to the database
            $category->image = $path;
        }

        $category->save();

        // Clear cache
        Cache::forget('categories_all');
        Cache::forget('categories_parent_only');
        Cache::forget('category_' . $category->slug);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => [
                'category' => $category
            ]
        ]);
    }

    /**
     * Remove the specified category
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Check if category has children
        if ($category->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with subcategories. Please delete the subcategories first.'
            ], 422);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with associated products. Please reassign or delete the products first.'
            ], 422);
        }

        // Delete image if exists
        if ($category->image) {
            Storage::delete('public/categories/' . $category->image);
        }

        // Delete category
        $category->delete();

        // Clear cache
        Cache::forget('categories_all');
        Cache::forget('categories_parent_only');
        Cache::forget('category_' . $category->slug);

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }

    /**
     * Update category status
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

        $category = Category::findOrFail($id);
        $category->is_active = $request->is_active;
        $category->save();

        // Clear cache
        Cache::forget('categories_all');
        Cache::forget('categories_parent_only');
        Cache::forget('category_' . $category->slug);

        return response()->json([
            'success' => true,
            'message' => 'Category status updated successfully',
            'data' => [
                'category' => $category
            ]
        ]);
    }

    /**
     * Update category order
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->categories as $item) {
            Category::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        // Clear cache
        Cache::forget('categories_all');
        Cache::forget('categories_parent_only');

        return response()->json([
            'success' => true,
            'message' => 'Category order updated successfully'
        ]);
    }

    /**
     * Get all parent categories for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Get all parent categories for dropdown
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getParentsForDropdown()
    {
        $categories = Category::whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'name_bn']);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories
            ]
        ]);
    }

    /**
     * Get all descendant IDs of a category
     *
     * @param Category $category
     * @return array
     */
    private function getAllDescendantIds(Category $category)
    {
        $ids = [];

        $children = $category->children;

        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getAllDescendantIds($child));
        }

        return $ids;
    }
}
