<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Activity Tracker — tracks file operations via git auto-commits.
 *
 * Initializes a git repository at ABSPATH (if none exists) and commits
 * after every file operation performed through Novamira abilities.
 */

/**
 * Get the full path to the git binary, or null if not found.
 */
function novamira_tracker_git_path(): ?string
{
    static $git_path = false;
    if ($git_path !== false) {
        return $git_path;
    }

    // Try git in PATH first.
    exec('git --version 2>/dev/null', $output, $code);
    if ($code === 0) {
        $git_path = 'git';
        return $git_path;
    }

    // Check common locations (macOS Homebrew, Xcode, Linux).
    $candidates = [
        '/opt/homebrew/bin/git',
        '/usr/local/bin/git',
        '/usr/bin/git',
    ];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            $git_path = $candidate;
            return $git_path;
        }
    }

    $git_path = null;
    return $git_path;
}

/**
 * Check whether git is available on this system.
 */
function novamira_tracker_has_git(): bool
{
    return novamira_tracker_git_path() !== null;
}

/**
 * Check whether the activity tracker is enabled.
 */
function novamira_tracker_is_enabled(): bool
{
    return (bool) get_option('novamira_activity_tracker_enabled', false);
}

/**
 * Initialize a git repository at ABSPATH if one does not already exist.
 *
 * @return bool True if a repo exists (or was created), false on failure.
 */
function novamira_tracker_init(): bool
{
    if (!novamira_tracker_has_git()) {
        return false;
    }

    $abspath = rtrim(ABSPATH, '/');

    // Already a git repo.
    if (is_dir($abspath . '/.git')) {
        return true;
    }

    // Initialize a new repo.
    $git = novamira_tracker_git_path() ?? 'git';
    exec(sprintf('cd %s && %s init 2>&1', escapeshellarg($abspath), escapeshellarg($git)), $output, $code);
    if ($code !== 0) {
        return false;
    }

    // Write a .gitignore that ignores everything by default.
    // Only files explicitly staged by the tracker will be committed.
    $gitignore_path = $abspath . '/.gitignore';
    if (!file_exists($gitignore_path)) {
        $gitignore = implode("\n", [
            '# Novamira Activity Tracker — ignore everything by default.',
            '# Only files touched by AI agents are tracked via explicit git add.',
            '*',
        ]) . "\n";
        file_put_contents($gitignore_path, $gitignore);
    }

    // Initial empty commit.
    novamira_tracker_exec('add --force -- .gitignore');
    novamira_tracker_exec('commit --allow-empty -m "Novamira: activity tracker initialized"');

    return true;
}

/**
 * Run a git command inside ABSPATH.
 *
 * @param string $command        Git subcommand (e.g. "add -A", "commit -m '...'").
 * @param bool   $capture_output Whether to capture stdout (false = discard, saves memory).
 * @return array{output: string, code: int}
 */
function novamira_tracker_exec(string $command, bool $capture_output = false): array
{
    $abspath = rtrim(ABSPATH, '/');
    $git = novamira_tracker_git_path() ?? 'git';
    $redirect = $capture_output ? '2>&1' : '>/dev/null 2>&1';
    $full = sprintf(
        'cd %s && GIT_AUTHOR_NAME=%s GIT_AUTHOR_EMAIL=%s GIT_COMMITTER_NAME=%s GIT_COMMITTER_EMAIL=%s %s %s %s',
        escapeshellarg($abspath),
        escapeshellarg('Novamira AI'),
        escapeshellarg('ai@novamira.ai'),
        escapeshellarg('Novamira AI'),
        escapeshellarg('ai@novamira.ai'),
        escapeshellarg($git),
        $command,
        $redirect,
    );
    exec($full, $output, $code);
    return ['output' => $capture_output ? implode("\n", $output) : '', 'code' => $code];
}

/**
 * Auto-commit a file operation.
 *
 * @param string $action   The ability name (e.g. "write-file", "delete-file").
 * @param string $path     Absolute path of the affected file.
 * @param string $detail   Optional extra detail for the commit message.
 */
function novamira_tracker_commit(string $action, string $path, string $detail = ''): void
{
    if (!novamira_tracker_is_enabled() || !novamira_tracker_has_git()) {
        return;
    }

    // Ensure repo exists.
    if (!novamira_tracker_init()) {
        return;
    }

    $abspath = rtrim(ABSPATH, '/');
    $relative = str_starts_with($path, $abspath)
        ? ltrim(substr($path, strlen($abspath)), '/')
        : basename($path);

    $message = sprintf('[novamira] %s: %s', $action, $relative);
    if ($detail !== '') {
        $message .= "\n\n" . $detail;
    }

    if ($action === 'delete-file') {
        // Stage the deletion.
        novamira_tracker_exec(sprintf('add -A -- %s', escapeshellarg($relative)));
    } else {
        // Stage the file (--force bypasses the catch-all .gitignore).
        novamira_tracker_exec(sprintf('add --force -- %s', escapeshellarg($relative)));
    }

    // Commit (skip if nothing staged).
    $check = novamira_tracker_exec('diff --cached --quiet');
    if ($check['code'] !== 0) {
        novamira_tracker_exec(sprintf('commit -m %s', escapeshellarg($message)));
    }
}

/**
 * Get the activity log (git log) for the admin UI.
 *
 * @param int $limit  Max entries to return.
 * @param int $offset Entries to skip.
 * @return list<array{hash: string, date: string, message: string, files: string}>
 */
function novamira_tracker_log(int $limit = 50, int $offset = 0): array
{
    if (!novamira_tracker_is_enabled() || !novamira_tracker_has_git()) {
        return [];
    }

    $abspath = rtrim(ABSPATH, '/');
    if (!is_dir($abspath . '/.git')) {
        return [];
    }

    $result = novamira_tracker_exec(sprintf(
        'log --skip=%d -n %d --pretty=format:"%%H|%%ai|%%an|%%s" --name-only',
        $offset,
        $limit,
    ), capture_output: true);

    if ($result['code'] !== 0 || $result['output'] === '') {
        return [];
    }

    $entries = [];
    $blocks = preg_split('/\n{2,}/', trim($result['output']));
    if ($blocks === false) {
        return [];
    }

    foreach ($blocks as $block) {
        $lines = explode("\n", trim($block));
        $header = $lines[0] ?? '';
        $parts = explode('|', $header, 4);
        if (count($parts) < 4) {
            continue;
        }
        $files = array_slice($lines, 1);
        $entries[] = [
            'hash' => $parts[0],
            'date' => $parts[1],
            'author' => $parts[2],
            'message' => $parts[3],
            'files' => implode(', ', array_filter($files)),
        ];
    }

    return $entries;
}

/**
 * Get the diff for a specific commit.
 *
 * @param string $hash Commit hash.
 * @return string The diff output.
 */
function novamira_tracker_diff(string $hash): string
{
    if (!preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
        return '';
    }
    $result = novamira_tracker_exec(sprintf('show --stat --patch %s', escapeshellarg($hash)), capture_output: true);
    return $result['code'] === 0 ? $result['output'] : '';
}

/**
 * Revert a specific commit.
 *
 * @param string $hash Commit hash to revert.
 * @return array{success: bool, output: string}
 */
function novamira_tracker_revert(string $hash): array
{
    if (!preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
        return ['success' => false, 'output' => 'Invalid commit hash.'];
    }
    $result = novamira_tracker_exec(sprintf('revert --no-edit %s', escapeshellarg($hash)), capture_output: true);
    return [
        'success' => $result['code'] === 0,
        'output' => $result['output'],
    ];
}
