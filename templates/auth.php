<?php
$kicker = $isRegister ? 'Join the studio' : 'Private workspace';
$eyebrow = $isRegister ? 'Create account' : 'Welcome back';
$heading = $isRegister ? '创建账号' : '登录控制台';
$sub = $isRegister ? '注册后系统会自动生成初始密码，请妥善保存。' : '继续管理你的站点、文章与灵感。';
$artLine = $isRegister ? 'Start your<br>own <em>archive.</em>' : 'Make space<br>for <em>ideas.</em>';
$artLede = $isRegister
    ? '留下文字，整理图像，保存那些值得被记住的想法。'
    : '一个为文字、图像与灵感准备的私人后台。安静，克制，随时可以开始。';

if (!$isRegister && is_array($kook)) {
    $sub = '使用 KOOK OAuth 或私信验证码，安全进入控制台。';
}

$brandLight = (string) ($logoUrl ?? '');
$brandDark = (string) ($logoDarkUrl ?? '');
$brandLight = $brandLight !== '' ? $brandLight : $brandDark;
$brandDark = $brandDark !== '' ? $brandDark : $brandLight;
?>
<div class="sp-auth">
    <section class="sp-auth-art">
        <div class="sp-auth-glow sp-auth-glow--one" aria-hidden="true"></div>
        <div class="sp-auth-glow sp-auth-glow--two" aria-hidden="true"></div>

        <a class="sp-auth-brand" href="<?php echo $e($siteUrl); ?>">
            <?php if ($brandLight !== ''): ?>
                <span class="sp-brand-logo sp-brand-logo--image" aria-hidden="true">
                    <img class="sp-logo sp-logo--light" src="<?php echo $e($brandLight); ?>" alt="" referrerpolicy="no-referrer">
                    <img class="sp-logo sp-logo--dark" src="<?php echo $e($brandDark); ?>" alt="" referrerpolicy="no-referrer">
                </span>
            <?php else: ?>
                <span class="sp-brand-logo" aria-hidden="true"><?php echo $e($initial); ?></span>
            <?php endif; ?>
            <span class="sp-brand-text">
                <strong><?php echo $e($siteTitle); ?></strong>
                <small>MoePanel</small>
            </span>
        </a>

        <div class="sp-auth-copy">
            <span class="sp-auth-kicker"><span class="sp-eyebrow-dot" aria-hidden="true"></span><?php echo $e($kicker); ?></span>
            <h1><?php echo $artLine; ?></h1>
            <p><?php echo $e($artLede); ?></p>
            <div class="sp-auth-chips" aria-hidden="true">
                <span>Articles</span>
                <span>Media</span>
                <span>Comments</span>
            </div>
        </div>

        <div class="sp-auth-art-foot">
            <span><?php echo $e($siteTitle); ?> / Studio</span>
            <span>Est. <?php echo $e($year); ?> · Made for makers</span>
        </div>
    </section>

    <section class="sp-auth-panel">
        <button type="button" class="sp-theme-toggle sp-theme-toggle--auth" data-sp-theme-toggle
                aria-label="切换深色模式" title="切换深色模式">
            <span class="sp-theme-icon sp-theme-icon--moon" aria-hidden="true"><?php echo $icon('moon'); ?></span>
            <span class="sp-theme-icon sp-theme-icon--sun" aria-hidden="true"><?php echo $icon('sun'); ?></span>
        </button>

        <div class="sp-auth-card">
            <p class="sp-eyebrow">
                <span class="sp-eyebrow-dot" aria-hidden="true"></span>
                <?php echo $e($isRegister ? $eyebrow : (is_array($kook) ? 'KOOK access' : $eyebrow)); ?>
                <span class="sp-eyebrow-line" aria-hidden="true"></span>
                <?php echo $isRegister ? 'Register' : 'Sign in'; ?>
            </p>
            <h2><?php echo $e($heading); ?></h2>
            <p class="sp-auth-sub"><?php echo $e($sub); ?></p>

            <?php if (!$isRegister && !is_array($kook) && !empty($kookSwitch)): ?>
                <a class="sp-kook-switch" href="<?php echo $e((string) $kookSwitch['url']); ?>">
                    <span class="sp-kook-oauth-mark" aria-hidden="true">K</span>
                    <span class="sp-kook-switch-copy"><strong>使用 KOOK 登录</strong><small>OAuth 或私信验证码</small></span>
                    <span class="sp-kook-oauth-arrow" aria-hidden="true">→</span>
                </a>
                <div class="sp-kook-divider"><span>或使用账号密码</span></div>
            <?php endif; ?>

            <?php if (!$isRegister && is_array($kook)): ?>
                <?php include __DIR__ . '/auth-kook.php'; ?>
            <?php else: ?>
                <form class="sp-form" action="<?php echo $action; ?>" method="post"
                      name="<?php echo $isRegister ? 'register' : 'login'; ?>" role="form">
                    <div class="sp-field">
                        <label for="name"><span><?php echo $isRegister ? '用户名' : '用户名或邮箱'; ?></span></label>
                        <input type="text" id="name" name="name" value="<?php echo $name; ?>"
                               placeholder="<?php echo $isRegister ? '输入用户名' : '输入用户名或邮箱'; ?>"
                               autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required>
                    </div>

                    <?php if ($isRegister): ?>
                        <div class="sp-field">
                            <label for="mail"><span>Email</span></label>
                            <input type="email" id="mail" name="mail" value="<?php echo $mail; ?>"
                                   placeholder="输入电子邮箱" autocomplete="email" required>
                        </div>
                    <?php else: ?>
                        <div class="sp-field">
                            <label for="password"><span>密码</span></label>
                            <div class="sp-password">
                                <input type="password" id="password" name="password"
                                       placeholder="输入密码" autocomplete="current-password" required>
                                <button type="button" class="sp-password-toggle" data-sp-password-toggle
                                        aria-label="显示密码" aria-pressed="false">
                                    <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.8 12S6.2 6.2 12 6.2 21.2 12 21.2 12 17.8 17.8 12 17.8 2.8 12 2.8 12Z"/>
                                        <circle cx="12" cy="12" r="2.9"/>
                                    </svg>
                                    <svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.8 12S6.2 6.2 12 6.2 21.2 12 21.2 12 17.8 17.8 12 17.8 2.8 12 2.8 12Z"/>
                                        <circle cx="12" cy="12" r="2.9"/><path d="M4 20 20 4"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="sp-form-row">
                            <label class="sp-check" for="remember">
                                <input type="checkbox" name="remember" value="1" id="remember"<?php echo $remember ? ' checked' : ''; ?>>
                                下次自动登录
                            </label>
                            <a href="<?php echo $e($siteUrl); ?>">返回首页 ↗</a>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="sp-submit">
                        <span><?php echo $isRegister ? '创建账号' : '进入控制台'; ?></span>
                        <span class="sp-submit-arrow" aria-hidden="true">→</span>
                    </button>

                    <?php if (!$isRegister): ?>
                        <input type="hidden" name="referer" value="<?php echo $referer; ?>">
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <div class="sp-auth-links">
                <?php echo $links; ?>
                <span>Typecho <?php echo $e($version); ?></span>
            </div>
        </div>

        <footer class="sp-auth-foot">
            © <?php echo $e($year); ?> <?php echo $e($siteTitle); ?> · Protected by Typecho
        </footer>
    </section>
</div>
