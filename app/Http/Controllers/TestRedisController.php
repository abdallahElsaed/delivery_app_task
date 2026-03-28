<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Redis;

class TestRedisController extends Controller
{
    public function testRedis()
    {
        $redis = Redis::set('product:1', 'Shoes');
        $userName = Redis::set('user:1', 'ali');
        return response()->json(['message' => 'Redis test successful', 'data' => Redis::get('product:1'), 'userName' => Redis::get('user:1')]);
    }
}
