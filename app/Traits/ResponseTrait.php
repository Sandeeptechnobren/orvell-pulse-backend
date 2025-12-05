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

    protected function error($message, $status = 400, $errors = null)
    {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors
        ], $status);
    }
}
