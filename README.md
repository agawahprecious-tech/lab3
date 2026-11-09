# Lab 03 - Clinic Appointment Booking

PHP 8 + MySQL/MariaDB form with server-side validation and secure insertion (PDO prepared statements).

## Files
| File | Purpose |
|---|---|
| `index.php` | Form, validation, database insert, extension feature |
| `style.css` | Layout and styling |
| `database.sql` | Creates database `lab03_clinic_appointments` and its table(s) |
| `README.md` | This document |

## Setup
1. Install XAMPP, WAMP, MAMP or any PHP 8.0+ and MySQL 5.7+/MariaDB 10.2+ stack.
2. Import the schema: `mysql -u root -p < database.sql` (or paste it into phpMyAdmin > SQL).
3. Copy this folder into the web root (e.g. `htdocs/`). If your MySQL user or password differs from `root` / empty, edit the `DB_USER` and `DB_PASS` constants at the top of `index.php`.
4. Open `http://localhost/lab03_clinic_appointments/index.php`.

## Database design
`appointments` (PK `appointment_id`, `status` default Pending, generated `slot_key` with UNIQUE index). Full definition in `database.sql`.

## Validation rules
- Patient name: required, 3-80 characters, letters plus space . ' - only.
- Email: optional; must be valid if supplied.
- Phone: required, 9 digits starting with 6 (+237 prefix tolerated).
- Department, doctor, time slot: required, from server-side lists; the doctor must belong to the chosen department.
- Appointment date: required, valid, strictly after today and within one year.
- Reason: 10-500 characters. Consent must be ticked.
- Status is always saved as `Pending`.
- No double booking: one non-cancelled appointment per doctor + date + slot (pre-check and UNIQUE index on `slot_key`; a Cancelled appointment frees the slot).

All checks run on the server; HTML5 attributes are only a convenience (the form uses `novalidate` so the server messages can be demonstrated). Invalid submissions keep the entered values (escaped with `htmlspecialchars`) and show a message beside each faulty field.

## Security measures
- Every query uses PDO prepared statements with real (non-emulated) preparation; user input is never concatenated into SQL.
- Success is shown only after `rowCount() === 1`; connection and SQL errors are logged with `error_log` and a generic message is shown to the user.
- CSRF token on every POST, post/redirect/get after success to prevent resubmission.
- All output is HTML-escaped.

## Extension challenge
Upcoming schedule table, filterable by doctor.

## Test cases
Run each case, then fill in the last column with the observed result and attach screenshots of the form and of the database query below.

| # | Case | Input | Expected result | Actual result |
|---|---|---|---|---|
| 1 | Valid submission | `Test Patient`, phone `671234567`, General Medicine / Dr. Ngu Atanga, date = tomorrow+3, slot 09:00-10:00, reason 12+ chars, consent | Success with reference; row with status Pending | _fill in_ |
| 2 | Missing required value | No time slot chosen | Error under time slot; no row | _fill in_ |
| 3 | Past date | Appointment date = today | Error "must be on a future date"; no row | _fill in_ |
| 4 | Short reason | Reason `Cough` | Error with character count; no row | _fill in_ |
| 5 | Optional email invalid | Email `not-an-email` | Error under email; blank email is accepted | _fill in_ |
| 6 | Wrong doctor/department | Dentistry with Dr. Ngu Atanga (edit request) | Error "does not work in the selected department" | _fill in_ |
| 7 | Double booking | Repeat test 1 for the same doctor/date/slot with another patient name | Error "already booked"; still 1 row | _fill in_ |
| 8 | Slot freed by cancellation | `UPDATE appointments SET status='Cancelled' WHERE appointment_id=1;` then repeat | Accepted | _fill in_ |

### Database evidence query
```sql
SELECT appointment_id, patient_name, doctor, appointment_date, time_slot, status, created_at FROM appointments ORDER BY appointment_id DESC;
```

## Assumptions
- Use fictional data only; no real personal data.
- Phone numbers follow the Cameroon mobile format (9 digits starting with 6) because prices are in XAF; change the regular expression in `index.php` to adapt.
