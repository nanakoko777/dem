<?php
require __DIR__ . '/bootstrap.php';
require_admin();

$statuses   = $CONFIG['statuses'];
$categories = $CONFIG['categories'];

// --- Смена статуса заявки ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $reqId  = (int) ($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, $statuses, true)) {
        $st = pdo()->prepare("UPDATE requests SET status = ? WHERE id = ?");
        $st->execute([$status, $reqId]);
        flash('success', "Статус заявки №{$reqId} изменён на «{$status}».");
    } else {
        flash('error', 'Недопустимый статус.');
    }
    // сохраняем фильтры в редиректе
    redirect('admin.php?' . http_build_query($_GET));
}

// --- Фильтры / сортировка / поиск ---
$fStatus = $_GET['status']   ?? '';
$fCat    = $_GET['category'] ?? '';
$q       = trim($_GET['q'] ?? '');
$sort    = $_GET['sort'] ?? 'new';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 6;

$where = [];
$args  = [];
if (in_array($fStatus, $statuses, true)) { $where[] = 'q.status = ?';   $args[] = $fStatus; }
if (in_array($fCat, $categories, true))  { $where[] = 'i.category = ?'; $args[] = $fCat; }
if ($q !== '') {
    $where[] = '(u.fio LIKE ? OR u.login LIKE ? OR u.email LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderMap = [
    'new'       => 'q.id DESC',
    'old'       => 'q.id ASC',
    'date_asc'  => 'q.event_date ASC',
    'date_desc' => 'q.event_date DESC',
    'status'    => 'q.status ASC, q.id DESC',
];
$orderSql = $orderMap[$sort] ?? $orderMap['new'];

// Всего
$cntStmt = pdo()->prepare("SELECT COUNT(*) FROM requests q
    JOIN users u ON u.id = q.user_id
    JOIN items i ON i.id = q.item_id $whereSql");
$cntStmt->execute($args);
$total = (int) $cntStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$listStmt = pdo()->prepare(
    "SELECT q.*, i.title, i.category, u.fio, u.login, u.phone, u.email
       FROM requests q
       JOIN users u ON u.id = q.user_id
       JOIN items i ON i.id = q.item_id
       $whereSql
       ORDER BY $orderSql
       LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($args);
$rows = $listStmt->fetchAll();

// Сводка по статусам
$summary = [];
foreach ($statuses as $s) $summary[$s] = 0;
foreach (pdo()->query("SELECT status, COUNT(*) c FROM requests GROUP BY status") as $r) {
    if (isset($summary[$r['status']])) $summary[$r['status']] = (int) $r['c'];
}
$summary['Всего'] = array_sum($summary);

// Хелпер для ссылок с сохранением фильтров
function adminLink(array $over): string {
    return 'admin.php?' . http_build_query(array_merge($_GET, $over));
}

$PAGE_TITLE = 'Панель администратора';
require __DIR__ . '/includes/header.php';
?>
<section class="section admin">
  <div class="container">
    <div class="admin-head reveal">
      <h1>Панель администратора</h1>
      <p class="muted-text">Управление заявками портала <?= e($CONFIG['brand']) ?></p>
    </div>

    <!-- Сводка -->
    <div class="stat-cards reveal">
      <div class="stat-card total">
        <span class="stat-num"><?= $summary['Всего'] ?></span>
        <span class="stat-label">Всего заявок</span>
      </div>
      <?php foreach ($statuses as $i => $s): ?>
        <div class="stat-card st-<?= $i ?>">
          <span class="stat-num"><?= $summary[$s] ?></span>
          <span class="stat-label"><?= e($s) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Фильтры -->
    <!-- Двухколоночная сетка: фильтры | таблица -->
<div class="admin-two-columns reveal">
    <!-- Левая колонка: панель фильтров -->
    <aside class="admin-filters-panel">
        <form class="admin-filters" method="get">
            <div class="filter-group">
                <label>Поиск</label>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="ФИО, логин, e-mail">
            </div>
            <div class="filter-group">
                <label>Статус</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="">Все статусы</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Категория</label>
                <select name="category" onchange="this.form.submit()">
                    <option value="">Все категории</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Сортировка</label>
                <select name="sort" onchange="this.form.submit()">
                    <option value="new"       <?= $sort==='new'?'selected':'' ?>>Сначала новые</option>
                    <option value="old"       <?= $sort==='old'?'selected':'' ?>>Сначала старые</option>
                    <option value="date_asc"  <?= $sort==='date_asc'?'selected':'' ?>>Дата события ↑</option>
                    <option value="date_desc" <?= $sort==='date_desc'?'selected':'' ?>>Дата события ↓</option>
                    <option value="status"    <?= $sort==='status'?'selected':'' ?>>По статусу</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="admin.php" class="btn btn-ghost btn-sm">Сбросить</a>
            </div>
        </form>
    </aside>

    <!-- Правая колонка: таблица + пагинация -->
    <div class="admin-table-area">
        <!-- Контейнер для скролла таблицы -->
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>№</th><th>Заявитель</th><th>Объект</th><th>Категория</th>
                        <th>Дата события</th><th>Оплата</th><th>Статус</th><th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="td-empty">Заявок по выбранным фильтрам не найдено.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td><b><?= e($r['fio']) ?></b><br><small class="muted-text"><?= e($r['login']) ?> · <?= e($r['phone']) ?></small></td>
                            <td><?= e($r['title']) ?></td>
                            <td><?= e($r['category']) ?></td>
                            <td><?= date('d.m.Y', strtotime($r['event_date'])) ?></td>
                            <td><small><?= e($r['payment']) ?></small></td>
                            <td><span class="badge <?= status_class($r['status'], $statuses) ?>"><?= e($r['status']) ?></span></td>
                            <td>
                                <button class="btn btn-outline btn-sm js-open-status"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-status="<?= e($r['status']) ?>"
                                        data-who="<?= e($r['fio']) ?>"
                                        data-title="<?= e($r['title']) ?>">Изменить</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Пагинация (теперь она вне скроллящегося блока, будет видна всегда) -->
        <?php if ($pages > 1): ?>
        <div class="pagination">
            <a class="page-btn <?= $page<=1?'disabled':'' ?>" href="<?= adminLink(['page'=>$page-1]) ?>">‹</a>
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <a class="page-btn <?= $p===$page?'active':'' ?>" href="<?= adminLink(['page'=>$p]) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn <?= $page>=$pages?'disabled':'' ?>" href="<?= adminLink(['page'=>$page+1]) ?>">›</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</section>

<!-- Модальное окно смены статуса -->
<div class="modal" id="statusModal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card reveal-now">
    <button class="modal-x" data-close aria-label="Закрыть">×</button>
    <h3>Изменение статуса</h3>
    <p class="muted-text" id="statusInfo"></p>
    <form method="post">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="request_id" id="statusReqId">
      <div class="status-options" id="statusOptions">
        <?php foreach ($statuses as $i => $s): ?>
          <label class="status-opt st-<?= $i ?>">
            <input type="radio" name="status" value="<?= e($s) ?>">
            <span><?= e($s) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Сохранить статус</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
