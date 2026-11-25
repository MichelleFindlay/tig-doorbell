<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

// Which event(s) is the door open for right now? (Managed on the organiser Events page.)
$openEvents = events_open_now();
$generic = !$openEvents;                      // no scheduled event on: fall back to config event_name
$closed = $generic && !config('allow_knock_without_event');
$event = count($openEvents) === 1 ? $openEvents[0]['title'] : config('event_name');
$selectedId = count($openEvents) === 1 ? (int) $openEvents[0]['id'] : (int) ($_POST['event_id'] ?? 0);
$errors = [];
$name = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$closed) {
    $name    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
    $message = trim((string) ($_POST['message'] ?? ''));
    $confirm = ($_POST['confirm'] ?? '') === 'yes';

    if (!csrf_check()) {
        $errors[] = 'Your session expired — please try again.';
    }
    if (($_POST['website'] ?? '') !== '') { // honeypot: humans never see this field
        $errors[] = 'Something went wrong — please try again.';
    }
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Please enter your name (up to 80 characters).';
    }
    if (mb_strlen($message) > 300) {
        $errors[] = 'Please keep your note under 300 characters.';
    }
    // Work out which event they're confirming.
    $chosen = null;
    foreach ($openEvents as $e) {
        if ((int) $e['id'] === $selectedId) {
            $chosen = $e;
        }
    }
    if (!$generic && !$chosen) {
        $errors[] = 'Please choose which event you are here for.';
    } elseif (!$confirm) {
        $label = $chosen ? $chosen['title'] : $event;
        $errors[] = "Please confirm you're here for {$label} — this door is for attendees only.";
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM knocks WHERE ip = ? AND created_at > ?');
        $stmt->execute([client_ip(), time() - config('knock_rate_window')]);
        if ((int) $stmt->fetchColumn() >= config('knock_rate_limit')) {
            $errors[] = "You've knocked a few times already — the organisers have been notified. Please wait a moment.";
        }
    }

    if (!$errors) {
        $now = time();
        $token = random_token();
        db()->prepare('INSERT INTO knocks(visitor_token, name, message, event_id, event_title, ip, created_at, last_notified_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$token, $name, $message !== '' ? $message : null, $chosen ? (int) $chosen['id'] : null,
                $chosen ? $chosen['title'] : config('event_name'), client_ip(), $now, $now]);
        db()->prepare('DELETE FROM knocks WHERE created_at < ?')->execute([$now - config('retention_days') * 86400]);

        try {
            notify_devices();
        } catch (Throwable $e) {
            error_log('[tig-door] notify failed: ' . $e->getMessage());
        }
        redirect('status.php?k=' . urlencode($token));
    }
}

$csrf = csrf_token(); // starts the session before any output
page_start("{$event} — Knock knock");
?>
<section class="card">
    <div class="door" aria-hidden="true">🚪</div>
    <h1>Welcome to <?= h($event) ?></h1>
    <p class="lead"><?= h(config('event_tagline')) ?></p>

    <?php if ($closed): $next = next_session(); ?>
        <div class="alert" role="status">
            <p>There's no event on right now, so the door alert is switched off.</p>
            <?php if ($next): ?>
                <p>Next: <strong><?= h($next['event']['title']) ?></strong> —
                   <?= h($next['start']->format('l j F, H:i')) ?><?= $next['event']['location'] ? ' · ' . h($next['event']['location']) : '' ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <?php if (count($openEvents) === 1): $e = $openEvents[0]; ?>
        <div class="event-info">
            <p><strong>🕒 <?= h($e['start_time']) ?>–<?= h($e['end_time']) ?></strong>
               <?php if ($e['location']): ?> · 📍 <?= h($e['location']) ?><?php endif; ?></p>
            <?php if ($e['description']): ?><p class="pre"><?= h($e['description']) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert" role="alert">
            <?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="on" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="hp" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <label for="name">Your name</label>
        <input id="name" name="name" type="text" required maxlength="80" autocomplete="name"
               value="<?= h($name) ?>" placeholder="e.g. Sam Taylor" autofocus>

        <label for="message">Anything we should know? <span class="muted">(optional)</span></label>
        <textarea id="message" name="message" maxlength="300" rows="2"
                  placeholder="e.g. I'm with Alex / need step-free access"><?= h($message) ?></textarea>

        <?php if (count($openEvents) > 1): ?>
            <fieldset class="choices">
                <legend>Which event are you here for?</legend>
                <?php foreach ($openEvents as $e): ?>
                    <label class="check">
                        <input type="radio" name="event_id" value="<?= (int) $e['id'] ?>" required <?= $selectedId === (int) $e['id'] ? 'checked' : '' ?>>
                        <span><strong><?= h($e['title']) ?></strong><br>
                            <span class="muted"><?= h($e['start_time']) ?>–<?= h($e['end_time']) ?><?= $e['location'] ? ' · ' . h($e['location']) : '' ?></span>
                            <?php if ($e['description']): ?><br><span class="muted pre"><?= h($e['description']) ?></span><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php elseif (count($openEvents) === 1): ?>
            <input type="hidden" name="event_id" value="<?= (int) $openEvents[0]['id'] ?>">
        <?php endif; ?>

        <label class="check">
            <input type="checkbox" name="confirm" value="yes" required <?= ($_POST['confirm'] ?? '') === 'yes' ? 'checked' : '' ?>>
            <span>I confirm I'm here for
                <?php if (count($openEvents) > 1): ?>the event selected above
                <?php else: ?><strong><?= h($event) ?></strong><?php endif; ?>
            </span>
        </label>

        <button type="submit" class="btn big">Knock on the door</button>
    </form>
    <?php endif; ?>
</section>
<?php page_end();
