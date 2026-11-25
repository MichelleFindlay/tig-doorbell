<?php
// Organisers add, edit and remove one-off or repeating events.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$me = current_organiser();
if (!$me) {
    redirect('login.php');
}

$errors = [];
$notice = '';
$form = null; // event being added/edited

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if (!csrf_check()) {
        $errors[] = 'Your session expired — please try again.';
    } elseif ($action === 'save') {
        [$data, $errors] = validate_event($_POST);
        if (!$errors) {
            $now = time();
            if ($id > 0) {
                db()->prepare(
                    'UPDATE events SET title = ?, location = ?, description = ?, start_date = ?, start_time = ?, end_time = ?,
                            recurrence = ?, repeat_until = ?, skip_dates = ?, active = ?, updated_at = ? WHERE id = ?'
                )->execute([$data['title'], $data['location'] ?: null, $data['description'] ?: null, $data['start_date'],
                    $data['start_time'], $data['end_time'], $data['recurrence'], $data['repeat_until'], $data['skip_dates'],
                    $data['active'], $now, $id]);
                $notice = "Saved \"{$data['title']}\".";
            } else {
                db()->prepare(
                    'INSERT INTO events(title, location, description, start_date, start_time, end_time, recurrence,
                                        repeat_until, skip_dates, active, created_by, created_at, updated_at)
                     VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$data['title'], $data['location'] ?: null, $data['description'] ?: null, $data['start_date'],
                    $data['start_time'], $data['end_time'], $data['recurrence'], $data['repeat_until'], $data['skip_dates'],
                    $data['active'], $me['display_name'], $now, $now]);
                $notice = "Added \"{$data['title']}\".";
            }
        } else {
            $form = $data + ['id' => $id];
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        $notice = 'Event deleted.';
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE events SET active = 1 - active, updated_at = ? WHERE id = ?')->execute([time(), $id]);
        $notice = 'Event updated.';
    } elseif ($action === 'skip') {
        // Cancel one session of a repeating event.
        $date = (string) ($_POST['date'] ?? '');
        $stmt = db()->prepare('SELECT skip_dates FROM events WHERE id = ?');
        $stmt->execute([$id]);
        if (event_date($date) && ($row = $stmt->fetch())) {
            $skips = event_skip_dates($row);
            $skips[] = $date;
            $skips = array_unique($skips);
            sort($skips);
            db()->prepare('UPDATE events SET skip_dates = ?, updated_at = ? WHERE id = ?')->execute([implode(',', $skips), time(), $id]);
            $notice = 'Session on ' . event_date($date)->format('l j M') . ' cancelled.';
        }
    }
}

// Load an event into the form for editing (?edit=ID) or prepare a blank one (?new).
if (!$form && isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $form = $stmt->fetch() ?: null;
}
if (!$form && isset($_GET['new'])) {
    $form = ['id' => 0, 'title' => config('event_name'), 'location' => '', 'description' => '',
        'start_date' => date('Y-m-d'), 'start_time' => '19:00', 'end_time' => '21:00',
        'recurrence' => 'weekly', 'repeat_until' => '', 'skip_dates' => '', 'active' => 1];
}

$events = db()->query('SELECT * FROM events ORDER BY active DESC, start_time, title')->fetchAll();
$today = events_on(new DateTimeImmutable('today'));
$openNow = array_column(events_open_now(), 'id');

$csrf = csrf_token();
page_start('Events — ' . app_name(), '../');
?>
<?php organiser_nav($me, 'events'); ?>

<?php if ($errors): ?>
    <div class="alert" role="alert"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="notice" role="status"><p><?= h($notice) ?></p></div>
<?php endif; ?>

