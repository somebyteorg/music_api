<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuthController extends Controller
{
    public function csrfToken()
    {
        return ['csrf_token' => csrf_token()];
    }

    public function callback(Request $r): RedirectResponse
    {
        try {
            $data = $r->validate(['token' => ['required', 'string', 'max:4096']], ['token.required' => '没有收到 EMOS 登录凭证，请重新登录。']);
            $this->authenticate($r, $data);
            $r->session()->forget('login_error');
        } catch (ValidationException $e) {
            $r->session()->flash('login_error', $e->validator->errors()->first());
        } catch (HttpExceptionInterface $e) {
            $r->session()->flash('login_error', $e->getMessage());
        }

        return new RedirectResponse('/');
    }

    private function authenticate(Request $r, array $data): Models\User
    {
        $url = rtrim((string)config('services.emos.url'), '/');
        abort_if($url === '', 503, 'EMOS 登录还没有配置。');
        try {
            $http = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->acceptJson();
            $res = !empty($data['token']) ? $http->withToken($data['token'])->get($url . '/api/user/base') : $http->post($url . '/server/music/userLogin', ['username' => $data['username'], 'password' => $data['password'], 'client_ip' => $r->ip(), 'ua' => $r->userAgent()]);
        } catch (ConnectionException $e) {
            abort(502, '无法连接 EMOS，请稍后重试。');
        }
        abort_if(in_array($res->status(), [401, 403]), 401, '账号或凭证没有通过验证，请重新登录。');
        abort_unless($res->successful(), 502, 'EMOS 登录失败，请稍后重试。');
        $profile = validator(['emos_id' => $res->json('user_id') ?? $res->json('emos_id'), 'username' => $res->json('username'), 'avatar' => $res->json('avatar')], ['emos_id' => 'required|string|max:10', 'username' => 'required|string|max:255', 'avatar' => 'nullable|url:http,https|max:2048'])->validate();
        $user = DB::transaction(function () use ($profile) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['emos-user:' . $profile['emos_id']]);
            $user = Models\User::select(Models\User::AUTH_FIELDS)->where('emos_id', $profile['emos_id'])->first();
            if ($user) {
                $user->update(['username' => $profile['username'], 'avatar' => $profile['avatar']]);
            } else {
                $user = Models\User::create($profile);
            }

            return $user;
        });
        app(Services\ServerAccount::class)->sync($user, password: empty($data['token']) ? $data['password'] : null);
        Auth::login($user, remember: true);
        $r->session()->regenerate();

        return $user;
    }

    public function login(Request $r)
    {
        $data = $r->validate([
            'token'    => ['nullable', 'string', 'max:4096'],
            'username' => ['required_without:token', 'string', 'max:100'],
            'password' => ['required_without:token', 'string', 'max:255'],
        ]);
        abort_if(empty($data['token']) && (empty($data['username']) || empty($data['password'])), 422, '请输入 EMOS 登录令牌或账号密码。');
        $user = $this->authenticate($r, $data);

        return ['user' => $user, 'csrf_token' => csrf_token()];
    }

    public function session(Request $r)
    {
        return [
            'user'         => $r->user(),
            'contribution' => config('contribution'),
            'sign_url'     => config('services.emos.url_link') ?: rtrim(config('services.emos.url'), '/') . '/api/sign?state=emos_music',
            'login_error'  => $r->session()->pull('login_error'),
            'server_url'   => '/web/',
            'todb_enabled' => filled(config('services.todb.url')),
        ];
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['message' => '已退出登录。'];
    }

    public function notice(Request $r)
    {
        $r->validate(['accepted' => ['required', 'accepted'], 'version' => ['required', Rule::in([config('contribution.notice.version')])]]);
        $r->user()->update(['notice_version' => config('contribution.notice.version'), 'notice_accepted_at' => now()]);

        return ['user' => $r->user()];
    }

    public function profile(Request $r, Services\ServerAccount $accounts)
    {
        $data = $r->validate([
            'pseudonym'       => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'pseudonym')->ignore($r->user()->id)],
            'quality'         => ['sometimes', Rule::in(array_keys(config('contribution.quality_labels')))],
            'server_username' => ['prohibited'],
            'server_password' => ['prohibited'],
        ]);

        return DB::transaction(function () use ($r, $accounts, $data) {
            $user = Models\User::select(Models\User::SESSION_FIELDS)->lockForUpdate()->findOrFail($r->user()->id);
            $user->fill($data);
            $accounts->sync($user);
            $user->save();

            return ['user' => $user];
        }, 3);
    }

    public function libraries(Request $r, Services\PlaybackLibraries $libraries)
    {
        return $libraries->settings($r->user());
    }

    public function updateLibraries(Request $r, Services\PlaybackLibraries $libraries)
    {
        $data = $r->validate([
            'library_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'library_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return $libraries->settings($r->user(), array_map('intval', $data['library_ids']));
    }
}
