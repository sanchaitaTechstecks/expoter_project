<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DeepSeekService
{
    public function chat(string $message): string
    {
        $response = Http::withToken(config('services.dahl.api_key'))
            ->acceptJson()
            ->timeout(60)
            ->post(
                rtrim(config('services.dahl.base_url'), '/') . '/chat/completions',
                [
                    'model' => config('services.dahl.model'),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $message,
                        ],
                    ],
                ]
            );

        $response->throw();

        return $response->json('choices.0.message.content');
    }
}