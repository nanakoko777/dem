<?php
require __DIR__ . '/bootstrap.php';
require_user();
$user = current_user();

// Добавление отзыва
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $reqId  = (int) ($_POST['request_id'] ?? 0);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');

    $st = pdo()->prepare("SELECT * FROM requests WHERE id = ? AND user_id = ?");
    $st->execute([$reqId, $user['id']]);
    $req = $st->fetch();

    if (!$req) {
        flash('error', 'Заявка не найдена.');
    } elseif ($req['status'] === $CONFIG['statuses'][0]) {
        flash('error', 'Оставить отзыв можно только после изменения статуса заявки администратором.');
    } elseif ($comment === '') {
        flash('error', 'Напишите текст отзыва.');
    } else {
        $ins = pdo()->prepare("INSERT INTO reviews (request_id, user_id, rating, comment)
                               VALUES (?,?,?,?)
                               ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)");
        $ins->execute([$reqId, $user['id'], $rating, $comment]);
        flash('success', 'Спасибо! Ваш отзыв сохранён.');
    }
    redirect('cabinet.php');
}

// История заявок пользователя + есть ли отзыв
$st = pdo()->prepare(
    "SELECT q.*, i.title, i.category, i.image,
            r.id AS review_id, r.rating, r.comment
       FROM requests q
       JOIN items i ON i.id = q.item_id
  LEFT JOIN reviews r ON r.request_id = q.id
      WHERE q.user_id = ?
   ORDER BY q.id DESC"
);
$st->execute([$user['id']]);
$requests = $st->fetchAll();

$statuses = $CONFIG['statuses'];

$PAGE_TITLE = 'Личный кабинет';
require __DIR__ . '/includes/header.php';
?>
<section class="section cabinet-section">
  <div class="container">

    <div class="cabinet-head reveal">
      <div>
        <h1>Здравствуйте, <?= e($user['fio']) ?>!</h1>
        <p class="muted-text"><?= e($user['email']) ?> · <?= e($user['phone']) ?></p>
      </div>
      <a href="booking.php" class="btn btn-primary"><?= e($CONFIG['cta']) ?></a>
    </div>

    <!-- Заголовок "Мои заявки" вынесен над сеткой -->
    <h2 class="block-title cabinet-title">Мои заявки</h2>

    <!-- Двухколоночная сетка: слева заявки, справа квадратный слайдер -->
    <div class="cabinet-grid reveal">
      <!-- ЛЕВАЯ КОЛОНКА: СПИСОК ЗАЯВОК -->
      <div class="cabinet-requests">
        <?php if (!$requests): ?>
          <div class="empty">
            <div class="empty-icon">📭</div>
            <h3>Заявок пока нет</h3>
            <p>Оформите первую заявку — она появится здесь.</p>
            <a href="booking.php" class="btn btn-primary"><?= e($CONFIG['cta']) ?></a>
          </div>
        <?php else: ?>
          <div class="req-list">
            <?php foreach ($requests as $q): ?>
              <article class="req-card">
                <div class="req-img"><img src="static/img/<?= e($q['image']) ?>" alt="" loading="lazy"></div>
                <div class="req-info">
                  <div class="req-top">
                    <h3><?= e($q['title']) ?></h3>
                    <span class="badge <?= status_class($q['status'], $statuses) ?>"><?= e($q['status']) ?></span>
                  </div>
                  <div class="req-meta">
                    <span>🏷️ <?= e($q['category']) ?></span>
                    <span>📅 <?= date('d.m.Y', strtotime($q['event_date'])) ?></span>
                    <span>💳 <?= e($q['payment']) ?></span>
                    <span>🆔 №<?= (int)$q['id'] ?></span>
                  </div>

                  <?php if ($q['status'] === $statuses[0]): ?>
                    <p class="review-locked">🔒 Отзыв станет доступен после изменения статуса администратором.</p>
                  <?php elseif ($q['review_id']): ?>
                    <div class="review-done">
                      <div class="stars"><?= str_repeat('★', (int)$q['rating']) . str_repeat('☆', 5 - (int)$q['rating']) ?></div>
                      <p>«<?= e($q['comment']) ?>»</p>
                    </div>
                  <?php else: ?>
                    <button class="btn btn-outline btn-sm js-open-review"
                            data-id="<?= (int)$q['id'] ?>" data-title="<?= e($q['title']) ?>">Оставить отзыв</button>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ПРАВАЯ КОЛОНКА: КВАДРАТНЫЙ СЛАЙДЕР -->
      <aside class="cabinet-slider-wrapper">
        <div class="cabinet-slider" id="cabinetSlider">
          <div class="cabinet-slides">
            <div class="cabinet-slide"><img src="static/img/slider/s1.jpg" alt="Слайд 1"><div class="cabinet-slide-cap">Современные площадки</div></div>
            <div class="cabinet-slide"><img src="static/img/slider/s2.jpg" alt="Слайд 2"><div class="cabinet-slide-cap">Удобное оборудование</div></div>
            <div class="cabinet-slide"><img src="static/img/slider/s3.jpg" alt="Слайд 3"><div class="cabinet-slide-cap">Атмосфера для идей</div></div>
            <div class="cabinet-slide"><img src="static/img/slider/s4.jpg" alt="Слайд 4"><div class="cabinet-slide-cap">Всё для вашего события</div></div>
          </div>
          <button class="cabinet-slider-btn prev" id="cabinetPrev" aria-label="Назад">‹</button>
          <button class="cabinet-slider-btn next" id="cabinetNext" aria-label="Вперёд">›</button>
          <div class="cabinet-slider-dots" id="cabinetDots"></div>
        </div>
      </aside>
    </div>

  </div>
