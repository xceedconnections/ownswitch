<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Auth\AuthService;

final class AuthController extends BaseController
{
    public function loginForm(Request $r, array $p = []): Response
    {
        App::session()->start();
        if (App::session()->userId() !== null) {
            return $this->redirect($r, '/');
        }
        return $this->view('auth/login', ['title' => 'Sign in', 'error' => null, 'email' => '']);
    }

    public function login(Request $r, array $p = []): Response
    {
        $email = is_string($r->post['email'] ?? null) ? (string) $r->post['email'] : '';
        $pass = is_string($r->post['password'] ?? null) ? (string) $r->post['password'] : '';
        $res = App::auth()->attempt($email, $pass, $r->ip());
        if (!$res['ok']) {
            return $this->view('auth/login', ['title' => 'Sign in', 'error' => $res['error'], 'email' => $email], 401);
        }
        $r->setAttr('user', $res['user']);
        $this->audit($r, 'login', 'user', (int) $res['user']['id']);
        return $this->redirect($r, '/');
    }

    public function logout(Request $r, array $p = []): Response
    {
        $this->audit($r, 'logout', 'user', (int) ($this->user($r)['id'] ?? 0) ?: null);
        App::auth()->logout();
        return Response::redirect($r->basePath . '/login');
    }

    public function passwordForm(Request $r, array $p = []): Response
    {
        return $this->view('auth/password', ['title' => 'Change password', 'errors' => []]);
    }

    public function passwordChange(Request $r, array $p = []): Response
    {
        $cur = is_string($r->post['current'] ?? null) ? (string) $r->post['current'] : '';
        $new = is_string($r->post['new'] ?? null) ? (string) $r->post['new'] : '';
        $confirm = is_string($r->post['confirm'] ?? null) ? (string) $r->post['confirm'] : '';
        if ($new !== $confirm) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => ['New password and confirmation do not match.']], 422);
        }
        $res = App::auth()->changePassword((int) $this->user($r)['id'], $cur, $new);
        if (!$res['ok']) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => $res['errors']], 422);
        }
        $this->audit($r, 'password_change', 'user', (int) $this->user($r)['id']);
        return $this->redirect($r, '/', 'Password changed.');
    }
}
