<div class="sp-topbar">
    <div class="sp-crumbs">
        <span class="sp-crumb-dot" aria-hidden="true"></span>
        <span><?php echo $e($siteTitle); ?></span>
        <span class="sp-crumb-sep">/</span>
        <span class="sp-crumb-current">MoePanel</span>
    </div>

    <form class="sp-search" action="<?php echo $e($searchUrl); ?>" method="get" role="search">
        <span class="sp-search-icon" aria-hidden="true"><?php echo $icon('search'); ?></span>
        <input type="search" name="keywords" placeholder="搜索文章…" aria-label="搜索文章">
        <button type="button" class="sp-kbd" data-sp-command-open aria-haspopup="dialog"
                aria-expanded="false" aria-label="打开命令面板">⌘K</button>
    </form>

    <div class="sp-top-actions">
        <span class="sp-top-date"><?php echo $e($date); ?></span>
        <button type="button" class="sp-command-trigger" data-sp-theme-menu-open
                aria-haspopup="menu" aria-expanded="false"
                aria-label="主题选择" title="主题选择">
            <?php echo $icon('sparkle'); ?>
        </button>
        <button type="button" class="sp-theme-toggle" data-sp-theme-toggle
                aria-label="切换深色模式" title="切换深色模式">
            <span class="sp-theme-icon sp-theme-icon--moon" aria-hidden="true"><?php echo $icon('moon'); ?></span>
            <span class="sp-theme-icon sp-theme-icon--sun" aria-hidden="true"><?php echo $icon('sun'); ?></span>
        </button>
        <?php if ($canWrite): ?>
            <a class="sp-write-btn" href="<?php echo $e($writeUrl); ?>">
                <?php echo $icon('pen'); ?><span>写文章</span>
            </a>
        <?php endif; ?>
    </div>
</div>
