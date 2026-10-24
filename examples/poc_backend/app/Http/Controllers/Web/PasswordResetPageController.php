<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use App\Support\Api\ApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Browser page opened from the reset email (route name password.reset, used by
 * Laravel's ResetPassword notification).
 */
class PasswordResetPageController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    public function show(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function store(Request $request): View
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        try {
            $this->resets->resetByEmail($data['email'], $data['token'], $data['password']);
        } catch (ApiException $e) {
            return view('auth.reset-password', ['token' => $data['token'], 'email' => $data['email'], 'failure' => $e->getMessage()]);
        }

        return view('auth.reset-password-done');
    }
}
