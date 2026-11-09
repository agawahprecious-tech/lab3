<?php
declare(strict_types=1);
session_start();

/* ---------- Configuration: edit for your environment ---------- */
const DB_HOST = 'localhost';
const DB_NAME = 'lab03_clinic_appointments';
const DB_USER = 'root';
const DB_PASS = '';

/* ---------- Helpers ---------- */
function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function post(string $key): string
{
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim($_POST[$key]) : '';
}

function err(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="error" id="' . h($key) . '-error" role="alert">' . h($errors[$key]) . '</p>' : '';
}

function inv(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . h($key) . '-error"' : '';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Allowed values ---------- */
$doctorsByDept = [
    'General Medicine' => ['Dr. Ngu Atanga', 'Dr. Mbarga Essomba'],
    'Paediatrics'      => ['Dr. Tchoumi Nana'],
    'Dentistry'        => ['Dr. Fotso Kamga', 'Dr. Ayuk Tabe'],
    'Cardiology'       => ['Dr. Bello Hamadou'],
];
$slots = ['08:00-09:00', '09:00-10:00', '10:00-11:00', '11:00-12:00', '14:00-15:00', '15:00-16:00', '16:00-17:00'];
$allDoctors = array_merge(...array_values($doctorsByDept));

/* ---------- State ---------- */
$errors = [];
$old = ['patient_name' => '', 'email' => '', 'phone' => '', 'department' => '', 'doctor' => '',
        'appointment_date' => '', 'time_slot' => '', 'reason' => '', 'consent' => ''];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$tomorrow = new DateTime('tomorrow');

/* ---------- Handle submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], post('csrf'))) {
        $errors['form'] = 'Your session expired. Reload the page and submit again.';
    }
    foreach ($old as $key => $_) {
        $old[$key] = post($key);
    }
    $old['patient_name'] = preg_replace('/\s+/u', ' ', $old['patient_name']);

    // Patient name: 3-80 characters
    $len = mb_strlen($old['patient_name']);
    if ($old['patient_name'] === '') {
        $errors['patient_name'] = 'Enter the patient name.';
    } elseif ($len < 3 || $len > 80) {
        $errors['patient_name'] = 'Name must be 3 to 80 characters.';
    } elseif (!preg_match("/^\p{L}[\p{L} .'\-]*$/u", $old['patient_name'])) {
        $errors['patient_name'] = 'Use letters, spaces, apostrophes, hyphens and full stops only.';
    }

    // Email: optional, valid if supplied
    $email = mb_strtolower($old['email']);
    if ($email !== '' && (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'Enter a valid email address or leave this blank.';
    }

    // Phone: 9 digits starting with 6; +237 prefix tolerated
    $phone = '';
    if ($old['phone'] === '') {
        $errors['phone'] = 'Enter a phone number.';
    } else {
        $compact = preg_replace('/[\s\-]/', '', $old['phone']);
        if (preg_match('/^(?:\+?237)?(6\d{8})$/', $compact, $m)) {
            $phone = $m[1];
        } else {
            $errors['phone'] = 'Enter 9 digits starting with 6, e.g. 671234567.';
        }
    }

    // Department and doctor (doctor must belong to the chosen department)
    if ($old['department'] === '') {
        $errors['department'] = 'Choose a department.';
    } elseif (!isset($doctorsByDept[$old['department']])) {
        $errors['department'] = 'Choose a department from the list.';
    }
    if ($old['doctor'] === '') {
        $errors['doctor'] = 'Choose a doctor.';
    } elseif (!in_array($old['doctor'], $allDoctors, true)) {
        $errors['doctor'] = 'Choose a doctor from the list.';
    } elseif (!isset($errors['department']) && !in_array($old['doctor'], $doctorsByDept[$old['department']], true)) {
        $errors['doctor'] = 'This doctor does not work in the selected department.';
    }

    // Appointment date: valid and strictly in the future
    $date = DateTime::createFromFormat('!Y-m-d', $old['appointment_date']);
    if ($old['appointment_date'] === '') {
        $errors['appointment_date'] = 'Choose an appointment date.';
    } elseif (!$date || $date->format('Y-m-d') !== $old['appointment_date']) {
        $errors['appointment_date'] = 'Enter a valid date (YYYY-MM-DD).';
    } elseif ($date < $tomorrow) {
        $errors['appointment_date'] = 'The appointment must be on a future date.';
    } elseif ($date > (new DateTime('today'))->modify('+1 year')) {
        $errors['appointment_date'] = 'Appointments can be booked up to one year ahead.';
    }

    // Time slot
    if ($old['time_slot'] === '') {
        $errors['time_slot'] = 'Choose a time slot.';
    } elseif (!in_array($old['time_slot'], $slots, true)) {
        $errors['time_slot'] = 'Choose a time slot from the list.';
    }

    // Reason: 10-500 characters
    $rlen = mb_strlen($old['reason']);
    if ($rlen < 10 || $rlen > 500) {
        $errors['reason'] = 'Describe the reason in 10 to 500 characters (currently ' . $rlen . ').';
    }

    // Consent
    if ($old['consent'] !== '1') {
        $errors['consent'] = 'You must consent to the use of your data.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $busy = $pdo->prepare(
                "SELECT 1 FROM appointments
                 WHERE doctor = :d AND appointment_date = :dt AND time_slot = :s AND status <> 'Cancelled' LIMIT 1"
            );
            $busy->execute([':d' => $old['doctor'], ':dt' => $date->format('Y-m-d'), ':s' => $old['time_slot']]);

            if ($busy->fetchColumn()) {
                $errors['time_slot'] = $old['doctor'] . ' is already booked for that date and slot. Choose another.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO appointments
                       (patient_name, email, phone, department, doctor, appointment_date, time_slot, reason, consent_given, status)
                     VALUES (:n, :e, :p, :dep, :doc, :dt, :s, :r, 1, 'Pending')"
                );
                $stmt->execute([
                    ':n' => $old['patient_name'], ':e' => $email !== '' ? $email : null, ':p' => $phone,
                    ':dep' => $old['department'], ':doc' => $old['doctor'], ':dt' => $date->format('Y-m-d'),
                    ':s' => $old['time_slot'], ':r' => $old['reason'],
                ]);
                if ($stmt->rowCount() === 1) {
                    $_SESSION['flash'] = ['msg' => 'Appointment request saved (reference ' . $pdo->lastInsertId()
                        . '). Status: Pending.'];
                    header('Location: index.php', true, 303);
                    exit;
                }
                $errors['form'] = 'The appointment could not be saved. Please try again.';
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $errors['time_slot'] = 'That doctor was just booked for this slot. Choose another.';
            } else {
                error_log('Lab03 insert failed: ' . $e->getMessage());
                $errors['form'] = 'The database is unavailable right now. Please try again later.';
            }
        }
    }
}

/* ---------- Extension: schedule by doctor ---------- */
$filter = isset($_GET['doctor']) && is_string($_GET['doctor']) && in_array($_GET['doctor'], $allDoctors, true)
    ? $_GET['doctor'] : '';
$schedule = [];
$listError = null;
try {
    $sql = "SELECT appointment_id, doctor, appointment_date, time_slot, patient_name, status
            FROM appointments WHERE status <> 'Cancelled' AND appointment_date >= CURDATE()";
    $params = [];
    if ($filter !== '') {
        $sql .= ' AND doctor = :doc';
        $params[':doc'] = $filter;
    }
    $sql .= ' ORDER BY doctor, appointment_date, time_slot LIMIT 100';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $schedule = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Lab03 list failed: ' . $e->getMessage());
    $listError = 'The schedule is unavailable (database connection problem).';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clinic Appointment Booking</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
  <h1 class="page-title">Clinic appointment booking</h1>
  <p class="subtitle">Request an appointment. Fields marked * are required. Use fictional data only.</p>

  <section class="card" aria-labelledby="form-title">
    <h2 id="form-title">Appointment request</h2>

    <?php if ($flash): ?>
      <div class="alert alert-success" role="status"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if (isset($errors['form'])): ?>
      <div class="alert alert-error" role="alert"><?= h($errors['form']) ?></div>
    <?php elseif ($errors): ?>
      <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
    <?php endif; ?>

    <form method="post" action="index.php" novalidate>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

      <div class="form-group">
        <label class="required" for="patient_name">Patient name</label>
        <input type="text" id="patient_name" name="patient_name" maxlength="80" autocomplete="name"
               value="<?= h($old['patient_name']) ?>"<?= inv($errors, 'patient_name') ?>>
        <?= err($errors, 'patient_name') ?>
      </div>

      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" maxlength="150" autocomplete="email"
               value="<?= h($old['email']) ?>"<?= inv($errors, 'email') ?>>
        <p class="hint">Optional.</p>
        <?= err($errors, 'email') ?>
      </div>

      <div class="form-group">
        <label class="required" for="phone">Phone</label>
        <input type="tel" id="phone" name="phone" inputmode="numeric" autocomplete="tel" placeholder="671234567"
               value="<?= h($old['phone']) ?>"<?= inv($errors, 'phone') ?>>
        <p class="hint">9 digits starting with 6. A +237 prefix is accepted.</p>
        <?= err($errors, 'phone') ?>
      </div>

      <div class="form-group">
        <label class="required" for="department">Department</label>
        <select id="department" name="department"<?= inv($errors, 'department') ?>>
          <option value="">Choose an option</option>
          <?php foreach (array_keys($doctorsByDept) as $dep): ?>
            <option value="<?= h($dep) ?>"<?= $old['department'] === $dep ? ' selected' : '' ?>><?= h($dep) ?></option>
          <?php endforeach; ?>
        </select>
        <?= err($errors, 'department') ?>
      </div>

      <div class="form-group">
        <label class="required" for="doctor">Preferred doctor</label>
        <select id="doctor" name="doctor" data-selected="<?= h($old['doctor']) ?>"<?= inv($errors, 'doctor') ?>>
          <option value="">Choose an option</option>
          <?php foreach ($allDoctors as $doc): ?>
            <option value="<?= h($doc) ?>"<?= $old['doctor'] === $doc ? ' selected' : '' ?>><?= h($doc) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint">The list narrows to the chosen department when JavaScript is on.</p>
        <?= err($errors, 'doctor') ?>
      </div>

      <div class="form-group">
        <label class="required" for="appointment_date">Appointment date</label>
        <input type="date" id="appointment_date" name="appointment_date" min="<?= h($tomorrow->format('Y-m-d')) ?>"
               value="<?= h($old['appointment_date']) ?>"<?= inv($errors, 'appointment_date') ?>>
        <?= err($errors, 'appointment_date') ?>
      </div>

      <div class="form-group">
        <label class="required" for="time_slot">Time slot</label>
        <select id="time_slot" name="time_slot"<?= inv($errors, 'time_slot') ?>>
          <option value="">Choose an option</option>
          <?php foreach ($slots as $s): ?>
            <option value="<?= h($s) ?>"<?= $old['time_slot'] === $s ? ' selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <?= err($errors, 'time_slot') ?>
      </div>

      <div class="form-group">
        <label class="required" for="reason">Symptoms or reason</label>
        <textarea id="reason" name="reason" maxlength="500"<?= inv($errors, 'reason') ?>><?= h($old['reason']) ?></textarea>
        <p class="hint">10 to 500 characters.</p>
        <?= err($errors, 'reason') ?>
      </div>

      <div class="form-group">
        <span class="required" style="font-weight:600">Consent to data use</span><br>
        <label class="checkbox-row" for="consent">
          <input type="checkbox" id="consent" name="consent" value="1"<?= $old['consent'] === '1' ? ' checked' : '' ?><?= inv($errors, 'consent') ?>>
          I agree
        </label>
        <?= err($errors, 'consent') ?>
      </div>

      <div class="actions">
        <button type="submit" class="btn-primary">Submit Form</button>
        <button type="reset" class="btn-secondary">Reset</button>
      </div>
    </form>
  </section>

  <section class="card" aria-labelledby="list-title">
    <h2 id="list-title">Upcoming schedule by doctor</h2>
    <form method="get" action="index.php" class="search-row">
      <label for="filter" style="position:absolute;left:-9999px">Filter by doctor</label>
      <select id="filter" name="doctor">
        <option value="">All doctors</option>
        <?php foreach ($allDoctors as $doc): ?>
          <option value="<?= h($doc) ?>"<?= $filter === $doc ? ' selected' : '' ?>><?= h($doc) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary">Show</button>
    </form>

    <?php if ($listError): ?>
      <p class="error"><?= h($listError) ?></p>
    <?php elseif (!$schedule): ?>
      <p class="empty">No upcoming appointments.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Doctor</th><th>Date</th><th>Slot</th><th>Patient</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($schedule as $a): ?>
            <tr>
              <td><?= h($a['doctor']) ?></td>
              <td><?= h($a['appointment_date']) ?></td>
              <td><?= h($a['time_slot']) ?></td>
              <td><?= h($a['patient_name']) ?></td>
              <td><span class="badge"><?= h($a['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<script>
// Progressive enhancement: show only the doctors of the chosen department.
(function () {
  var map = <?= json_encode($doctorsByDept, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var dep = document.getElementById('department'), doc = document.getElementById('doctor');
  function rebuild(keep) {
    var list = map[dep.value] || [].concat.apply([], Object.keys(map).map(function (k) { return map[k]; }));
    doc.innerHTML = '';
    var first = document.createElement('option');
    first.value = ''; first.textContent = 'Choose an option';
    doc.appendChild(first);
    list.forEach(function (name) {
      var o = document.createElement('option');
      o.value = name; o.textContent = name;
      if (name === keep) { o.selected = true; }
      doc.appendChild(o);
    });
  }
  dep.addEventListener('change', function () { rebuild(''); });
  rebuild(doc.getAttribute('data-selected') || '');
})();
</script>
</body>
</html>
