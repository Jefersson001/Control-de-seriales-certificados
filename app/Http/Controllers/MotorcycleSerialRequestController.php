<?php

namespace App\Http\Controllers;

use App\Models\MotorcycleSerialRequest;
use Illuminate\Contracts\View\View;

class MotorcycleSerialRequestController extends Controller
{
    public function index(): View
    {
        return view('motorcycle-serial-requests.index');
    }

    public function create(): View
    {
        return view('motorcycle-serial-requests.form');
    }

    public function edit(MotorcycleSerialRequest $motorcycleSerialRequest): View
    {
        return view('motorcycle-serial-requests.form', [
            'motorcycleSerialRequest' => $motorcycleSerialRequest,
        ]);
    }
}
