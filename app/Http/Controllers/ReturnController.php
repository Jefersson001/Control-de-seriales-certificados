<?php

namespace App\Http\Controllers;

use App\Models\ProductReturn;
use Illuminate\Contracts\View\View;

class ReturnController extends Controller
{
    public function index(): View
    {
        return view('returns.index');
    }

    public function create(): View
    {
        return view('returns.form');
    }

    public function edit(ProductReturn $return): View
    {
        return view('returns.form', compact('return'));
    }
}
