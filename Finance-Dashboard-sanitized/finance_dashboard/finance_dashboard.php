<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
const ALLOWED_USER_IDS = [1];
const ALLOWED_ROLES    = ['admin'];
const APPROVER_ROLES   = ['admin'];
function sget($keys) {
    $ref = $_SESSION;
    foreach ((array)$keys as $k) {
        if (!is_array($ref) || !array_key_exists($k, $ref)) return null;
        $ref = $ref[$k];
    }
    return $ref;
}
$userId   = sget('user_id')
         ?? sget(['user','id'])
         ?? sget(['auth','id'])
         ?? sget('uid')
         ?? sget('userid')
         ?? sget('userId')
         ?? null;
$username = sget('username')
         ?? sget(['user','username'])
         ?? sget(['auth','username'])
         ?? sget('name')
         ?? '';
$role     = sget('role')
         ?? sget(['user','role'])
         ?? sget(['auth','role'])
         ?? sget('rank')
         ?? '';
$userId = is_numeric($userId) ? (int)$userId : null;
$role   = is_string($role) ? strtolower($role) : '';
$hasAccess =
    ($userId !== null && in_array($userId, ALLOWED_USER_IDS, true)) &&
    ($role === '' || in_array($role, ALLOWED_ROLES, true));
if (!$hasAccess) {
    $noAccess = dirname(__DIR__) . '/partials/no_access.inc.php';
    if (is_file($noAccess)) {
        include $noAccess;
        return;
    }
    return;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
require_once __DIR__ . '/../tools/finance_dashboard/config_finance.php';
$today = date('Y-m-d');
$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : null;
$currentPeriod = null;
if ($periodId) {
    $stmt = $pdo->prepare("SELECT * FROM periods WHERE id = :id");
    $stmt->execute([':id' => $periodId]);
    $currentPeriod = $stmt->fetch();
}
if (!$currentPeriod) {
    $stmt = $pdo->prepare("
        SELECT * FROM periods
        WHERE :today BETWEEN start_date AND end_date
        ORDER BY start_date
        LIMIT 1
    ");
    $stmt->execute([':today' => $today]);
    $currentPeriod = $stmt->fetch();
}
if (!$currentPeriod) {
    $stmt = $pdo->query("SELECT * FROM periods ORDER BY end_date DESC LIMIT 1");
    $currentPeriod = $stmt->fetch();
}
if ($currentPeriod) {
    $periodId    = (int)$currentPeriod['id'];
    $periodStart = $currentPeriod['start_date'];
    $periodEnd   = $currentPeriod['end_date'];
    $periodLabel = $currentPeriod['name'];
} else {
    $periodId    = null;
    $periodStart = date('Y-m-01');
    $periodEnd   = date('Y-m-t');
    $periodLabel = date('Y.m', strtotime($periodStart)) . '. havi időszak';
}
$periodsStmt = $pdo->query("
    SELECT id, name, start_date, end_date
    FROM periods
    ORDER BY start_date DESC
");
$periods = $periodsStmt->fetchAll();
$settingsStmt = $pdo->query("SELECT `key`, `value` FROM settings");
$settings = [];
foreach ($settingsStmt as $row) {
    $settings[$row['key']] = $row['value'];
}
$lowBalanceThreshold = isset($settings['low_balance_threshold'])
    ? (int)$settings['low_balance_threshold']
    : 0;
$totalsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN c.kind = 'income'   THEN t.amount           ELSE 0 END) AS income_total,
        SUM(CASE WHEN c.kind = 'expense'  THEN -t.amount          ELSE 0 END) AS expense_total,
        SUM(CASE WHEN c.kind = 'transfer' THEN t.amount           ELSE 0 END) AS transfer_total,
        SUM(t.amount) AS net_total
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.date BETWEEN :start AND :end
      AND t.status = 'booked'
");
$totalsStmt->execute([
    ':start' => $periodStart,
    ':end'   => $periodEnd,
]);
$totals = $totalsStmt->fetch() ?: [
    'income_total'  => 0,
    'expense_total' => 0,
    'transfer_total'=> 0,
    'net_total'     => 0,
];
$incomeTotal   = (int)($totals['income_total']   ?? 0);
$expenseTotal  = (int)($totals['expense_total']  ?? 0);
$netTotal      = (int)($totals['net_total']      ?? 0);
$transferTotal = (int)($totals['transfer_total'] ?? 0);
$budgetAmount       = $currentPeriod ? (int)$currentPeriod['budget_amount'] : 0;
$budgetRemaining    = ($budgetAmount > 0) ? ($budgetAmount - $expenseTotal) : null;
$budgetUsagePercent = ($budgetAmount > 0 && $expenseTotal > 0)
    ? min(100, round($expenseTotal / $budgetAmount * 100))
    : null;
$accountsStmt = $pdo->query("
    SELECT
        a.id,
        a.name,
        a.type,
        a.is_active,
        COALESCE(SUM(CASE WHEN t.status = 'booked' THEN t.amount ELSE 0 END), 0) AS balance
    FROM accounts a
    LEFT JOIN transactions t ON t.account_id = a.id
    GROUP BY a.id, a.name, a.type, a.is_active
    ORDER BY a.is_active DESC, a.name
");
$accounts = $accountsStmt->fetchAll();
$latestStmt = $pdo->prepare("
    SELECT
        t.date,
        a.name AS account_name,
        c.name AS category_name,
        p.name AS payee_name,
        t.amount,
        t.memo
    FROM transactions t
    JOIN accounts a  ON t.account_id  = a.id
    JOIN categories c ON t.category_id = c.id
    LEFT JOIN payees p ON t.payee_id   = p.id
    WHERE t.date BETWEEN :start AND :end
      AND t.status = 'booked'
    ORDER BY t.date DESC, t.id DESC
    LIMIT 10
");
$latestStmt->execute([
    ':start' => $periodStart,
    ':end'   => $periodEnd,
]);
$latestTransactions = $latestStmt->fetchAll();
$plannedStmt = $pdo->prepare("
    SELECT
        pt.date,
        a.name AS account_name,
        pt.amount,
        pt.memo,
        pt.status
    FROM planned_transactions pt
    JOIN accounts a ON pt.account_id = a.id
    WHERE pt.status = 'planned'
    ORDER BY pt.date ASC
    LIMIT 10
");
$plannedStmt->execute();
$plannedTransactions = $plannedStmt->fetchAll();
$expenseCatStmt = $pdo->prepare("
    SELECT c.name, -SUM(t.amount) AS total
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    WHERE c.kind = 'expense'
      AND t.date BETWEEN :start AND :end
      AND t.status = 'booked'
    GROUP BY c.id, c.name
    ORDER BY total DESC
");
$expenseCatStmt->execute([
    ':start' => $periodStart,
    ':end'   => $periodEnd,
]);
$expenseCatRows    = $expenseCatStmt->fetchAll();
$expenseCatLabels  = [];
$expenseCatData    = [];
foreach ($expenseCatRows as $row) {
    $expenseCatLabels[] = $row['name'];
    $expenseCatData[]   = (int)$row['total'];
}
$dailyStmt = $pdo->prepare("
    SELECT
        t.date,
        -SUM(CASE WHEN c.kind = 'expense' THEN t.amount ELSE 0 END) AS total_expense
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    WHERE t.date BETWEEN :start AND :end
      AND t.status = 'booked'
    GROUP BY t.date
    ORDER BY t.date
");
$dailyStmt->execute([
    ':start' => $periodStart,
    ':end'   => $periodEnd,
]);
$dailyRows   = $dailyStmt->fetchAll();
$dailyLabels = [];
$dailyData   = [];
foreach ($dailyRows as $row) {
    $dailyLabels[] = $row['date'];
    $dailyData[]   = (int)$row['total_expense'];
}
function format_amount_huf(int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' Ft';
}
function format_date_hu(string $date): string
{
    $d = new DateTime($date);
    return $d->format('Y.m.d.');
}
function get_account_logo_src(array $acc): ?string
{
    $name = isset($acc['name']) ? $acc['name'] : '';
    $type = isset($acc['type']) ? $acc['type'] : '';
    if (function_exists('mb_strtolower')) {
        $nameL = mb_strtolower($name, 'UTF-8');
    } else {
        $nameL = strtolower($name);
    }
    $typeL = strtolower((string)$type);
    if (strpos($nameL, 'raiffeisen') !== false || strpos($nameL, 'rai') !== false) {
        return 'tools/finance_dashboard/logos/raiffeisen.png';
    }
    if (strpos($nameL, 'otp') !== false) {
        return 'tools/finance_dashboard/logos/otp.png';
    }
    if (strpos($nameL, 'revolut') !== false || strpos($nameL, 'revoult') !== false) {
        return 'tools/finance_dashboard/logos/revolut.png';
    }
    if (
        $typeL === 'cash' ||
        strpos($nameL, 'készpénz') !== false ||
        strpos($nameL, 'keszpenz') !== false ||
        strpos($nameL, 'cash') !== false ||
        strpos($nameL, 'pénztárca') !== false
    ) {
        return 'tools/finance_dashboard/logos/cash.png';
    }
    return null;
}
?>
<!-- ===== Tartalom kezdete – AdminLTE ===== -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1><i class="fas fa-wallet mr-2"></i>Pénzügyi áttekintés</h1>
        <p class="text-muted mb-0">
          Időszak:
          <strong><?= htmlspecialchars($periodLabel) ?></strong>
          (<?= format_date_hu($periodStart) ?> – <?= format_date_hu($periodEnd) ?>)
        </p>
      </div>
      <div class="col-sm-6">
        <?php if (!empty($periods)): ?>
          <div class="form-group float-sm-right mb-0">
            <label for="finance-period-select" class="mr-2 mb-0">Időszak váltás:</label>
            <select id="finance-period-select" class="form-control form-control-sm">
              <?php foreach ($periods as $p): ?>
                <option value="<?= (int)$p['id'] ?>"
                  <?= $p['id'] == $periodId ? 'selected' : '' ?>>
                  <?= htmlspecialchars($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <!-- TOP KÁRTYÁK -->
    <div class="row">
      <div class="col-lg-3 col-6">
        <div class="small-box bg-success">
          <div class="inner">
            <h3><?= format_amount_huf($incomeTotal) ?></h3>
            <p>Bevételek az időszakban</p>
          </div>
          <div class="icon">
            <i class="fas fa-arrow-down"></i>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-6">
        <div class="small-box bg-danger">
          <div class="inner">
            <h3><?= format_amount_huf($expenseTotal) ?></h3>
            <p>Kiadások az időszakban</p>
          </div>
          <div class="icon">
            <i class="fas fa-arrow-up"></i>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-6">
        <div class="small-box <?= $netTotal >= 0 ? 'bg-primary' : 'bg-warning' ?>">
          <div class="inner">
            <h3><?= format_amount_huf($netTotal) ?></h3>
            <p>Időszak eredménye (nettó)</p>
          </div>
          <div class="icon">
            <i class="fas fa-balance-scale"></i>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-6">
        <div class="small-box bg-info">
          <div class="inner">
            <?php if ($budgetAmount > 0): ?>
              <h3><?= format_amount_huf($budgetAmount) ?></h3>
              <p>Költségvetés – felhasználva: <?= $budgetUsagePercent ?>%</p>
            <?php else: ?>
              <h3>–</h3>
              <p>Nincs beállított költségvetés</p>
            <?php endif; ?>
          </div>
          <div class="icon">
            <i class="fas fa-piggy-bank"></i>
          </div>
        </div>
      </div>
    </div>
    <!-- SZÁMLA EGYENLEGEK – small-box kártyák EGYMÁS MELLETT -->
    <div class="row">
      <div class="col-12">
        <h3 class="mb-3">Számla egyenlegek</h3>
      </div>
    </div>
    <?php if (empty($accounts)): ?>
      <div class="row">
        <div class="col-12">
          <p class="text-center text-muted">Nincs még rögzített számla.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="row">
        <?php foreach ($accounts as $acc): ?>
          <?php
            $balance  = (int)$acc['balance'];
            $isLow    = $lowBalanceThreshold > 0 && $balance < $lowBalanceThreshold;
            $logoSrc  = get_account_logo_src($acc);
            $typeText = isset($acc['type']) && $acc['type'] !== '' ? strtoupper($acc['type']) : '';

            if ($balance < 0)       $boxClass = 'bg-danger';
            elseif ($balance === 0) $boxClass = 'bg-secondary';
            elseif ($isLow)         $boxClass = 'bg-warning';
            else                    $boxClass = 'bg-success';
          ?>
          <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="small-box <?= $boxClass ?> account-small-box">
              <div class="inner">
                <h4 class="mb-1"><?= htmlspecialchars($acc['name']) ?></h4>
                <p class="mb-1">
                  <?php if ($typeText !== ''): ?>
                    <span class="text-uppercase"><?= htmlspecialchars($typeText) ?></span>
                  <?php endif; ?>
                  <?php if (!$acc['is_active']): ?>
                    <span class="badge badge-light text-dark ml-1">inaktív</span>
                  <?php endif; ?>
                </p>
                <h3 class="mb-0"><?= format_amount_huf($balance) ?></h3>
              </div>
              <div class="icon">
                <?php if ($logoSrc): ?>
                  <img src="<?= htmlspecialchars($logoSrc) ?>"
                       alt="<?= htmlspecialchars($acc['name']) ?> logo"
                       class="account-smallbox-logo">
                <?php else: ?>
                  <i class="fas fa-university"></i>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <!-- LEGUTÓBBI / TERVEZETT – külön sorban -->
    <div class="row">
      <div class="col-lg-6">
        <div class="card card-outline card-secondary">
          <div class="card-header">
            <h3 class="card-title">Legutóbbi tranzakciók (időszak)</h3>
          </div>
          <div class="card-body p-0">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th>Kategória</th>
                  <th class="text-right">Összeg</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($latestTransactions)): ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted">
                      Nincs tranzakció ebben az időszakban.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($latestTransactions as $t): ?>
                    <tr>
                      <td><?= format_date_hu($t['date']) ?></td>
                      <td>
                        <?= htmlspecialchars($t['category_name']) ?>
                        <?php if (!empty($t['memo'])): ?>
                          <br><small class="text-muted"><?= htmlspecialchars($t['memo']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td class="text-right">
                        <span class="<?= $t['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                          <?= format_amount_huf((int)$t['amount']) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card card-outline card-warning">
          <div class="card-header">
            <h3 class="card-title">Közelgő tervezett tételek</h3>
          </div>
          <div class="card-body p-0">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th>Megjegyzés</th>
                  <th class="text-right">Összeg</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($plannedTransactions)): ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted">
                      Nincs tervezett tétel.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($plannedTransactions as $pt): ?>
                    <?php $isOverdue = $pt['date'] < $today; ?>
                    <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                      <td>
                        <?= format_date_hu($pt['date']) ?>
                        <?php if ($isOverdue): ?>
                          <br><span class="badge badge-danger">Lejárt</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?= htmlspecialchars($pt['memo'] ?: '-') ?>
                        <br><small class="text-muted">
                          <?= htmlspecialchars($pt['account_name']) ?>
                        </small>
                      </td>
                      <td class="text-right">
                        <span class="<?= $pt['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                          <?= format_amount_huf((int)$pt['amount']) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <!-- DIAGRAMOK -->
    <div class="row">
      <div class="col-lg-6">
        <div class="card card-outline card-info">
          <div class="card-header">
            <h3 class="card-title">Kiadások kategóriánként</h3>
          </div>
          <div class="card-body">
            <div class="chart-container" style="position: relative; min-height: 280px;">
              <canvas id="finance-expense-by-category"></canvas>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card card-outline card-success">
          <div class="card-header">
            <h3 class="card-title">Napi kiadások (Ft)</h3>
          </div>
          <div class="card-body">
            <div class="chart-container" style="position: relative; min-height: 280px;">
              <canvas id="finance-daily-expenses"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
	
  </div><!-- /.container-fluid -->
</section>

<style>
  .account-small-box {
    position: relative;
  }
  .account-small-box .inner h4 {
    font-size: 1.1rem;
    font-weight: 600;
  }
  .account-small-box .inner h3 {
    font-size: 1.4rem;
    font-weight: 700;
  }
  .account-small-box .icon {
    position: absolute;
    top: 50%;
    right: 15px;
    left: auto;
    transform: translateY(-50%);
    z-index: 0;
    text-align: center;
  }
  .account-smallbox-logo {
    width: 70px;
    height: 70px;
    display: block;
    object-fit: contain;
    opacity: .9;
  }
</style>
<!-- ===== Lokális JS – Chart.js CDN-ről, Ajax-szal kompatibilisen ============ -->
<script>
(function () {
  var periodSelect = document.getElementById('finance-period-select');
  if (periodSelect) {
    periodSelect.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('period_id', this.value);
      window.location.href = url.toString();
    });
  }
  var categoryLabels = <?= json_encode($expenseCatLabels, JSON_UNESCAPED_UNICODE) ?>;
  var categoryData   = <?= json_encode($expenseCatData, JSON_NUMERIC_CHECK) ?>;
  var dailyLabels    = <?= json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) ?>;
  var dailyData      = <?= json_encode($dailyData, JSON_NUMERIC_CHECK) ?>;
  
  function initFinanceDashboardCharts() {
    if (typeof Chart === 'undefined') return;
    var catCanvas = document.getElementById('finance-expense-by-category');
    if (catCanvas && categoryLabels.length) {
      var ctxCat = catCanvas.getContext('2d');
      new Chart(ctxCat, {
        type: 'doughnut',
        data: { labels: categoryLabels, datasets: [{ data: categoryData }] },
        options: {
          maintainAspectRatio: false,
          legend: { display: true, position: 'bottom' },
          cutoutPercentage: 60
        }
      });
    }
    var dailyCanvas = document.getElementById('finance-daily-expenses');
    if (dailyCanvas && dailyLabels.length) {
      var ctxDaily = dailyCanvas.getContext('2d');
      new Chart(ctxDaily, {
        type: 'bar',
        data: {
          labels: dailyLabels,
          datasets: [{ label: 'Napi kiadás', data: dailyData }]
        },
        options: {
          maintainAspectRatio: false,
          scales: { yAxes: [{ ticks: { beginAtZero: true } }] },
          legend: { display: false }
        }
      });
    }
  }
  function loadChartJsAndInit() {
    if (typeof Chart !== 'undefined') {
      initFinanceDashboardCharts();
      return;
    }
    var existing = document.getElementById('finance-chartjs-cdn');
    if (!existing) {
      var s = document.createElement('script');
      s.id = 'finance-chartjs-cdn';
      s.src = 'https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js';
      s.onload = initFinanceDashboardCharts;
      document.head.appendChild(s);
    } else {
      var interval = setInterval(function () {
        if (typeof Chart !== 'undefined') {
          clearInterval(interval);
          initFinanceDashboardCharts();
        }
      }, 100);
    }
  }
  loadChartJsAndInit();
})();
</script>