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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Accident Alerts</title>
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
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['created_at']) ?></td>
              <td><span class="hospital-name"><?= htmlspecialchars($row['hospital']) ?></span></td>
              <td>
                <a class="btn btn-maps" href="https://maps.google.com/?q=<?= (float)$row['lat'] ?>,<?= (float)$row['lng'] ?>" target="_blank" rel="noopener">📍 View on Map</a>
              </td>
              <td><span class="coords"><?= htmlspecialchars($row['lat']) ?>, <?= htmlspecialchars($row['lng']) ?></span></td>
            </tr>
            <?php endwhile; ?>
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

</body>
</html>
