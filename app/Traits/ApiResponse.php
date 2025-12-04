<?php
namespace App\Traits;

class ApiResponse
{
    public static function success($data = [], $message = 'success', $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function fail($message = 'error', $code = 400, $data = [])
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }
}
