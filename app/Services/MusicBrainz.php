<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MusicBrainz
{
    private const API_URL = 'https://musicbrainz.org/ws/2';

    private const COVER_ART_URL = 'https://coverartarchive.org';

    public function search(string $query, ?string $artist = null): array
    {
        $terms = ['release:"' . $this->escapePhrase($query) . '"'];
        if ($artist !== null && trim($artist) !== '') {
            $terms[] = 'artist:"' . $this->escapePhrase($artist) . '"';
        }

        $data = $this->musicBrainzGet('release', [
            'query' => implode(' AND ', $terms),
            'fmt' => 'json',
            'limit' => 10,
        ]);

        return array_map(fn (array $release) => $this->summary($release), $data['releases'] ?? []);
    }

    public function release(string $id): array
    {
        $data = $this->musicBrainzGet("release/$id", [
            'inc' => 'recordings+artist-credits+labels+release-groups+media+isrcs+genres',
            'fmt' => 'json',
        ]);
        $media = $data['media'] ?? [];
        $tracks = [];

        foreach ($media as $discIndex => $medium) {
            foreach (($medium['tracks'] ?? []) as $trackIndex => $track) {
                $recording = $track['recording'] ?? [];
                $credits = $track['artist-credit'] ?? $recording['artist-credit'] ?? $data['artist-credit'] ?? [];
                $tracks[] = [
                    'musicbrainz_id' => $recording['id'] ?? null,
                    'disc' => (int) ($medium['position'] ?? $discIndex + 1),
                    'track' => (int) ($track['position'] ?? $trackIndex + 1),
                    'title' => $track['title'] ?? $recording['title'] ?? '',
                    'artist_name' => $this->artistCredit($credits),
                    'artist_credits' => $this->credits($credits),
                    'length_ms' => isset($recording['length'])
                        ? (int) $recording['length']
                        : (isset($track['length']) ? (int) $track['length'] : null),
                    'isrcs' => array_values($recording['isrcs'] ?? []),
                ];
            }
        }
        $declaredTrackCount = array_sum(array_map(
            fn (array $medium) => (int) ($medium['track-count'] ?? count($medium['tracks'] ?? [])),
            $media,
        ));

        $labels = [];
        $catalogNumbers = [];
        foreach (($data['label-info'] ?? []) as $info) {
            if (!empty($info['label']['name'])) {
                $labels[] = $info['label']['name'];
            }
            if (!empty($info['catalog-number'])) {
                $catalogNumbers[] = $info['catalog-number'];
            }
        }

        $date = (string) ($data['date'] ?? '');
        $artworks = $this->coverArtworks($id);
        $front = collect($artworks)->first(fn ($image) => $image['front']);
        $genre = collect($data['genres'] ?? [])
            ->sortByDesc(fn (array $item) => (int) ($item['count'] ?? 0))
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->unique()
            ->take(3)
            ->implode(', ');
        $result = [
            'musicbrainz_id' => $data['id'],
            'artist_credits' => $this->credits($data['artist-credit'] ?? []),
            'release_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? $date : null,
            'status' => $data['status'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'formats' => array_values(array_unique(array_filter(array_map(
                fn (array $medium) => $medium['format'] ?? null,
                $media,
            )))),
            'artworks' => $artworks,
            'genre' => mb_substr($genre, 0, 100),
            'id' => $data['id'],
            'title' => $data['title'] ?? '',
            'artist_name' => $this->artistCredit($data['artist-credit'] ?? ''),
            'date' => $date,
            'release_year' => preg_match('/^\d{4}/', $date) ? (int) substr($date, 0, 4) : null,
            'release_country' => $data['country'] ?? '',
            'label' => implode(' / ', array_unique($labels)),
            'catalog_number' => implode(' / ', array_unique($catalogNumbers)),
            'total_discs' => count($media) ?: null,
            'total_tracks' => $declaredTrackCount ?: (count($tracks) ?: (isset($data['track-count']) ? (int) $data['track-count'] : null)),
            'tracks' => $tracks,
            'cover_preview_url' => $front['preview_url'] ?? null,
        ];

        return $result;
    }

    /** @return array{body: string, mime_type: string} */
    public function cover(string $id, ?string $imageId = null): array
    {
        $images = $this->coverManifest($id);
        $image = null;
        foreach ($images as $entry) {
            $isRequestedImage = $imageId !== null
                ? (string) ($entry['id'] ?? '') === $imageId
                : !empty($entry['front']);
            if ($isRequestedImage) {
                $image = $entry;
                break;
            }
        }
        abort_if(!$image, 404, 'MusicBrainz 中没有这张发行的指定封面。');
        $imageUrl = $this->safeCoverUrl($image['image']);
        try {
            $response = Http::withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])
                ->timeout(20)
                ->get($imageUrl);
        } catch (ConnectionException) {
            abort(502, '暂时无法从 Cover Art Archive 取得封面。');
        }
        abort_unless($response->successful(), 502, 'Cover Art Archive 暂时无法提供这张封面。');
        $mimeType = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 422, '封面格式不受支持。');
        $body = $response->body();
        abort_if(strlen($body) > (int) config('contribution.max_cover_size'), 413, '外部封面超过服务器允许的大小。');

        return ['body' => $body, 'mime_type' => $mimeType];
    }

    private function coverManifest(string $id): array
    {
        return Cache::remember('coverart:manifest:' . $id, now()->addHours(12), function () use ($id) {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->get(self::COVER_ART_URL . "/release/$id");
            } catch (ConnectionException) {
                return [];
            }
            if (!$response->successful()) {
                return [];
            }

            $images = $response->json('images', []);
            if (!is_array($images)) {
                return [];
            }

            return array_values(array_filter(
                $images,
                fn ($image) => is_array($image) && is_string($image['image'] ?? null),
            ));
        });
    }

    private function coverArtworks(string $id): array
    {
        return array_map(function (array $image) {
            $thumbnail = $image['thumbnails']['500'] ?? $image['thumbnails']['250'] ?? $image['image'];
            $types = is_array($image['types'] ?? null) ? array_values($image['types']) : [];
            $normalizedTypes = array_map('strtolower', $types);
            $artworkType = !empty($image['front']) || in_array('front', $normalizedTypes, true)
                ? 'cover'
                : (!empty($image['back']) || in_array('back', $normalizedTypes, true)
                    ? 'back'
                    : (array_intersect($normalizedTypes, ['medium', 'disc'])
                        ? 'disc'
                        : (in_array('booklet', $normalizedTypes, true) ? 'booklet' : 'other')));

            return [
                'id' => isset($image['id']) ? (string) $image['id'] : '',
                'types' => $types,
                'front' => (bool) ($image['front'] ?? false),
                'back' => (bool) ($image['back'] ?? false),
                'approved' => (bool) ($image['approved'] ?? false),
                'preview_url' => $this->safeCoverUrl($thumbnail),
                'type' => $artworkType,
            ];
        }, $this->coverManifest($id));
    }

    private function credits(array|string $credits): array
    {
        if (is_string($credits)) {
            return [];
        }

        return array_values(array_map(fn (array $part) => [
            'musicbrainz_id' => $part['artist']['id'] ?? null,
            'name' => (string) ($part['artist']['name'] ?? $part['name'] ?? ''),
            'credited_name' => (string) ($part['name'] ?? $part['artist']['name'] ?? ''),
            'joinphrase' => (string) ($part['joinphrase'] ?? ''),
        ], array_filter($credits, 'is_array')));
    }

    private function summary(array $release): array
    {
        $media = $release['media'] ?? [];
        $trackCount = isset($release['track-count'])
            ? (int) $release['track-count']
            : array_sum(array_map(fn (array $medium) => (int) ($medium['track-count'] ?? 0), $media));

        return [
            'id' => $release['id'],
            'title' => $release['title'] ?? '',
            'artist_name' => $this->artistCredit($release['artist-credit'] ?? []),
            'date' => $release['date'] ?? '',
            'country' => $release['country'] ?? '',
            'track_count' => $trackCount ?: null,
            'status' => $release['status'] ?? '',
        ];
    }

    private function artistCredit(array|string $credit): string
    {
        if (is_string($credit)) {
            return trim($credit);
        }

        $name = '';
        foreach ($credit as $part) {
            $name .= $part['name'] ?? $part['artist']['name'] ?? '';
            $name .= $part['joinphrase'] ?? '';
        }

        return trim($name);
    }

    private function escapePhrase(string $value): string
    {
        return preg_replace('/([+\-!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', trim($value)) ?? trim($value);
    }

    private function musicBrainzGet(string $path, array $query): array
    {
        $cacheKey = 'musicbrainz:' . hash('sha256', $path . '?' . http_build_query($query));

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($path, $query) {
            try {
                $response = Cache::lock('musicbrainz:request-lock', 30)->block(30, function () use ($path, $query) {
                    $lastRequestAt = (float) Cache::get('musicbrainz:last-request-at', 0);
                    $waitMicros = (int) max(0, (1.05 - (microtime(true) - $lastRequestAt)) * 1_000_000);
                    if ($waitMicros > 0) {
                        usleep($waitMicros);
                    }
                    Cache::put('musicbrainz:last-request-at', microtime(true), now()->addMinutes(2));

                    return Http::baseUrl(self::API_URL)
                        ->acceptJson()
                        ->withUserAgent('EmosMusicAPI/1.0 (+https://github.com/somebyteorg/music_api)')
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->get($path, $query);
                });
            } catch (ConnectionException) {
                abort(502, '暂时无法连接 MusicBrainz，请稍后重试。');
            }

            abort_unless($response->successful(), $response->status() >= 500 ? 502 : 404, 'MusicBrainz 暂时无法提供资料。');
            $data = $response->json();
            abort_unless(is_array($data), 502, 'MusicBrainz 返回了无法识别的资料。');

            return $data;
        });
    }

    private function safeCoverUrl(string $url): string
    {
        $parts = parse_url($url);
        abort_unless(is_array($parts), 502, '封面来源地址不受支持。');
        $host = strtolower($parts['host'] ?? '');
        abort_unless(
            ($parts['scheme'] ?? 'https') === 'https'
                && (in_array($host, ['coverartarchive.org', 'archive.org'], true) || str_ends_with($host, '.archive.org')),
            502,
            '封面来源地址不受支持。',
        );

        return 'https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
