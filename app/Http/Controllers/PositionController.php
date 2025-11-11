<?php

namespace App\Http\Controllers;

use App\Support\Greeks\PositionAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PositionController extends Controller
{
    public function analyze(Request $req)
    {
        $v = Validator::make($req->all(), [
            'underlying.symbol' => 'required|string|max:12',
            'underlying.price'  => 'required|numeric|min:0.01',
            'legs'   => 'required|array|min:1',
            'legs.*.type'   => 'required|in:call,put',
            'legs.*.side'   => 'required|in:long,short',
            'legs.*.qty'    => 'required|integer|min:1',
            'legs.*.strike' => 'required|numeric|min:0.01',
            'legs.*.expiry' => 'required|date',
            'legs.*.iv'     => 'nullable|numeric|min:0.01|max:5',
            'legs.*.price'  => 'nullable|numeric|min:0',
            'scenarios.spot_pct' => 'sometimes|array',
            'scenarios.iv_pts'   => 'sometimes|array',
            'scenarios.days'     => 'sometimes|array',
            'default_iv'         => 'nullable|numeric|min:0.01|max:5',
            'r' => 'nullable|numeric|min:-0.05|max:0.1',
            'q' => 'nullable|numeric|min:-0.2|max:0.2',
        ]);
        if ($v->fails()) return response()->json(['errors'=>$v->errors()], 422);

        $payload = $v->validated();
        $hash = 'pos:v1:'.md5(json_encode($payload));
        $ttl  = now()->addMinutes(5);

        return Cache::remember($hash, $ttl, function () use ($payload) {
            $S = (float)$payload['underlying']['price'];
            foreach ($payload['legs'] as &$L) {
                if (!isset($L['iv']) && !isset($L['price'])) {
                    $expDM = Carbon::parse($L['expiry'])->format('m-d');
                    $row = DB::table('option_snapshots')
                        ->where('symbol', strtoupper($payload['underlying']['symbol']))
                        ->whereRaw("DATE_FORMAT(expiry, '%m-%d') = ?", [$expDM])
                        ->where('strike', $L['strike'])
                        ->where('type', $L['type'])
                        ->orderByDesc('fetched_at')
                        ->first();
                    if ($row && $row->mid > 0) {
                        $L['price'] = (float)$row->mid;
                    }
                }
            }
            unset($L);
            $legs = $payload['legs'];
            $r = (float)($payload['r'] ?? 0.00);
            $q = (float)($payload['q'] ?? 0.00);
            $defaultIv = isset($payload['default_iv']) ? (float)$payload['default_iv'] : null;
            $sc = $payload['scenarios'] ?? null;

            $result = PositionAnalyzer::analyze($legs, $S, $r, $q, $defaultIv, $sc);

            return response()->json([
                'underlying' => $payload['underlying'],
                'now'        => $result['now'],
                'payoff'     => $result['payoff'],
                'scenarios'  => $result['grid'],
            ]);
        });
    }
}
