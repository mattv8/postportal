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
        .content { background-color: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; margin-bottom: 20px; }
        .post-title { font-size: 20px; font-weight: bold; margin-bottom: 15px; color: #212529; }
        .post-body { margin-bottom: 20px; }

        /* Quill alignment classes */
        .post-body .ql-align-left { text-align: left; }
        .post-body .ql-align-center { text-align: center; }
        .post-body .ql-align-right { text-align: right; }
        .post-body .ql-align-justify { text-align: justify; }

        /* Image styling */
        .post-body img { max-width: 100%; height: auto; display: block; margin: 1rem auto; }
        .post-body p img:not(.ql-align-left):not(.ql-align-right):not(.ql-align-center),
        .post-body > img:not(.ql-align-left):not(.ql-align-right):not(.ql-align-center) { display: block; margin: 1rem auto; }
        .post-body .ql-align-center img,
        .post-body p.ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
        .post-body .ql-align-right img,
        .post-body p.ql-align-right img { display: block; margin-left: auto; margin-right: 0; }
        .post-body .ql-align-left img,
        .post-body p.ql-align-left img { display: block; margin-left: 0; margin-right: auto; }
        .post-body img.ql-align-center { display: block; margin-left: auto; margin-right: auto; }
        .post-body img.ql-align-right { float: right; margin-left: 1rem; }
        .post-body img.ql-align-left { float: left; margin-right: 1rem; }

        /* Text formatting */
        .post-body p { margin: 0 0 1rem 0; }
        .post-body ul, .post-body ol { margin: 0 0 1rem 0; padding-left: 1.5rem; }
        .post-body h1, .post-body h2, .post-body h3, .post-body h4, .post-body h5, .post-body h6 { margin: 1.5rem 0 1rem 0; font-weight: bold; }
        .post-body blockquote { border-left: 4px solid #dee2e6; padding-left: 1rem; margin: 1rem 0; color: #6c757d; font-style: italic; }
        .post-body pre { background: #f8f9fa; padding: 1rem; border-radius: 5px; overflow-x: auto; }
        .post-body code { background: #f8f9fa; padding: 0.2rem 0.4rem; border-radius: 3px; font-family: 'Courier New', monospace; }
        .post-body a { color: #0066cc; text-decoration: underline; }

        .cta-button { display: inline-block; padding: 12px 24px; background-color: #0066cc; color: #ffffff !important; text-decoration: none; border-radius: 5px; margin: 10px 0; }
        .footer { text-align: center; color: #6c757d; font-size: 12px; padding: 20px 0; }

        /* Hero image styling */
        .hero-container { position: relative; width: 100%; overflow: hidden; margin-bottom: 20px; }
        .hero-image { display: block; width: 100%; height: auto; }
        .hero-title-overlay { position: absolute; bottom: 0; left: 0; right: 0; padding: 20px; background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 100%); }
        .hero-title-overlay h2 { margin: 0; color: #ffffff; font-size: 24px; font-weight: bold; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>{$site_title|escape:'htmlall'}</h1>
    </div>
    <div class='content'>
         {if $hero_image_url}
         <div class='hero-container' style='padding-bottom: {$hero_image_height|default:100}%; position: relative; margin: -20px -20px 20px -20px; width: calc(100% + 40px); max-width: none;'>
              <img src='{$hero_image_url|escape:'htmlall'}'
                   alt='{$post_title|escape:'htmlall'}'
                 class='hero-image'
                 style='position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; display: block;{if $hero_title_overlay|default:1} filter: brightness({$hero_overlay_opacity|default:0.70});{/if}' />
            {if $hero_title_overlay|default:1}
             <div class='hero-title-overlay' style='position: absolute; bottom: 0; left: 0; right: 0; padding: 20px; background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 100%);'>
                  <h2 style='margin: 0; color: #ffffff; font-size: 24px; font-weight: bold;'>{$post_title|escape:'htmlall'}</h2>
             </div>
             {/if}
         </div>
         {/if}
         {if !$hero_title_overlay|default:1 || !$hero_image_url}
          <div class='post-title'>{$post_title|escape:'htmlall'}</div>
        {/if}
        <div class='post-body'>
            {$body_html nofilter}
        </div>
    </div>
    <div style='text-align: center;'>
         <a href='{$post_url|escape:'htmlall'}' class='cta-button'>View Full Post on Site</a>
     </div>
     <div class='footer'>
          <p>You're receiving this because you subscribed to updates from {$site_title|escape:'htmlall'}.</p>
          <p><a href='{$base_url|escape:'htmlall'}'>Visit our site</a> | <a href='{$unsubscribe_url|escape:'htmlall'}'>Unsubscribe</a></p>
     </div>
</body>
</html>
