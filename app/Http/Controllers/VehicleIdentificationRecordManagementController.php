<?php

namespace App\Http\Controllers;

use App\Models\VehicleIdentificationRecordManagement;
use Illuminate\Contracts\View\View;

class VehicleIdentificationRecordManagementController extends Controller
{
    public function index(): View
    {
        return view('vehicle-identification-record-management.index');
    }

    public function edit(VehicleIdentificationRecordManagement $management): View
    {
        return view('vehicle-identification-record-management.form', [
            'management' => $management,
        ]);
    }
}
