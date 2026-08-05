<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;

class UserManagementController extends Controller
{
    public function create(): View
    {
        return view('users.form');
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user]);
    }
}
