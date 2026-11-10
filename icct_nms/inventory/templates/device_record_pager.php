<?php

/** Shared pagination keeps each device history section and its selected tab. */
$recordPage = static fn($key) => max(1, min(1000000, (int) ($_GET[$key] ?? 1)));
$recordPager = static function (
    $key,
    $page,
    $total,
    $anchor = 'view-records',
    $extra = [],
    $pageSize = 25,
) use ($id) {
    $pages = max(1, (int) ceil($total / $pageSize));
    $url = static function ($target) use ($id, $key, $anchor, $extra) {
        $params = [
            'id' => $id,
            'view' => 1,
            'changes_page' => max(1, (int) ($_GET['changes_page'] ?? 1)),
            'ssh_changes_page' => max(1, (int) ($_GET['ssh_changes_page'] ?? 1)),
            'diagnostics_page' => max(1, (int) ($_GET['diagnostics_page'] ?? 1)),
            'port_events_page' => max(1, (int) ($_GET['port_events_page'] ?? 1)),
        ];
        $params[$key] = $target;
        return 'inventory/controllers/device.php?' .
            http_build_query(array_replace($params, $extra)) .
            '#' .
            $anchor;
    };
    echo '<div class="type-pagination"><span>' .
        ($total ? ($page - 1) * $pageSize + 1 . '–' . min($page * $pageSize, $total) : '0') .
        ' of ' .
        $total .
        ' records</span><span class="type-page-count">Page ' .
        $page .
        ' of ' .
        $pages .
        '</span>';
    foreach ([$page - 1 => 'Previous', $page + 1 => 'Next'] as $target => $label) {
        $disabled = $target < 1 || $target > $pages;
        echo '<a href="' .
            icct_nms_h($url(max(1, min($pages, $target)))) .
            '" class="pagination-control" aria-label="' .
            $label .
            ' records page"' .
            ($disabled ? ' aria-disabled="true" tabindex="-1"' : '') .
            '>' .
            ($label === 'Previous'
                ? '<svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 7 12 15 19Z"/></svg>'
                : '<svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5 17 12 9 19Z"/></svg>') .
            '</a>';
    }
    echo '</div>';
};
