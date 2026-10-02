<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $threshold = (int) Setting::getValue('alert_score_threshold', 40);

        $alerts = Call::query()
            ->with(['operator:id,name', 'analysis'])
            ->whereHas('analysis', function ($q) use ($threshold) {
                $q->where('has_rudeness', true)
                    ->orWhereRaw('COALESCE(manual_score, total_score) < ?', [$threshold])
                    ->orWhere('buyer_potential_score', '>=', 70);
            })
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function (Call $call) use ($threshold) {
                /** @var CallAnalysis|null $analysis */
                $analysis = $call->analysis;
                $reasons = [];

                if ($analysis?->has_rudeness) {
                    $reasons[] = 'Kobudluq';
                }
                if ($analysis && ($call->effective_score !== null) && $call->effective_score < $threshold) {
                    $reasons[] = "Aşağı skor (<{$threshold})";
                }
                if (($analysis?->buyer_potential_score ?? 0) >= 70) {
                    $reasons[] = 'Yüksək alıcı potensialı';
                }

                return [
                    'id' => $call->id,
                    'customer_name' => $call->customer_name,
                    'customer_phone' => $call->customer_phone,
                    'operator' => $call->operator?->name,
                    'score' => $call->effective_score,
                    'reasons' => $reasons,
                    'called_at' => $call->called_at?->format('d.m.Y H:i'),
                ];
            });

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts,
            'threshold' => $threshold,
        ]);
    }
}
