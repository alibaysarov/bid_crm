<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexEventsRequest;
use App\Http\Resources\EventResource;
use App\Repositories\EventRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function index(
        IndexEventsRequest $request,
        EventRepository $events,
    ): AnonymousResourceCollection {
        return EventResource::collection($events->paginate($request->validated()));
    }
}
