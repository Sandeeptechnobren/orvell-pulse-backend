<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Traits\ResponseTrait;
use App\Models\Client;
use App\Models\Space;
use App\Models\space_iq;
use App\Models\User;
use App\Services\OtpService;
use App\Services\AuditService;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Orvell Auth",
 *     description="Authentication, Signup, Login & Password Reset APIs"
 * )
 */
class AuthController extends Controller
{
    use ResponseTrait;

    protected OtpService $otpService;
    protected AuditService $auditService;

    public function __construct(OtpService $otpService, AuditService $auditService)
    {
        $this->otpService = $otpService;
        $this->auditService = $auditService;
    }

    /**
     * @OA\Post(
     *     path="/api/signup",
     *     tags={"Orvell Auth"},
     *     summary="Register client & verify email using native Laravel SMTP OTP",
     *     description="
     * STEP 1: Send name, business details, email, password → OTP sent to email via native SMTP  
     * STEP 2: Send email + otp → signup completed, token issued
     * ",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="business_name", type="string", example="Orvell Space"),
     *             @OA\Property(property="business_location", type="string", example="Accra"),
     *             @OA\Property(property="phone_number", type="string", example="+233240000001"),
     *             @OA\Property(property="email", type="string", format="email", example="test@orvell.com"),
     *             @OA\Property(property="password", type="string", example="password123"),
     *             @OA\Property(property="otp", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(response=200, description="OTP sent or signup completed"),
     *     @OA\Response(response=400, description="Invalid request / Invalid OTP / OTP expired"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function register(Request $request)
    {
        /**
         * STEP 1: Register & Send OTP
         */
        if ($request->filled('name') && $request->filled('password') && !$request->filled('otp')) {
            $request->validate([
                'name'              => 'required|string|max:255',
                'business_name'     => 'required|string|max:255',
                'business_location' => 'required|string|max:255',
                'phone_number'      => 'required|string|max:30',
                'email'             => 'required|email|unique:clients,email',
                'password'          => 'required|min:8',
            ]);

            DB::beginTransaction();
            try {
                $client = Client::create([
                    'name'              => $request->name,
                    'business_name'     => $request->business_name,
                    'business_location' => $request->business_location,
                    'phone_number'      => $request->phone_number,
                    'email'             => $request->email,
                    'password'          => Hash::make($request->password),
                ]);

                $space = Space::create([
                    'client_id' => $client->id,
                    'name'      => $client->name,
                ]);

                space_iq::create([
                    'space_id' => $space->id,
                ]);

                // Send OTP via OtpService (native Laravel Mailer + SendOtpMail)
                $this->otpService->generateAndSend($client->email, 'register', 'ORVELL PULSE');

                $this->auditService->log(
                    action: 'auth.register_initiated',
                    auditable: $client,
                    newValues: ['email' => $client->email, 'business_name' => $client->business_name]
                );

                DB::commit();

                return response()->json([
                    'status'  => true,
                    'step'    => 'otp',
                    'message' => 'OTP sent to email',
                ], 200);
            } catch (\Illuminate\Validation\ValidationException $ve) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => $ve->getMessage(),
                    'errors'  => $ve->errors(),
                ], 422);
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('REGISTER ERROR', ['error' => $e->getMessage()]);
                return response()->json([
                    'status'  => false,
                    'message' => 'Registration failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        /**
         * STEP 2: Verify OTP & Complete Signup
         */
        if ($request->filled('email') && $request->filled('otp')) {
            $verifyResult = $this->otpService->verify($request->email, (string) $request->otp, 'register');

            if (!$verifyResult['status']) {
                return response()->json([
                    'status'  => false,
                    'message' => $verifyResult['message'],
                ], $verifyResult['code'] ?? 400);
            }

            $client = Client::where('email', $request->email)->first();
            if (!$client) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Client not found',
                ], 404);
            }

            $client->touch(); // Record verification
            $token = $client->createToken('api_token', ['*'], now()->addDays(7))->plainTextToken;

            $this->auditService->log(
                action: 'auth.register_completed',
                auditable: $client,
                newValues: ['email' => $client->email]
            );

            return response()->json([
                'status'  => true,
                'step'    => 'completed',
                'message' => 'Signup completed successfully',
                'user'    => $client,
                'token'   => $token,
            ], 200);
        }

        return response()->json([
            'status'  => false,
            'message' => 'Invalid request',
        ], 400);
    }

    /**
     * @OA\Post(
     *     path="/api/signin",
     *     tags={"Orvell Auth"},
     *     summary="Client / Staff login",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", example="test@orvell.com"),
     *             @OA\Property(property="password", type="string", example="password123")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Login successful"),
     *     @OA\Response(response=401, description="Invalid credentials")
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // 1. Try Client authentication
        $client = Client::where('email', $request->email)->first();
        if ($client && Hash::check($request->password, $client->password)) {
            $token = $client->createToken('api_token', ['*'], now()->addDays(7))->plainTextToken;

            $this->auditService->log(
                action: 'auth.login_client',
                auditable: $client
            );

            return response()->json([
                'status'  => true,
                'message' => 'Signin successful',
                'user'    => $client,
                'token'   => $token,
            ], 200);
        }

        // 2. Try User (Staff/Cashier/Admin) authentication
        $user = User::where('email', $request->email)->first();
        if ($user && Hash::check($request->password, $user->password)) {
            $token = $user->createToken('api_token', ['*'], now()->addDays(7))->plainTextToken;

            $this->auditService->log(
                action: 'auth.login_user',
                auditable: $user
            );

            return response()->json([
                'status'  => true,
                'message' => 'Signin successful',
                'user'    => $user,
                'token'   => $token,
            ], 200);
        }

        return response()->json([
            'status'  => false,
            'message' => 'Invalid credentials',
        ], 401);
    }

    /**
     * @OA\Post(
     *     path="/api/tokenCheck",
     *     tags={"Orvell Auth"},
     *     summary="Verify token validity and return authenticated user context",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Token is valid"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function tokenCheck(Request $request)
    {
        $user = $request->user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Token is valid',
            'data'    => [
                'user' => $user,
            ],
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/logout",
     *     tags={"Orvell Auth"},
     *     summary="Logout client / user",
     *     description="Revokes the current access token",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Logged out successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function logout(Request $request)
    {
        $user = $request->user() ?? auth('sanctum')->user();
        if ($user) {
            $user->currentAccessToken()?->delete();

            $this->auditService->log(
                action: 'auth.logout',
                auditable: $user
            );
        }

        return response()->json([
            'status'  => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/logoutall",
     *     tags={"Orvell Auth"},
     *     summary="Logout from all devices",
     *     description="Revokes all access tokens for the authenticated user",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Logged out from all sessions successfully")
     * )
     */
    public function logoutall(Request $request)
    {
        $user = $request->user() ?? auth('sanctum')->user();
        if ($user) {
            $user->tokens()->delete();

            $this->auditService->log(
                action: 'auth.logout_all',
                auditable: $user
            );
        }

        return response()->json([
            'status'  => true,
            'message' => 'Logged out from all devices successfully',
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/password-reset",
     *     tags={"Orvell Auth"},
     *     summary="Password reset flow using native SMTP OTP",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="email", type="string", example="test@orvell.com"),
     *             @OA\Property(property="otp", type="string", example="123456"),
     *             @OA\Property(property="new_password", type="string", example="newpassword123")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Password reset flow success"),
     *     @OA\Response(response=400, description="Invalid or expired OTP"),
     *     @OA\Response(response=404, description="Email not registered"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function passwordResetFlow(Request $request)
    {
        try {
            /**
             * STEP 1: Send OTP
             */
            if ($request->filled('email') && !$request->filled('otp')) {
                $client = Client::where('email', $request->email)->first();
                $user = User::where('email', $request->email)->first();

                if (!$client && !$user) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Email not registered',
                    ], 404);
                }

                $this->otpService->generateAndSend($request->email, 'reset', 'ORVELL PULSE');

                $this->auditService->log(
                    action: 'auth.password_reset_initiated',
                    auditable: $client ?? $user,
                    newValues: ['email' => $request->email]
                );

                return response()->json([
                    'status'  => true,
                    'step'    => 'otp',
                    'message' => 'OTP sent to email',
                ], 200);
            }

            /**
             * STEP 2: Verify OTP
             */
            if ($request->filled('email') && $request->filled('otp') && !$request->filled('new_password')) {
                $verifyResult = $this->otpService->verify($request->email, (string) $request->otp, 'reset');

                if (!$verifyResult['status']) {
                    return response()->json([
                        'status'  => false,
                        'message' => $verifyResult['message'],
                    ], $verifyResult['code'] ?? 400);
                }

                return response()->json([
                    'status'  => true,
                    'step'    => 'password',
                    'message' => 'OTP verified',
                ], 200);
            }

            /**
             * STEP 3: Reset Password
             */
            if ($request->filled('email') && $request->filled('otp') && $request->filled('new_password')) {
                $request->validate([
                    'new_password' => 'required|min:8',
                ]);

                $client = Client::where('email', $request->email)->first();
                $user = User::where('email', $request->email)->first();

                if (!$client && !$user) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Account not found',
                    ], 404);
                }

                $newPasswordHash = Hash::make($request->new_password);

                if ($client) {
                    $client->update(['password' => $newPasswordHash]);
                    $client->tokens()->delete();
                }
                if ($user) {
                    $user->update(['password' => $newPasswordHash]);
                    $user->tokens()->delete();
                }

                $this->auditService->log(
                    action: 'auth.password_reset_completed',
                    auditable: $client ?? $user,
                    newValues: ['email' => $request->email]
                );

                return response()->json([
                    'status'  => true,
                    'message' => 'Password reset successful',
                ], 200);
            }

            return response()->json([
                'status'  => false,
                'message' => 'Invalid request',
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'status'  => false,
                'message' => $ve->getMessage(),
                'errors'  => $ve->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('PASSWORD RESET ERROR', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Server error',
            ], 500);
        }
    }
}
