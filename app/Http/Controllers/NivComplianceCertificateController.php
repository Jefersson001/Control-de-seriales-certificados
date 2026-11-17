<?php

namespace App\Http\Controllers;

use App\Models\NivComplianceCertificate;
use Illuminate\Contracts\View\View;

class NivComplianceCertificateController extends Controller
{
    public function index(): View
    {
        return view('niv-compliance-certificates.index');
    }

    public function create(): View
    {
        return view('niv-compliance-certificates.form');
    }

    public function edit(NivComplianceCertificate $certificate): View
    {
        return view('niv-compliance-certificates.form', ['certificate' => $certificate]);
    }
}
