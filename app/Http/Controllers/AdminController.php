<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('dashboard');
    }

    public function resetPassword(Request $request): Response
    {
        return Inertia::render('reset-password', [
            'token' => $request->query('token'),
            'email' => $request->query('email'),
        ]);
    }
}
