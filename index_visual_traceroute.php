<?php
// Version 1.4 - Animated hops + colored latency summary below raw traceroute output
if (isset($_GET['api']) && $_GET['api'] === 'trace') {
    header('Content-Type: application/json');
    $destination = escapeshellarg($_GET['destination']);
    $raw = shell_exec("traceroute -n $destination 2>&1");

    if (!$raw) {
        echo json_encode([
            "summary" => null,
            "raw" => "Traceroute failed.",
            "hops" => []
        ]);
        exit;
    }

    $lines = explode("\n", $raw);
    array_shift($lines);

    $hops = [];
    $latencies = [];
    $prevLatency = null;

    foreach ($lines as $line) {
        if (preg_match('/^\s*(\d+)\s+([\d\.]+|\*)\s+(.*)/', $line, $matches)) {
            $ip = $matches[2];
            $latency = null;
            if (preg_match_all('/(\d+\.\d+)\s+ms/', $matches[3], $latencyMatches)) {
                $latency = array_sum($latencyMatches[1]) / count($latencyMatches[1]);
                $latencies[] = $latency;
            }

            $asn = null;
            $peeringdb = null;
            if ($ip !== '*') {
                $heHtml = @file_get_contents("https://bgp.he.net/ip/$ip");
                if ($heHtml && preg_match('/AS(\d+)/', $heHtml, $asnMatch)) {
                    $asn = $asnMatch[1];
                    $peeringdb = "https://www.peeringdb.com/asn/" . $asn;
                }
            }

            $hop = [
                'ip' => $ip === '*' ? 'Filtered' : $ip,
                'latency' => $latency ? round($latency, 2) : null,
                'latencyDiff' => ($latency !== null && $prevLatency !== null) ? round($latency - $prevLatency, 2) : 0,
                'asn' => $asn,
                'peeringdb' => $peeringdb
            ];
            $prevLatency = $latency !== null ? $latency : $prevLatency;

            $hops[] = $hop;
        }
    }

    $summary = $latencies ? [
        "min" => round(min($latencies), 2),
        "avg" => round(array_sum($latencies) / count($latencies), 2),
        "max" => round(max($latencies), 2)
    ] : null;

    echo json_encode([
        "summary" => $summary,
        "raw" => $raw,
        "hops" => $hops
    ]);
    exit;
}
?>

