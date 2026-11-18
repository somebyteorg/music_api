<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PublicAlbumPage
{
    private const MAX_HTML_BYTES = 1_500_000;

    private const MAX_PASTED_CHARS = 50_000;

    private const MAX_TRACKS = 500;

    private const ALLOWED_HOSTS = [
        'y.qq.com' => 'QQ 音乐',
        'c.y.qq.com' => 'QQ 音乐',
        'music.163.com' => '网易云音乐',
        'm.music.163.com' => '网易云音乐',
        'kugou.com' => '酷狗音乐',
        'www.kugou.com' => '酷狗音乐',
        't1.kugou.com' => '酷狗音乐',
    ];

    private const COVER_HOSTS = [
        'y.qq.com', 'y.gtimg.cn', 'imgcache.qq.com', 'p.qpic.cn',
        'music.163.com', 'p1.music.126.net', 'p2.music.126.net',
        'kugou.com', 'www.kugou.com', 'imgessl.kugou.com', 'imge.kugou.com',
    ];

    public function preview(?string $url, ?string $text): array
    {
        if (is_string($text) && trim($text) !== '') {
            return $this->previewText($text, $url);
        }

        if (!is_string($url) || trim($url) === '') {
            throw ValidationException::withMessages(['url' => '请输入专辑页链接，或粘贴页面文字。']);
        }

        $page = $this->validatePageUrl(trim($url), true);
        $currentUrl = $page['url'];
        $lastWarning = '公开页面当前无法读取。请打开页面并粘贴可见的专辑信息与曲目清单。';

        for ($redirect = 0; $redirect <= 3; $redirect++) {
            if (!$this->hasPublicAddress($page['host'])) {
                return $this->unavailable($currentUrl, $page['platform'], '无法验证公开页面地址，请改为粘贴页面文字。');
            }

            try {
                $response = Http::accept('text/html,application/xhtml+xml')
                    ->withUserAgent('CueEmosPublicMetadataPreview/1.0')
                    ->withOptions(['stream' => true])
                    ->connectTimeout(4)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get($currentUrl);
            } catch (ConnectionException) {
                return $this->unavailable($currentUrl, $page['platform'], '连接公开页面超时或失败。请粘贴页面可见文字；不会尝试登录或绕过访问限制。');
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (!is_string($location) || $redirect === 3) {
                    break;
                }

                try {
                    $page = $this->validatePageUrl($this->resolveLocation($currentUrl, $location), false);
                    $currentUrl = $page['url'];
                } catch (ValidationException) {
                    return $this->unavailable($currentUrl, $page['platform'], '页面跳转到不支持的地址。请打开原页面并粘贴可见文字。');
                }

                continue;
            }

            if (!$response->successful()) {
                $lastWarning = '页面返回 HTTP ' . $response->status() . '，可能需要登录、受地区限制或暂时不可用。请粘贴页面可见文字；不会尝试绕过。';
                break;
            }

            $contentType = strtolower((string) $response->header('Content-Type', ''));
            if (!str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
                $lastWarning = '来源没有提供公开 HTML 专辑页面。请粘贴页面可见文字。';
                break;
            }
            $contentLength = (int) $response->header('Content-Length', 0);
            if ($contentLength > self::MAX_HTML_BYTES) {
                $lastWarning = '公开页面内容过大，未继续读取。请粘贴页面可见文字。';
                break;
            }
            try {
                $stream = $response->toPsrResponse()->getBody();
                $html = '';
                while (!$stream->eof() && strlen($html) <= self::MAX_HTML_BYTES) {
                    $chunk = $stream->read(min(8192, self::MAX_HTML_BYTES + 1 - strlen($html)));
                    if ($chunk === '') {
                        break;
                    }
                    $html .= $chunk;
                }
            } catch (\Throwable) {
                $lastWarning = '读取公开页面失败。请粘贴页面可见文字。';
                break;
            }
            if (strlen($html) > self::MAX_HTML_BYTES) {
                $lastWarning = '公开页面内容过大，未继续读取。请粘贴页面可见文字。';
                break;
            }

            $metadata = $this->parseHtml($html, $page['platform'], $currentUrl);
            $metadata['method'] = 'public-page';
            $metadata['source_platform'] = $page['platform'];
            $metadata['source_url'] = $currentUrl;
            $metadata['read_status'] = ($metadata['title'] || $metadata['tracks']) ? 'partial' : 'unreadable';
            if ($metadata['read_status'] === 'unreadable') {
                $metadata['warning'] = '页面可访问，但没有识别出足够的专辑资料；可能是动态页面或登录墙。请粘贴页面可见文字。';
            } elseif (!$metadata['tracks']) {
                $metadata['warning'] = '已读取到部分发行资料，但没有读到曲目清单。请粘贴包含曲目表的页面文字。';
            } else {
                $metadata['read_status'] = 'read';
                $metadata['warning'] = $this->trackCompletenessWarning($metadata);
            }

            return $metadata;
        }

        return $this->unavailable($currentUrl, $page['platform'], $lastWarning);
    }

    public function previewText(string $text, ?string $sourceUrl = null): array
    {
        $text = mb_substr($text, 0, self::MAX_PASTED_CHARS);
        $source = $this->sourceForPastedText($sourceUrl);
        $metadata = $this->parseText($text);
        $metadata['method'] = 'pasted-text';
        $metadata['source_platform'] = $source['platform'];
        $metadata['source_url'] = $source['url'];
        $metadata['read_status'] = ($metadata['title'] || $metadata['tracks']) ? 'partial' : 'unreadable';
        $metadata['warning'] = $metadata['read_status'] === 'unreadable'
            ? '没有从粘贴文字中识别出专辑名或曲目，请补充专辑标题、艺人及按顺序排列的曲目。'
            : $this->trackCompletenessWarning($metadata);
        if ($metadata['title'] && $metadata['tracks'] && !$metadata['warning']) {
            $metadata['read_status'] = 'read';
        }

        return $metadata;
    }

    protected function hasPublicAddress(string $host): bool
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) {
            return false;
        }

        $ips = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (is_string($record[$key] ?? null)) {
                    $ips[] = $record[$key];
                }
            }
        }
        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function validatePageUrl(string $url, bool $allowShareRedirect): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw ValidationException::withMessages(['url' => '链接格式不受支持。']);
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw ValidationException::withMessages(['url' => '链接格式无效。']);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['url' => '只支持 HTTPS 的 QQ 音乐、网易云音乐和酷狗公开专辑页。']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || !isset(self::ALLOWED_HOSTS[$host])) {
            throw ValidationException::withMessages(['url' => '链接域名不在支持的平台公开页面列表中。']);
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');
        if (!$this->isAlbumPagePath($host, $path, $query, $fragment, $allowShareRedirect)) {
            throw ValidationException::withMessages(['url' => '只读取支持的专辑详情页或专辑分享页；若链接打不开，请粘贴页面文字。']);
        }

        return ['url' => $url, 'host' => $host, 'platform' => self::ALLOWED_HOSTS[$host]];
    }

    private function isAlbumPagePath(string $host, string $path, string $query, string $fragment, bool $allowShareRedirect): bool
    {
        if (in_array($host, ['y.qq.com'], true)) {
            return (bool) preg_match('~^/n/ryqq(?:_v2)?/(?:albumDetail|album)/[A-Za-z0-9]+/?$~', $path);
        }
        if ($host === 'c.y.qq.com') {
            return $allowShareRedirect && $path === '/base/fcgi-bin/u' && $query !== '';
        }
        if (in_array($host, ['music.163.com', 'm.music.163.com'], true)) {
            $albumRoute = $path === '/album' || $path === '/album/';
            $queryHasId = (bool) preg_match('/(?:^|&)id=\d+(?:&|$)/', $query);
            $fragmentHasAlbumId = (bool) preg_match('~(?:^|/)album\?id=\d+(?:&|$)~', $fragment);

            return ($albumRoute && $queryHasId) || $fragmentHasAlbumId;
        }

        if (in_array($host, ['kugou.com', 'www.kugou.com'], true)) {
            return (bool) preg_match('~^/(?:album/info/[A-Za-z0-9]+/?|yy/album/[A-Za-z0-9_-]+/?|album\.html)$~', $path);
        }
        if ($host === 't1.kugou.com') {
            return $allowShareRedirect && $path === '/album.html' && (bool) preg_match('/(?:^|&)id=[A-Za-z0-9_-]+(?:&|$)/', $query);
        }

        return false;
    }

    private function resolveLocation(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $parts = parse_url($baseUrl);
        $origin = 'https://' . ($parts['host'] ?? '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $directory = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/'));

        return $origin . ($directory ?: '/') . $location;
    }

    private function parseHtml(string $html, string $platform, string $sourceUrl): array
    {
        if (!class_exists(DOMDocument::class)) {
            return $this->parseText(strip_tags($html));
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $this->emptyMetadata();
        }

        $xpath = new DOMXPath($document);
        $meta = $this->metaValues($xpath);
        $visibleText = $this->visibleText($xpath);
        $plain = $this->parseText($visibleText);
        $jsonAlbum = $this->findMusicAlbum($xpath);
        $title = $this->clean($jsonAlbum['name'] ?? $this->firstHeading($xpath) ?? $meta['og:title'] ?? $meta['twitter:title'] ?? $plain['title'] ?? '');
        $artistName = $this->artistName($jsonAlbum['byArtist'] ?? null)
            ?: $plain['artist_name']
            ?: $this->labelValue($visibleText, ['专辑艺人', '歌手', '演唱者', '艺人', 'Artist'])
            ?: $this->artistNearHeading($xpath, $title);
        $tracks = $this->structuredTracks($jsonAlbum['track'] ?? null);
        if (!$tracks) {
            $tracks = $this->linkedTracks($xpath, $artistName);
        }
        if (!$tracks) {
            $tracks = $plain['tracks'];
        }

        $dateValue = $jsonAlbum['datePublished'] ?? $jsonAlbum['dateCreated'] ?? null;
        $date = $this->dateParts($dateValue ?: $this->labelValue($visibleText, ['发行日期', '发行时间', '发行日期时间', 'Release date']) ?: $plain['release_date']);
        $description = $this->clean($jsonAlbum['description'] ?? $meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? $plain['description'] ?? '', 2000);
        $cover = $this->safeCoverUrl((string) ($this->firstImage($jsonAlbum['image'] ?? null) ?: $meta['og:image'] ?? $meta['twitter:image'] ?? ''), $platform);
        $artistCredits = $this->artistCredits($jsonAlbum['byArtist'] ?? null, $artistName);
        $totalDiscs = $this->labelValue($visibleText, ['碟片总数', '总碟数', 'Discs']) ?: ($jsonAlbum['numberOfDiscs'] ?? null) ?: $plain['total_discs'];
        $barcode = $this->labelValue($visibleText, ['条码', 'Barcode', 'UPC', 'EAN']) ?: ($jsonAlbum['gtin13'] ?? $jsonAlbum['gtin'] ?? null) ?: $plain['barcode'];

        $metadata = [
            ...$this->emptyMetadata(),
            'title' => $title,
            'artist_name' => $artistName,
            'artist_credits' => $artistCredits,
            'release_date' => $date['date'],
            'release_year' => $date['year'] ?: $this->nullableInt($jsonAlbum['releaseYear'] ?? null),
            'release_country' => $this->clean($this->labelValue($visibleText, ['发行地区', '发行国家', 'Release country']) ?: $plain['release_country'] ?: ''),
            'label' => $this->clean($this->artistName($jsonAlbum['recordLabel'] ?? null) ?: $this->labelValue($visibleText, ['唱片公司', '发行公司', '厂牌', 'Label']) ?: $plain['label'] ?: ''),
            'catalog_number' => $this->clean($this->labelValue($visibleText, ['唱片编号', '目录号', '专辑编号', 'Catalog number']) ?: $plain['catalog_number'] ?: ''),
            'genre' => $this->clean($this->scalarOrList($jsonAlbum['genre'] ?? null) ?: ($this->labelValue($visibleText, ['流派', '曲风', 'Genre']) ?: $plain['genre'] ?: ''), 100),
            'total_discs' => $this->nullableInt($totalDiscs),
            'total_tracks' => $this->nullableInt($jsonAlbum['numTracks'] ?? null) ?: ($plain['total_tracks'] ?: (count($tracks) ?: null)),
            'barcode' => $this->clean((string) ($barcode ?? ''), 100),
            'formats' => $this->formats($visibleText, $jsonAlbum) ?: $plain['formats'],
            'description' => $description,
            'cover_preview_url' => $cover,
            'tracks' => array_slice($tracks, 0, self::MAX_TRACKS),
        ];

        if (!$metadata['total_discs'] && $metadata['tracks']) {
            $discs = array_column($metadata['tracks'], 'disc');
            $metadata['total_discs'] = $discs ? max($discs) : null;
        }
        if (!$metadata['total_tracks'] && $metadata['tracks']) {
            $metadata['total_tracks'] = count($metadata['tracks']);
        }

        return $metadata;
    }

    private function parseText(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_substr($text, 0, self::MAX_PASTED_CHARS);
        $lines = preg_split('/\R/u', $text) ?: [];
        $metadata = $this->emptyMetadata();
        $currentDisc = 1;
        $pendingTrack = null;
        $tracks = [];

        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            $line = $this->clean($line, 1000);
            $line = preg_replace('/^\s*[•*]\s*/u', '', $line) ?? $line;
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?:专辑名称|专辑名|Album|专辑艺人|歌手|演唱者|艺人|Artist|发行日期|发行时间|发行日期时间|Release date|发行地区|发行国家|Release country|唱片公司|发行公司|厂牌|Label|唱片编号|目录号|专辑编号|Catalog number|流派|曲风|Genre|格式|Format|碟片总数|总碟数|Discs|曲目总数|歌曲数量|Total tracks|条码|Barcode|UPC|EAN|简介|专辑简介|Description)\s*[:：]?\s*$/iu', $line) && isset($lines[$index + 1])) {
                $line = preg_replace('/\s*[:：]\s*$/u', '', $line) . '：' . $this->clean((string) $lines[++$index], 1000);
            }

            if (preg_match('/^(?:CD|Disc|Disk|碟片?|唱片)\s*([1-9]\d?)\b/iu', $line, $match)) {
                $currentDisc = (int) $match[1];
                continue;
            }

            $this->assignLabeledField($metadata, $line);

            if (preg_match('/^\s*(\d{1,3})\s*$/u', $line, $match)) {
                $pendingTrack = (int) $match[1];
                continue;
            }

            $track = $this->parseTrackLine($line, $currentDisc, $pendingTrack);
            if ($track) {
                $tracks[] = $track;
                $pendingTrack = null;
            }
        }

        $metadata['tracks'] = array_slice($this->uniqueTracks($tracks), 0, self::MAX_TRACKS);
        $metadata['release_year'] = $metadata['release_year'] ?: ($metadata['release_date'] ? (int) substr($metadata['release_date'], 0, 4) : null);
        $metadata['total_tracks'] = $metadata['total_tracks'] ?: (count($metadata['tracks']) ?: null);
        if (!$metadata['total_discs'] && $metadata['tracks']) {
            $metadata['total_discs'] = max(array_column($metadata['tracks'], 'disc')) ?: null;
        }
        if ($metadata['artist_name'] && !$metadata['artist_credits']) {
            $metadata['artist_credits'] = $this->makeCredits($metadata['artist_name']);
        }

        return $metadata;
    }

    private function assignLabeledField(array &$metadata, string $line): void
    {
        $fields = [
            'title' => ['专辑名称', '专辑名', 'Album'],
            'artist_name' => ['专辑艺人', '歌手', '演唱者', '艺人', 'Artist'],
            'release_date' => ['发行日期', '发行时间', '发行日期时间', 'Release date'],
            'release_country' => ['发行地区', '发行国家', 'Release country'],
            'label' => ['唱片公司', '发行公司', '厂牌', 'Label'],
            'catalog_number' => ['唱片编号', '目录号', '专辑编号', 'Catalog number'],
            'genre' => ['流派', '曲风', 'Genre'],
            'formats' => ['格式', 'Format'],
            'total_discs' => ['碟片总数', '总碟数', 'Discs'],
            'total_tracks' => ['曲目总数', '歌曲数量', 'Total tracks'],
            'barcode' => ['条码', 'Barcode', 'UPC', 'EAN'],
            'description' => ['简介', '专辑简介', 'Description'],
        ];

        foreach ($fields as $key => $labels) {
            $labelPattern = implode('|', array_map(fn ($label) => preg_quote($label, '/'), $labels));
            if (!preg_match('/^\s*(?:' . $labelPattern . ')\s*[:：]\s*(.+?)\s*$/iu', $line, $match)) {
                continue;
            }

            $value = $this->clean($match[1], $key === 'description' ? 2000 : 255);
            if ($value === '') {
                return;
            }
            if ($key === 'release_date') {
                $date = $this->dateParts($value);
                $metadata['release_date'] = $date['date'];
                $metadata['release_year'] = $date['year'];
            } elseif (in_array($key, ['total_discs', 'total_tracks'], true)) {
                $metadata[$key] = $this->nullableInt($value);
            } elseif ($key === 'formats') {
                $metadata[$key] = array_values(array_filter(array_map('trim', preg_split('/[,，、\/]+/u', $value) ?: [$value])));
            } else {
                $metadata[$key] = $value;
            }
            if ($key === 'artist_name') {
                $metadata['artist_credits'] = $this->makeCredits($value);
            }

            return;
        }
    }

    private function parseTrackLine(string $line, int $currentDisc, ?int $pendingTrack): ?array
    {
        $trackNumber = $pendingTrack;
        $body = $line;
        $disc = $currentDisc;
        if (preg_match('/^\s*(\d{1,2})\s*[-.]\s*(\d{1,3})\s*[.)、）:：]?\s+(.+?)\s*$/u', $line, $match)) {
            $disc = (int) $match[1];
            $trackNumber = (int) $match[2];
            $body = $match[3];
        } elseif (preg_match('/^\s*(\d{1,3})\s*[.)、）:：-]\s*(.+?)\s*$/u', $line, $match)) {
            $trackNumber = (int) $match[1];
            $body = $match[2];
        } elseif (preg_match('/^\s*(\d{1,3})\s+(.+?)\s*$/u', $line, $match)) {
            $trackNumber = (int) $match[1];
            $body = $match[2];
        } elseif ($trackNumber === null) {
            return null;
        }

        if (!$trackNumber || $trackNumber > 999) {
            return null;
        }
        $durationMs = null;
        if (preg_match('/\s+(\d{1,2}:\d{2})\s*$/u', $body, $match)) {
            $durationMs = $this->durationMs($match[1]);
            $body = trim(substr($body, 0, -strlen($match[0])));
        }

        $artist = '';
        if (preg_match('/^(.+?)\s+[—–-]\s+(.+?)$/u', $body, $match)) {
            $body = trim($match[1]);
            $artist = trim($match[2]);
        }
        $title = $this->clean($body, 255);
        if ($title === '' || preg_match('/^(?:发行时间|唱片公司|流派|专辑简介|播放全部|登录)$/u', $title)) {
            return null;
        }

        return [
            'disc' => max(1, $disc),
            'track' => $trackNumber,
            'title' => $title,
            'artist_name' => $this->clean($artist, 255),
            'artist_credits' => $this->makeCredits($artist),
            'length_ms' => $durationMs,
            'isrcs' => [],
            'platform_id' => null,
        ];
    }

    private function linkedTracks(DOMXPath $xpath, string $albumArtist): array
    {
        $links = $xpath->query('//a[contains(@href,"songDetail") or contains(@href,"/song/info/") or contains(@href,"/song?id=")]');
        if (!$links) {
            return [];
        }

        $tracks = [];
        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $title = $this->clean($link->textContent, 255);
            if ($title === '') {
                continue;
            }

            $row = $link;
            for ($level = 0; $level < 4 && $row->parentNode instanceof DOMElement; $level++) {
                $candidate = $row->parentNode;
                $candidateText = $this->clean($candidate->textContent, 1000);
                if (mb_strlen($candidateText) <= 260) {
                    $row = $candidate;
                    if (preg_match('/\d{1,2}:\d{2}/', $candidateText) || preg_match('/^\s*\d{1,3}\b/u', $candidateText)) {
                        break;
                    }
                }
            }
            $rowText = $this->clean($row->textContent, 1000);
            $number = count($tracks) + 1;
            if (preg_match('/^\s*(\d{1,3})\s*(?:[.)、-]\s*)/u', $rowText, $match)) {
                $number = (int) $match[1];
            }
            $durationMs = null;
            if (preg_match('/(?<!\d)(\d{1,2}:\d{2})(?!\d)/', $rowText, $match)) {
                $durationMs = $this->durationMs($match[1]);
            }

            $artist = $albumArtist;
            $rowLinks = $xpath->query('.//a[contains(@href,"singer") or contains(@href,"artist")]', $row);
            if ($rowLinks) {
                foreach ($rowLinks as $artistLink) {
                    $candidateArtist = $this->clean($artistLink->textContent, 255);
                    if ($candidateArtist !== '' && $candidateArtist !== $title) {
                        $artist = $candidateArtist;
                        break;
                    }
                }
            }
            $tracks[] = [
                'disc' => 1,
                'track' => max(1, $number),
                'title' => $title,
                'artist_name' => $artist,
                'artist_credits' => $this->makeCredits($artist),
                'length_ms' => $durationMs,
                'isrcs' => [],
                'platform_id' => null,
            ];
            if (count($tracks) >= self::MAX_TRACKS) {
                break;
            }
        }

        return $this->uniqueTracks($tracks);
    }

    private function structuredTracks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['itemListElement']) && is_array($value['itemListElement'])) {
            $value = $value['itemListElement'];
        } elseif (isset($value['name']) || isset($value['item'])) {
            $value = [$value];
        }

        $tracks = [];
        foreach ($value as $index => $item) {
            if (is_array($item) && isset($item['item']) && is_array($item['item'])) {
                $item = [...$item['item'], 'position' => $item['position'] ?? $item['item']['position'] ?? null];
            }
            if (!is_array($item)) {
                continue;
            }
            $title = $this->clean($item['name'] ?? $item['title'] ?? '', 255);
            if ($title === '') {
                continue;
            }
            $position = $item['position'] ?? $index + 1;
            $disc = 1;
            $track = $index + 1;
            if (is_numeric($position)) {
                $track = (int) $position;
            } elseif (is_string($position) && preg_match('/^(\d{1,2})[-.](\d{1,3})$/', $position, $match)) {
                $disc = (int) $match[1];
                $track = (int) $match[2];
            }
            $artistValue = $item['byArtist'] ?? $item['artist'] ?? null;
            $artist = $this->artistName($artistValue);
            $tracks[] = [
                'disc' => max(1, $disc),
                'track' => max(1, $track),
                'title' => $title,
                'artist_name' => $artist,
                'artist_credits' => $this->artistCredits($artistValue, $artist),
                'length_ms' => $this->durationMs(is_string($item['duration'] ?? null) ? $item['duration'] : null),
                'isrcs' => [],
                'platform_id' => null,
            ];
        }

        return $this->uniqueTracks($tracks);
    }

    private function findMusicAlbum(DOMXPath $xpath): array
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if (!$scripts) {
            return [];
        }
        foreach ($scripts as $script) {
            $json = json_decode((string) $script->textContent, true);
            if (!is_array($json)) {
                continue;
            }
            $album = $this->findAlbumNode($json);
            if ($album) {
                return $album;
            }
        }

        return [];
    }

    private function findAlbumNode(array $node): array
    {
        $type = $node['@type'] ?? [];
        $types = is_array($type) ? $type : [$type];
        if (array_intersect($types, ['MusicAlbum', 'Album']) && !empty($node['name'])) {
            return $node;
        }
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as $child) {
                    if (is_array($child) && ($found = $this->findAlbumNode($child))) {
                        return $found;
                    }
                }
            } elseif ($found = $this->findAlbumNode($value)) {
                return $found;
            }
        }

        return [];
    }

    private function metaValues(DOMXPath $xpath): array
    {
        $values = [];
        foreach ($xpath->query('//meta') ?: [] as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            $value = $this->clean($meta->getAttribute('content'), 2000);
            if ($key !== '' && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function visibleText(DOMXPath $xpath): string
    {
        $parts = [];
        foreach ($xpath->query('//body//text()[normalize-space()]') ?: [] as $node) {
            $parent = $node->parentNode;
            $skip = false;
            while ($parent instanceof DOMElement) {
                if (in_array(strtolower($parent->tagName), ['script', 'style', 'noscript', 'svg'], true)) {
                    $skip = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            if (!$skip) {
                $text = $this->clean($node->textContent, 1000);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return mb_substr(implode("\n", $parts), 0, self::MAX_PASTED_CHARS);
    }

    private function firstHeading(DOMXPath $xpath): ?string
    {
        $heading = $xpath->query('//h1')->item(0);

        return $heading ? $this->clean($heading->textContent, 255) : null;
    }

    private function artistNearHeading(DOMXPath $xpath, string $title): string
    {
        $heading = $xpath->query('//h1')->item(0);
        $node = $heading;
        for ($level = 0; $level < 4 && $node instanceof DOMNode && $node->parentNode; $level++) {
            $node = $node->parentNode;
            if (!$node instanceof DOMElement) {
                continue;
            }
            foreach ($xpath->query('.//a[contains(@href,"singer") or contains(@href,"artist")]', $node) ?: [] as $link) {
                $candidate = $this->clean($link->textContent, 255);
                if ($candidate !== '' && mb_strtolower($candidate) !== mb_strtolower($title)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function labelValue(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*[:：]\s*([^\n]{1,255})/iu';
            if (preg_match($pattern, $text, $match)) {
                return $this->clean($match[1], 255);
            }
        }

        return null;
    }

    private function dateParts(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ['date' => null, 'year' => null];
        }
        $value = trim($value);
        if (preg_match('/\b(\d{4})[-/.年](\d{1,2})[-/.月](\d{1,2})日?\b/u', $value, $match)) {
            $year = (int) $match[1];
            $month = (int) $match[2];
            $day = (int) $match[3];
            if (checkdate($month, $day, $year)) {
                return ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'year' => $year];
            }
        }
        if (preg_match('/\b(\d{4})\b/u', $value, $match)) {
            $year = (int) $match[1];

            return ['date' => null, 'year' => $year >= 1000 && $year <= 9999 ? $year : null];
        }

        return ['date' => null, 'year' => null];
    }

    private function artistName(mixed $value): string
    {
        if (is_string($value)) {
            return $this->clean($value, 255);
        }
        if (is_array($value)) {
            if (isset($value['name'])) {
                return $this->clean((string) $value['name'], 255);
            }
            $names = array_values(array_filter(array_map(fn ($item) => is_array($item) ? $this->clean((string) ($item['name'] ?? ''), 255) : '', $value)));

            return $this->clean(implode(' / ', $names), 255);
        }

        return '';
    }

    private function makeCredits(string $artist): array
    {
        $artist = $this->clean($artist, 255);
        if ($artist === '') {
            return [];
        }

        return [['musicbrainz_id' => null, 'name' => $artist, 'credited_name' => $artist, 'joinphrase' => '']];
    }

    private function artistCredits(mixed $value, string $fallback): array
    {
        if (is_array($value) && array_is_list($value)) {
            $names = array_values(array_filter(array_map(
                fn ($artist) => is_array($artist)
                    ? $this->clean((string) ($artist['name'] ?? ''), 255)
                    : (is_string($artist) ? $this->clean($artist, 255) : ''),
                $value,
            )));
            if (count($names) > 1) {
                $credits = [];
                foreach ($names as $index => $name) {
                    $credits[] = [
                        'musicbrainz_id' => null,
                        'name' => $name,
                        'credited_name' => $name,
                        'joinphrase' => $index < count($names) - 1 ? ' / ' : '',
                    ];
                }

                return $credits;
            }
        }

        return $this->makeCredits($fallback);
    }

    private function firstImage(mixed $image): string
    {
        if (is_string($image)) {
            return $image;
        }
        if (is_array($image)) {
            if (isset($image['url']) && is_string($image['url'])) {
                return $image['url'];
            }
            foreach ($image as $item) {
                if (is_string($item)) {
                    return $item;
                }
                if (is_array($item) && is_string($item['url'] ?? null)) {
                    return $item['url'];
                }
            }
        }

        return '';
    }

    private function safeCoverUrl(string $url, string $platform): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, self::COVER_HOSTS, true) || trim($url) === '') {
            return null;
        }

        return $url;
    }

    private function formats(string $text, array $jsonAlbum): array
    {
        $format = $this->scalarOrList($jsonAlbum['bookFormat'] ?? $jsonAlbum['musicReleaseFormat'] ?? null);
        if (!$format) {
            $format = $this->labelValue($text, ['格式', 'Format']);
        }

        return $format ? array_slice(array_values(array_unique(array_map('trim', preg_split('/[,，、\/]+/u', $format) ?: [$format]))), 0, 10) : [];
    }

    private function scalarOrList(mixed $value): string
    {
        if (is_string($value)) {
            return $this->clean($value, 255);
        }
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(fn ($item) => is_string($item) ? $this->clean($item, 255) : '', $value)));

            return implode(', ', $items);
        }

        return '';
    }

    private function durationMs(?string $duration): ?int
    {
        if (!$duration) {
            return null;
        }
        if (preg_match('/^(?:PT)?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $duration, $match)) {
            if (isset($match[1]) || isset($match[2]) || isset($match[3])) {
                return (((int) ($match[1] ?? 0) * 60 + (int) ($match[2] ?? 0)) * 60 + (int) ($match[3] ?? 0)) * 1000;
            }
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $match)) {
            return ((int) $match[1] * 60 + (int) $match[2]) * 1000;
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/\d+/', $value, $match)) {
            $number = (int) $match[0];

            return $number > 0 ? $number : null;
        }

        return null;
    }

    private function clean(string $text, int $max = 255): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_substr($text, 0, $max);
    }

    private function uniqueTracks(array $tracks): array
    {
        $unique = [];
        foreach ($tracks as $track) {
            $key = $track['disc'] . ':' . $track['track'];
            if (!isset($unique[$key])) {
                $unique[$key] = $track;
            }
        }

        return array_values($unique);
    }

    private function trackCompletenessWarning(array $metadata): ?string
    {
        $actual = count($metadata['tracks'] ?? []);
        $expected = $metadata['total_tracks'] ?? null;
        if ($actual >= self::MAX_TRACKS) {
            return '清单已达到 500 首展示上限；未显示的曲目不会创建。';
        }
        if ($expected && $actual && $actual < $expected) {
            return "公开页面当前只识别到 $actual/$expected 首。未读到的曲目不会创建；可粘贴包含完整曲目表的页面文字。";
        }

        return null;
    }

    private function sourceForPastedText(?string $sourceUrl): array
    {
        if (!is_string($sourceUrl) || trim($sourceUrl) === '') {
            return ['platform' => '粘贴页面文字', 'url' => null];
        }
        try {
            $page = $this->validatePageUrl(trim($sourceUrl), true);
        } catch (ValidationException) {
            return ['platform' => '粘贴页面文字', 'url' => null];
        }

        return ['platform' => $page['platform'] . '（粘贴文字）', 'url' => $page['url']];
    }

    private function unavailable(string $url, string $platform, string $warning): array
    {
        return [
            ...$this->emptyMetadata(),
            'method' => 'public-page',
            'source_platform' => $platform,
            'source_url' => $url,
            'read_status' => 'unreadable',
            'warning' => $warning,
        ];
    }

    private function emptyMetadata(): array
    {
        return [
            'title' => '',
            'artist_name' => '',
            'artist_credits' => [],
            'release_date' => null,
            'release_year' => null,
            'release_country' => '',
            'label' => '',
            'catalog_number' => '',
            'genre' => '',
            'total_discs' => null,
            'total_tracks' => null,
            'barcode' => '',
            'formats' => [],
            'description' => '',
            'cover_preview_url' => null,
            'tracks' => [],
        ];
    }
}
