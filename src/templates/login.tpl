<div class="{$page_bg_color_class} d-flex align-items-center justify-content-center min-vh-100 py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-5 inset-1">
                <div class="card shadow-sm">
                    <div class="card-header text-center">
                        <i class="fa me-2 fa-user-lock"></i>{$msg_login}
                    </div>
                    <div class="card-body">

                        <div class="container-fluid px-2" id="auth-banner" style="display: none;">
                            <div class="row alert" role="alert">
                                <div class="col-auto me-auto my-1" id="auth-banner-text"></div>
                                <div class="col-auto my-1"><button class="fa fa-fw fa-remove inline-icon" id="banner-dismiss" onclick="document.getElementById('auth-banner').style.display='none';"></button></div>
                            </div>
                        </div>

                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                            <input type="username" name="username" id="username" class="form-control" placeholder="{$msg_username}"{if $debug} value="admin"{/if}/>
                        </div>
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                            <input type="password" name="password" id="password" class="form-control" placeholder="{$msg_password}"{if $debug && $default_admin_password} value="{$default_admin_password}"{/if}/>
                        </div>
                        <!-- SEC-11: Account activation password confirmation field (hidden by default) -->
                        <div class="input-group mb-3" id="password-confirm-field" style="display: none;">
                            <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                            <input type="password" name="password-confirm" id="password-confirm" class="form-control" placeholder="Confirm Password"/>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                            <a href="/" class="btn btn-secondary col-md-auto"><i class="fa me-2 fa-arrow-left"></i>{$msg_go_back|default:'Go Back'}</a>
                            <button type="submit" class="btn btn-primary col-md-auto" id="login-submit-btn" onclick="handleLogin(this)"><i class="fa me-2 fa-check-circle"></i>{$msg_submit}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>