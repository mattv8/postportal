<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #0066cc; font-size: 24px; }
        .content { background-color: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .message { font-size: 18px; margin-bottom: 15px; color: #212529; }
        .excerpt { font-size: 14px; color: #6c757d; margin-bottom: 20px; font-style: italic; }
        .cta-button { display: inline-block; padding: 12px 24px; background-color: #0066cc; color: #ffffff !important; text-decoration: none; border-radius: 5px; margin: 10px 0; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; padding: 20px 0; }
    </style>
</head>
<body>
     <div class='header'>
          <h1>{$site_title|escape:'htmlall'}</h1>
     </div>
     <div class='content'>
          <div class='message'>{$author_name|escape:'htmlall'} has posted an update!</div>
          <div class='excerpt'>{$excerpt|escape:'htmlall'|truncate:250:"…"}</div>
          <a href='{$post_url|escape:'htmlall'}' class='cta-button'>Read the Full Update</a>
     </div>
     <div class='footer'>
          <p>You're receiving this because you subscribed to updates from {$site_title|escape:'htmlall'}.</p>
          <p><a href='{$base_url|escape:'htmlall'}'>Visit our site</a> | <a href='{$unsubscribe_url|escape:'htmlall'}'>Unsubscribe</a></p>
     </div>
</body>
</html>