<?php if ($form): ?>
<section class="card">
    <h2><?= (int) $form['id'] > 0 ? 'Edit event' : 'Add an event' ?></h2>
    <form method="post" action="events.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <label for="title">Event name <span class="muted">(visitors confirm they're here for this)</span></label>
        <input id="title" name="title" type="text" required maxlength="80" value="<?= h($form['title']) ?>" placeholder="e.g. TIG Monthly Meetup">

        <label for="location">Location / room <span class="muted">(optional)</span></label>
        <input id="location" name="location" type="text" maxlength="120" value="<?= h($form['location']) ?>" placeholder="e.g. Upstairs hall, 2nd floor">

        <label for="description">Details for visitors <span class="muted">(optional, shown on the door page)</span></label>
        <textarea id="description" name="description" rows="3" maxlength="1000" placeholder="e.g. Doors at 6:45. Lift is on the left."><?= h($form['description']) ?></textarea>

        <div class="grid3">
            <div>
                <label for="start_date"><?= $form['recurrence'] === 'none' ? 'Date' : 'First date' ?></label>
                <input id="start_date" name="start_date" type="date" required value="<?= h($form['start_date']) ?>">
            </div>
            <div>
                <label for="start_time">Starts</label>
                <input id="start_time" name="start_time" type="time" required value="<?= h($form['start_time']) ?>">
            </div>
            <div>
                <label for="end_time">Ends</label>
                <input id="end_time" name="end_time" type="time" required value="<?= h($form['end_time']) ?>">
            </div>
        </div>

        <label for="recurrence">Repeats</label>
        <select id="recurrence" name="recurrence">
            <?php foreach (recurrence_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $form['recurrence'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>

        <div id="repeatFields">
            <label for="repeat_until">Repeat until <span class="muted">(optional — leave blank to repeat forever)</span></label>
            <input id="repeat_until" name="repeat_until" type="date" value="<?= h((string) $form['repeat_until']) ?>">

            <label for="skip_dates">Skip these dates <span class="muted">(optional, e.g. holidays — YYYY-MM-DD, comma separated)</span></label>
            <input id="skip_dates" name="skip_dates" type="text" value="<?= h(str_replace(',', ', ', (string) $form['skip_dates'])) ?>" placeholder="2026-12-22, 2026-12-29">
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" <?= (int) $form['active'] ? 'checked' : '' ?>>
            <span>Active — show this event on the door page when it's on</span>
        </label>

        <div class="row" style="margin-top:20px">
            <button class="btn" type="submit">Save event</button>
            <a class="btn secondary" href="events.php">Cancel</a>
        </div>
    </form>
</section>
<script>
(() => {
    const sel = document.getElementById('recurrence');
    const extra = document.getElementById('repeatFields');
    const sync = () => { extra.hidden = sel.value === 'none'; };
    sel.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>

<section class="card">
    <h2>Today</h2>
    <?php if (!$today): ?>
        <p class="muted">No events today.<?= config('allow_knock_without_event') ? ' Visitors will be asked to confirm they\'re here for ' . h(config('event_name')) . '.' : ' The door page is closed.' ?></p>
    <?php else: ?>
        <ul class="knocks">
            <?php foreach ($today as $e): ?>
                <li class="knock">
                    <div>
                        <strong><?= h($e['title']) ?></strong>
                        <span class="muted">· <?= h($e['start_time']) ?>–<?= h($e['end_time']) ?></span>
                        <?php if ($e['location']): ?><p><?= h($e['location']) ?></p><?php endif; ?>
                    </div>
                    <?php if (in_array($e['id'], $openNow)): ?>
                        <span class="badge">● On the door page now</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <div class="topbar" style="margin:0 0 8px">
        <h2 style="margin:0">All events</h2>
        <?php if (!$form): ?><a class="btn" href="events.php?new">+ Add event</a><?php endif; ?>
    </div>
    <?php if (!$events): ?>
        <p class="muted">No events yet. Add your regular sessions (e.g. every Tuesday 19:00–21:00) and one-off events here.</p>
    <?php endif; ?>
    <ul class="knocks">
        <?php foreach ($events as $e): $next = event_next_dates($e, 3); ?>
            <li class="event <?= (int) $e['active'] ? '' : 'inactive' ?>">
                <div>
                    <strong><?= h($e['title']) ?></strong>
                    <?php if (!(int) $e['active']): ?><span class="muted"> · paused</span><?php endif; ?>
                    <p><?= h(event_schedule_text($e)) ?></p>
                    <?php if ($e['location']): ?><p>📍 <?= h($e['location']) ?></p><?php endif; ?>
                    <?php if ($e['description']): ?><p class="pre"><?= h($e['description']) ?></p><?php endif; ?>
                    <?php if ((int) $e['active']): ?>
                        <?php if ($next): ?>
                            <p class="next">Next:
                                <?php foreach ($next as $i => $ymd): ?>
                                    <span class="chip"><?= h(event_date($ymd)->format('D j M')) ?>
                                        <?php if ($e['recurrence'] !== 'none'): ?>
                                            <form method="post" class="inline confirm" data-msg="Cancel the session on <?= h(event_date($ymd)->format('l j F')) ?>?">
                                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="skip">
                                                <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                                                <input type="hidden" name="date" value="<?= h($ymd) ?>">
                                                <button type="submit" class="chip-x" title="Cancel this session" aria-label="Cancel this session">×</button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </p>
                        <?php else: ?>
                            <p class="next muted">No upcoming sessions.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (event_skip_dates($e)): ?>
                        <p class="muted small-left">Skipping: <?= h(implode(', ', array_map(fn ($d) => event_date($d)?->format('j M Y') ?? $d, event_skip_dates($e)))) ?></p>
                    <?php endif; ?>
                </div>
                <div class="row">
                    <a class="btn secondary" href="events.php?edit=<?= (int) $e['id'] ?>">Edit</a>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                        <button class="btn secondary" type="submit"><?= (int) $e['active'] ? 'Pause' : 'Resume' ?></button>
                    </form>
                    <form method="post" class="inline confirm" data-msg="Delete &quot;<?= h($e['title']) ?>&quot; and all its sessions?">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<script>
document.querySelectorAll('form.confirm').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.msg)) e.preventDefault();
}));
</script>
<?php page_end();
