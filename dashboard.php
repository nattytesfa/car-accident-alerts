<?php
require 'auth_check.php';
require 'db.php';

$user = $conn->prepare("SELECT username FROM users WHERE id = ?");
$user->bind_param("i", $_SESSION['user_id']);
$user->execute();
$user->bind_result($username);
$user->fetch();
$user->close();

$result = $conn->query("SELECT * FROM alerts ORDER BY created_at DESC");
$total = $result->num_rows;

$todayStart = date('Y-m-d 00:00:00');
$today = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE created_at >= '$todayStart'")->fetch_assoc()['c'];

$recent = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetch_assoc()['c'];

$hospitals = $conn->query("SELECT COUNT(DISTINCT hospital) AS c FROM alerts")->fetch_assoc()['c'];

/* Latest alert for the banner */
$result->data_seek(0);
$latest = $result->fetch_assoc();
$result->data_seek(0);

/* Per-hospital breakdown */
$breakdown = [];
$bd = $conn->query("SELECT hospital, COUNT(*) AS c FROM alerts WHERE hospital IS NOT NULL AND hospital != '' GROUP BY hospital ORDER BY c DESC");
while ($b = $bd->fetch_assoc()) {
    $breakdown[] = $b;
}

/* Last 24h activity by hour (server-side buckets, CSS chart) */
$hours = array_fill(0, 24, 0);
$hc = $conn->query("SELECT HOUR(created_at) AS h, COUNT(*) AS c FROM alerts WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY HOUR(created_at)");
while ($r = $hc->fetch_assoc()) {
    $hours[(int)$r['h']] = (int)$r['c'];
}
$maxHour = max($hours);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Command Center - Accident Alerts</title>
  <link rel="stylesheet" href="styles.css">
  <meta http-equiv="refresh" content="10">
</head>
<body>

  <nav class="navbar">
    <div class="brand">
      <span class="brand-badge">🛰️</span>
      Accident Alerts
    </div>
    <div class="nav-links">
      <a href="dashboard.php" class="nav-link active">Dashboard</a>
      <span class="user-chip">👤 <?= htmlspecialchars($username) ?></span>
      <a href="logout.php" class="btn-logout">Log out</a>
    </div>
  </nav>

  <main class="container">

    <div class="page-head">
      <div>
        <h1>Command Center</h1>
        <p style="color:var(--muted);font-size:0.95rem;margin-top:4px;">Real-time monitoring of incoming accident alerts</p>
      </div>
      <span class="badge-live"><span class="dot"></span> Live</span>
    </div>

    <?php if ($latest): ?>
    <section class="alert-banner">
      <div class="alert-banner-icon">🚨</div>
      <div class="alert-banner-body">
        <div class="alert-banner-title">Latest Accident Reported</div>
        <div class="alert-banner-info">
          <span>🏥 <strong><?= htmlspecialchars($latest['hospital']) ?></strong></span>
          <span>⏰ <?= htmlspecialchars($latest['created_at']) ?></span>
          <span>📍 <a class="btn btn-maps" href="https://maps.google.com/?q=<?= (float)$latest['lat'] ?>,<?= (float)$latest['lng'] ?>" target="_blank" rel="noopener">Open in Maps</a></span>
        </div>
      </div>
      <span class="badge-live badge-red"><span class="dot dot-red"></span> Newest</span>
    </section>
    <?php endif; ?>

    <section class="stats">
      <div class="stat-card">
        <div class="stat-label">Total Alerts</div>
        <div class="stat-value pri"><?= (int)$total ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Today</div>
        <div class="stat-value sec"><?= (int)$today ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Last 24h</div>
        <div class="stat-value grn"><?= (int)$recent ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Hospitals</div>
        <div class="stat-value amb"><?= (int)$hospitals ?></div>
      </div>
    </section>

    <section class="charts">
      <div class="panel">
        <div class="panel-head">
          <h2>Activity — Last 24h</h2>
          <span class="panel-note">alerts per hour</span>
        </div>
        <div class="chart" role="img" aria-label="Alerts per hour over the last 24 hours">
          <?php for ($i = 0; $i < 24; $i++): ?>
            <?php $v = $hours[$i]; $pct = $maxHour > 0 ? (int)round($v / $maxHour * 100) : 0; ?>
            <div class="chart-col" title="<?= $i ?>:00 - <?= $v ?> alert(s)">
              <span class="chart-val"><?= $v ?></span>
              <span class="chart-bar" style="height:<?= max($pct, $v > 0 ? 4 : 1) ?>%;"></span>
              <span class="chart-label"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?>h</span>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>Alerts by Hospital</h2>
          <span class="panel-note"><?= (int)count($breakdown) ?> active</span>
        </div>
        <?php if ($breakdown): ?>
        <div class="breakdown">
          <?php foreach ($breakdown as $b): ?>
          <div class="break-row">
            <div class="break-label"><?= htmlspecialchars($b['hospital']) ?></div>
            <div class="break-track"><div class="break-fill" style="width:<?= $total > 0 ? round($b['c'] / $total * 100) : 0 ?>%;"></div></div>
            <div class="break-val"><?= (int)$b['c'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-small">No hospital activity yet.</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="table-card">
      <div class="card-head">
        <h2>Incoming Alerts</h2>
        <span class="alert-count"><?= (int)$total ?></span>
      </div>
      <div class="table-scroll">
        <?php if ($total > 0): ?>
        <table>
          <thead>
            <tr>
              <th>Time</th>
              <th>Hospital</th>
              <th>Location</th>
              <th>Coordinates</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 0; while ($row = $result->fetch_assoc()): ?>
            <tr class="<?= $i === 0 ? 'row-latest' : '' ?>">
              <td><?= htmlspecialchars($row['created_at']) ?></td>
              <td>
                <span class="hospital-name"><?= htmlspecialchars($row['hospital']) ?></span>
                <?php if ($i === 0): ?><span class="tag-new">NEW</span><?php endif; ?>
              </td>
              <td>
                <a class="btn btn-maps" href="https://maps.google.com/?q=<?= (float)$row['lat'] ?>,<?= (float)$row['lng'] ?>" target="_blank" rel="noopener">📍 View on Map</a>
              </td>
              <td><span class="coords"><?= htmlspecialchars($row['lat']) ?>, <?= htmlspecialchars($row['lng']) ?></span></td>
            </tr>
            <?php $i++; endwhile; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <p>No incoming alerts right now.</p>
          <p style="font-size:0.85rem;">The dashboard refreshes automatically every 10 seconds.</p>
        </div>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <footer class="footer">
    Accident Alerts Command Center · auto-refreshes every 10 seconds
  </footer>

</body>
</html>