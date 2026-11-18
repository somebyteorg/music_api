<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request, string $type, Services\Catalog $catalog)
    {
        $class = $catalog->model($type);
        if ($type === 'libraries') {
            Models\MediaLibrary::ensureDefault($request->user());
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'musicbrainz_id' => ['nullable', 'uuid'], 'sort' => ['nullable', 'in:latest,name,oldest'], 'album_id' => ['nullable', 'uuid'], 'library_id' => ['nullable', 'uuid'], 'artist_id' => ['nullable', 'uuid'], 'quality' => ['nullable', 'in:standard,high,lossless,hires'], 'mine' => ['nullable', 'boolean'], 'favorites' => ['nullable', 'boolean'], 'missing' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = $class::select($type === 'songs' ? array_map(fn($f) => 'songs.' . $f, Models\Song::LIST_FIELDS) : $class::FIELDS);
        if ($type === 'songs' && !empty($data['musicbrainz_id'])) { $q->where('songs.musicbrainz_id', $data['musicbrainz_id']); }
        if (!empty($data['q'])) {
            $q->where('name', 'ilike', '%' . addcslashes($data['q'], '%_\\') . '%');
        }
        if ($request->boolean('mine')) {
            $q->where('user_id', $request->user()->id);
        }
        if ($type === 'songs') {
            $q->with(['artists:id,name,cover_id,image_url', 'albums:id,name,cover_id,image_url,release_year,edition', 'library:id,name'])->withCount('files')->withAvg('files as duration', 'duration');
            if ($request->filled('album_id')) {
                $q->whereHas('albums', fn($a) => $a->where('albums.id', $data['album_id']));
            }
            if ($request->filled('library_id')) {
                $q->where('library_id', $data['library_id']);
            }
            if ($request->filled('artist_id')) {
                $q->whereHas('artists', fn($a) => $a->where('artists.id', $data['artist_id']));
            }
            if ($request->filled('quality')) {
                $q->whereHas('files', fn($f) => $f->where('quality', $data['quality']));
            }
            if ($request->boolean('missing')) {
                $q->whereDoesntHave('files');
            }
            if ($request->boolean('favorites')) {
                $q->whereHas('fans', fn($u) => $u->where('users.id', $request->user()->id));
            }
            $q->withExists(['fans as is_favorite' => fn($u) => $u->where('users.id', $request->user()->id)]);
            if ($request->filled('album_id')) {
                $q->join('album_song as album_position', fn($join) => $join->on('album_position.song_id', '=', 'songs.id')->where('album_position.album_id', '=', $data['album_id']))->orderBy('album_position.disc_number')->orderBy('album_position.track_number');
            }
        } elseif ($type === 'albums') {
            $q->with('artists:id,name,cover_id,image_url')->withCount(['songs' => fn($songs) => $songs->when($request->filled('library_id'), fn($songs) => $songs->where('library_id', $data['library_id']))]);
            if ($request->filled('library_id')) {
                $q->whereHas('songs', fn($songs) => $songs->where('library_id', $data['library_id']));
            }
            if ($request->filled('artist_id')) {
                $q->whereHas('artists', fn($a) => $a->where('artists.id', $data['artist_id']));
            }
        } elseif ($type === 'libraries') {
            $q->withCount('songs')->with('creator:' . implode(',', Models\User::PUBLIC_FIELDS));
        } else {
            $q->withCount(['songs', 'albums']);
        }
        match ($data['sort'] ?? 'latest') {
            'name'   => $q->orderBy($type . '.name'),
            'oldest' => $q->orderBy($type . '.created_at'),
            default  => $q->orderByDesc($type . '.created_at')
        };

        $page = $q->orderBy($type . '.id')->paginate($this->pageSize($request));

        return $this->FormatPage($page, $page->items());
    }

    public function show(Request $request, string $type, string $id, Services\Catalog $catalog)
    {
        $class = $catalog->model($type);
        $e = $class::select($class::FIELDS)->findOrFail($id);
        $e->load('creator:' . implode(',', Models\User::PUBLIC_FIELDS));
        if (in_array($type, ['songs', 'albums'], true)) {
            $e->load('artists:id,name,cover_id,image_url');
        }
        if ($type === 'songs') {
            $e->load(['lyrics', 'albums:id,name,cover_id,image_url,release_year,edition', 'library:id,name', 'files' => fn($q) => $q->select(Models\StoredFile::FIELDS)->orderByDesc('is_default')->orderBy('created_at')->with('uploader:' . implode(',', Models\User::PUBLIC_FIELDS))]);
            $e->setAttribute('is_favorite', $e->fans()->where('users.id', $request->user()->id)->exists());
            $e->setAttribute('recording_tracks', Models\Song::select(['id', 'name', 'recording_version', 'library_id'])->where('recording_id', $e->recording_id)->whereKeyNot($e->id)->with(['albums:id,name,release_year,edition', 'library:id,name'])->orderBy('created_at')->get());
        } else {
            $e->loadCount('songs');
        }

        return ['item' => $e];
    }

    public function store(Request $r, string $type, Services\Catalog $c)
    {
        return ['item' => $c->write($r, $type)];
    }

    public function update(Request $r, string $type, string $id, Services\Catalog $c)
    {
        return ['item' => $c->write($r, $type, $id)];
    }

    public function destroy(Request $r, string $type, string $id, Services\Catalog $c)
    {
        $c->delete($r, $type, $id);

        return ['message' => '资料已删除，可从操作记录中恢复。'];
    }

    public function merge(Request $r, string $type, string $id, Services\Catalog $c)
    {
        $c->merge($r, $type, $id);

        return ['message' => '合并成功。'];
    }

    public function favorite(Request $r, string $id)
    {
        $data = $r->validate(['favorite' => ['required', 'boolean']]);
        $song = Models\Song::select(['id'])->findOrFail($id);
        if ($data['favorite']) {
            $song->fans()->syncWithoutDetaching([$r->user()->id]);
        } else {
            $song->fans()->detach($r->user()->id);
        }

        return ['is_favorite' => $data['favorite']];
    }

    public function albumMatches(Request $request, Services\AlbumCatalog $albums)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'artist_name' => ['nullable', 'string', 'max:255'], ...$albums->rules()]);

        return ['items' => $albums->candidates($data)];
    }

    public function albumPositions(string $id)
    {
        Models\Album::select(['id'])->findOrFail($id);

        return ['items' => Models\AlbumTrack::select(['song_id', 'disc_number', 'track_number'])
            ->where('album_id', $id)->orderBy('disc_number')->orderBy('track_number')->get()];
    }

    public function assignLibrary(Request $request, Services\MediaLibraries $libraries)
    {
        $data = $request->validate(['library_id' => ['required', 'uuid', Rule::exists('libraries', 'id')->whereNull('deleted_at')], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.id' => ['required', 'uuid', 'distinct'], 'items.*.version' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $libraries->assign($request->user(), $data['items'], $data['library_id'], $data['reason'] ?? null);

        return ['message' => '歌曲所属媒体库已更新。'];
    }

    public function assignAlbum(Request $request, Services\AlbumTracks $tracks)
    {
        $data = $request->validate([
            'album_id'             => ['required', 'uuid', Rule::exists('albums', 'id')->whereNull('deleted_at')],
            'items'                => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id'           => ['required', 'uuid', 'distinct'], 'items.*.version' => ['required', 'integer'],
            'items.*.disc_number'  => ['required', 'integer', 'between:1,999'],
            'items.*.track_number' => ['required', 'integer', 'between:1,9999'],
            'items.*.remove'       => ['sometimes', 'boolean'],
            'reason'               => ['nullable', 'string', 'max:1000'],
        ]);
        $tracks->assign($request->user(), $data['items'], $data['album_id'], $data['reason'] ?? null);

        return ['message' => '专辑归属和曲序已更新。'];
    }
}
