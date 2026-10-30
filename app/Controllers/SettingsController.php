<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Settings\SettingsService;

final class SettingsController extends BaseController
{
    public function edit(Request $r, array $p = []): Response
    {
        $s = new SettingsService($this->db());
        $values = [];
        foreach (SettingsService::SCHEMA as $k => $_) {
            $values[$k] = $s->get($k, '');
        }
        return $this->view('settings/edit', ['title' => 'Settings', 'schema' => SettingsService::SCHEMA, 'values' => $values, 'errors' => []]);
    }

    public function update(Request $r, array $p = []): Response
    {
        $s = new SettingsService($this->db());
        $errors = [];
        $clean = [];
        foreach (SettingsService::SCHEMA as $k => $_) {
            [$v, $err] = SettingsService::validate($k, $r->post[$k] ?? null);
            if ($err !== null) {
                $errors[$k] = $err;
            } else {
                $clean[$k] = $v;
            }
        }
        if ($errors) {
            return $this->view('settings/edit', ['title' => 'Settings', 'schema' => SettingsService::SCHEMA, 'values' => array_filter($r->post, 'is_string'), 'errors' => $errors], 422);
        }
        $uid = (int) $this->user($r)['id'];
        $before = $s->all();
        foreach ($clean as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $s->set($k, (string) $v, $uid);
            }
        }
        $this->audit($r, 'settings_update', 'system_settings', null, array_intersect_key($before, $clean), $clean);
        return $this->redirect($r, '/settings', 'Settings saved.');
    }
}
