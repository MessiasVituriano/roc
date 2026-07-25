<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventStateService;
use Illuminate\Http\JsonResponse;

class DisplayController extends Controller
{
    public function __construct(protected EventStateService $state) {}

    /** Polled once per second by the big screen. */
    public function __invoke(): JsonResponse
    {
        $event = $this->state->activeEvent();

        if (! $event) {
            return response()->json(['event' => null, 'tables' => []]);
        }

        return response()->json($this->state->display($event));
    }
}
