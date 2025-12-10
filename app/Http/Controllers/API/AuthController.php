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
     *     description="Register a new user account.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password"},
     *             @OA\Property(property="name", type="string", example="Ujjwal"),
     *             @OA\Property(property="email", type="string", example="ujjwal@gmail.com"),
     *             @OA\Property(property="password", type="string", example="123456")
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

        DB::beginTransaction();

        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $token = $user->createToken('api_token', [], now()->addDays(7))->plainTextToken;

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Signup successful',
                'user' => $user,
                'token' => $token
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Signup failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/signin",
     *     tags={"Auth"},
     *     summary="User Signin",
     *     description="Login using email and password.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", example="ujjwal@gmail.com"),
     *             @OA\Property(property="password", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Signin successful"),
     *     @OA\Response(response=401, description="Invalid password"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function signin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        DB::beginTransaction();

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!Hash::check($request->password, $user->password)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid password'
                ], 401);
            }

            $token = $user->createToken('api_token', [], now()->addDays(7))->plainTextToken;

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Signin successful',
                'user' => $user,
                'token' => $token
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Signin failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/logout",
     *     tags={"Auth"},
     *     summary="Logout current device",
     *     security={{"bearerAuth":{}}},
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
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logged out from all devices"
     *     )
     * )
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        return $this->success(null, 'Logged out from all devices');
    }

    /**
     * @OA\post(
     *     path="/api/tokenCheck",
     *     tags={"Auth"},
     *     summary="Check if token is valid",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Token is valid"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid or expired token"
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
