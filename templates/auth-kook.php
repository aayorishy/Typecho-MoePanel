<?php
$kookError = (string) ($kook['error'] ?? '');
$kookNotice = (string) ($kook['notice'] ?? '');
$kookWarnings = is_array($kook['warnings'] ?? null) ? $kook['warnings'] : [];
$kookFatal = $kook['fatal'] ?? null;
$kookStep = 'code' === ($kook['step'] ?? 'identity') ? 'code' : 'identity';
$kookTtl = max(1, (int) round(((int) ($kook['settings']['codeTtl'] ?? 600)) / 60));

if ($kookWarnings) {
    foreach ($kookWarnings as $warning) {
        echo '<div class="sp-kook-notice is-warn" role="status"><span aria-hidden="true">!</span><p>' . $e($warning) . '</p></div>';
    }
}

if ($kookError !== '') {
    echo '<div class="sp-kook-notice is-error" role="alert"><span aria-hidden="true">!</span><p>' . $e($kookError) . '</p></div>';
}

if ($kookNotice !== '') {
    echo '<div class="sp-kook-notice is-ok" role="status"><span aria-hidden="true">✓</span><p>' . $e($kookNotice) . '</p></div>';
}

if (is_array($kookFatal)) {
    include __DIR__ . '/auth-kook-fatal.php';
} elseif ('code' === $kookStep) {
    include __DIR__ . '/auth-kook-code.php';
} else {
    include __DIR__ . '/auth-kook-identity.php';
}

if (!is_array($kookFatal)) {
    echo '<p class="sp-kook-foot-hint"><span>' . $icon('shield') . ' 不会保存 KOOK 密码</span><span>' . $icon('clock') . ' 验证码 ' . $kookTtl . ' 分钟内有效</span></p>';
}
