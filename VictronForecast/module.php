<?php

declare(strict_types=1);

class VictronForecast extends IPSModule
{
    private const FETCH_INTERVAL = 3600;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('SiteID', 0);

        $this->RegisterAttributeInteger('LastFetchAttempt', 0);
        $this->RegisterAttributeString('CachedForecastData', '');

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableFloat(
            'ForecastNext12Hours',
            'Forecast naechste 12 Stunden',
            '~Electricity',
            1
        );
        $this->RegisterVariableFloat(
            'ForecastToday',
            'Forecast heute',
            '~Electricity',
            2
        );
        $this->RegisterVariableFloat(
            'ForecastTomorrow',
            'Forecast morgen',
            '~Electricity',
            3
        );
        $this->RegisterVariableFloat(
            'ForecastDayAfterTomorrow',
            'Forecast uebermorgen',
            '~Electricity',
            4
        );
        $this->RegisterVariableBoolean(
            'FetchData',
            'Daten abrufen',
            '~Switch',
            5
        );
        $this->EnableAction('FetchData');

        $fetchDataId = @$this->GetIDForIdent('FetchData');
        if ($fetchDataId !== false) {
            SetValue($fetchDataId, false);
        }

        $cachedData = $this->LoadCachedForecastData();
        if ($cachedData !== false) {
            $this->UpdateForecastVariables($cachedData);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'FetchData') {
            throw new Exception('Ungueltiger Ident: ' . $Ident);
        }

        $fetchDataId = $this->GetIDForIdent('FetchData');
        SetValue($fetchDataId, (bool)$Value);

