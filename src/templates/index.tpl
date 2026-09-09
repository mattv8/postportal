<!DOCTYPE html>
<html lang="en">

<head>
  <title>{if $settings.site_title}{$settings.site_title|escape}{else}Post Portal{/if}</title>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  {if $authenticated and $csrf_token}
    <meta name="csrf-token" content="{$csrf_token}" />
  {/if}

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  {* App styles with cache-busting *}
  <link rel="stylesheet" type="text/css" href="{asset_css file='global.css'}" />
  <link rel="stylesheet" type="text/css" href="{asset_css file='custom.css'}" />
  <link rel="stylesheet" type="text/css" href="{asset_css file='home.css'}" />
  <link rel="stylesheet" type="text/css" href="{asset_css file='post_overlay.css'}" />
  <link rel="stylesheet" type="text/css" href="{asset_css file='donation-modal.css'}" />

  <script>
    "use strict";
    window.GLOBAL = {};
    GLOBAL.config = {$js_config|@json_encode nofilter};
    GLOBAL.config.authenticated = {if $authenticated}true{else}false{/if};
    GLOBAL.config.isAdmin = {if $isadmin}true{else}false{/if};
    GLOBAL.config.currentUser = {if $currentUser}"{$currentUser|escape:'javascript'}"{else}null{/if};
  </script>

  {if $page eq 'login' and $js_config.recaptcha_key}
    <script src="https://www.google.com/recaptcha/api.js?render={$js_config.recaptcha_key}"></script>
  {/if}

  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

  {* Custom header includes *}
  {include file="templates/header.tpl"}

  {* Favicon - use dynamic favicon from branding settings or default *}
  {if $favicon_ico}
    <link href="{$favicon_ico}" rel="icon" type="image/x-icon" />
    <link href="{$favicon_ico}" rel="shortcut icon" />
  {/if}
  {if $favicon_16}
    <link href="{$favicon_16}" rel="icon" type="image/png" sizes="16x16" />
  {/if}
  {if $favicon_32}
    <link href="{$favicon_32}" rel="icon" type="image/png" sizes="32x32" />
  {/if}
  {if $favicon_192}
    <link href="{$favicon_192}" rel="icon" type="image/png" sizes="192x192" />
  {/if}
  {if $favicon_512}
    <link href="{$favicon_512}" rel="icon" type="image/png" sizes="512x512" />
  {/if}
  {if $favicon_svg}
    <link href="{$favicon_svg}" rel="icon" type="image/svg+xml" />
  {/if}
</head>

