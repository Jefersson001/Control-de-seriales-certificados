<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public function create(): View
    {
        return view('products.form');
    }

    public function edit(Product $product): View
    {
        return view('products.form', ['product' => $product]);
    }
}
