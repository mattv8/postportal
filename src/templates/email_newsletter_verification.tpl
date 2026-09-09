<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #0066cc; }
        .content { background-color: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; }
        .info { background-color: #e7f3ff; padding: 15px; border-left: 4px solid #0066cc; margin: 20px 0; }
        .button-container { text-align: center; margin: 30px 0; }
        .button { display: inline-block; padding: 12px 30px; background-color: #0066cc; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .button:hover { background-color: #0052a3; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        .warning { background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Verify Your Newsletter Subscription</h1>
        </div>
        <div class="content">
            <p>Thank you for subscribing to the newsletter at {$site_title|escape:'html'}!</p>

            <p>To complete your subscription and start receiving updates, please verify your email address by clicking the button below:</p>

            <div class="button-container">
                <a href="{$verification_url|escape:'html'}" class="button">Verify Subscription</a>
            </div>

            <p>Or copy and paste this link in your browser:</p>
            <div class="info">
                <code>{$verification_url|escape:'html'}</code>
            </div>

            <div class="warning">
                <strong>Important:</strong> This verification link will expire in {$token_expiry_hours|escape:'html'} hours. Please verify your subscription promptly.
            </div>

            <p>If you did not subscribe to this newsletter, you can safely ignore this email.</p>
        </div>
        <div class="footer">
            <p>This email was sent automatically. Please do not reply to this message.</p>
            <p>© {$smarty.now|date_format:"%Y"} {$site_title|escape:'html'}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
