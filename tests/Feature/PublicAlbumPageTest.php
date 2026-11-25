<?php

use App\Services\PublicAlbumPage;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function publicAlbumPageService(): PublicAlbumPage
{
    return new class extends PublicAlbumPage
    {
        protected function hasPublicAddress(string $host): bool
        {
            return true;
        }
    };
}

test('it maps pasted domestic album text without inventing platform or MusicBrainz identifiers', function () {
    $item = publicAlbumPageService()->preview(null, implode("\n", [
        '专辑名称：示例专辑',
        '歌手：示例歌手',
        '发行时间：2024-03-08',
        '唱片公司：示例厂牌',
        '流派：流行',
        '碟片总数：1',
        '1. 第一首 - 示例歌手 03:12',
        '2. 第二首 - 合作歌手 04:05',
    ]));

    expect($item['read_status'])->toBe('read')
        ->and($item['title'])->toBe('示例专辑')
        ->and($item['artist_name'])->toBe('示例歌手')
        ->and($item['artist_credits'][0]['musicbrainz_id'])->toBeNull()
        ->and($item['release_date'])->toBe('2024-03-08')
        ->and($item['release_year'])->toBe(2024)
        ->and($item['label'])->toBe('示例厂牌')
        ->and($item['genre'])->toBe('流行')
        ->and($item['total_tracks'])->toBe(2)
        ->and($item['tracks'][0])->toMatchArray([
            'disc' => 1,
            'track' => 1,
            'title' => '第一首',
            'artist_name' => '示例歌手',
            'length_ms' => 192000,
            'platform_id' => null,
        ])
        ->and($item['tracks'][1]['artist_name'])->toBe('合作歌手')
        ->and($item['method'])->toBe('pasted-text');
});

test('it supports split label lines and multi-disc track positions', function () {
    $item = publicAlbumPageService()->previewText("专辑名称：\n多碟专辑\n发行时间：\n2020\nCD 2\n2-01 第二碟第一首\n2-02 第二碟第二首");

    expect($item['title'])->toBe('多碟专辑')
        ->and($item['release_year'])->toBe(2020)
        ->and($item['tracks'])->toHaveCount(2)
        ->and($item['tracks'][0])->toMatchArray(['disc' => 2, 'track' => 1, 'title' => '第二碟第一首'])
        ->and($item['total_discs'])->toBe(2);
});

test('it rejects non-platform hosts, non-https and non-album paths before making requests', function () {
    $service = publicAlbumPageService();

    expect(fn () => $service->preview('https://example.com/album?id=1', null))->toThrow(ValidationException::class)
        ->and(fn () => $service->preview('http://music.163.com/album?id=1', null))->toThrow(ValidationException::class)
        ->and(fn () => $service->preview('https://music.163.com/song?id=1', null))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('it reads a public QQ album page as HTML and extracts visible album and track data', function () {
    Http::fake([
        'y.qq.com/n/ryqq/albumDetail/*' => Http::response(<<<'HTML'
            <!doctype html><html><head><meta property="og:image" content="https://y.gtimg.cn/music/photo/cover.jpg"></head>
            <body><div><h1>公开专辑</h1><a href="/n/ryqq_v2/singer/artist1">公开歌手</a></div>
            <p>发行时间：2022-05-11</p><p>唱片公司：公开厂牌</p><p>流派：摇滚</p>
            <ul><li>1. 第一首 <a href="/n/ryqq_v2/songDetail/song1">第一首</a><a href="/n/ryqq_v2/singer/artist1">公开歌手</a><span>04:32</span></li></ul>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
    ]);

    $item = publicAlbumPageService()->preview('https://y.qq.com/n/ryqq/albumDetail/album1', null);

    expect($item['read_status'])->toBe('read')
        ->and($item['source_platform'])->toBe('QQ 音乐')
        ->and($item['title'])->toBe('公开专辑')
        ->and($item['artist_name'])->toBe('公开歌手')
        ->and($item['release_date'])->toBe('2022-05-11')
        ->and($item['label'])->toBe('公开厂牌')
        ->and($item['tracks'][0]['title'])->toBe('第一首')
        ->and($item['tracks'][0]['length_ms'])->toBe(272000)
        ->and($item['cover_preview_url'])->toBe('https://y.gtimg.cn/music/photo/cover.jpg');

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'y.qq.com/n/ryqq/albumDetail/album1')
        && !isset($request->getHeaders()['Cookie']));
});

test('it reports a login wall as unreadable without trying another route', function () {
    Http::fake([
        'music.163.com/album*' => Http::response('<html><body>登录后查看更多信息</body></html>', 403, ['Content-Type' => 'text/html']),
    ]);

    $item = publicAlbumPageService()->preview('https://music.163.com/album?id=18886', null);

    expect($item['read_status'])->toBe('unreadable')
        ->and($item['warning'])->toContain('不会尝试绕过')
        ->and($item['tracks'])->toBe([]);
});
