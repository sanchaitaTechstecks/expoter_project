<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DeepSeekService;

class AIController extends Controller
{
    protected $deepSeekService;

    public function __construct(DeepSeekService $deepSeekService)
    {
        $this->deepSeekService = $deepSeekService;
    }

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:10000',
        ]);

        try {
            $reply = $this->deepSeekService->chat(
                $request->message
            );

            return response()->json([
                'success' => true,
                'reply' => $reply,
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to get response from AI.',
            ], 500);
        }
    }
}