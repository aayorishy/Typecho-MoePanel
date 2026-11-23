<?php
$message = (string) ($kook['fatal']['message'] ?? '请检查 MoePanel 的 KOOK 设置。');
?>
<div class="sp-kook-notice is-error" role="alert">
    <span aria-hidden="true">!</span>
    <p><?php echo $e($message); ?></p>
</div>
<p class="sp-kook-help">你可以先使用管理员密码进入后台，再补全 KOOK 配置。</p>
<?php if (!empty($kook['fallback'])): ?>
    <a class="sp-submit sp-submit--kook" href="<?php echo $e((string) $kook['fallbackUrl']); ?>">
        <span>使用管理员密码登录</span>
        <span class="sp-submit-arrow" aria-hidden="true">→</span>
    </a>
<?php endif; ?>
