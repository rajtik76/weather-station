@php($report = $this->stationReport)
<ul class="hz-status" aria-label="Station status">
    <li>Station <b>{{ $this->isSilent ? 'silent' : 'online' }}</b></li>
    @if ($this->measuredAt !== null)
        <li>Last reading <b>{{ $this->measuredAt }}</b></li>
    @endif
    @if ($report !== null)
        <li>Firmware <b>{{ $report['firmware'] }}</b></li>
        <li>Wi-Fi <b>{{ \App\ValueObject\Figure::withMinus($report['rssi']) }} dBm</b></li>
        <li>Uptime <b>{{ $report['uptime'] }}</b></li>
        <li>Buffered <b>{{ $report['buffered'] }}</b>, failed uploads <b>{{ $report['uploadFailures'] }}</b></li>
        <li><a class="link-ink" href="{{ route('charts', $this->sensorQuery()) }}#station">Station report {{ $report['at'] }}</a></li>
    @endif
    <li>Samples every <b>30 s</b>, uploads every <b>10 min</b></li>
</ul>
