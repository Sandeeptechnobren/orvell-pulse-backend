<?php

namespace App\Http\Controllers;

use App\Http\Request\Item_categoryRequest;
use App\Services\Item_categoryService;
use App\Http\Resources\Item_categoryResource;

class Item_categoryController extends Controller
{
    protected $service;
    public function __construct(Item_categoryService $service)
    {
        $this->service = $service;
    }
    public function index()
    {
        return response()->json([
            'message' => 'Item categories fetched',
            'data' => Item_categoryResource::collection($this->service->list())
        ]);
    }
    public function store(Item_categoryRequest $request)
    {
        $item = $this->service->create($request->validated());
        return response()->json([
            'message' => 'Item category created',
            'data' => new Item_categoryResource($item)
        ]);
    }

    public function show($uuid)
    {
        $item = $this->service->getByUuid($uuid);
        return response()->json([
            'message' => 'Item category fetched',
            'data' => new Item_categoryResource($item)
        ]);
    }
    public function update(Item_categoryRequest $request, $uuid)
    {
        $item = $this->service->updateByUuid($uuid, $request->validated());
        return response()->json([
            'message' => 'Item category updated',
            'data' => new Item_categoryResource($item)
        ]);
    }
    public function destroy($uuid)
    {
        $this->service->deleteByUuid($uuid);
        return response()->json([
            'message' => 'Item category deleted'
        ]);
    }
}
