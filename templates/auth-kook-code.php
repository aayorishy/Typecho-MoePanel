<?php
$actionUrl = (string) $kook['actionUrl'];
$ttlMinutes = max(1, (int) round(((int) ($kook['settings']['codeTtl'] ?? 600)) / 60));
?>
<div class="sp-kook-chip">
    <span class="sp-kook-chip-icon" aria-hidden="true">✓</span>
    <span>验证码已发送至 <strong><?php echo $e((string) $kook['masked']); ?></strong></span>
</div>

<form class="sp-form sp-kook-form" method="post" action="<?php echo $e($actionUrl); ?>">
    <input type="hidden" name="kook_csrf" value="<?php echo $e((string) $kook['csrf']); ?>">
    <input type="hidden" name="kook_action" value="verify">
    <input type="hidden" name="kook_flow" value="<?php echo $e((string) $kook['flow']); ?>">

    <div class="sp-field">
        <label for="kook-code"><span>6 位验证码</span></label>
        <input class="sp-kook-code" type="text" id="kook-code" name="kook_code"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               autocomplete="one-time-code" placeholder="000000" autofocus required>
    </div>

    <p class="sp-kook-help">验证码已通过 KOOK 机器人私信发送，<?php echo (int) $ttlMinutes; ?> 分钟内有效。</p>

    <button type="submit" class="sp-submit sp-submit--kook">
        <span>验证并登录</span>
        <span class="sp-submit-arrow" aria-hidden="true">→</span>
    </button>

    <a class="sp-kook-back" href="<?php echo $e($actionUrl); ?>">返回重新输入账号</a>
</form>
