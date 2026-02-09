<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Traits\ResponseTrait;
use App\Models\Client;
use App\Models\Space;
use App\Models\space_iq;
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

    /**
     * @OA\Post(
     *     path="/api/register",
     *     tags={"Orvell Auth"},
     *     summary="Register client & verify email using OTP (Redis)",
     *     description="
     * STEP 1: Send name, business details, email, password → OTP sent  
     * STEP 2: Send email + otp → signup completed
     * ",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="business_name", type="string", example="Orvell Space"),
     *             @OA\Property(property="business_location", type="string", example="New Delhi"),
     *             @OA\Property(property="phone_number", type="string", example="9999999999"),
     *             @OA\Property(property="email", type="string", format="email", example="test@orvell.com"),
     *             @OA\Property(property="password", type="string", example="password123"),
     *             @OA\Property(property="otp", type="integer", example=123456)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="OTP sent or signup completed"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request / Invalid OTP / OTP expired"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function register(Request $request)
    {
        /**
         * STEP 1: Register & Send OTP
         */
        if ($request->filled('name') && $request->filled('password')) {

            $request->validate([
                'name'              => 'required|string|max:255',
                'business_name'     => 'required|string|max:255',
                'business_location' => 'required|string|max:255',
                'phone_number'      => 'required|string|max:20',
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
                    'email_verified_at' => null,
                ]);

                $space = Space::create([
                    'client_id' => $client->id,
                    'name'      => $client->name,
                ]);

                space_iq::create([
                    'space_id' => $space->id,
                ]);

                // Redis OTP (10 min TTL)
                $otp = rand(100000, 999999);
                Redis::setex("otp:orvell:signup:{$client->email}", 600, $otp);

                Http::post('https://apiadmin.schoolexl.com/index.php/api/v2/auth/send-otp', [
                    'type'     => 'register',
                    'app_name' => 'Orvell',
                    'email'    => $client->email,
                    'otp'      => (string) $otp,
                ]);

                DB::commit();

                return response()->json([
                    'status'  => true,
                    'step'    => 'otp',
                    'message' => 'OTP sent to email',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('REGISTER ERROR', ['error' => $e->getMessage()]);
                return response()->json([
                    'status'  => false,
                    'message' => 'Registration failed',
                ], 500);
            }
        }

        /**
         * STEP 2: Verify OTP
         */
        if ($request->filled('email') && $request->filled('otp')) {

            $key = "otp:orvell:signup:{$request->email}";
            $storedOtp = Redis::get($key);

            if (!$storedOtp) {
                return response()->json([
                    'status'  => false,
                    'message' => 'OTP expired',
                ], 400);
            }

            if ($storedOtp != $request->otp) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid OTP',
                ], 400);
            }

            Redis::del($key);

            $client = Client::where('email', $request->email)->firstOrFail();
            $client->update(['email_verified_at' => now()]);

            $token = $client->createToken('api_token', [], now()->addDays(7))->plainTextToken;

            return response()->json([
                'status'  => true,
                'step'    => 'completed',
                'message' => 'Signup completed successfully',
                'user'    => $client,
                'token'   => $token,
            ]);
        }

        return response()->json([
            'status'  => false,
            'message' => 'Invalid request',
        ], 400);
    }

    /**
     * @OA\Post(
     *     path="/api/login",
     *     tags={"Orvell Auth"},
     *     summary="Client login",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", example="test@orvell.com"),
     *             @OA\Property(property="password", type="string", example="password123")
     *         )
     *     ),
     *
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

        $client = Client::where('email', $request->email)->first();

        if (!$client || !Hash::check($request->password, $client->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $client->createToken('api_token', [], now()->addDays(7))->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Signin successful',
            'user'    => $client,
            'token'   => $token,
        ]);
    }


/**
 * @OA\Post(
 *     path="/api/logout",
 *     tags={"Orvell Auth"},
 *     summary="Logout client",
 *     description="Logout authenticated client by revoking current access token",
 *     security={{"sanctum":{}}},
 *
 *     @OA\Response(
 *         response=200,
 *         description="Logged out successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Logged out successfully")
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=401,
 *         description="Unauthenticated"
 *     )
 * )
 */
public function logout(Request $request)
{
    $request->user()->currentAccessToken()?->delete();

    return response()->json([
        'status'  => true,
        'message' => 'Logged out successfully',
    ]);
}


    /**
     * @OA\Post(
     *     path="/api/password-reset",
     *     tags={"Orvell Auth"},
     *     summary="Password reset flow with OTP (Redis)",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="email", type="string", example="test@orvell.com"),
     *             @OA\Property(property="otp", type="integer", example=123456),
     *             @OA\Property(property="new_password", type="string", example="newpassword123")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Password reset flow success"),
     *     @OA\Response(response=400, description="Invalid or expired OTP"),
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
                if (!$client) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Email not registered'
                    ], 404);
                }

                $otp = rand(100000, 999999);
                Redis::setex("otp:orvell:forgot:{$client->email}", 600, $otp);

                Http::post('https://apiadmin.schoolexl.com/index.php/api/v2/auth/send-otp', [
                    'type'     => 'reset',
                    'app_name' => 'Orvell',
                    'email'    => $client->email,
                    'otp'      => (string) $otp,
                ]);

                return response()->json([
                    'status' => true,
                    'step'   => 'otp',
                    'message'=> 'OTP sent to email'
                ]);
            }

            /**
             * STEP 2: Verify OTP
             */
            if ($request->filled('email') && $request->filled('otp') && !$request->filled('new_password')) {

                $key = "otp:orvell:forgot:{$request->email}";
                $storedOtp = Redis::get($key);

                if (!$storedOtp || $storedOtp != $request->otp) {
                    return response()->json([
                        'status' => false,
                        'message'=> 'Invalid or expired OTP'
                    ], 400);
                }

                return response()->json([
                    'status' => true,
                    'step'   => 'password',
                    'message'=> 'OTP verified'
                ]);
            }

            /**
             * STEP 3: Reset Password
             */
            if ($request->filled('email') && $request->filled('otp') && $request->filled('new_password')) {

                $request->validate([
                    'new_password' => 'required|min:8'
                ]);

                $key = "otp:orvell:forgot:{$request->email}";
                $storedOtp = Redis::get($key);

                if (!$storedOtp || $storedOtp != $request->otp) {
                    return response()->json([
                        'status' => false,
                        'message'=> 'Invalid or expired OTP'
                    ], 400);
                }

                Redis::del($key);

                $client = Client::where('email', $request->email)->firstOrFail();
                $client->update([
                    'password' => Hash::make($request->new_password)
                ]);

                return response()->json([
                    'status' => true,
                    'message'=> 'Password reset successful'
                ]);
            }

            return response()->json([
                'status' => false,
                'message'=> 'Invalid request'
            ], 400);

        } catch (\Exception $e) {
            Log::error('PASSWORD RESET ERROR', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message'=> 'Server error'
            ], 500);
        }
    }
}
