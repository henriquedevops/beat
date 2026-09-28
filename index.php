<?php
define('BEAT', true);
require __DIR__ . '/lib/bootstrap.php';
$user = current_user();
header('Cache-Control: no-store');
$v = filemtime(__DIR__ . '/app.js') . filemtime(__DIR__ . '/app.css');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Beat</title>
<meta name="theme-color" content="#172440">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="app.css?v=<?= $v ?>">
</head>
<body>
<?php if (!$user): ?>
<main class="login">
  <form id="login-form" class="login-card">
    <div class="lightbar" aria-hidden="true"><span></span><span></span></div>
    <h1 class="wordmark">Beat</h1>
    <p class="muted">Sign in to track your time.</p>
    <label>Username<input name="username" autocomplete="username" required autofocus></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <p class="error" id="login-error" role="alert"></p>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
</main>
<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target, err = document.getElementById('login-error');
  err.textContent = '';
  f.querySelector('button').disabled = true;
  try {
    const res = await fetch('api.php', {
      method: 'POST', headers: {'Content-Type': 'application/json', 'X-Beat': '1'},
      body: JSON.stringify({action: 'login', username: f.username.value, password: f.password.value}),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error);
    location.reload();
  } catch (x) {
    err.textContent = x.message || 'Could not reach the server. Check your connection.';
    f.querySelector('button').disabled = false;
  }
});
</script>
<?php else: ?>
<header class="topbar">
  <div class="topbar-inner">
    <span class="wordmark">Beat</span>
    <nav class="tabs" aria-label="Sections">
      <button data-tab="log" class="tab" aria-current="page">Log</button>
      <button data-tab="reports" class="tab">Reports</button>
      <button data-tab="projects" class="tab">Projects</button>
    </nav>
    <button class="account" id="account-btn" aria-label="Account"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></button>
  </div>
</header>

<main class="wrap">
  <section id="tab-log" class="tab-panel">
    <form id="start-form" class="start">
      <input name="description" placeholder="What are you working on?" aria-label="Description" autocomplete="off">
      <select name="project_id" aria-label="Project"></select>
      <button class="btn go" type="submit">Start</button>
    </form>
    <div id="running"></div>
    <div class="log-head">
      <h2>Entries</h2>
      <button class="btn ghost" id="add-entry">Add entry manually</button>
    </div>
    <div id="log"></div>
    <button class="btn ghost more" id="load-more">Show earlier entries</button>
  </section>

  <section id="tab-reports" class="tab-panel" hidden>
    <form id="report-form" class="report-controls">
      <label>Period
        <select name="preset">
          <option value="week">This week</option>
          <option value="lastweek">Last week</option>
          <option value="month" selected>This month</option>
          <option value="lastmonth">Last month</option>
          <option value="year">This year</option>
          <option value="custom">Custom range</option>
        </select>
      </label>
      <label>From<input type="date" name="from"></label>
      <label>To<input type="date" name="to"></label>
      <label>Project<select name="project"></select></label>
      <button class="btn ghost" type="button" id="export-csv">Download CSV</button>
    </form>
    <div id="report"></div>
  </section>

  <section id="tab-projects" class="tab-panel" hidden>
    <form id="project-form" class="start">
      <input name="name" placeholder="New project name" aria-label="Project name" required autocomplete="off">
      <input type="color" name="color" value="#2f5fd0" aria-label="Project colour">
      <button class="btn primary" type="submit">Add project</button>
    </form>
    <div id="projects"></div>
  </section>
</main>

<dialog id="entry-dialog">
  <form method="dialog" id="entry-form">
    <h2 id="entry-title">Edit entry</h2>
    <label>Description<input name="description" autocomplete="off"></label>
    <label>Project<select name="project_id"></select></label>
    <div class="pair">
      <label>Start<input type="datetime-local" name="start_time" step="1" required></label>
      <label>End<input type="datetime-local" name="end_time" step="1"></label>
    </div>
    <p class="hint">Leave End empty to keep the timer running.</p>
    <label>Notes<textarea name="notes" rows="3"></textarea></label>
    <p class="error" role="alert"></p>
    <div class="dialog-actions">
      <button type="button" class="btn danger-ghost" id="entry-delete">Delete</button>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" value="cancel" data-close>Cancel</button>
      <button type="submit" class="btn primary">Save entry</button>
    </div>
  </form>
</dialog>

<dialog id="account-dialog">
  <form method="dialog" id="password-form">
    <h2>Signed in as <?= htmlspecialchars($user['username']) ?></h2>
    <h3>Change password</h3>
    <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
    <label>New password<input type="password" name="new" autocomplete="new-password" minlength="8" required></label>
    <p class="error" role="alert"></p>
    <div class="dialog-actions">
      <button type="button" class="btn danger-ghost" id="logout">Sign out</button>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" data-close>Close</button>
      <button type="submit" class="btn primary">Change password</button>
    </div>
  </form>
</dialog>

<div id="toast" role="status" aria-live="polite"></div>
<script src="app.js?v=<?= $v ?>"></script>
<?php endif; ?>
</body>
</html>
