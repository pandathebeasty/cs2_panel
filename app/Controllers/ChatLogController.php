<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Settings;
use App\Models\ChatLogModel;

final class ChatLogController extends Controller
{
    public function index(): void
    {
        Auth::requireManage();
        header('Cache-Control: no-store, private');

        $enabled = ChatLogModel::enabled();
        $filters = ['search' => '', 'server_id' => '', 'team' => '', 'channel' => ''];
        $error = null;
        foreach ($filters as $key => $_) {
            $value = $this->query($key, '');
            if (!is_string($value)) {
                $error = Lang::t('chat_logs.invalid_filters');
                continue;
            }
            $filters[$key] = trim($value);
        }
        if (strlen($filters['search']) > 1024 || strlen($filters['server_id']) > 36
            || ($filters['team'] !== '' && !in_array($filters['team'], ChatLogModel::TEAMS, true))
            || ($filters['channel'] !== '' && !in_array($filters['channel'], ChatLogModel::CHANNELS, true))) {
            $error = Lang::t('chat_logs.invalid_filters');
        }

        $defaultPerPage = (int) Settings::get('per_page', '25');
        if (!in_array($defaultPerPage, ChatLogModel::PER_PAGE_OPTIONS, true)) {
            $defaultPerPage = 25;
        }
        $perPage = filter_var($this->query('per_page', $defaultPerPage), FILTER_VALIDATE_INT);
        $page = filter_var($this->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($perPage === false || !in_array($perPage, ChatLogModel::PER_PAGE_OPTIONS, true) || $page === false) {
            $error = Lang::t('chat_logs.invalid_filters');
        }

        $logs = null;
        $serverIds = [];
        if ($enabled) {
            if ($error !== null) {
                http_response_code(400);
            } else {
                try {
                    $serverIds = ChatLogModel::serverIds();
                    $logs = ChatLogModel::paginate($filters, $page, $perPage);
                } catch (\PDOException | \RuntimeException | \InvalidArgumentException $exception) {
                    \cs2panel_log_error('Chat Logger data could not be loaded: ' . $exception->getMessage());
                    http_response_code(503);
                    $error = Lang::t('chat_logs.unavailable');
                }
            }
        }

        $this->view('chat_logs/index', [
            'enabled' => $enabled,
            'filters' => $filters,
            'perPage' => $perPage === false ? $defaultPerPage : $perPage,
            'serverIds' => $serverIds,
            'logs' => $logs,
            'error' => $error,
        ]);
    }
}
