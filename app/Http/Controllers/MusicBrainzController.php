<?php

namespace App\Http\Controllers;

use App\Services\MusicBrainz;
use Illuminate\Http\Request;

class MusicBrainzController extends Controller
{
    public function search(Request $request, MusicBrainz $musicBrainz)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
        ]);

        return ['items' => $musicBrainz->search($data['q'], $data['artist'] ?? null)];
    }

    public function show(string $id, MusicBrainz $musicBrainz)
    {
        return ['item' => $musicBrainz->release($id)];
    }

    public function cover(Request $request, string $id, MusicBrainz $musicBrainz)
    {
        $data = $request->validate(['image_id' => ['nullable', 'string', 'max:64']]);
        $image = $musicBrainz->cover($id, $data['image_id'] ?? null);

        return response($image['body'], 200, [
            'Content-Type' => $image['mime_type'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
