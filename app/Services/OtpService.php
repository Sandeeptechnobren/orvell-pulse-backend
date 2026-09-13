<?php

namespace App\Services;

use App\Models\EmailOtp;
use App\Mail\SendOtpMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpService
{
    /**
     * Generate, store secure hash, and dispatch OTP via native Laravel Mailer.
     *
     * @param string $email
     * @param string $type ('register', 'reset')
     * @param string $appName
     * @return array
     * @throws ValidationException
     */
    public function generateAndSend(string $email, string $type = 'register', string $appName = 'ORVELL PULSE'): array
    {
        // 1. Rate Limiting: Max 3 OTP requests per 15 minutes per email
        $recentRequests = EmailOtp::where('email', $email)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentRequests >= 3) {
            throw ValidationException::withMessages([
                'email' => ['Too many OTP requests. Please wait 15 minutes before requesting a new OTP.'],
            ]);
        }

        // 2. Invalidate previous unverified active OTPs for this email and type
        EmailOtp::where('email', $email)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        // 3. Generate secure random 6-digit OTP
        $otp = (string) random_int(100000, 999999);

        // 4. Secure Hash storage
        $otpHash = Hash::make($otp);

        EmailOtp::create([
            'email'       => $email,
            'otp_hash'    => $otpHash,
            'type'        => $type,
            'expires_at'  => now()->addMinutes(10),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        // 5. Send via Laravel native Mailer using configured SMTP in .env
        try {
            Mail::to($email)->send(new SendOtpMail($otp, $type, $appName));
            Log::info("OTP successfully dispatched to recipient", [
                'email_domain' => substr(strrchr($email, "@"), 1),
                'type'         => $type,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to send OTP email: " . $e->getMessage(), [
                'type' => $type,
            ]);
            throw new \RuntimeException("Unable to send verification email. Please try again later.");
        }

        return [
            'status'  => true,
            'step'    => 'otp',
            'message' => 'OTP sent to email',
        ];
    }

    /**
     * Verify submitted OTP against secure hashed record.
     *
     * @param string $email
     * @param string $otp
     * @param string $type ('register', 'reset')
     * @return array
     */
    public function verify(string $email, string $otp, string $type = 'register'): array
    {
        $record = EmailOtp::where('email', $email)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->orderBy('id', 'desc')
            ->first();

        if (!$record || $record->expires_at->isPast()) {
            return [
                'status'  => false,
                'message' => 'Invalid or expired OTP',
                'code'    => 400,
            ];
        }

        if ($record->attempts >= 5) {
            return [
                'status'  => false,
                'message' => 'Maximum verification attempts exceeded. Please request a new OTP.',
                'code'    => 429,
            ];
        }

        if (!Hash::check((string) $otp, $record->otp_hash)) {
            $record->increment('attempts');
            $remaining = 5 - $record->attempts;
            return [
                'status'  => false,
                'message' => $remaining > 0
                    ? "Invalid OTP. {$remaining} attempts remaining."
                    : "Maximum verification attempts exceeded. Please request a new OTP.",
                'code'    => 400,
            ];
        }

        // Single-use invalidation
        $record->update(['verified_at' => now()]);

        return [
            'status'  => true,
            'message' => 'OTP verified successfully',
            'code'    => 200,
        ];
    }
}
