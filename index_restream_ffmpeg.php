<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Stream Dashboard v1.13.SHORT-HLS-CLEAN</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
      background-color: #fff;
      color: #222;
      transition: background-color 0.3s, color 0.3s;
    }
    body.dark {
      background-color: #111;
      color: #eee;
    }
    .toggle-theme {
      cursor: pointer;
      font-weight: bold;
      margin-bottom: 20px;
    }
    .stream-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
      gap: 20px;
    }
    .stream-box {
      background: #f0f0f0;
      padding: 15px;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    body.dark .stream-box {
      background-color: #222;
    }
    .stream-box button {
      margin: 5px 4px 0 0;
      padding: 8px 14px;
      border: none;
      border-radius: 6px;
      font-weight: bold;
      cursor: pointer;
    }
    .btn-red { background: #e74c3c; color: white; }
    .btn-green { background: #2ecc71; color: white; }
    .btn-dark { background: #2c3e50; color: white; }
    .copyable {
      color: #3498db;
      cursor: pointer;
      text-decoration: underline;
    }
    form.inline { display: inline; }
    .status { font-weight: bold; }
  </style>
</head>
<body>

<div class="toggle-theme" onclick="toggleTheme()">🌞 Light / 🌙 Dark Mode</div>

<h2>Start New Stream</h2>
<form method="post">
  Channel Name: <input name="channel" required>
  Input URL: <input name="source" required>
  <button type="submit" name="start">Start</button>
</form>

<hr>
<h3>Streams</h3>
<div class="stream-grid">
<?php
$dataFile = 'streams.json';
if (!file_exists($dataFile)) file_put_contents($dataFile, '{}');
$streams = json_decode(file_get_contents($dataFile), true);

function getPidFile($channel) {
  return "pids/{$channel}.pid";
}

function isRunning($channel) {
  $pidFile = getPidFile($channel);
  if (!file_exists($pidFile)) return false;
  $pid = trim(file_get_contents($pidFile));
  return posix_kill((int)$pid, 0);
}

// Start / Restart logic
if (isset($_POST['start'])) {
  if (isset($_POST['source'])) {
    $ch = $_POST['channel'];
    $src = $_POST['source'];
    $streams[$ch] = ['url' => $src, 'time' => time()];
  } else {
    $ch = $_POST['start'];
    $src = $streams[$ch]['url'];
    $streams[$ch]['time'] = time();
    $pidFile = getPidFile($ch);
    if (file_exists($pidFile)) {
      $pid = trim(file_get_contents($pidFile));
      exec("kill $pid");
      unlink($pidFile);
    }
  }

  $out = "rtmp://localhost/live/$ch";
  $cmd = "ffmpeg -re -i \"$src\" -vcodec libx264 -acodec aac -f flv \"$out\" > /dev/null 2>&1 & echo $!";
  $pid = shell_exec($cmd);
  file_put_contents(getPidFile($ch), $pid);
  file_put_contents($dataFile, json_encode($streams));
  header("Refresh:0");
}

// Stop stream
if (isset($_POST['stop'])) {
  $ch = $_POST['stop'];
  $pidFile = getPidFile($ch);
  if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    exec("kill $pid");
    unlink($pidFile);
  }
}

// Delete stream
if (isset($_POST['delete'])) {
  $ch = $_POST['delete'];
  $pidFile = getPidFile($ch);
  if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    exec("kill $pid");
    unlink($pidFile);
  }
  unset($streams[$ch]);
  file_put_contents($dataFile, json_encode($streams));
}

foreach ($streams as $ch => $info) {
  $running = isRunning($ch);
  $hls = "http://" . $_SERVER['SERVER_ADDR'] . ":8088/hls/{$ch}.m3u8";
  $displayUrl = "hls/{$ch}.m3u8";
  echo "<div class='stream-box'>";
  echo "<strong>Channel:</strong> $ch<br>";
  echo "<strong>HLS URL:</strong> <span class='copyable' onclick=\"copyToClipboard('$hls')\" title='$hls'>$displayUrl</span><br>";
  echo "<strong>Status:</strong> <span class='status'>" . ($running ? "🟢 Running" : "🔴 Stopped") . "</span><br>";
  echo "<strong>Duration:</strong> " . ($running ? floor((time() - $info['time']) / 60) . " min" : "N/A") . "<br>";

  if ($running) {
    echo "<form method='post' class='inline' onsubmit=\"return confirm('Are you sure you want to stop this stream?');\">";
    echo "<button class='btn-red' name='stop' value='$ch'>Stop</button>";
    echo "</form>";
  } else {
    echo "<form method='post' class='inline' onsubmit=\"return confirm('Are you sure you want to restart this stream?');\">";
    echo "<button class='btn-green' name='start' value='$ch'>Restart</button>";
    echo "</form>";
  }

  echo "<form method='post' class='inline' onsubmit=\"return confirm('Delete this stream? It will also stop if still running.');\">";
  echo "<button class='btn-dark' name='delete' value='$ch'>Delete</button>";
  echo "</form>";
  echo "</div>";
}
?>
</div>

<?php
$total = count($streams);
$active = 0;
foreach ($streams as $ch => $info) {
  if (isRunning($ch)) $active++;
}
echo "<div style='margin-top:20px; font-weight:bold;'>";
echo "📦 Total Streams: $total &nbsp;&nbsp;&nbsp; 🟢 Active Streams: $active";
echo "</div>";
?>

<script>
function copyToClipboard(text) {
  const tempInput = document.createElement("input");
  tempInput.style.position = "absolute";
  tempInput.style.left = "-1000px";
  tempInput.value = text;
  document.body.appendChild(tempInput);
  tempInput.select();
  document.execCommand("copy");
  document.body.removeChild(tempInput);
  alert("✅ HLS URL copied:\n" + text);
}

function toggleTheme() {
  document.body.classList.toggle('dark');
  localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
}
window.onload = () => {
  if (localStorage.getItem("theme") === "dark") {
    document.body.classList.add("dark");
  }
};
</script>

</body>
</html>
