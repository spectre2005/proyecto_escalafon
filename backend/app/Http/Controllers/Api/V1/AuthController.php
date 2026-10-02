<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        if (! Auth::attempt(['email_institucional' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'activo' => true])) {
            throw ValidationException::withMessages(['email' => 'Correo o contrasena incorrectos.']);
        }
        if (! $request->user()->isAdministrator()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Esta cuenta no tiene acceso administrativo.');
        }
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesion cerrada.']);
    }
}
