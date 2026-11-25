<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;

class User extends Base implements AuthenticatableContract
{
    use Authenticatable;

    const PUBLIC_FIELDS = ['id', 'username', 'pseudonym', 'avatar'];

    const SESSION_FIELDS = [...self::PUBLIC_FIELDS, 'emos_id', 'role', 'quality', 'size_upload', 'notice_version', 'notice_accepted_at'];

    const AUTH_FIELDS = [...self::SESSION_FIELDS, 'remember_token'];

    protected $hidden = ['remember_token', 'emos_id'];

    protected $attributes = ['role' => 'user', 'quality' => 'high'];

    public function getAuthPasswordName(): string
    {
        // EMOS verifies passwords; bind Laravel's remember cookie to the revocable local token.
        return $this->getRememberTokenName();
    }

    public function scopeNamed(Builder $query, string $term): void
    {
        $pattern = '%' . addcslashes($term, '%_\\') . '%';
        $query->where(fn($names) => $names->where('username', 'ilike', $pattern)->orWhere('pseudonym', 'ilike', $pattern));
    }

    public function canModerate(): bool
    {
        return in_array($this->role, ['edit', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    protected function casts(): array
    {
        return ['notice_accepted_at' => 'datetime', 'size_upload' => 'integer'];
    }
}
