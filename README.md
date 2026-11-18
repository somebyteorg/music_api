# music

## Build

```bash
docker build -t music/frankenphp:8.5 .
```

## Run

```bash
composer install --optimize-autoloader --no-dev -vvv
docker compose up -d
```

## MusicBrainz upload metadata

Authenticated clients can search releases with `GET /api/metadata/musicbrainz/releases/search?q=...&artist=...`, load one selected release with `GET /api/metadata/musicbrainz/releases/{mbid}`, and request a specific Cover Art Archive image with `GET /api/metadata/musicbrainz/releases/{mbid}/cover?image_id=...`.

A release preview includes its MusicBrainz ID, full release date when known, year, country, status, barcode, labels, catalog numbers, media formats, genre tags and artist credits. Its complete disc/track list includes recording IDs, track artist credits, lengths and ISRCs when available. MusicBrainz IDs on songs and artists are unique when present; an exact recording ID reuses the existing song, and artists are resolved by MusicBrainz ID before name. A same-name artist with a different MusicBrainz ID is kept separate; an ambiguous name without an ID is not silently merged.

Only songs corresponding to audio files the user selected are created or linked. Other release tracks are shown for comparison and never become empty song records. Existing album and song fields are supplemented only when blank; a selected CAA image is imported only after an explicit user confirmation and never automatically replaces an existing album cover. The EmoS schema does not have dedicated barcode, media-format, ISRC or track-length columns, so those remain visible in the release/track preview rather than being written to those catalog fields.

Requests identify this application as `EmosMusicAPI`, are paced to at most one MusicBrainz API request per second and cached for 12 hours. Cover Art Archive manifests are cached; downloaded images are limited to HTTPS Archive.org hosts, supported image types and the configured cover-size limit. Artwork rights remain with their respective owners; clients must obtain user confirmation that an image may be used before importing it.

## Domestic public album page preview

Authenticated clients can submit `POST /api/metadata/public-album/preview` with either `{ "url": "https://..." }` or `{ "url": "https://...", "text": "..." }`. The endpoint is rate limited and returns a draft only; it does not create catalog entities or save release data. It accepts HTTPS album/share pages only on the supported QQ Music, NetEase Cloud Music and Kugou hosts and album paths, checks public DNS addresses, follows only a few allowlisted HTTPS redirects, and limits request time and page size. It sends no cookies, does not execute JavaScript, and does not call platform APIs or hidden endpoints.

The parser reads public page metadata (Open Graph/JSON-LD), visible page text and visible track links. If a page requires sign-in, is blocked, is dynamically rendered without readable public metadata, or does not expose its track list, the response asks the client to use the same preview endpoint with copied visible text instead; it does not bypass the restriction. Imported values are shown for review and then use the existing album/song matching and publish flow. Platform IDs are never stored in MusicBrainz ID fields. Barcode, formats, descriptions and remote cover URLs are preview-only because the EmoS catalog has no corresponding writable fields. Remote covers are not downloaded; users can select a local image through the existing upload UI. Only user-selected local audio files can create or link songs; other listed tracks remain a comparison list.

## Login

The existing session-cookie flow accepts either the EMOS single-sign-on callback or `POST /api/login` with a `token` in the request body. The token is sent by the server to the configured `EMOS_URL` as a bearer credential for `/api/user/base`, then discarded; it is not saved as an account password or included in a URL. The frontend uses the same-origin `/api` path, including when deployed at `https://music.emos.best/`.
