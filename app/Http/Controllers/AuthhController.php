<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Traits\ResponseTrait;
use App\Models\Client;
use App\Models\Space;
use App\Models\space_iq;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AuthhController extends Controller
{
    use ResponseTrait;
    public function register(Request $request)
    {
         
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
            $otp = rand(100000, 999999);
            DB::table('email_otps')->updateOrInsert(
                ['email' => $client->email],
                [
                    'otp'        => $otp,
                    'expires_at' => now()->addMinutes(10),
                    'updated_at' => now(),
                ]
            );
            Http::post(
                'https://apiadmin.schoolexl.com/index.php/api/v2/auth/send-otp',
                [
                    'type'     => 'reset',
                    'app_name' => 'Orvell',
                    'name'     => $client->email,
                    'email'    => $client->email,
                    'otp'      => (string) $otp,
                ]
            );
            DB::commit();
            return response()->json([
                'status'  => true,
                'step'    => 'otp',
                'message' => 'OTP sent to email',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }

    }

    //STEP 2
    if ($request->filled('email') && $request->filled('otp')) {

        $record = DB::table('email_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid OTP',
            ], 400);
        }

        if (now()->gt($record->expires_at)) {
            return response()->json([
                'status'  => false,
                'message' => 'OTP expired',
            ], 400);
        }

        $client = Client::where('email', $request->email)->first();

        if (!$client) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        // mark email verified
        $client->update([
            'email_verified_at' => now(),
        ]);

        // remove OTP
        DB::table('email_otps')->where('email', $request->email)->delete();

        // NOW signup is complete → issue token
        $token = $client
            ->createToken('api_token', [], now()->addDays(7))
            ->plainTextToken;

        return response()->json([
            'status'  => true,
            'step'    => 'completed',
            'message' => 'Signup completed successfully',
            'user'    => $client,
            'token'   => $token,
        ]);
    }

    return response()->json([
        'status' => false,
        'message' => 'Invalid request',
    ], 400);
    }


    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        try {
            $client = Client::where('email', $request->email)->first();

            if (!$client) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User not found',
                ], 404);
            }

            if (!Hash::check($request->password, $client->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid password',
                ], 401);
            }

            $token = $client
                ->createToken('api_token', [], now()->addDays(7))
                ->plainTextToken;

            return response()->json([
                'status'  => true,
                'message' => 'Signin successful',
                'user'    => $client,
                'token'   => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Signin failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'status'  => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Logged out from all devices',
        ]);
    }

    public function tokenCheck(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status'  => false,
                'message' => 'Token not provided',
            ], 401);
        }

        $validToken = PersonalAccessToken::findToken($token);

        if (!$validToken) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid token',
            ], 401);
        }

        if ($validToken->expires_at && $validToken->expires_at->isPast()) {
            return response()->json([
                'status'  => false,
                'message' => 'Token expired',
            ], 401);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Token is valid',
        ]);
    }

    
    public function passwordResetFlow(Request $request)
{
    try {
        Log::info('RESET PASSWORD HIT', $request->all());

        // STEP 1: Email → OTP
        if ($request->filled('email') && !$request->filled('otp')) {

            $client = Client::where('email', $request->email)->first();

            if (!$client) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email not registered'
                ], 404);
            }

            $otp = rand(100000, 999999);

            Log::info('OTP GENERATED', ['otp' => $otp]);

            // delete old
            DB::table('password_resets')
                ->where('email', $request->email)
                ->delete();

            // insert new
            DB::table('password_resets')->insert([
                'email' => $request->email,
                'otp' => $otp,
                'token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

             $sendOtp = Http::post('https://apiadmin.schoolexl.com/index.php/api/v2/auth/send-otp', [
            'type'     => 'reset',
            'app_name' => 'Croose',
            'name'     => $client->email,
            'email'    => $client->email,
            'otp'      => (string) $otp,
        ]);


            Log::info('OTP INSERTED IN DB');

            return response()->json([
                'status' => true,
                'step' => 'otp',
                'message' => 'OTP sent',
            ]);
        }

        // STEP 2: OTP verify
        if ($request->filled('email') && $request->filled('otp') && !$request->filled('new_password')) {

            $record = DB::table('password_resets')
                ->where('email', $request->email)
                ->where('otp', $request->otp)
                ->first();

            Log::info('OTP VERIFY QUERY RESULT', (array) $record);

            if (!$record) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            return response()->json([
                'status' => true,
                'step' => 'password',
                'message' => 'OTP verified'
            ]);
        }

        // STEP 3: Reset password
        if ($request->filled('email') && $request->filled('otp') && $request->filled('new_password')) {

            $client = Client::where('email', $request->email)->first();

            $client->update([
                'password' => Hash::make($request->new_password)
            ]);

            DB::table('password_resets')
                ->where('email', $request->email)
                ->delete();

            Log::info('PASSWORD RESET DONE');

            return response()->json([
                'status' => true,
                'message' => 'Password reset successful'
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Invalid request'
        ], 400);

    } catch (\Exception $e) {

        Log::error('RESET PASSWORD ERROR', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Server error',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
