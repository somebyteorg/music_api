<?php

namespace App\Services;

use App\Models;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Catalog
{
    const TYPES = ['songs' => Models\Song::class, 'albums' => Models\Album::class, 'artists' => Models\Artist::class, 'libraries' => Models\MediaLibrary::class];

    public function write(Request $request, string $type, ?string $id = null)
    {
        $class = $this->model($type);
        abort_if($type === 'libraries' && !$request->user()->isAdmin(), 403, '只有管理员可以设置媒体库。');
        Models\MediaLibrary::ensureDefault($request->user());
        $data = $request->validate($this->rules($type, $id !== null, $id));

        return DB::transaction(function () use ($request, $type, $id, $class, $data) {
            $entity = $id ? $class::select($class::FIELDS)->lockForUpdate()->findOrFail($id) : new $class;
            abort_if($id && $data['version'] !== $entity->version, 409, '资料已被其他用户修改，请刷新后重新保存。');
            $before = $id ? app(Activity::class)->snapshot($entity) : null;
            if ($type === 'albums' && !empty($data['release_date']) && !empty($data['release_year'])) {
                abort_if((int)substr($data['release_date'], 0, 4) !== $data['release_year'], 422, '发行日期与发行年份不一致，请核对。');
            }
            $artistIds = $data['artist_ids'] ?? null;
            $recordingSongId = $data['recording_song_id'] ?? null;
            $albums = $data['albums'] ?? null;
            unset($data['artist_ids'], $data['version'], $data['recording_song_id'], $data['albums']);
            if ($type === 'songs') {
                Models\MediaLibrary::select(['id'])->lockForUpdate()->findOrFail($data['library_id']);
                if ($recordingSongId) {
                    $data['recording_id'] = Models\Song::whereKey($recordingSongId)->value('recording_id');
                }
            }
            foreach (['description', 'genre', 'recording_version', 'edition', 'release_country', 'label', 'catalog_number'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] ??= '';
                }
            }
            $entity->fill($data);
            if (!$id) {
                $entity->user_id = $request->user()->id;
            } else {
                $entity->version++;
            }
            $entity->save();
            if ($albums !== null) {
                app(AlbumTracks::class)->syncSong($entity, $albums);
            }
            if ($artistIds !== null) {
                $entity->artists()->sync($artistIds);
            }
            app(Activity::class)->record($request->user(), $id ? 'updated' : 'created', $type, $entity, $before);
            app(SyncScheduler::class)->touch();

            return $entity;
        });
    }

    public function model(string $type): string
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    public function rules(string $type, bool $updating = false, ?string $id = null): array
    {
        $rules = [
            'name'     => ['required', 'string', 'max:255'], 'description' => ['present', 'nullable', 'string', 'max:30000'],
            'cover_id' => ['nullable', 'uuid', Rule::exists('files', 'id')->where('kind', 'cover')->whereNull('deleted_at')],
        ];
        if ($updating) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($type, ['songs', 'albums'], true)) {
            $rules += [
                'release_date' => ['nullable', 'date_format:Y-m-d'], 'genre' => ['nullable', 'string', 'max:100'],
                'artist_ids'   => ['present', 'array', 'max:30'],
                'artist_ids.*' => ['uuid', 'distinct', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            ];
        }
        if (in_array($type, ['songs', 'artists'], true)) {
            $rules['musicbrainz_id'] = ['nullable', 'uuid', Rule::unique($type, 'musicbrainz_id')->ignore($id)];
        }
        if ($type === 'songs') {
            $rules += [
                'library_id'            => ['required', 'uuid', Rule::exists('libraries', 'id')->whereNull('deleted_at')],
                'albums'                => ['present', 'array', 'max:100'],
                'albums.*.album_id'     => ['required', 'uuid', 'distinct', Rule::exists('albums', 'id')->whereNull('deleted_at')],
                'albums.*.disc_number'  => ['required', 'integer', 'between:1,999'],
                'albums.*.track_number' => ['required', 'integer', 'between:1,9999'],
                'recording_version'     => ['nullable', 'string', 'max:100'],
                'recording_song_id'     => ['nullable', 'uuid', Rule::exists('songs', 'id')->whereNull('deleted_at')],
            ];
        }
        if ($type === 'albums') {
            $rules += app(AlbumCatalog::class)->rules();
            $rules['musicbrainz_id'][] = Rule::unique('albums', 'musicbrainz_id')->ignore($id);
        }
        if ($type === 'libraries') {
            $rules['name'][] = Rule::unique('libraries', 'name')->ignore($id);
        }

        return $rules;
    }

    public function delete(Request $request, string $type, string $id): void
    {
        $class = $this->model($type);
        abort_if($type === 'libraries' && !$request->user()->isAdmin(), 403, '只有管理员可以设置媒体库。');
        $data = $request->validate(['version' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $type, $class, $id, $data) {
            $entity = $class::select($class::FIELDS)->lockForUpdate()->findOrFail($id);
            abort_unless($entity->user_id === $request->user()->id || $request->user()->canModerate(), 403, '只有创建者、编辑或管理员可以删除这份资料。');
            abort_if($entity->user_id !== $request->user()->id && blank($data['reason'] ?? ''), 422, '删除他人的资料时，请填写原因。');
            abort_if($entity->version !== $data['version'], 409, '资料已更新，请刷新后重试。');
            abort_if($entity instanceof Models\MediaLibrary && $entity->is_default, 422, '默认媒体库不能删除，可以修改名称和简介。');
            $occupied = match ($type) {
                'songs'               => $entity->files()->exists(),
                'albums', 'libraries' => $entity->songs()->exists(),
                'artists'             => $entity->songs()->exists() || $entity->albums()->exists(),
            };
            abort_if($occupied, 422, '请先移出关联的歌曲或音频，再删除资料。');
            $before = app(Activity::class)->snapshot($entity);
            if ($entity instanceof Models\Song) {
                app(AlbumTracks::class)->syncSong($entity, []);
            }
            $entity->version++;
            $entity->save();
            $entity->delete();
            app(Activity::class)->record($request->user(), 'deleted', $type, $entity, $before, $data['reason'] ?? null);
            app(SyncScheduler::class)->touch();
        });
    }

    public function merge(Request $request, string $type, string $id): void
    {
        abort_if($type === 'libraries', 422, '请先批量移动歌曲，再删除空的媒体库。');
        app(CatalogMerge::class)->merge($request, $type, $id);
    }
}
