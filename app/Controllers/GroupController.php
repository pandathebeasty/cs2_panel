<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Models\GroupModel;
use App\Models\ServerModel;

final class GroupController extends Controller
{
    /** The available CSS flags (shared canonical list). */
    private function flagList(): array
    {
        return \App\Core\Flags::all();
    }

    public function index(): void
    {
        Auth::requireManage();
        $this->view('groups/index', [
            'groups'     => GroupModel::all(),
            'servers'    => ServerModel::options(),
            'flagList'   => $this->flagList(),
            'flagGroups' => \App\Core\Flags::grouped(),
            'csrf'       => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            $this->flash('error', 'Group name is required.');
            $this->redirect('/groups');
        }

        $immunity = (int) $this->input('immunity', 0);
        $flags    = (array) $this->input('flags', []);
        $servers  = (array) $this->input('servers', []);
        $id = GroupModel::create($name, $immunity, $flags, $servers);

        // Audit: target = group name, details = what the group was created with.
        $summary = 'Immunity: ' . $immunity
            . ' · Flags: ' . count(array_filter($flags, 'strlen'))
            . ' · Servers: ' . (count($servers) > 0 ? count($servers) : 'all');
        Logger::log('group.create', $name !== '' ? $name : (string) $id, $summary);
        $this->flash('success', 'Group created.');
        $this->redirect('/groups');
    }

    public function update(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $gid  = (int) $id;
        $before = GroupModel::find($gid); // state before the edit (for the diff)

        $name     = trim((string) $this->input('name', ''));
        $immunity = (int) $this->input('immunity', 0);
        $flags    = (array) $this->input('flags', []);
        $servers  = (array) $this->input('servers', []);

        GroupModel::update($gid, $name, $immunity, $flags, $servers);

        // Audit: target = group name; details = a real before -> after diff.
        $after = [
            'name'     => $name,
            'immunity' => $immunity,
            'flags'    => array_values(array_filter(array_map('strval', $flags), 'strlen')),
            'servers'  => array_map('intval', $servers),
        ];
        $details = $this->diffGroup($before, $after);
        Logger::log('group.update', $name !== '' ? $name : (string) $gid, $details);
        $this->flash('success', 'Group updated.');
        $this->redirect('/groups');
    }

    public function delete(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $gid = (int) $id;
        $before = GroupModel::find($gid); // resolve the name before it's gone
        GroupModel::delete($gid);
        // Audit: target = the group's name (not the numeric id).
        Logger::log('group.delete', $before['name'] ?? (string) $gid);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $this->json(['ok' => true]);
        }
        $this->flash('success', 'Group deleted.');
        $this->redirect('/groups');
    }

    /**
     * Build a human "before -> after" diff for a group edit, used as the Discord
     * audit details. Only changed parts are listed; "No changes" when identical.
     *
     * @param array{name:string,immunity:int,flags:string[],servers:int[]}|null $before
     * @param array{name:string,immunity:int,flags:string[],servers:int[]}      $after
     */
    private function diffGroup(?array $before, array $after): string
    {
        if ($before === null) {
            return 'Immunity: ' . $after['immunity'] . ' · Flags: ' . count($after['flags']);
        }
        $parts = [];
        if ((string) $before['name'] !== (string) $after['name']) {
            $parts[] = 'Name: ' . $before['name'] . ' -> ' . $after['name'];
        }
        if ((int) $before['immunity'] !== (int) $after['immunity']) {
            $parts[] = 'Immunity: ' . $before['immunity'] . ' -> ' . $after['immunity'];
        }
        // Flags added / removed.
        $oldFlags = array_values(array_unique($before['flags']));
        $newFlags = array_values(array_unique($after['flags']));
        $addedF   = array_values(array_diff($newFlags, $oldFlags));
        $removedF = array_values(array_diff($oldFlags, $newFlags));
        if ($addedF || $removedF) {
            $fp = [];
            if ($addedF)   { $fp[] = '+' . implode(' +', $addedF); }
            if ($removedF) { $fp[] = '-' . implode(' -', $removedF); }
            $parts[] = 'Flags: ' . implode(' ', $fp);
        }
        // Server scope added / removed (by id).
        $oldS = array_map('intval', $before['servers']);
        $newS = array_map('intval', $after['servers']);
        $addedS   = array_values(array_diff($newS, $oldS));
        $removedS = array_values(array_diff($oldS, $newS));
        if ($addedS || $removedS) {
            $sp = [];
            if ($addedS)   { $sp[] = '+' . implode(' +', $addedS); }
            if ($removedS) { $sp[] = '-' . implode(' -', $removedS); }
            $parts[] = 'Servers: ' . implode(' ', $sp);
        }
        return $parts ? implode(' · ', $parts) : 'No changes';
    }
}
