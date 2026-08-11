<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use Illuminate\Contracts\View\View;

class DispatchController extends Controller
{
    public function index(): View
    {
        return view('dispatches.index');
    }

    public function create(): View
    {
        return view('dispatches.form');
    }

    public function edit(Dispatch $dispatch): View
    {
        return view('dispatches.form', compact('dispatch'));
    }
}