</section>

<!-- Модальное окно отзыва -->
<div class="modal" id="reviewModal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card reveal-now">
    <button class="modal-x" data-close aria-label="Закрыть">×</button>
    <h3>Отзыв о заявке</h3>
    <p class="muted-text" id="reviewItemTitle"></p>
    <form method="post" class="form">
      <input type="hidden" name="action" value="review">
      <input type="hidden" name="request_id" id="reviewReqId">
      <div class="field">
        <label>Оценка</label>
        <div class="rating-input" id="ratingInput">
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
            <label for="r<?= $i ?>">★</label>
          <?php endfor; ?>
        </div>
      </div>
      <div class="field">
        <label>Комментарий</label>
        <textarea name="comment" rows="4" placeholder="Поделитесь впечатлениями о площадке и сервисе…" required></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Отправить отзыв</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Слайдер в личном кабинете
  const slider = document.getElementById('cabinetSlider');
  if (slider) {
    const slidesContainer = slider.querySelector('.cabinet-slides');
    const slides = slider.querySelectorAll('.cabinet-slide');
    const prevBtn = slider.querySelector('.cabinet-slider-btn.prev');
    const nextBtn = slider.querySelector('.cabinet-slider-btn.next');
    const dotsContainer = slider.querySelector('.cabinet-slider-dots');
    
    let currentIndex = 0;
    let totalSlides = slides.length;
    let interval;

    function createDots() {
      dotsContainer.innerHTML = '';
      for (let i = 0; i < totalSlides; i++) {
        const dot = document.createElement('div');
        dot.classList.add('cabinet-dot');
        if (i === currentIndex) dot.classList.add('active');
        dot.addEventListener('click', () => goToSlide(i));
        dotsContainer.appendChild(dot);
      }
    }

    function goToSlide(index) {
      if (index < 0) index = totalSlides - 1;
      if (index >= totalSlides) index = 0;
      currentIndex = index;
      slidesContainer.style.transform = `translateX(-${currentIndex * 100}%)`;
      updateDots();
      resetInterval();
    }

    function updateDots() {
      const dots = dotsContainer.querySelectorAll('.cabinet-dot');
      dots.forEach((dot, i) => {
        dot.classList.toggle('active', i === currentIndex);
      });
    }

    function nextSlide() { goToSlide(currentIndex + 1); }
    function prevSlide() { goToSlide(currentIndex - 1); }
    function resetInterval() {
      if (interval) clearInterval(interval);
      interval = setInterval(nextSlide, 4000);
    }

    prevBtn.addEventListener('click', prevSlide);
    nextBtn.addEventListener('click', nextSlide);
    createDots();
    resetInterval();
  }

  // Модальное окно отзыва
  const modal = document.getElementById('reviewModal');
  const reviewButtons = document.querySelectorAll('.js-open-review');
  const closeButtons = document.querySelectorAll('[data-close]');
  const reviewReqId = document.getElementById('reviewReqId');
  const reviewItemTitle = document.getElementById('reviewItemTitle');

  function closeModal() {
    if (modal) modal.setAttribute('hidden', '');
  }

  if (reviewButtons.length) {
    reviewButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const title = btn.dataset.title;
        if (reviewReqId) reviewReqId.value = id;
        if (reviewItemTitle) reviewItemTitle.textContent = `Заявка: ${title}`;
        if (modal) modal.removeAttribute('hidden');
      });
    });
  }

  if (closeButtons.length) {
    closeButtons.forEach(btn => {
      btn.addEventListener('click', closeModal);
    });
  }
  window.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>