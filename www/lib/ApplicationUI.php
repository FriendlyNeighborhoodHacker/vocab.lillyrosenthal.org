<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/FlashcardProgress.php';

class ApplicationUI {

    /**
     * Generate a cache-busted URL for a static resource
     */
    public static function staticResourceUrl(string $path): string {
        $filePath = __DIR__ . '/../' . ltrim($path, '/');
        $version = @filemtime($filePath);
        if (!$version) {
            $version = date('Ymd');
        }
        return $path . '?v=' . $version;
    }

    /**
     * Generate a complete CSS link tag with cache-busting
     */
    public static function cssLink(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate a complete JS script tag with cache-busting
     */
    public static function jsScript(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    /**
     * The score chip shown in the top bar: words mastered (latest mark was
     * "Got it") out of the whole deck, with today's review count underneath.
     * review.js repaints the numbers from every mark response.
     */
    public static function scoreChipHtml(int $userId): string {
        $score = FlashcardProgress::getScoreSummary($userId);
        return '<a class="score-chip" id="score-chip" href="/progress/" title="Words you\'ve got — tap for stats">'
             . '<span class="score-star" aria-hidden="true">&#11088;</span>'
             . '<span class="score-numbers"><span id="score-mastered">' . number_format($score['mastered']) . '</span>'
             . ' / <span id="score-total">' . number_format($score['total_words']) . '</span></span>'
             . '<span class="score-today"><span id="score-today">' . number_format($score['reviewed_today']) . '</span> today</span>'
             . '</a>';
    }

    /**
     * Page shell: playful top bar with the brand, main nav, score chip, and
     * profile menu; content renders inside <main>.
     */
    public static function headerHtml(string $title): void {
        $u = current_user();
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $siteTitle = Settings::siteTitle();

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . h($title) . ' - ' . h($siteTitle) . '</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body>';

        if ($u) {
            $navItems = [
                ['path' => '/review/', 'label' => 'Flashcards', 'prefixes' => ['/review/', '/index.php']],
                ['path' => '/progress/', 'label' => 'My Stats', 'prefixes' => ['/progress/']],
            ];

            echo '<header class="topbar">';
            echo '<a class="brand" href="/review/"><span class="brand-mark" aria-hidden="true">&#10024;</span> ' . h($siteTitle) . '</a>';

            echo '<nav class="topnav">';
            foreach ($navItems as $item) {
                $active = false;
                foreach ($item['prefixes'] as $prefix) {
                    if (strpos($script, $prefix) === 0) { $active = true; break; }
                }
                echo '<a href="' . h($item['path']) . '"' . ($active ? ' class="active"' : '') . '>' . h($item['label']) . '</a>';
            }

            if (!empty($u['is_admin'])) {
                $inAdmin = strpos($script, '/admin/') === 0;
                $adminItems = [
                    ['path' => '/admin/words.php', 'label' => 'Words'],
                    ['path' => '/admin/import/upload.php?flow=words', 'label' => 'Import Words'],
                    ['path' => '/admin/users.php', 'label' => 'Users'],
                    ['path' => '/admin/settings.php', 'label' => 'Settings'],
                    ['path' => '/admin/activity_log.php', 'label' => 'Activity Log'],
                    ['path' => '/admin/email_log.php', 'label' => 'Email Log'],
                ];
                $adminLinks = '';
                foreach ($adminItems as $item) {
                    $active = $inAdmin && basename(parse_url($item['path'], PHP_URL_PATH)) === basename($script);
                    $adminLinks .= '<a href="' . h($item['path']) . '" role="menuitem"' . ($active ? ' class="active"' : '') . '>' . h($item['label']) . '</a>';
                }
                echo '<span class="menu-wrap">'
                   . '<button type="button" id="adminToggle" class="topnav-toggle' . ($inAdmin ? ' active' : '') . '" aria-expanded="false" aria-controls="adminMenu">Admin &#9662;</button>'
                   . '<span id="adminMenu" class="popup-menu hidden" role="menu" aria-hidden="true">' . $adminLinks . '</span>'
                   . '</span>';
            }
            echo '</nav>';

            echo '<div class="topbar-right">';
            echo self::scoreChipHtml((int)$u['id']);

            $initials = strtoupper((string)substr((string)($u['first_name'] ?? ''), 0, 1) . (string)substr((string)($u['last_name'] ?? ''), 0, 1));
            $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
            echo '<span class="menu-wrap">'
               . '<button type="button" id="profileToggle" class="avatar" aria-expanded="false" aria-controls="profileMenu" title="' . h($name) . '" aria-label="Account menu for ' . h($name) . '">' . h($initials) . '</button>'
               . '<span id="profileMenu" class="popup-menu popup-menu-right hidden" role="menu" aria-hidden="true">'
               .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
               .   '<a href="/logout.php" role="menuitem">Logout</a>'
               . '</span>'
               . '</span>';
            echo '</div>';
            echo '</header>';

            echo '<div class="content"><main>';
        } else {
            echo '<header class="topbar"><a class="brand" href="/login.php"><span class="brand-mark" aria-hidden="true">&#10024;</span> ' . h($siteTitle) . '</a></header>';
            echo '<div class="content"><main>';
        }
    }

    public static function footerHtml(): void {
        echo '</main></div>' . self::jsScript('/main.js') . '</body></html>';
    }
}
