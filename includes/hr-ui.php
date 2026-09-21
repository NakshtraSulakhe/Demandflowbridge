<?php

function hrRenderHeader(array $crumbs, string $title, string $subtitle = '', array $actions = [], array $badges = []): void {
    echo '<div class="hr-page-header mb-3">';
    echo '<div class="d-flex justify-content-between align-items-end gap-3 flex-wrap">';
    echo '<div>';
    if (!empty($crumbs)) {
        echo '<nav aria-label="breadcrumb">';
        echo '<ol class="breadcrumb">';
        foreach ($crumbs as $c) {
            $label = (string)($c['label'] ?? '');
            $href = (string)($c['href'] ?? '');
            $active = empty($href);
            echo '<li class="breadcrumb-item' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>';
            if ($active) {
                echo htmlspecialchars($label);
            } else {
                echo '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
            }
            echo '</li>';
        }
        echo '</ol>';
        echo '</nav>';
    }
    echo '<div class="hr-page-title">' . htmlspecialchars($title) . '</div>';
    if ($subtitle !== '') echo '<div class="hr-page-subtitle">' . htmlspecialchars($subtitle) . '</div>';
    echo '</div>';
    echo '<div class="hr-actions d-flex gap-2 align-items-center flex-wrap justify-content-end">';
    foreach ($badges as $b) {
        $text = (string)($b['text'] ?? '');
        $class = (string)($b['class'] ?? 'bg-secondary');
        echo '<span class="badge ' . htmlspecialchars($class) . '">' . htmlspecialchars($text) . '</span>';
    }
    foreach ($actions as $a) {
        $label = (string)($a['label'] ?? '');
        $href = (string)($a['href'] ?? '#');
        $icon = (string)($a['icon'] ?? '');
        $class = (string)($a['class'] ?? 'btn-outline-primary');
        $attrs = trim((string)($a['attrs'] ?? ''));
        echo '<a class="btn ' . htmlspecialchars($class) . ' btn-sm" href="' . htmlspecialchars($href) . '"' . ($attrs !== '' ? (' ' . $attrs) : '') . '>';
        if ($icon !== '') echo '<i class="bi ' . htmlspecialchars($icon) . '"></i> ';
        echo htmlspecialchars($label) . '</a>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

function hrKpi(string $label, string $value, string $icon = '', string $sub = '', string $valueClass = ''): void {
    echo '<div class="hr-kpi h-100">';
    echo '<div class="d-flex justify-content-between align-items-start gap-2">';
    echo '<div class="hr-kpi-label">' . htmlspecialchars($label) . '</div>';
    if ($icon !== '') echo '<i class="bi ' . htmlspecialchars($icon) . ' text-muted"></i>';
    echo '</div>';
    echo '<div class="hr-kpi-value ' . htmlspecialchars($valueClass) . '">' . htmlspecialchars($value) . '</div>';
    if ($sub !== '') echo '<div class="hr-kpi-sub">' . htmlspecialchars($sub) . '</div>';
    echo '</div>';
}
