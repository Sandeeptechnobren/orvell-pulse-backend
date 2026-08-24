<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} Verification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
            color: #1e293b;
        }
        .container {
            max-width: 540px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 32px 24px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 36px 32px;
            text-align: center;
        }
        .greeting {
            font-size: 16px;
            color: #334155;
            margin-bottom: 20px;
        }
        .otp-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 20px;
            margin: 24px 0;
            display: inline-block;
        }
        .otp-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #0284c7;
            margin: 0;
        }
        .instructions {
            font-size: 14px;
            color: #64748b;
            line-height: 1.6;
            margin-top: 16px;
        }
        .warning {
            font-size: 13px;
            color: #ef4444;
            background-color: #fef2f2;
            border-radius: 6px;
            padding: 12px;
            margin-top: 24px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $appName }}</h1>
        </div>
        <div class="content">
            <p class="greeting">
                @if($type === 'reset')
                    We received a request to reset your password.
                @else
                    Thank you for joining {{ $appName }}. Please verify your email address.
                @endif
            </p>
            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
            </div>
            <p class="instructions">
                Enter this 6-digit verification code to complete your request.
                This code is valid for <strong>10 minutes</strong> and can only be used once.
            </p>
            <div class="warning">
                If you did not request this code, please ignore this email or contact support immediately.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
        </div>
    </div>
</body>
</html>
