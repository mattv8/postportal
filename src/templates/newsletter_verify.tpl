<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin">
    <title>{if $status == 'confirm'}Confirm Subscription{elseif $status == 'success'}Subscription Confirmed{else}Verification Failed{/if}{if $settings.site_title} – {$settings.site_title|escape}{/if}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 50px auto; padding: 20px; background-color: #f8f9fa; }
        .card { background-color: #fff; border: 1px solid #dee2e6; border-radius: 5px; padding: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #0066cc; margin-top: 0; }
        .error { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #0066cc; color: #fff; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background-color: #0052a3; }
        .message { margin: 20px 0; }
        .message p { margin: 10px 0; }
        .home-link { display: inline-block; margin-top: 16px; color: #0066cc; text-decoration: none; font-size: 15px; }
        .home-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div id="newsletter-verify-card" class="card">
        {if $status == 'error'}
            <div id="newsletter-verify-error" class="error" role="alert">
                <h2 id="verify-error-heading">Unable to Verify</h2>
                <p>This verification link is invalid or has expired. Please check your email and try again, or subscribe again to receive a new link.</p>
            </div>
            <a href="/" class="home-link" id="newsletter-verify-error-home-link">← Return to site</a>
        {elseif $status == 'success'}
            <div id="newsletter-verify-success" class="success" role="status" aria-live="polite">
                <h2 id="verify-success-heading">Subscription Confirmed</h2>
                <p>Thank you! Your email address has been verified and you are now subscribed.</p>
            </div>
            <a href="/" class="home-link" id="newsletter-verify-home-link">← Return to site</a>
        {elseif $status == 'confirm'}
            <div id="newsletter-verify-confirm" aria-labelledby="verify-heading">
                <h2 id="verify-heading">Confirm Your Subscription</h2>
                <div id="verify-confirm-message" class="message">
                    <p>You are about to confirm your newsletter subscription.</p>
                    <p>Click the button below to complete your sign-up and start receiving updates.</p>
                </div>
                <form id="newsletter-verify-form" method="POST" action="/api/newsletter-verify.php">
                    <input type="hidden" name="token" value="{$token|escape:'html'}">
                    <button id="newsletter-verify-submit" type="submit" class="btn">Yes, confirm my subscription</button>
                </form>
            </div>
        {/if}
    </div>
</body>
</html>
