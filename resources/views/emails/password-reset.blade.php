<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Reset your NexaSMS password</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f6fa;color:#263238;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f6fa;border-collapse:collapse;">
    <tr>
        <td align="center" style="padding:32px 12px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e1e7ef;border-collapse:separate;border-spacing:0;">
                <tr>
                    <td style="padding:24px 32px;background-color:#263b4a;color:#ffffff;font-size:22px;font-weight:bold;">
                        NexaSMS by Mathias
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;font-size:16px;line-height:1.6;">
                        <p style="margin:0 0 18px;">Hello {{ $userName }},</p>
                        <p style="margin:0 0 24px;">We received a request to reset the password for your NexaSMS account.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 24px;border-collapse:collapse;">
                            <tr>
                                <td align="center" bgcolor="#1685d4" style="border-radius:4px;">
                                    <a href="{{ $resetUrl }}" style="display:inline-block;padding:13px 24px;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;">Reset Password</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:0 0 16px;">This password reset link will expire in {{ $expireMinutes }} minutes.</p>
                        <p style="margin:0 0 24px;">If you did not request a password reset, no further action is required.</p>
                        <p style="margin:0;">Regards,<br>NexaSMS by Mathias</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px;background-color:#f7f9fc;border-top:1px solid #e1e7ef;color:#637381;font-size:13px;line-height:1.6;">
                        <p style="margin:0 0 8px;">&copy; {{ date('Y') }} NexaSMS by Mathias. All rights reserved.</p>
                        <p style="margin:0;">
                            <a href="{{ $privacyUrl }}" style="color:#1769aa;text-decoration:underline;">Privacy Policy</a>
                            <span style="padding:0 8px;">|</span>
                            <a href="{{ $termsUrl }}" style="color:#1769aa;text-decoration:underline;">Terms &amp; Conditions</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
