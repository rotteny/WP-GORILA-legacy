<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstanceResource;
use App\Models\Instance;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstanceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InstanceResource::collection(
            Instance::query()->orderBy('id')->get()
        );
    }

    public function show(Instance $instance): InstanceResource
    {
        return new InstanceResource($instance);
    }
}