<body>

  {* Login page *}
  {if $page eq 'login'}
    {include file="templates/login.tpl"}

   {* Public pages when unauthenticated *}
   {elseif ($page eq 'home' or $page eq 'post') and not $authenticated}
     <nav class="navbar navbar-light bg-white shadow-sm">
       <div class="container d-flex align-items-center">
         <a class="navbar-brand fw-bold fs-4 m-0 py-2 d-flex align-items-center" href="?page=home">
           {if $settings.show_logo && $logo_url}
             {if $logo_srcset_png && $logo_srcset_webp}
               <picture>
                 <source type="image/webp" srcset="{$logo_srcset_webp}" />
                 <img src="{$logo_url}" srcset="{$logo_srcset_png}" alt="Logo" style="max-height: 40px; width: auto;" class="me-2" />
               </picture>
             {else}
               <img src="{$logo_url}" alt="Logo" style="max-height: 40px; width: auto;" class="me-2" />
             {/if}
           {/if}
           <span>{if $settings.site_title}{$settings.site_title|escape}{else}Post Portal{/if}</span>
         </a>
         <div>
           <a href="?page=login" class="btn btn-sm btn-outline-primary">Admin Login</a>
         </div>
       </div>
     </nav>

     {if $error or $page eq 'error'}
       <div class="container mt-4">
         <div class="alert alert-danger">
           <i class="fa fa-fw fa-exclamation-circle"></i> {$error}
         </div>
       </div>
      {else}
        {if $page eq 'home' or $page eq 'post'}
          {include file="templates/home.tpl"}
        {else}
          <div class="container mt-4">
            <div class="alert alert-danger">
              <i class="fa fa-fw fa-exclamation-circle"></i> Page not found
            </div>
          </div>
        {/if}
      {/if}

    {* Public pages while authenticated *}
    {elseif ($page eq 'home' or $page eq 'post') and $authenticated}
     <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
       <div class="container-fluid d-flex align-items-center">
         <a class="navbar-brand fw-bold fs-4 m-0 py-2 d-flex align-items-center" href="?page=home">
           {if $settings.show_logo && $logo_url}
             {if $logo_srcset_png && $logo_srcset_webp}
               <picture>
                 <source type="image/webp" srcset="{$logo_srcset_webp}" />
                 <img src="{$logo_url}" srcset="{$logo_srcset_png}" alt="Logo" style="max-height: 40px; width: auto;" class="me-2" />
               </picture>
             {else}
               <img src="{$logo_url}" alt="Logo" style="max-height: 40px; width: auto;" class="me-2" />
             {/if}
           {/if}
           <span>{if $settings.site_title}{$settings.site_title|escape}{else}Post Portal{/if}</span>
         </a>

         <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
           <span class="navbar-toggler-icon"></span>
         </button>

         <div class="collapse navbar-collapse" id="navbarNav">
           <ul class="navbar-nav ms-auto">
             {if $isadmin}
              <li class="nav-item">
                <a class="nav-link" href="?page=admin"><i class="fa fa-fw fa-cog"></i> Admin Dashboard</a>
              </li>
              <li class="nav-item">
                <button class="nav-link btn btn-link" data-bs-toggle="modal" data-bs-target="#createPostModal">
                  <i class="fa fa-fw fa-plus"></i> Create Post
                </button>
              </li>
              {/if}
             <li class="nav-item">
               <button class="nav-link btn btn-link" onclick="logoff()"><i class="fa fa-fw fa-sign-out"></i> Logout</button>
             </li>
           </ul>
         </div>
       </div>
     </nav>

     {if $error or $page eq 'error'}
       <div class="container mt-4">
         <div class="alert alert-danger">
           <i class="fa fa-fw fa-exclamation-circle"></i> {$error}
         </div>
       </div>
      {else}
        {if $page eq 'home' or $page eq 'post'}
          {include file="templates/home.tpl"}
        {else}
          <div class="container mt-4">
            <div class="alert alert-danger">
              <i class="fa fa-fw fa-exclamation-circle"></i> Page not found
            </div>
          </div>
        {/if}
      {/if}

     {if $isadmin}
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
     <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    {if $_bundle_mode}
    <script defer src="{bundle_url name='post-modal.bundle.js'}"></script>
    {else}
    <script defer src="{asset_js file='image-crop-manager.js'}"></script>
     <script defer src="{asset_js file='post-modal.js'}"></script>
     {/if}
     {/if}

   {* Authenticated/Admin layout *}
   {else}
     <div class="container-fluid inset-3">
       <div class="panel panel-success {$page_bg_color_class}">
         {include file="templates/menu.tpl"}

         <div class="container-fluid px-0" id="page-content">
           {if $error or $page eq 'error'}
             <div class="alert alert-danger"><i class="fa fa-fw fa-exclamation-circle"></i> {$error}</div>
           {elseif $page eq 'admin'}
             {include file="templates/admin.tpl"}
           {elseif $page eq 'login'}
             {include file="templates/login.tpl"}
           {else}
             <div class="alert alert-danger"><i class="fa fa-fw fa-exclamation-circle"></i> Page not found</div>
           {/if}
         </div>
       </div>
     </div>
   {/if}

  {if $_bundle_mode}
  <script defer src="{bundle_url name='auth.bundle.js'}"></script>
  {else}
  <script defer src="{asset_js file='auth.js'}"></script>
  {/if}
</body>
</html>
