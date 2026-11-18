<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class ArtistNames
{
    public function resolve(Models\User $user, array $ids = [], ?string $name = null, array $credits = []): array
    {
        if ($credits) {
            $resolved = [];
            foreach ($credits as $credit) {
                if (!is_array($credit)) {
                    continue;
                }

                $artistName = trim((string) ($credit['name'] ?? $credit['credited_name'] ?? ''));
                if ($artistName === '') {
                    continue;
                }

                $mbid = $credit['musicbrainz_id'] ?? null;
                $resolved[] = $this->resolveOne($user, $artistName, is_string($mbid) ? $mbid : null);
            }

            return array_values(array_unique($resolved));
        }

        if ($ids || blank($name)) {
            return $ids;
        }

        return [$this->resolveOne($user, trim($name), null)];
    }

    private function resolveOne(Models\User $user, string $name, ?string $mbid): string
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['artist-' . ($mbid ?: $name)]);

        if ($mbid) {
            $byId = Models\Artist::withTrashed()->where('musicbrainz_id', $mbid)->first();
            if ($byId) {
                abort_if($byId->trashed(), 422, '这位 MusicBrainz 艺人已删除，请先恢复资料后再导入。');

                return $byId->id;
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['artist-name-' . $name]);
            $matches = Models\Artist::where('name', $name)->lockForUpdate()->limit(2)->get();
            if ($matches->count() === 1 && blank($matches[0]->musicbrainz_id)) {
                $artist = $matches[0];
                $before = app(Activity::class)->snapshot($artist);
                $artist->musicbrainz_id = $mbid;
                $artist->version++;
                $artist->save();
                app(Activity::class)->record($user, 'updated', 'artists', $artist, $before, '关联 MusicBrainz 艺人 ID');

                return $artist->id;
            }

            // A different MusicBrainz ID identifies a different artist, even with the same display name.
            $artist = Models\Artist::create(['name' => $name, 'musicbrainz_id' => $mbid, 'user_id' => $user->id]);
            app(Activity::class)->record($user, 'created', 'artists', $artist);

            return $artist->id;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['artist-name-' . $name]);
        $matches = Models\Artist::select(['id'])->where('name', $name)->limit(2)->get();
        abort_if($matches->count() > 1, 422, '有多位同名音乐人，请选择具体的音乐人。');
        $artist = $matches->first();
        if (!$artist) {
            $artist = Models\Artist::create(['name' => $name, 'user_id' => $user->id]);
            app(Activity::class)->record($user, 'created', 'artists', $artist);
        }

        return $artist->id;
    }
}
