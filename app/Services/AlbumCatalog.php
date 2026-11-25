<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AlbumCatalog
{
    public function draftRules(string $prefix): array
    {
        return [
            $prefix . 'id'           => ['required_with:' . rtrim($prefix, '.'), 'uuid'],
            $prefix . 'name'         => ['required_with:' . rtrim($prefix, '.'), 'string', 'max:255'],
            $prefix . 'artist_name'  => ['nullable', 'string', 'max:255'],
            $prefix . 'artist_credits' => ['sometimes', 'array', 'max:30'],
            $prefix . 'artist_credits.*.musicbrainz_id' => ['nullable', 'uuid'],
            $prefix . 'artist_credits.*.name' => ['required', 'string', 'max:255'],
            $prefix . 'artist_credits.*.credited_name' => ['nullable', 'string', 'max:255'],
            $prefix . 'artist_credits.*.joinphrase' => ['nullable', 'string', 'max:50'],
            $prefix . 'artist_ids'   => ['sometimes', 'array', 'max:30'],
            $prefix . 'artist_ids.*' => ['uuid', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            $prefix . 'cover_id'     => ['nullable', 'uuid', Rule::exists('files', 'id')->where('kind', 'cover')->whereNull('deleted_at')],
            ...$this->rules($prefix),
        ];
    }

    public function rules(string $prefix = ''): array
    {
        return [
            $prefix . 'release_date'      => ['nullable', 'date_format:Y-m-d'],
            $prefix . 'genre'             => ['nullable', 'string', 'max:100'],
            $prefix . 'release_year'      => ['nullable', 'integer', 'between:1000,9999'],
            $prefix . 'edition'           => ['nullable', 'string', 'max:100'],
            $prefix . 'release_country'   => ['nullable', 'string', 'max:100'],
            $prefix . 'label'             => ['nullable', 'string', 'max:255'],
            $prefix . 'catalog_number'    => ['nullable', 'string', 'max:100'],
            $prefix . 'musicbrainz_id'    => ['nullable', 'uuid'],
            $prefix . 'artist_credits' => ['sometimes', 'array', 'max:30'],
            $prefix . 'artist_credits.*.musicbrainz_id' => ['nullable', 'uuid'],
            $prefix . 'artist_credits.*.name' => ['required', 'string', 'max:255'],
            $prefix . 'artist_credits.*.credited_name' => ['nullable', 'string', 'max:255'],
            $prefix . 'artist_credits.*.joinphrase' => ['nullable', 'string', 'max:50'],
            $prefix . 'total_discs'       => ['nullable', 'integer', 'between:1,999'],
            $prefix . 'total_tracks'      => ['nullable', 'integer', 'between:1,9999'],
            $prefix . 'artwork'           => ['sometimes', 'array', 'max:100'],
            $prefix . 'artwork.*.file_id' => ['required', 'uuid', Rule::exists('files', 'id')->where('kind', 'cover')->whereNull('deleted_at')],
            $prefix . 'artwork.*.type'    => ['required', 'in:cover,back,disc,booklet,other'],
        ];
    }

    public function create(Models\User $user, array $data): Models\Album
    {
        if (!empty($data['release_date']) && !empty($data['release_year'])) {
            abort_if((int) substr($data['release_date'], 0, 4) !== (int) $data['release_year'], 422, '发行日期与发行年份不一致，请核对。');
        }
        // The client keeps this ID across batches/retries, so an album is created once.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['album-' . $data['id']]);
        $existing = Models\Album::withTrashed()->select(Models\Album::FIELDS)->lockForUpdate()->find($data['id']);
        if ($existing) {
            abort_if($existing->trashed() || $existing->user_id !== $user->id || $existing->name !== trim($data['name']), 409, '这张专辑的创建资料已变化，请重新选择专辑。');
            foreach (['release_date', 'genre', ...Models\Album::EDITION_FIELDS] as $field) {
                abort_if((string) ($existing->$field ?? '') !== (string) ($data[$field] ?? ''), 409, '专辑的发行资料已变化，请重新选择已有专辑。');
            }
            if (!empty($data['artist_ids'])) {
                $expected = collect($data['artist_ids'])->unique()->sort()->values()->all();
                abort_if($existing->artists()->orderBy('artists.id')->pluck('artists.id')->all() !== $expected, 409, '专辑艺人已变化，请重新选择已有专辑。');
            } elseif (!blank($data['artist_name'] ?? null)) {
                abort_if($existing->artists()->pluck('artists.name')->implode(' / ') !== trim($data['artist_name']), 409, '专辑艺人已变化，请重新选择已有专辑。');
            }

            return $existing;
        }
        if (!empty($data['musicbrainz_id'])) {
            abort_if(Models\Album::withTrashed()->where('musicbrainz_id', $data['musicbrainz_id'])->exists(), 422, '这个发行版本已存在，请选择已有专辑；已删除的专辑请先恢复。');
        }
        $artists = app(ArtistNames::class)->resolve($user, $data['artist_ids'] ?? [], $data['artist_name'] ?? null, $data['artist_credits'] ?? []);
        $fields = array_intersect_key($data, array_flip(['id', 'cover_id', 'release_date', 'genre', ...Models\Album::EDITION_FIELDS, 'artwork']));
        foreach (['edition', 'release_country', 'label', 'catalog_number'] as $key) {
            $fields[$key] = trim($fields[$key] ?? '');
        }
        $album = Models\Album::create([...$fields, 'name' => trim($data['name']), 'user_id' => $user->id]);
        $album->artists()->sync($artists);
        app(Activity::class)->record($user, 'created', 'albums', $album);

        return $album;
    }

    public function candidates(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $external = $data['musicbrainz_id'] ?? null;
        if ($name === '' && !$external) {
            return [];
        }
        $q = Models\Album::select(Models\Album::FIELDS)->with('artists:id,name')->withCount('songs');
        $q->where(function ($q) use ($name, $external) {
            if ($name !== '') {
                $q->where('name', 'ilike', '%' . addcslashes($name, '%_\\') . '%');
            }
            if ($external) {
                $q->orWhere('musicbrainz_id', $external);
            }
        });
        $normalize = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));

        return $q->orderByDesc('updated_at')->limit(20)->get()->map(function (Models\Album $album) use ($data, $name, $external, $normalize) {
            $conflicts = [];
            $sameName = $normalize($album->name) === $normalize($name);
            $sameArtist = !blank($data['artist_name'] ?? '') && $normalize($album->artists->pluck('name')->implode(' / ')) === $normalize($data['artist_name']);
            $sameId = $external && $album->musicbrainz_id === $external;
            if (!$sameName) {
                $conflicts[] = '专辑名称不同';
            }
            if (!blank($data['artist_name'] ?? '') && !$sameArtist) {
                $conflicts[] = '专辑艺人不同';
            }
            foreach (['release_date', 'release_year', 'genre', 'edition', 'release_country', 'label', 'catalog_number', 'musicbrainz_id', 'total_discs', 'total_tracks'] as $key) {
                $actual = $key === 'release_year' ? ($album->release_year ?: ($album->release_date ? substr($album->release_date, 0, 4) : null)) : $album->$key;
                if (!blank($data[$key] ?? null) && !blank($actual) && $normalize($data[$key]) !== $normalize($actual)) {
                    $labels = ['release_date' => '发行日期', 'release_year' => '发行年份', 'genre' => '流派', 'edition' => '发行版本', 'release_country' => '发行地区', 'label' => '厂牌', 'catalog_number' => '唱片编号', 'musicbrainz_id' => '外部发行 ID', 'total_discs' => '碟片总数', 'total_tracks' => '曲目总数'];
                    $conflicts[] = $labels[$key] . '不同';
                }
            }

            return [...$album->toArray(), 'recommended' => !$conflicts && ($sameId || ($sameName && $sameArtist)), 'conflicts' => $conflicts];
        })->sortByDesc('recommended')->values()->all();
    }

    public function assertSlot(?string $albumId, int $disc, int $track, array $except = []): void
    {
        if (!$albumId) {
            return;
        }
        $existing = Models\AlbumTrack::select(['song_id'])->where('album_id', $albumId)->where('disc_number', $disc)->where('track_number', $track)->whereNotIn('song_id', $except)->whereHas('song')->with('song:id,name')->first();
        abort_if($existing, 422, '第 ' . $disc . ' 碟第 ' . $track . ' 首已有「' . ($existing?->song?->name ?? '') . '」，请关联已有曲目或调整曲序。');
    }

    public function supplement(Models\User $user, string $id, array $assets): void
    {
        $album = Models\Album::select(Models\Album::FIELDS)->lockForUpdate()->findOrFail($id);
        $before = app(Activity::class)->snapshot($album);
        $hadReleaseDate = filled($album->release_date);
        foreach (['release_date', 'genre', ...Models\Album::EDITION_FIELDS] as $field) {
            if ($field === 'release_year' && $hadReleaseDate) {
                continue;
            }
            if (blank($album->$field) && !blank($assets[$field] ?? null)) {
                if ($field === 'musicbrainz_id') {
                    abort_if(Models\Album::withTrashed()->where('musicbrainz_id', $assets[$field])->whereKeyNot($id)->exists(), 422, '这个外部发行 ID 已属于其他专辑，请重新核对。');
                }
                $album->$field = $assets[$field];
            }
        }
        if (!empty($assets['replace_cover']) && array_key_exists('cover_id', $assets) && $album->cover_id !== $assets['cover_id']) {
            abort_unless($album->version === ($assets['cover_version'] ?? null), 409, '专辑资料刚有更新，请重新选择专辑后替换封面。');
            $album->cover_id = $assets['cover_id'];
        } elseif (!$album->cover_id && !$album->image_url && !empty($assets['cover_id'])) {
            $album->cover_id = $assets['cover_id'];
        }
        if (!empty($assets['artist_credits'])) {
            $artistIds = app(ArtistNames::class)->resolve($user, [], null, $assets['artist_credits']);
            $album->artists()->syncWithoutDetaching($artistIds);
        }
        $album->artwork = collect([...($album->artwork ?? []), ...($assets['artwork'] ?? [])])->unique('file_id')->values()->all();
        if ($album->isDirty()) {
            $album->version++;
            $album->save();
            app(Activity::class)->record($user, 'updated', 'albums', $album, $before, '根据确认的上传资料补全专辑信息与图片');
        }
    }
}
