<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Traits\ResponseTrait;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use ResponseTrait;

    /**
     * @OA\Post(
     *     path="/api/signup",
     *     tags={"Auth"},
     *     summary="User Signup",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "name": "Ujjwal",
     *                 "email": "ujjwal@gmail.com",
     *                 "password": "123456"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Signup successful"
     *     )
     * )
     */
    public function signup(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:6'
        ]);

        $userId = DB::table('users')->insertGetId([
            'name'       => $request->name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $user = User::find($userId);

        $tokenResult = $user->createToken('api_token', [], now()->addDays(7));
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Signup successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/signin",
     *     tags={"Auth"},
     *     summary="User Signin",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             example={
     *                  "email": "ujjwal@gmail.com",
     *                 "password": "123456"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Signin successful"
     *     )
     * )
     */
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

        $userModel = User::find($user->id);

        $tokenResult = $userModel->createToken('api_token', [], now()->addDays(7));
        $token = $tokenResult->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Signin successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/logout",
     *     tags={"Auth"},
     *     summary="Logout current token",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logged out successfully"
     *     )
     * )
     */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return $this->success(null, 'Logged out successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/logout-all",
     *     tags={"Auth"},
     *     summary="Logout from all devices",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logged out from all devices"
     *     )
     * )
     */
    public function logoutAll(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        return $this->success(null, 'Logged out from all devices');
    }

    /**
     * @OA\post(
     *     path="/api/tokenCheck",
     *     tags={"Auth"},
     *     summary="Check if token is valid",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Token is valid"
     *     )
     * )
     */
    public function tokenCheck(Request $request)
    {
        $token = $request->bearerToken();
        $validToken = PersonalAccessToken::findToken($token);

        if ($validToken) {
            if ($validToken->expires_at && $validToken->expires_at->isPast()) {
                return $this->fail('Token expired', 401);
            }
            return $this->success([], 'Token is valid');
        }

        return $this->fail('Invalid token', 401);
    }
}
