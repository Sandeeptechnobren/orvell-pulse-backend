<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Traits\ResponseTrait;
use App\Models\User;

class AuthController extends Controller
{
    use ResponseTrait;
    // Signup
    public function signup(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:6'
        ]);
        
        // Insert user in DB
        $userId = DB::table('users')->insertGetId([
            'name'       => $request->name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Get user as Eloquent model to generate token
        $user = User::find($userId);

        // Create token using Sanctum
        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Signup successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    // Signin
    public function signin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        $user = DB::table('users')->where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid password'
            ], 401);
        }

        // Convert DB user to Eloquent model to generate token
        $userModel = User::find($user->id);

        // Create new token
        $token = $userModel->createToken('api_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Signin successful',
            'user' => $user,
            'token' => $token
        ]);
    }
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return $this->success(null, 'Logged out successfully');
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        return $this->success(null, 'Logged out from all devices');
    }

    public function tokenCheck(Request $request)
    {
        $token = $request->bearerToken();
        $validToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if ($validToken) {
            return $this->success([], 'Token is valid');
        }
        return $this->fail('Invalid or expired token', 401);
    }

}
