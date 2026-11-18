<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FilePublisher
{
    public function rules(string $prefix = ''): array
    {
        return [
            $prefix . 'publication_group' => ['nullable', 'uuid'],
            $prefix . 'song_id'                        => ['nullable', 'uuid', Rule::exists('songs', 'id')->whereNull('deleted_at')],
            $prefix . 'name'                           => ['required_without:' . $prefix . 'song_id', 'nullable', 'string', 'max:255'],
            $prefix . 'artist_ids'                     => ['nullable', 'array', 'max:30'], $prefix . 'artist_ids.*' => ['uuid', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            $prefix . 'artist_name'                    => ['nullable', 'string', 'max:255'],
            $prefix . 'musicbrainz_id'                    => ['nullable', 'uuid'],
            $prefix . 'release_date'                      => ['nullable', 'date_format:Y-m-d'],
            $prefix . 'genre'                            => ['nullable', 'string', 'max:100'],
            $prefix . 'artist_credits'                    => ['sometimes', 'array', 'max:30'],
            $prefix . 'artist_credits.*.musicbrainz_id'   => ['nullable', 'uuid'],
            $prefix . 'artist_credits.*.name'             => ['required', 'string', 'max:255'],
            $prefix . 'artist_credits.*.credited_name'    => ['nullable', 'string', 'max:255'],
            $prefix . 'artist_credits.*.joinphrase'       => ['nullable', 'string', 'max:50'],
            $prefix . 'recording_song_id'              => ['nullable', 'uuid', Rule::exists('songs', 'id')->whereNull('deleted_at')],
            $prefix . 'album'                          => ['nullable', 'array'],
            ...app(AlbumCatalog::class)->draftRules($prefix . 'album.'),
            $prefix . 'album_supplement'               => ['nullable', 'array'],
            $prefix . 'album_supplement.cover_id'      => ['nullable', 'uuid', Rule::exists('files', 'id')->where('kind', 'cover')->whereNull('deleted_at')],
            $prefix . 'album_supplement.replace_cover' => ['sometimes', 'boolean'],
            $prefix . 'album_supplement.cover_version' => ['required_if:' . $prefix . 'album_supplement.replace_cover,true', 'integer', 'min:1'],
            ...app(AlbumCatalog::class)->rules($prefix . 'album_supplement.'),
            $prefix . 'library_id'                     => ['nullable', 'uuid', Rule::exists('libraries', 'id')->whereNull('deleted_at')],
            $prefix . 'album_id'                       => ['nullable', 'uuid', Rule::exists('albums', 'id')->whereNull('deleted_at')],
            $prefix . 'recording_version'              => ['nullable', 'string', 'max:100'], $prefix . 'disc_number' => ['nullable', 'integer', 'between:1,999'],
            $prefix . 'track_number'                   => ['nullable', 'integer', 'between:1,9999'],
        ];
    }

    public function publishMany(Models\User $user, array $items): array
    {
        return DB::transaction(function () use ($user, $items) {
            $files = Models\StoredFile::select(['id', 'file_name'])->where('user_id', $user->id)->whereIn('id', array_column($items, 'file_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($files->count() === count($items), 403, '只能整理自己上传的待确认文件。');
            $results = [];
            foreach ($items as $item) {
                try {
                    $results[] = $this->publish($user, $item['file_id'], $item, false);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    abort($e->getStatusCode(), $files[$item['file_id']]->file_name . '：' . $e->getMessage());
                }
            }

            app(SyncScheduler::class)->touch();

            return ['items' => $results];
        }, 3);
    }

    public function publish(Models\User $user, string $id, array $data, bool $schedule = true): array
    {
        return DB::transaction(function () use ($user, $id, $data, $schedule) {
            $file = Models\StoredFile::select([...Models\StoredFile::FIELDS, 'publication'])->lockForUpdate()->findOrFail($id);
            abort_unless($file->kind === 'audio', 422, '只能把音频加入歌曲。');
            abort_unless($file->user_id === $user->id || $file->song_id !== null, 403, '待确认的文件只能由上传者处理。');
            $receipts = app(PublicationReceipts::class);
            if ($replay = $receipts->replay($user, $file, $data)) {
                return $replay;
            }
            $request = $data;
            $key = $receipts->trackKey($user, $data);
            $data = $receipts->resolve($user, $file, $data, $key);
            abort_if($file->song_id !== null && $file->song_id !== ($data['song_id'] ?? null), 409, '这份音频已经加入歌曲，请选择原歌曲来补充专辑关联。');
            $albumId = $data['album_id'] ?? null;
            if (!empty($data['musicbrainz_id'])) {
                $known = Models\Song::withTrashed()->where('musicbrainz_id', $data['musicbrainz_id'])->lockForUpdate()->first();
                if ($known) {
                    abort_if($known->trashed(), 422, '这首 MusicBrainz 录音对应的歌曲已删除，请先恢复资料。');
                    abort_if(!empty($data['song_id']) && $data['song_id'] !== $known->id, 409, '这个 MusicBrainz recording ID 已对应其他歌曲，请选择该歌曲以避免重复。');
                    $data['song_id'] = $known->id;
                    unset($data['recording_song_id']);
                }
            }
            if (!empty($data['album'])) {
                abort_if($albumId, 422, '请选择已有专辑或新建专辑。');
                $albumId = app(AlbumCatalog::class)->create($user, $data['album'])->id;
            }
            $created = empty($data['song_id']);
            if (!$created) {
                $song = Models\Song::select(Models\Song::FIELDS)->lockForUpdate()->findOrFail($data['song_id']);
                abort_if(!empty($data['recording_song_id']), 422, '请选择已有歌曲或新建录音版本。');
                $beforeAlbums = app(Activity::class)->snapshot($song);
                if (!empty($data['musicbrainz_id']) && !empty($song->musicbrainz_id) && $song->musicbrainz_id !== $data['musicbrainz_id']) {
                    abort(409, '所选歌曲已绑定不同的 MusicBrainz recording ID。');
                }
                if (!empty($data['musicbrainz_id']) && empty($song->musicbrainz_id)) {
                    $song->musicbrainz_id = $data['musicbrainz_id'];
                }
                foreach (['release_date', 'genre'] as $field) {
                    if (blank($song->$field) && !blank($data[$field] ?? null)) {
                        $song->$field = $data[$field];
                    }
                }
                if (!empty($data['artist_credits'])) {
                    $artistIds = app(ArtistNames::class)->resolve($user, [], null, $data['artist_credits']);
                    $song->artists()->syncWithoutDetaching($artistIds);
                }
                if ($song->isDirty()) {
                    $song->version++;
                    $song->save();
                    app(Activity::class)->record($user, 'updated', 'songs', $song, $beforeAlbums, '根据确认的上传资料补全歌曲信息');
                }
            } else {
                $source = !empty($data['recording_song_id']) ? Models\Song::select(Models\Song::FIELDS)->findOrFail($data['recording_song_id']) : null;
                $artists = app(ArtistNames::class)->resolve($user, $data['artist_ids'] ?? [], $data['artist_name'] ?? null, $data['artist_credits'] ?? []);
                $libraryId = $data['library_id'] ?? Models\MediaLibrary::ensureDefault($user)->id;
                Models\MediaLibrary::select(['id'])->lockForUpdate()->findOrFail($libraryId);
                $song = Models\Song::create([
                    'name'              => trim($data['name']), 'user_id' => $user->id, 'library_id' => $libraryId,
                    'recording_id'      => $source?->recording_id,
                    'recording_version' => $data['recording_version'] ?? '',
                    'musicbrainz_id'   => $data['musicbrainz_id'] ?? null,
                    'release_date'     => $data['release_date'] ?? null,
                    'genre'            => $data['genre'] ?? '',
                ]);
                if ($source) {
                    app(Lyrics::class)->replace($song, app(Lyrics::class)->versions($source));
                }
                $song->artists()->sync($artists);
            }
            $albumChanged = $albumId && app(AlbumTracks::class)->attach($song, $albumId, $data['disc_number'] ?? null, $data['track_number'] ?? null);
            if ($created) {
                app(Activity::class)->record($user, 'created', 'songs', $song);
            } elseif ($albumChanged) {
                $song->version++;
                $song->save();
                app(Activity::class)->record($user, 'updated', 'songs', $song, $beforeAlbums, '补充专辑收录与曲序');
            }
            if ($albumId && !empty($data['album_supplement'])) {
                app(AlbumCatalog::class)->supplement($user, $albumId, $data['album_supplement']);
            }
            if ($file->song_id === $song->id) {
                if ($schedule) {
                    app(SyncScheduler::class)->touch();
                }

                return $receipts->remember($user, $file, $request, $key, $song, $albumId);
            }
            $currentLyrics = app(Lyrics::class)->versions($song);
            $lyrics = app(Lyrics::class)->merge($currentLyrics, app(Lyrics::class)->import($file));
            if ($lyrics !== $currentLyrics) {
                $beforeLyrics = app(Activity::class)->snapshot($song);
                app(Lyrics::class)->replace($song, $lyrics);
                $song->version++;
                $song->save();
                app(Activity::class)->record($user, 'updated', 'songs', $song, $beforeLyrics, '采用音频内嵌歌词');
            }
            $embeddedCover = $file->metadata['cover_file_id'] ?? null;
            if ($embeddedCover && Models\StoredFile::whereKey($embeddedCover)->where('kind', 'cover')->exists()) {
                if (!$song->cover_id) {
                    $beforeCover = app(Activity::class)->snapshot($song);
                    $song->cover_id = $embeddedCover;
                    $song->version++;
                    $song->save();
                    app(Activity::class)->record($user, 'updated', 'songs', $song, $beforeCover, '采用音频内嵌封面');
                }
                if ($albumId && !array_key_exists('cover_id', $data['album'] ?? []) && !array_key_exists('cover_id', $data['album_supplement'] ?? [])) {
                    $album = Models\Album::select(['id', 'cover_id', 'version'])->lockForUpdate()->find($albumId);
                    if ($album && !$album->cover_id) {
                        $album = Models\Album::select(Models\Album::FIELDS)->findOrFail($album->id);
                        $beforeCover = app(Activity::class)->snapshot($album);
                        $album->cover_id = $embeddedCover;
                        $album->version++;
                        $album->save();
                        app(Activity::class)->record($user, 'updated', 'albums', $album, $beforeCover, '采用音频内嵌封面');
                    }
                }
            }
            $duplicate = Models\StoredFile::select(['id'])->where('song_id', $song->id)->where('sha256', $file->getRawOriginal('sha256'))->first();
            abort_if($duplicate, 409, '这首歌已有相同文件，无需重复添加。');
            $before = app(Activity::class)->snapshot($file);
            $before['_defaults'][$song->id] = $song->files()->where('is_default', true)->value('id');
            $file->update(['song_id' => $song->id, 'upload_draft' => null]);
            Quality::ensureDefault($song);
            $file = Models\StoredFile::select(Models\StoredFile::FIELDS)->findOrFail($file->id);
            $result = $receipts->remember($user, $file, $request, $key, $song, $albumId);
            app(Activity::class)->record($user, 'published', 'files', $file, $before);
            if ($schedule) {
                app(SyncScheduler::class)->touch();
            }

            return $result;
        });
    }
}
