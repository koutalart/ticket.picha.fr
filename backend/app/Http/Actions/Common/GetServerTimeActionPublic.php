<?php

namespace HiEvents\Http\Actions\Common;

use Carbon\Carbon;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;

class GetServerTimeActionPublic extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        return $this->jsonResponse([
            'server_time' => Carbon::now()->toIso8601ZuluString('millisecond'),
        ])->header('Cache-Control', 'no-store');
    }
}
