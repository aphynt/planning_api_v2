<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    //
    public function login(Request $request)
    {
        $request->validate([
            'nik' => 'required',
            'password' => 'required',
        ],
            [
                'nik.required' => 'NIK harus diisi!',
                'password.required' => 'Password harus diisi!',
            ]
        );

        try {
            $user = User::where('nik', $request->nik)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Login gagal. NIK atau password salah.',
                    'copyright' => env('COPYRIGHT'),
                ], 401);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'Berhasil login',
                'token' => $token,
                'user' => $user,
                'copyright' => env('COPYRIGHT'),
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal login, silakan coba kembali',
                'error' => $th->getMessage(),
            ], 500);
        }

    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

    return response()->json([
        'message' => 'Logout berhasil',
    ]);
    }
}