        if ((bool)$Value) {
            $this->RefreshForecastData();
            SetValue($fetchDataId, false);
        }
    }

    public function RefreshForecastData(): bool
    {
        if (!$this->CanFetchForecastData()) {
            return false;
        }

        $data = $this->FetchForecastDataFromApi();

        if (!$this->IsValidForecastData($data)) {
            return false;
        }

        $encodedData = json_encode($data);
        if ($encodedData === false) {
            return false;
        }

        $this->WriteAttributeString('CachedForecastData', $encodedData);
        $this->UpdateForecastVariables($data);

        return true;
    }

    private function GetDailyForecast()
    {
        $data = $this->GetForecastData();

        if ($data === false) {
            return false;
        }

        $days = [];

        foreach ($data['records']['solar_yield_forecast'] ?? [] as $row) {

            $day = date('d.m', $row[0] / 1000);

            if (!isset($days[$day])) {
                $days[$day] = [
                    'pv' => 0,
                    'consumption' => 0
                ];
            }

            $days[$day]['pv'] += $row[1];
        }

        foreach ($data['records']['vrm_consumption_fc'] ?? [] as $row) {

            $day = date('d.m', $row[0] / 1000);

            if (!isset($days[$day])) {
                $days[$day] = [
                    'pv' => 0,
                    'consumption' => 0
                ];
            }

            $days[$day]['consumption'] += $row[1];
        }

        return $days;
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(
            __DIR__ . '/assets/main.html'
        );

        $css = file_get_contents(
            __DIR__ . '/assets/main.css'
        );

        $js = file_get_contents(
            __DIR__ . '/assets/app.js'
        );

        
        $forecast = $this->GetDailyForecast();

        $data = [];

        foreach (($forecast ?: []) as $day => $row) {

            $pv =
                round($row['pv'] / 1000, 2);

            $consumption =
                round($row['consumption'] / 1000, 2);

            $data[] = [
                'day' => $day,
                'pv' => $pv,
                'consumption' => $consumption,
                'surplus' =>
                round(
                    $pv - $consumption,
                    2
                )
            ];
        }

        $html = str_replace(
            '{{CSS}}',
            $css,
            $html
        );

        $html = str_replace(
            '{{JS}}',
            $js,
            $html
        );

        $html = str_replace(
            '{{DATA}}',
            json_encode($data),
            $html
        );

        return $html;
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'Token',
                    'caption' => 'Victron API Token'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'SiteID',
                    'caption' => 'Victron Site ID'
                ]
            ]
        ]);
    }

    private function GetForecastData()
    {
        if ($this->ShouldRefreshForecastData()) {
            $this->RefreshForecastData();
        }

        return $this->LoadCachedForecastData();
    }

    private function FetchForecastDataFromApi()
    {
        $token = $this->ReadPropertyString('Token');
        $siteId = $this->ReadPropertyInteger('SiteID');

        if ($token === '' || $siteId === 0) {
            return false;
        }

        $start = strtotime('today midnight');
        $end = strtotime('midnight +7 days') - 1;

        $url =
            "https://vrmapi.victronenergy.com/v2/installations/$siteId/stats"
            . "?type=forecast"
            . "&start=$start"
            . "&end=$end";

        $this->WriteAttributeInteger('LastFetchAttempt', time());

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "x-authorization: Token $token"
            ]
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);
            return false;
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        $data = json_decode($response, true);

        return is_array($data) ? $data : false;
    }

    private function LoadCachedForecastData()
    {
        $cachedData = $this->ReadAttributeString('CachedForecastData');

        if ($cachedData === '') {
            return false;
        }

        $data = json_decode($cachedData, true);

        if (!$this->IsValidForecastData($data)) {
            return false;
        }

        return $data;
    }

    private function IsValidForecastData($data): bool
    {
        return is_array($data)
            && (($data['success'] ?? false) === true)
            && isset($data['records'])
            && is_array($data['records']);
    }

    private function ShouldRefreshForecastData(): bool
    {
        $lastFetchAttempt = $this->ReadAttributeInteger('LastFetchAttempt');
        $hasCachedData = $this->LoadCachedForecastData() !== false;

        if (!$hasCachedData) {
            return $this->CanFetchForecastData();
        }

        return $lastFetchAttempt === 0
            || (time() - $lastFetchAttempt) >= self::FETCH_INTERVAL;
    }

    private function CanFetchForecastData(): bool
    {
        $lastFetchAttempt = $this->ReadAttributeInteger('LastFetchAttempt');

        return $lastFetchAttempt === 0
            || (time() - $lastFetchAttempt) >= self::FETCH_INTERVAL;
    }

    private function UpdateForecastVariables(array $data): void
    {
        $solarForecast = $data['records']['solar_yield_forecast'] ?? [];
        $now = time();
        $next12HoursEnd = $now + (12 * 3600);

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime('today')));
        $dayAfterTomorrow = date('Y-m-d', strtotime('+2 day', strtotime('today')));

        $next12Hours = 0.0;
        $todayForecast = 0.0;
        $tomorrowForecast = 0.0;
        $dayAfterTomorrowForecast = 0.0;

        foreach ($solarForecast as $row) {
            if (!isset($row[0], $row[1])) {
                continue;
            }

            $timestamp = (int) ($row[0] / 1000);
            $value = (float) $row[1];
            $day = date('Y-m-d', $timestamp);

            if ($timestamp >= $now && $timestamp < $next12HoursEnd) {
                $next12Hours += $value;
            }

            if ($day === $today) {
                $todayForecast += $value;
            }

            if ($day === $tomorrow) {
                $tomorrowForecast += $value;
            }

            if ($day === $dayAfterTomorrow) {
                $dayAfterTomorrowForecast += $value;
            }
        }

        SetValue($this->GetIDForIdent('ForecastNext12Hours'), round($next12Hours / 1000, 2));
        SetValue($this->GetIDForIdent('ForecastToday'), round($todayForecast / 1000, 2));
        SetValue($this->GetIDForIdent('ForecastTomorrow'), round($tomorrowForecast / 1000, 2));
        SetValue($this->GetIDForIdent('ForecastDayAfterTomorrow'), round($dayAfterTomorrowForecast / 1000, 2));
    }
}
