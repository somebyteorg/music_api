<?php

namespace App\Http\Controllers;

use App\Services\PublicAlbumPage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicAlbumPageController extends Controller
{
    public function preview(Request $request, PublicAlbumPage $pages): array
    {
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:2048'],
            'text' => ['nullable', 'string', 'max:50000'],
        ]);

        if (blank($data['url'] ?? null) && blank($data['text'] ?? null)) {
            throw ValidationException::withMessages(['url' => '请输入专辑页链接，或粘贴页面文字。']);
        }

        return ['item' => $pages->preview($data['url'] ?? null, $data['text'] ?? null)];
    }
}
