<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Settings;
use App\Models\BenefitModel;

/**
 * Public Benefits page + admin CRUD (called from the Settings editor).
 */
final class BenefitController extends Controller
{
    /** Where uploaded benefit icons live (relative to the public root). */
    private const UPLOAD_DIR = '/assets/img/benefits/uploads';

    /** Public page: one card per category with its perk list. */
    public function index(): void
    {
        if (!Settings::bool('benefits_enabled', false)) {
            $this->redirect('/');
        }

        $cards = BenefitModel::ordered();

        $this->view('benefits/index', [
            'cards' => $cards,
        ]);
    }

    /**
     * Save the whole benefits catalog (called from the Settings editor).
     *
     * Parallel arrays, one entry per row (indexed by the editor's row key):
     *   ben_category[]   card heading
     *   ben_desc[]       the perk list (long free text)
     *   ben_icon[]       the current icon value (emoji / known name / filename)
     *   ben_enabled[]    checkbox
     *   ben_icon_reset[] when set, the row's icon is cleared
     *   $_FILES['ben_icon_file'] optional uploaded image per row
     *
     * An uploaded file wins over the text value; a reset clears both.
     */
    public function save(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $categories = (array) $this->input('ben_category', []);
        $descs      = (array) $this->input('ben_desc', []);
        $icons      = (array) $this->input('ben_icon', []);
        $enabled    = (array) $this->input('ben_enabled', []);
        $resets     = (array) $this->input('ben_icon_reset', []);

        // Iterate over every row index that appears in any of the arrays.
        $keys = array_keys($categories + $descs + $icons);

        $rows = [];
        foreach ($keys as $i) {
            $category = trim((string) ($categories[$i] ?? ''));
            $description = trim((string) ($descs[$i] ?? ''));
            $icon = trim((string) ($icons[$i] ?? ''));

                                                                           
            if (!empty($resets[$i])) {
                $this->deleteUploadedIcon($icon);
                $icon = '';
            } else {
                // A new upload for this row replaces the icon value.
                $uploaded = $this->handleIconUpload($i);
                if ($uploaded !== null) {
                    $this->deleteUploadedIcon($icon); // drop the previous upload
                    $icon = $uploaded;
                }
            }

            if ($category === '' && $description === '') {
                continue; // nothing worth keeping
            }
            $rows[] = [
                'category'    => $category,
                'description' => $description,
                'icon'        => $icon,
                'enabled'     => !empty($enabled[$i]),
            ];
        }

        BenefitModel::replaceAll($rows);

        // The page on/off toggle lives on the same form.
        Settings::set('benefits_enabled', $this->input('benefits_enabled') ? '1' : '0');

        $this->flash('success', Lang::t('benefits.saved'));
        $this->redirect('/settings#benefits');
    }

    /**
     * Store an uploaded icon for row $i, if one was sent. Returns the stored
     * filename (relative to the uploads dir) or null when there is no upload.
     * Only common image types are accepted; anything else is ignored.
     */
    private function handleIconUpload(int|string $i): ?string
    {
        if (!isset($_FILES['ben_icon_file']) || !is_array($_FILES['ben_icon_file']['name'] ?? null)) {
            return null;
        }
        $err = $_FILES['ben_icon_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            return null;
        }
        $tmp  = (string) ($_FILES['ben_icon_file']['tmp_name'][$i] ?? '');
        $size = (int) ($_FILES['ben_icon_file']['size'][$i] ?? 0);
        $name = (string) ($_FILES['ben_icon_file']['name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }
        // Max 2 MB.
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return null;
        }

                                                          
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        if (!isset($allowed[$ext])) {
            return null;
        }
        if ($ext !== 'svg') {
            $info = @getimagesize($tmp);
            if ($info === false) {
                return null;
            }
        }

        $dir = PUBLIC_PATH . self::UPLOAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[CS2Panel] benefits uploads dir not writable: ' . $dir);
            return null;
        }

        $filename = 'ben_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $filename)) {
            error_log('[CS2Panel] benefits icon move failed for ' . $filename);
            return null;
        }
        return $filename;
    }

    /**
     * Remove a previously uploaded icon file. Only touches files that live in
     * the uploads dir (a bundled icon name / emoji / external value is left
     * alone). Best-effort; failure is non-fatal.
     */
    private function deleteUploadedIcon(string $icon): void
    {
        $icon = trim($icon);
        // Only our own uploaded files are deletable (plain basename, no slashes).
        if ($icon === '' || !preg_match('/^ben_[a-f0-9]{12}\.(png|jpe?g|gif|webp|svg)$/i', $icon)) {
            return;
        }
        $path = PUBLIC_PATH . self::UPLOAD_DIR . '/' . $icon;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