<!-- Updated v1.4: Animated hop icons + colored latency summary -->

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Traceroute Visual GUI v1.4</title>
  <style>
    body { font-family: Arial, sans-serif; }
    #map { position: relative; height: 500px; border: 1px solid #ccc; background: #f9f9f9; margin-top: 20px; }
    .hop {
      position: absolute;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      color: white;
      text-align: center;
      line-height: 30px;
      cursor: pointer;
      z-index: 10;
      opacity: 0;
      animation: fadeIn 0.6s forwards;
    }
    .hop.filtered { background-color: black; }
    .hop-label { position: absolute; font-size: 12px; text-align: center; transform: translateX(-50%); }
    .tooltip { position: absolute; background: #fff; border: 1px solid #ccc; padding: 10px; display: none; z-index: 1000; }
    #progressBar { height: 10px; width: 0; background-color: green; transition: width 0.5s ease; }
    #progressContainer { width: 100%; background: #eee; margin-top: 10px; position: relative; }
    #loadingText { position: absolute; left: 50%; top: -20px; transform: translateX(-50%); font-size: 14px; color: #555; display: none; }
    .legend { margin-top: 10px; font-size: 14px; }
    .summary { margin-top: 10px; font-size: 14px; }
    .summary span.low { color: green; }
    .summary span.medium { color: orange; }
    .summary span.high { color: red; }
    #rawOutput { margin-top: 10px; white-space: pre-wrap; background: #f0f0f0; padding: 10px; border: 1px solid #ccc; }
    .line { position: absolute; height: 2px; background-color: gray; z-index: 1; }
    .line.dashed { border-top: 2px dashed black; background: none; height: 0; }
    .latency-label { position: absolute; font-size: 12px; background: #fff; padding: 2px 4px; border: 1px solid #ccc; border-radius: 3px; transform: translate(-50%, -50%); z-index: 5; }
    @keyframes fadeIn {
      to { opacity: 1; }
    }
  </style>
</head>
<body>
  <h2>Traceroute Visual GUI (v1.4)</h2>
  <form id="tracerouteForm">
    <input type="text" name="destination" id="destination" placeholder="Enter IP or domain" required>
    <button type="submit">Trace</button>
  </form>
  <div id="progressContainer">
    <div id="loadingText">Traceroute running...</div>
    <div id="progressBar"></div>
  </div>
  <div class="legend">
    <strong>Latency Legend:</strong>
    <span style="color:green">Low (&lt;50ms)</span> |
    <span style="color:orange">Medium (50-150ms)</span> |
    <span style="color:red">High (&gt;150ms)</span>
  </div>
  <div id="map"></div>
  <div id="rawOutput"></div>
  <div class="summary" id="summary"></div>
  <div id="tooltip" class="tooltip"></div>

  <script>
    function getLatencyClass(latency) {
      if (latency < 50) return 'low';
      if (latency <= 150) return 'medium';
      return 'high';
    }

    const form = document.getElementById('tracerouteForm');
    const map = document.getElementById('map');
    const tooltip = document.getElementById('tooltip');
    const progressBar = document.getElementById('progressBar');
    const loadingText = document.getElementById('loadingText');
    const summary = document.getElementById('summary');
    const rawOutput = document.getElementById('rawOutput');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      map.innerHTML = '';
      tooltip.style.display = 'none';
      summary.innerHTML = '';
      rawOutput.innerHTML = '';
      progressBar.style.width = '0';
      loadingText.style.display = 'block';

      const destination = document.getElementById('destination').value;
      progressBar.style.width = '50%';

      const response = await fetch(`?api=trace&destination=${encodeURIComponent(destination)}`);
      const result = await response.json();
      const hops = result.hops;

      progressBar.style.width = '100%';
      loadingText.style.display = 'none';

      const spacing = map.clientWidth / (hops.length + 1);
      const topOffset = map.clientHeight / 2;
      let prevX = null;
      let prevY = null;

      hops.forEach((hop, i) => {
        const div = document.createElement('div');
        div.className = 'hop';
        if (hop.ip === 'Filtered') {
          div.classList.add('filtered');
        } else if (hop.latency < 50) {
          div.style.backgroundColor = 'green';
        } else if (hop.latency < 150) {
          div.style.backgroundColor = 'orange';
        } else {
          div.style.backgroundColor = 'red';
        }

        const x = spacing * (i + 1) - 15;
        const y = topOffset - 15;
        div.style.left = `${x}px`;
        div.style.top = `${y}px`;
        div.style.animationDelay = `${i * 0.2}s`;
        div.textContent = i + 1;

        const label = document.createElement('div');
        label.className = 'hop-label';
        label.style.left = `${x + 15}px`;
        label.style.top = `${y + 35}px`;
        label.innerHTML = `${hop.ip}<br>${hop.latency !== null ? hop.latency + ' ms' : ''}`;

        div.addEventListener('click', (event) => {
          event.stopPropagation();
          tooltip.innerHTML = `
            <strong>Hop ${i + 1}</strong><br>
            ${i === 0 ? 'Gateway<br>' : ''}
            IP: ${hop.ip}<br>
            ASN: ${hop.asn ? 'AS' + hop.asn : 'N/A'}<br>
            ${hop.peeringdb ? `<a href='${hop.peeringdb}' target='_blank'>PeeringDB</a>` : 'PeeringDB: N/A'}
          `;
          tooltip.style.left = `${div.offsetLeft + 40}px`;
          tooltip.style.top = `${div.offsetTop - 10}px`;
          tooltip.style.display = 'block';
        });

        map.appendChild(div);
        map.appendChild(label);

        if (prevX !== null) {
          const line = document.createElement('div');
          line.className = hop.ip === 'Filtered' ? 'line dashed' : 'line';
          const length = Math.hypot(x - prevX, y - prevY);
          const angle = Math.atan2(y - prevY, x - prevX) * 180 / Math.PI;
          line.style.width = length + 'px';
          line.style.left = prevX + 15 + 'px';
          line.style.top = prevY + 15 + 'px';
          line.style.transform = `rotate(${angle}deg)`;
          map.appendChild(line);

          const midX = (prevX + x) / 2;
          const midY = (prevY + y) / 2;
          const latLabel = document.createElement('div');
          latLabel.className = 'latency-label';
          latLabel.style.left = `${midX}px`;
          latLabel.style.top = `${midY}px`;
          latLabel.textContent = hop.ip === 'Filtered' ? 'Filtered' : `${hop.latencyDiff.toFixed(1)} ms`;
          map.appendChild(latLabel);
        }

        prevX = x;
        prevY = y;
      });

      if (result.raw) {
        rawOutput.textContent = result.raw;
      }

      if (result.summary) {
        summary.innerHTML = `<strong>Traceroute Summary:</strong>
          Min: <span class='${getLatencyClass(result.summary.min)}'>${result.summary.min} ms</span>,
          Avg: <span class='${getLatencyClass(result.summary.avg)}'>${result.summary.avg} ms</span>,
          Max: <span class='${getLatencyClass(result.summary.max)}'>${result.summary.max} ms</span>`;
      }
    });

    window.addEventListener('click', () => {
      tooltip.style.display = 'none';
    });
  </script>
</body>
</html>
