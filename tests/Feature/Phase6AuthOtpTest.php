<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use App\Models\Client;
use App\Models\User;
use App\Models\EmailOtp;
use App\Models\AuditLog;
use App\Mail\SendOtpMail;
use App\Services\OtpService;
use App\Services\AuditService;

class Phase6AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_generation_and_mail_dispatch(): void
    {
        Mail::fake();

        $otpService = app(OtpService::class);
        $result = $otpService->generateAndSend('testbuyer@orvell.com', 'register', 'ORVELL PULSE');

        $this->assertTrue($result['status']);
        $this->assertEquals('otp', $result['step']);

        // Assert mail dispatched to correct recipient
        Mail::assertSent(SendOtpMail::class, function ($mail) {
            return $mail->hasTo('testbuyer@orvell.com')
                && strlen($mail->otp) === 6
                && $mail->type === 'register';
        });

        // Assert OTP is securely hashed in database, not stored in plaintext
        $record = EmailOtp::where('email', 'testbuyer@orvell.com')->first();
        $this->assertNotNull($record);
        $this->assertNotEquals('123456', $record->otp_hash);
        $this->assertTrue(Hash::info($record->otp_hash)['algo'] !== null);
    }

    public function test_correct_otp_verification(): void
    {
        $otpService = app(OtpService::class);

        // Create an OTP record with known code
        EmailOtp::create([
            'email'       => 'verify@orvell.com',
            'otp_hash'    => Hash::make('654321'),
            'type'        => 'register',
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        $result = $otpService->verify('verify@orvell.com', '654321', 'register');

        $this->assertTrue($result['status']);
        $this->assertEquals('OTP verified successfully', $result['message']);

        // Assert record is now verified (single-use)
        $record = EmailOtp::where('email', 'verify@orvell.com')->first();
        $this->assertNotNull($record->verified_at);
    }

    public function test_incorrect_otp_increments_attempts(): void
    {
        $otpService = app(OtpService::class);

        EmailOtp::create([
            'email'       => 'wrong@orvell.com',
            'otp_hash'    => Hash::make('112233'),
            'type'        => 'register',
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        $result = $otpService->verify('wrong@orvell.com', '999999', 'register');

        $this->assertFalse($result['status']);
        $this->assertStringContainsString('Invalid OTP', $result['message']);

        $record = EmailOtp::where('email', 'wrong@orvell.com')->first();
        $this->assertEquals(1, $record->attempts);
        $this->assertNull($record->verified_at);
    }

    public function test_expired_otp_rejection(): void
    {
        $otpService = app(OtpService::class);

        EmailOtp::create([
            'email'       => 'expired@orvell.com',
            'otp_hash'    => Hash::make('123456'),
            'type'        => 'register',
            'expires_at'  => now()->subMinutes(1), // Expired
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        $result = $otpService->verify('expired@orvell.com', '123456', 'register');

        $this->assertFalse($result['status']);
        $this->assertEquals('Invalid or expired OTP', $result['message']);
    }

    public function test_single_use_otp_cannot_be_reused(): void
    {
        $otpService = app(OtpService::class);

        EmailOtp::create([
            'email'       => 'used@orvell.com',
            'otp_hash'    => Hash::make('123456'),
            'type'        => 'register',
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => now()->subMinutes(2), // Already used
            'attempts'    => 0,
        ]);

        $result = $otpService->verify('used@orvell.com', '123456', 'register');

        $this->assertFalse($result['status']);
        $this->assertEquals('Invalid or expired OTP', $result['message']);
    }

    public function test_maximum_failed_attempts_lockout(): void
    {
        $otpService = app(OtpService::class);

        EmailOtp::create([
            'email'       => 'locked@orvell.com',
            'otp_hash'    => Hash::make('123456'),
            'type'        => 'register',
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => null,
            'attempts'    => 5, // Locked
        ]);

        $result = $otpService->verify('locked@orvell.com', '123456', 'register');

        $this->assertFalse($result['status']);
        $this->assertEquals(429, $result['code']);
        $this->assertStringContainsString('Maximum verification attempts exceeded', $result['message']);
    }

    public function test_rate_limiting_max_3_otps_per_15_minutes(): void
    {
        Mail::fake();
        $otpService = app(OtpService::class);

        $email = 'ratelimit@orvell.com';

        // 1st request
        $otpService->generateAndSend($email, 'register');
        // 2nd request
        $otpService->generateAndSend($email, 'register');
        // 3rd request
        $otpService->generateAndSend($email, 'register');

        // 4th request must throw ValidationException
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $otpService->generateAndSend($email, 'register');
    }

    public function test_previous_otp_invalidation_when_new_otp_requested(): void
    {
        Mail::fake();
        $otpService = app(OtpService::class);

        $email = 'invalidation@orvell.com';

        $otpService->generateAndSend($email, 'register');
        $firstOtpRecord = EmailOtp::where('email', $email)->first();
        $this->assertTrue($firstOtpRecord->expires_at->isFuture());

        // Generate 2nd OTP
        $otpService->generateAndSend($email, 'register');
        $firstOtpRecord->refresh();

        // First OTP must now be expired / invalidated
        $this->assertTrue($firstOtpRecord->expires_at->isPast() || $firstOtpRecord->expires_at->isCurrentSecond());
    }

    public function test_end_to_end_signup_api_flow(): void
    {
        Mail::fake();

        // STEP 1: Signup request
        $response1 = $this->postJson('/api/signup', [
            'name'              => 'Ama Osei',
            'business_name'     => 'Ama Wholesales',
            'business_location' => 'Kumasi',
            'phone_number'      => '+233240001122',
            'email'             => 'ama@wholesales.com',
            'password'          => 'SecurePass123!',
        ]);

        $response1->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'step'    => 'otp',
                'message' => 'OTP sent to email',
            ]);

        $this->assertDatabaseHas('clients', ['email' => 'ama@wholesales.com']);

        // Inspect captured OTP from Mail fake
        $capturedOtp = null;
        Mail::assertSent(SendOtpMail::class, function ($mail) use (&$capturedOtp) {
            $capturedOtp = $mail->otp;
            return true;
        });

        $this->assertNotNull($capturedOtp);

        // STEP 2: Verify OTP
        $response2 = $this->postJson('/api/signup', [
            'email' => 'ama@wholesales.com',
            'otp'   => $capturedOtp,
        ]);

        $response2->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'step'    => 'completed',
                'message' => 'Signup completed successfully',
            ]);

        $this->assertNotNull($response2->json('token'));

        // Assert Audit Logs recorded
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register_initiated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register_completed']);
    }

    public function test_client_and_user_login(): void
    {
        // 1. Client login
        $client = Client::create([
            'name'          => 'Client User',
            'business_name' => 'Client Biz',
            'email'         => 'client@biz.com',
            'password'      => Hash::make('password123'),
        ]);

        $responseClient = $this->postJson('/api/signin', [
            'email'    => 'client@biz.com',
            'password' => 'password123',
        ]);

        $responseClient->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'message' => 'Signin successful',
            ]);
        $this->assertNotNull($responseClient->json('token'));

        // 2. User (Staff) login
        $user = User::create([
            'name'     => 'Staff Member',
            'email'    => 'staff@biz.com',
            'password' => Hash::make('staffpass123'),
        ]);

        $responseUser = $this->postJson('/api/signin', [
            'email'    => 'staff@biz.com',
            'password' => 'staffpass123',
        ]);

        $responseUser->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'message' => 'Signin successful',
            ]);
        $this->assertNotNull($responseUser->json('token'));
    }

    public function test_token_check_and_logout_endpoints(): void
    {
        $client = Client::create([
            'name'          => 'Token Tester',
            'business_name' => 'Token Biz',
            'email'         => 'token@biz.com',
            'password'      => Hash::make('password123'),
        ]);

        $token = $client->createToken('test_token')->plainTextToken;

        // Token check
        $checkResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/tokenCheck');

        $checkResponse->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'message' => 'Token is valid',
            ]);

        // Logout
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'message' => 'Logged out successfully',
            ]);

        // Assert token deleted
        $this->assertEquals(0, $client->tokens()->count());
    }

    public function test_password_reset_flow_with_smtp_otp(): void
    {
        Mail::fake();

        $client = Client::create([
            'name'          => 'Reset Client',
            'business_name' => 'Reset Biz',
            'email'         => 'reset@biz.com',
            'password'      => Hash::make('OldPassword123'),
        ]);

        // STEP 1: Request reset OTP
        $response1 = $this->postJson('/api/password-reset', [
            'email' => 'reset@biz.com',
        ]);

        $response1->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'step'    => 'otp',
                'message' => 'OTP sent to email',
            ]);

        $capturedOtp = null;
        Mail::assertSent(SendOtpMail::class, function ($mail) use (&$capturedOtp) {
            $capturedOtp = $mail->otp;
            return true;
        });

        // STEP 2: Verify OTP
        $response2 = $this->postJson('/api/password-reset', [
            'email' => 'reset@biz.com',
            'otp'   => $capturedOtp,
        ]);

        $response2->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'step'    => 'password',
                'message' => 'OTP verified',
            ]);

        // STEP 3: Reset password
        $response3 = $this->postJson('/api/password-reset', [
            'email'        => 'reset@biz.com',
            'otp'          => $capturedOtp,
            'new_password' => 'BrandNewPassword123!',
        ]);

        $response3->assertStatus(200)
            ->assertJson([
                'status'  => true,
                'message' => 'Password reset successful',
            ]);

        // Verify new password works
        $client->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123!', $client->password));
    }

    public function test_audit_service_centralized_logging(): void
    {
        $auditService = app(AuditService::class);

        $client = Client::create([
            'name'          => 'Audit Test Client',
            'business_name' => 'Audit Biz',
            'email'         => 'audit@biz.com',
            'password'      => Hash::make('secret'),
        ]);

        $log = $auditService->logCreate($client, 'client.created');

        $this->assertNotNull($log->uuid);
        $this->assertEquals('client.created', $log->action);
        $this->assertEquals('App\Models\Client', $log->auditable_type);
        $this->assertEquals($client->id, $log->auditable_id);
    }
}
