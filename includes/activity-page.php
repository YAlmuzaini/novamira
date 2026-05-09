<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Handle revert actions (PRG pattern).
 */
function novamira_handle_activity_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $action = $_GET['action'] ?? null;
    $hash = $_GET['hash'] ?? null;

    if ($action !== 'revert' || !is_string($hash)) {
        return;
    }

    if (!check_admin_referer('novamira_revert_' . $hash)) {
        return;
    }

    $result = novamira_tracker_revert($hash);
    $status = $result['success'] ? 'reverted' : 'revert_failed';
    wp_safe_redirect(admin_url('admin.php?page=novamira-activity&novamira_result=' . $status));
    exit();
}

/**
 * Render the Activity Log admin page.
 */
function novamira_render_activity_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $result_message = match ($_GET['novamira_result'] ?? null) {
        'reverted' => __('Commit reverted successfully.', domain: 'novamira'),
        'revert_failed' => __('Failed to revert commit. Check the git log for conflicts.', domain: 'novamira'),
        default => null,
    };

    $has_git = novamira_tracker_has_git();
    $is_enabled = novamira_tracker_is_enabled();
    $viewing_diff = $_GET['diff'] ?? null;

    novamira_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Activity Log', domain: 'novamira'); ?></h1>

        <?php if ($result_message !== null) { ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php } ?>

        <?php if (!$has_git) { ?>
            <div class="notice notice-error">
                <p><?php esc_html_e(
                    'Git is not installed on this server. The activity tracker requires git to function.',
                    domain: 'novamira',
                ); ?></p>
            </div>
        <?php } elseif (!$is_enabled) { ?>
            <div class="notice notice-warning">
                <p>
                    <?php esc_html_e('The activity tracker is disabled. Enable it in', domain: 'novamira'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=novamira-connect')); ?>"><?php esc_html_e(
                        'Configuration',
                        domain: 'novamira',
                    ); ?></a>.
                </p>
            </div>
        <?php } elseif (is_string($viewing_diff) && preg_match('/^[0-9a-f]{7,40}$/', $viewing_diff)) { ?>
            <?php novamira_render_diff_view($viewing_diff); ?>
        <?php } else { ?>
            <?php novamira_render_activity_table(); ?>
        <?php } ?>
    </div>
    <?php
}

/**
 * Render the activity log table.
 */
function novamira_render_activity_table(): void
{
    $page_num = max(1, (int) ($_GET['paged'] ?? 1));
    $per_page = 30;
    $offset = ($page_num - 1) * $per_page;
    $entries = novamira_tracker_log(limit: $per_page, offset: $offset);

    ?>
    <p><?php esc_html_e('File operations performed by AI agents through Novamira.', domain: 'novamira'); ?></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:160px;"><?php esc_html_e('Date', domain: 'novamira'); ?></th>
                <th style="width:110px;"><?php esc_html_e('Author', domain: 'novamira'); ?></th>
                <th><?php esc_html_e('Action', domain: 'novamira'); ?></th>
                <th><?php esc_html_e('Files', domain: 'novamira'); ?></th>
                <th style="width:140px;"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($entries === []) { ?>
                <tr><td colspan="5"><?php esc_html_e('No activity recorded yet.', domain: 'novamira'); ?></td></tr>
            <?php } ?>
            <?php foreach ($entries as $entry) { ?>
                <?php

                $diff_url = admin_url('admin.php?page=novamira-activity&diff=' . urlencode($entry['hash']));
                $revert_url = wp_nonce_url(
                    admin_url('admin.php?page=novamira-activity&action=revert&hash=' . urlencode($entry['hash'])),
                    'novamira_revert_' . $entry['hash'],
                );
                $is_novamira = $entry['author'] === 'Novamira AI';
                ?>
                <tr>
                    <td><?php echo esc_html($entry['date']); ?></td>
                    <td>
                        <?php if ($is_novamira) { ?>
                            <span style="color: #d63638; font-weight: 600;"><?php echo
                                esc_html($entry['author'])
                            ; ?></span>
                        <?php } else { ?>
                            <?php echo esc_html($entry['author']); ?>
                        <?php } ?>
                    </td>
                    <td><code><?php echo esc_html($entry['message']); ?></code></td>
                    <td><small><?php echo esc_html($entry['files']); ?></small></td>
                    <td>
                        <a href="<?php echo esc_url($diff_url); ?>" class="button button-small"><?php esc_html_e(
                            'Diff',
                            domain: 'novamira',
                        ); ?></a>
                        <a href="<?php echo esc_url($revert_url); ?>" class="button button-small"
                           onclick="return confirm('<?php echo
                               esc_js(__('Revert this commit? This will undo the changes.', domain: 'novamira'))
                           ; ?>');"
                           style="color: #d63638; border-color: #d63638;"><?php esc_html_e(
                               'Revert',
                               domain: 'novamira',
                           ); ?></a>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php if (count($entries) === $per_page) { ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php if ($page_num > 1) { ?>
                    <a class="button" href="<?php echo
                        esc_url(admin_url('admin.php?page=novamira-activity&paged=' . ($page_num - 1)))
                    ; ?>">&laquo; <?php esc_html_e('Previous', domain: 'novamira'); ?></a>
                <?php } ?>
                <a class="button" href="<?php echo
                    esc_url(admin_url('admin.php?page=novamira-activity&paged=' . ($page_num + 1)))
                ; ?>"><?php esc_html_e('Next', domain: 'novamira'); ?> &raquo;</a>
            </div>
        </div>
    <?php } ?>
    <?php
}

/**
 * Render the diff view for a single commit.
 */
function novamira_render_diff_view(string $hash): void
{
    $diff = novamira_tracker_diff($hash);
    $back_url = admin_url('admin.php?page=novamira-activity');
    ?>
    <p>
        <a href="<?php echo esc_url($back_url); ?>" class="button">&laquo; <?php esc_html_e(
            'Back to Activity Log',
            domain: 'novamira',
        ); ?></a>
        <code><?php echo esc_html(substr($hash, offset: 0, length: 12)); ?></code>
    </p>
    <div style="background:#1e1e1e; color:#d4d4d4; padding:16px; border-radius:6px; overflow-x:auto; font-family:monospace; font-size:13px; line-height:1.5; white-space:pre-wrap; max-height:70vh; overflow-y:auto;"><?php

    // Colorize diff output.
    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
            echo '<span style="color:#4ec9b0;">' . esc_html($line) . "</span>\n";
        } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
            echo '<span style="color:#f44747;">' . esc_html($line) . "</span>\n";
        } elseif (str_starts_with($line, '@@')) {
            echo '<span style="color:#569cd6;">' . esc_html($line) . "</span>\n";
        } elseif (str_starts_with($line, 'diff') || str_starts_with($line, 'index')) {
            echo '<span style="color:#808080;">' . esc_html($line) . "</span>\n";
        } else {
            echo esc_html($line) . "\n";
        }
    }
    ?></div>
    <?php
}
