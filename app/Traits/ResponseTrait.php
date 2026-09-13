<?php

namespace App\Traits;

trait ResponseTrait
{
    protected function success($message, $data = null, $status = 200)
    {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => $data
        ], $status);
    }

    public function fail($message = 'Failed', $code = 400, $data = [])
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    public function error($message = 'Failed', $code = 400, $data = [])
    {
        return $this->fail($message, $code, $data);
    }
}
