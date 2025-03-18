<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductStock;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Unit;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Get all products with pagination and filters
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');
        $categoryId = $request->input('category_id');
        $brandId = $request->input('brand_id');
        $search = $request->input('search');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $productsQuery = Product::with(['category', 'brand', 'unit', 'images']);

        // Apply filters
        if ($status) {
            $productsQuery->where('status', $status);
        }

        if ($categoryId) {
            $productsQuery->where('category_id', $categoryId);
        }

        if ($brandId) {
            $productsQuery->where('brand_id', $brandId);
        }

        if ($search) {
            $productsQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('name_bn', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $productsQuery->orderBy($sortBy, $sortOrder);

        // Get paginated results
        $products = $productsQuery->paginate($perPage);

        // Add current stock count to each product
        foreach ($products as $product) {
            $product->current_stock = $product->getCurrentStock();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products
            ]
        ]);
    }

    /**
     * Get a specific product with detailed information
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $product = Product::with([
            'category',
            'brand',
            'unit',
            'images',
            'variants',
            'variants.stocks',
            'stocks',
            'attributeValues.attribute'
        ])->findOrFail($id);

        // Calculate current stock
        $product->current_stock = $product->getCurrentStock();

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product
            ]
        ]);
    }

    /**
     * Create new product
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
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku' => 'required|string|max:100|unique:products',
            'barcode' => 'nullable|string|max:100',
            'base_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'weight' => 'nullable|numeric|min:0',
            'is_vat_applicable' => 'nullable|boolean',
            'vat_percentage' => 'nullable|numeric|min:0|max:100',
            'is_featured' => 'nullable|boolean',
            'is_popular' => 'nullable|boolean',
            'stock_alert_quantity' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive,draft',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'stock_quantity' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Start transaction
        DB::beginTransaction();

        try {
            // Create product
            $product = new Product();
            $product->name = $request->name;
            $product->name_bn = $request->name_bn;
            $product->slug = Str::slug($request->name);
            $product->description = $request->description;
            $product->description_bn = $request->description_bn;
            $product->category_id = $request->category_id;
            $product->brand_id = $request->brand_id;
            $product->unit_id = $request->unit_id;
            $product->sku = $request->sku;
            $product->barcode = $request->barcode;
            $product->base_price = $request->base_price;
            $product->sale_price = $request->sale_price;
            $product->discount_percentage = $request->discount_percentage ?? 0;
            $product->weight = $request->weight;
            $product->is_vat_applicable = $request->is_vat_applicable ?? false;
            $product->vat_percentage = $request->vat_percentage ?? 0;
            $product->is_featured = $request->is_featured ?? false;
            $product->is_popular = $request->is_popular ?? false;
            $product->stock_alert_quantity = $request->stock_alert_quantity ?? 10;
            $product->status = $request->status;
            $product->meta_title = $request->meta_title;
            $product->meta_description = $request->meta_description;
            $product->meta_keywords = $request->meta_keywords;
            $product->save();

            // Handle image uploads
            if ($request->hasFile('images')) {
                $isPrimary = true; // First image is primary

                foreach ($request->file('images') as $image) {
                    $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                    $path = $image->storeAs('public/products', $filename);

                    $productImage = new ProductImage();
                    $productImage->product_id = $product->id;
                    $productImage->image = $filename;
                    $productImage->is_primary = $isPrimary;
                    $productImage->save();

                    $isPrimary = false; // Only first image is primary
                }
            }

            // Create initial stock if quantity provided
            if ($request->has('stock_quantity') && $request->stock_quantity > 0) {
                $stock = new ProductStock();
                $stock->product_id = $product->id;
                $stock->quantity = $request->stock_quantity;
                $stock->save();

                // Record inventory transaction
                InventoryTransaction::create([
                    'type' => 'purchase',
                    'product_id' => $product->id,
                    'quantity' => $request->stock_quantity,
                    'before_quantity' => 0,
                    'after_quantity' => $request->stock_quantity,
                    'reference_type' => 'initial_stock',
                    'reference_id' => $product->id,
                    'remarks' => "Initial stock for new product: {$product->name}",
                    'created_by' => $request->user()->id
                ]);
            }

            // Commit transaction
            DB::commit();

            // Load product with relations
            $product->load(['category', 'brand', 'unit', 'images', 'stocks']);

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => [
                    'product' => $product
                ]
            ], 201);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing product
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'description_bn' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku' => 'required|string|max:100|unique:products,sku,' . $id,
            'barcode' => 'nullable|string|max:100',
            'base_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'weight' => 'nullable|numeric|min:0',
            'is_vat_applicable' => 'nullable|boolean',
            'vat_percentage' => 'nullable|numeric|min:0|max:100',
            'is_featured' => 'nullable|boolean',
            'is_popular' => 'nullable|boolean',
            'stock_alert_quantity' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive,draft',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'new_images' => 'nullable|array',
            'new_images.*' => 'image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Start transaction
        DB::beginTransaction();

        try {
            // Update product
            $product->name = $request->name;
            $product->name_bn = $request->name_bn;

            // Only update slug if name has changed
            if ($product->name != $request->name) {
                $product->slug = Str::slug($request->name);
            }

            $product->description = $request->description;
            $product->description_bn = $request->description_bn;
            $product->category_id = $request->category_id;
            $product->brand_id = $request->brand_id;
            $product->unit_id = $request->unit_id;
            $product->sku = $request->sku;
            $product->barcode = $request->barcode;
            $product->base_price = $request->base_price;
            $product->sale_price = $request->sale_price;
            $product->discount_percentage = $request->discount_percentage ?? 0;
            $product->weight = $request->weight;
            $product->is_vat_applicable = $request->is_vat_applicable ?? false;
            $product->vat_percentage = $request->vat_percentage ?? 0;
            $product->is_featured = $request->is_featured ?? false;
            $product->is_popular = $request->is_popular ?? false;
            $product->stock_alert_quantity = $request->stock_alert_quantity ?? 10;
            $product->status = $request->status;
            $product->meta_title = $request->meta_title;
            $product->meta_description = $request->meta_description;
            $product->meta_keywords = $request->meta_keywords;
            $product->save();

            // Handle new image uploads
            if ($request->hasFile('new_images')) {
                // Check if product has any images
                $hasPrimaryImage = $product->images()->where('is_primary', true)->exists();

                foreach ($request->file('new_images') as $image) {
                    $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                    $path = $image->storeAs('public/products', $filename);

                    $productImage = new ProductImage();
                    $productImage->product_id = $product->id;
                    $productImage->image = $filename;

                    // Make first image primary if no primary image exists
                    if (!$hasPrimaryImage) {
                        $productImage->is_primary = true;
                        $hasPrimaryImage = true;
                    } else {
                        $productImage->is_primary = false;
                    }

                    $productImage->save();
                }
            }

            // Commit transaction
            DB::commit();

            // Load product with relations
            $product->load(['category', 'brand', 'unit', 'images', 'stocks', 'variants']);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => [
                    'product' => $product
                ]
            ]);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a product
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        // Start transaction
        DB::beginTransaction();

        try {
            // Delete related data
            $product->variants()->delete();
            $product->stocks()->delete();

            // Delete images
            foreach ($product->images as $image) {
                Storage::delete('public/products/' . $image->image);
                $image->delete();
            }

            // Delete product
            $product->delete();

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update product status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive,draft'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::findOrFail($id);
        $product->status = $request->status;
        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Product status updated successfully',
            'data' => [
                'product' => $product
            ]
        ]);
    }

    /**
     * Upload product images
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadImages(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::findOrFail($id);
        $uploadedImages = [];

        // Check if product has any primary image
        $hasPrimaryImage = $product->images()->where('is_primary', true)->exists();

        foreach ($request->file('images') as $image) {
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('public/products', $filename);

            $productImage = new ProductImage();
            $productImage->product_id = $product->id;
            $productImage->image = $filename;

            // Make first image primary if no primary image exists
            if (!$hasPrimaryImage) {
                $productImage->is_primary = true;
                $hasPrimaryImage = true;
            } else {
                $productImage->is_primary = false;
            }

            $productImage->save();
            $uploadedImages[] = $productImage;
        }

        return response()->json([
            'success' => true,
            'message' => 'Product images uploaded successfully',
            'data' => [
                'images' => $uploadedImages
            ]
        ]);
    }

    /**
     * Delete a product image
     *
     * @param int $id
     * @param int $imageId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImage($id, $imageId)
    {
        $product = Product::findOrFail($id);
        $image = ProductImage::where('product_id', $id)
            ->where('id', $imageId)
            ->firstOrFail();

        // Check if this is the primary image
        $isPrimary = $image->is_primary;

        // Delete the image file
        Storage::delete('public/products/' . $image->image);

        // Delete the image record
        $image->delete();

        // If deleted image was primary, set another image as primary if available
        if ($isPrimary) {
            $anotherImage = $product->images()->first();
            if ($anotherImage) {
                $anotherImage->is_primary = true;
                $anotherImage->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product image deleted successfully'
        ]);
    }

    /**
     * Set a primary image for the product
     *
     * @param int $id
     * @param int $imageId
     * @return \Illuminate\Http\JsonResponse
     */
    public function setPrimaryImage($id, $imageId)
    {
        $product = Product::findOrFail($id);

        // Reset all images to non-primary
        $product->images()->update(['is_primary' => false]);

        // Set new primary image
        $image = ProductImage::where('product_id', $id)
            ->where('id', $imageId)
            ->firstOrFail();

        $image->is_primary = true;
        $image->save();

        return response()->json([
            'success' => true,
            'message' => 'Primary image set successfully'
        ]);
    }

    /**
     * Add product variant
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function addVariant(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'sku' => 'required|string|max:100|unique:product_variants',
            'additional_price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::findOrFail($id);

        // Start transaction
        DB::beginTransaction();

        try {
            // Create variant
            $variant = new ProductVariant();
            $variant->product_id = $product->id;
            $variant->name = $request->name;
            $variant->name_bn = $request->name_bn;
            $variant->sku = $request->sku;
            $variant->additional_price = $request->additional_price;
            $variant->stock_quantity = 0; // Will be updated through stock
            $variant->is_active = $request->is_active ?? true;

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = time() . '_variant_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('public/products/variants', $filename);
                $variant->image = $filename;
            }

            $variant->save();

            // Create stock for variant
            if ($request->stock_quantity > 0) {
                $stock = new ProductStock();
                $stock->product_id = $product->id;
                $stock->product_variant_id = $variant->id;
                $stock->quantity = $request->stock_quantity;
                $stock->save();

                // Update variant stock quantity
                $variant->stock_quantity = $request->stock_quantity;
                $variant->save();

                // Record inventory transaction
                InventoryTransaction::create([
                    'type' => 'purchase',
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $request->stock_quantity,
                    'before_quantity' => 0,
                    'after_quantity' => $request->stock_quantity,
                    'reference_type' => 'initial_stock',
                    'reference_id' => $variant->id,
                    'remarks' => "Initial stock for new variant: {$variant->name}",
                    'created_by' => $request->user()->id
                ]);
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product variant added successfully',
                'data' => [
                    'variant' => $variant
                ]
            ], 201);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add product variant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update product variant
     *
     * @param Request $request
     * @param int $id
     * @param int $variantId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateVariant(Request $request, $id, $variantId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'sku' => 'required|string|max:100|unique:product_variants,sku,' . $variantId,
            'additional_price' => 'required|numeric|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::findOrFail($id);
        $variant = ProductVariant::where('product_id', $id)
            ->where('id', $variantId)
            ->firstOrFail();

        // Update variant
        $variant->name = $request->name;
        $variant->name_bn = $request->name_bn;
        $variant->sku = $request->sku;
        $variant->additional_price = $request->additional_price;
        $variant->is_active = $request->is_active ?? $variant->is_active;

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($variant->image) {
                Storage::delete('public/products/variants/' . $variant->image);
            }

            $image = $request->file('image');
            $filename = time() . '_variant_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('public/products/variants', $filename);
            $variant->image = $filename;
        }

        $variant->save();

        return response()->json([
            'success' => true,
            'message' => 'Product variant updated successfully',
            'data' => [
                'variant' => $variant
            ]
        ]);
    }

    /**
     * Delete product variant
     *
     * @param int $id
     * @param int $variantId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteVariant($id, $variantId)
    {
        $product = Product::findOrFail($id);
        $variant = ProductVariant::where('product_id', $id)
            ->where('id', $variantId)
            ->firstOrFail();

        // Start transaction
        DB::beginTransaction();

        try {
            // Delete variant stocks
            $variant->stocks()->delete();

            // Delete image if exists
            if ($variant->image) {
                Storage::delete('public/products/variants/' . $variant->image);
            }

            // Delete variant
            $variant->delete();

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product variant deleted successfully'
            ]);

        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product variant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dropdowns data for product form
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDropdownsData()
    {
        $categories = Category::where('is_active', true)->get();
        $brands = Brand::where('is_active', true)->get();
        $units = Unit::all();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'units' => $units
            ]
        ]);
    }
}
