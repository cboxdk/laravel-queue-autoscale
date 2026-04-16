<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

final class LinearRegressionForecaster implements ForecasterContract
{
    private const int MIN_SAMPLES = 5;

    /**
     * @param  list<array{timestamp: float, rate: float}>  $history
     */
    public function forecast(array $history, int $horizonSeconds): ForecastResult
    {
        $n = count($history);

        if ($n < self::MIN_SAMPLES) {
            return ForecastResult::insufficientData();
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        $latestT = 0.0;

        foreach ($history as $point) {
            $sumX += $point['timestamp'];
            $sumY += $point['rate'];
            $sumXY += $point['timestamp'] * $point['rate'];
            $sumXX += $point['timestamp'] * $point['timestamp'];
            if ($point['timestamp'] > $latestT) {
                $latestT = $point['timestamp'];
            }
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;

        $denominator = $sumXX - $n * $meanX * $meanX;

        if (abs($denominator) < 1e-12) {
            return new ForecastResult(
                projectedRate: $meanY,
                rSquared: 1.0,
                slope: 0.0,
                sampleCount: $n,
                hasSufficientData: true,
            );
        }

        $slope = ($sumXY - $n * $meanX * $meanY) / $denominator;
        $intercept = $meanY - $slope * $meanX;

        $ssTot = 0.0;
        $ssRes = 0.0;
        foreach ($history as $point) {
            $predicted = $slope * $point['timestamp'] + $intercept;
            $ssRes += ($point['rate'] - $predicted) ** 2;
            $ssTot += ($point['rate'] - $meanY) ** 2;
        }

        $rSquared = $ssTot < 1e-12
            ? 1.0
            : max(0.0, 1.0 - ($ssRes / $ssTot));

        $projectedRate = max(0.0, $slope * ($latestT + $horizonSeconds) + $intercept);

        return new ForecastResult(
            projectedRate: $projectedRate,
            rSquared: $rSquared,
            slope: $slope,
            sampleCount: $n,
            hasSufficientData: true,
        );
    }
}
