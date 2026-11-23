<?php
$oauthEnabled = !empty($kook['oauthEnabled']);
$codeEnabled = !empty($kook['codeEnabled']);
$actionUrl = (string) $kook['actionUrl'];
?>
<?php if ($oauthEnabled): ?>
    <a class="sp-kook-oauth" href="<?php echo $e((string) $kook['oauthUrl']); ?>"
       target="_self" rel="nofollow">
        <span class="sp-kook-oauth-mark" aria-hidden="true">K</span>
        <span class="sp-kook-oauth-copy">
            <strong>使用 KOOK OAuth 登录</strong>
            <small>在 KOOK 授权页确认后自动返回</small>
        </span>
        <span class="sp-kook-oauth-arrow" aria-hidden="true">→</span>
    </a>
<?php endif; ?>

<?php if ($oauthEnabled && $codeEnabled): ?>
    <div class="sp-kook-divider"><span>或使用私信验证码</span></div>
<?php endif; ?>

<?php if ($codeEnabled): ?>
    <form class="sp-form sp-kook-form" method="post" action="<?php echo $e($actionUrl); ?>">
        <input type="hidden" name="kook_csrf" value="<?php echo $e((string) $kook['csrf']); ?>">
        <input type="hidden" name="kook_action" value="send">

        <div class="sp-field">
            <label for="kook-identity"><span>KOOK 账号</span></label>
            <input type="text" id="kook-identity" name="kook_identity"
                   value="<?php echo $e((string) $kook['identity']); ?>"
                   placeholder="昵称#编号 或 用户 ID"
                   autocomplete="username" autocapitalize="off" spellcheck="false"
                   maxlength="128" autofocus required>
        </div>

        <p class="sp-kook-help">例如 <code>Ayko#1234</code> 或 <code>123456789</code>。验证码会发送到该账号的 KOOK 私信。</p>

        <button type="submit" class="sp-submit sp-submit--kook">
            <span>发送验证码</span>
            <span class="sp-submit-arrow" aria-hidden="true">→</span>
        </button>
    </form>
<?php elseif ($oauthEnabled): ?>
    <p class="sp-kook-only">当前仅启用了 OAuth 登录，请点击上方按钮继续。</p>
<?php else: ?>
    <div class="sp-kook-notice is-warn" role="status">
        <span aria-hidden="true">!</span>
        <p>两种 KOOK 登录方式均已关闭，请使用下方的密码登录入口。</p>
    </div>
<?php endif; ?>
